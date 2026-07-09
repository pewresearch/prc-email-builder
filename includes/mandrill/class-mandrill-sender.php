<?php
declare(strict_types=1);
/**
 * Mandrill bulk-send integration for "system email" newsletters.
 *
 * Used when a newsletter's delivery mode is set to 'mandrill'. Delivers the
 * published email HTML to a fixed list of recipients stored in wp_options
 * (built by the `wp prc datasets build-audience` CLI) via the Mandrill
 * messages/send HTTP endpoint. Mailchimp campaign creation is bypassed entirely.
 *
 * Sends are resumable and re-entry-safe: each batch is checkpointed as it
 * succeeds, tied to a hash of the recipient list, and guarded by an atomic send
 * lock so a partial failure or a concurrent send request never re-blasts
 * recipients who already received the email. The lock is acquired atomically
 * (compare-and-swap on a dedicated, non-autoloaded option), carries an owner
 * token, and is heartbeated after every batch so a long-running send never goes
 * stale mid-flight and gets stolen by a concurrent worker.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Handles bulk delivery of system-email newsletters via the Mandrill HTTP API.
 */
class Mandrill_Sender {
	const API_KEY_CONSTANT = 'PRC_PLATFORM_MANDRILL_KEY';
	const API_URL          = 'https://mandrillapp.com/api/1.0/';
	const BATCH_SIZE       = 500;

	/**
	 * Seconds a send lock is considered fresh before it may be reclaimed by a
	 * new worker. The lock is heartbeated after every batch, so this only has to
	 * exceed the worst-case time for a single batch (plus a healthy margin), not
	 * the runtime of the entire send.
	 */
	const LOCK_TTL = 600;

	const STATUS_META   = 'prc_email_mandrill_send_status';
	const SUMMARY_META  = 'prc_email_mandrill_send_summary';
	const PROGRESS_META = 'prc_email_mandrill_send_progress';

	/**
	 * Legacy post-meta lock key (pre-atomic-lock). Retained only so reset/clear
	 * can purge any value left behind by an older release. The live lock now
	 * lives in the options table keyed by LOCK_OPTION_PREFIX . post_id.
	 */
	const LOCK_META = 'prc_email_mandrill_send_lock';

	/** Option-name prefix for the atomic send lock (one row per post). */
	const LOCK_OPTION_PREFIX = 'prc_email_mandrill_lock_';

	/** Action Scheduler hook for background bulk sends. */
	const SEND_HOOK = 'prc_email_mandrill_send';

	/** Action Scheduler group for Mandrill send jobs. */
	const ACTION_GROUP = 'prc-email-builder';

	public function __construct( ?Loader $loader = null ) {
		if ( $loader ) {
			$loader->add_action( self::SEND_HOOK, $this, 'run_scheduled_send', 10, 1 );
		}
	}

	/**
	 * Queue a bulk Mandrill send to run outside the current HTTP request.
	 *
	 * Deduplicated per post: the post ID is passed as the action argument with a
	 * constant group and `$unique = true`. Under Action Scheduler 3.x uniqueness
	 * was keyed on hook + group only, so a pending send for *any* post in the
	 * `prc-email-builder` group blocked every other post's send — a latent
	 * over-dedup bug that contradicted the per-post "already queued for post %d"
	 * error below. Action Scheduler 4.0.0 folds the arguments into the dedup key
	 * (hook + group + post ID), so this now correctly dedupes per post.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return int|WP_Error Action Scheduler action ID, or WP_Error on failure.
	 */
	public function schedule_send( int $post_id ): int|WP_Error {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error(
				'action_scheduler_unavailable',
				'Action Scheduler is not available. Use WP-CLI: wp prc email resend.'
			);
		}

		$action_id = as_enqueue_async_action(
			self::SEND_HOOK,
			[ $post_id ],
			self::ACTION_GROUP,
			true
		);

		if ( ! $action_id ) {
			return new WP_Error(
				'send_already_scheduled',
				sprintf(
					'A Mandrill send is already queued or in progress for post %d.',
					$post_id
				)
			);
		}

		return $action_id;
	}

	/**
	 * Action Scheduler callback: deliver a queued bulk send.
	 *
	 * @param int $post_id Newsletter post ID.
	 */
	public function run_scheduled_send( int $post_id ): void {
		if ( Migration::is_migrated( $post_id ) ) {
			return;
		}

		$html = Cached_Email_Html::resolve( $post_id );
		if ( is_wp_error( $html ) ) {
			update_post_meta( $post_id, self::STATUS_META, 'failed' );
			error_log( sprintf(
				'[prc-email-builder] Mandrill scheduled send failed for post %d: %s',
				$post_id,
				$html->get_error_message()
			) );
			return;
		}

		$result = $this->send_to_audience( $post_id, $html );

		if ( is_wp_error( $result ) ) {
			if ( 'send_locked' === $result->get_error_code() ) {
				if ( ! $this->is_locked( $post_id ) ) {
					$current = (string) get_post_meta( $post_id, self::STATUS_META, true );
					if ( in_array( $current, [ 'sending', '' ], true ) ) {
						update_post_meta( $post_id, self::STATUS_META, 'failed' );
						update_post_meta(
							$post_id,
							self::SUMMARY_META,
							wp_json_encode( [ 'error' => $result->get_error_message() ] )
						);
					}
				}
				return;
			}

			error_log( sprintf(
				'[prc-email-builder] Mandrill send failed for post %d: %s',
				$post_id,
				$result->get_error_message()
			) );
		}
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Delivers the newsletter HTML to the audience stored in wp_options.
	 *
	 * Resumes from the per-batch checkpoint when one exists for the same
	 * recipient list; only batches that have not yet succeeded are sent.
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $html    Email-safe HTML.
	 * @return array|WP_Error Rollup summary array or WP_Error on failure.
	 */
	public function send_to_audience( int $post_id, string $html ): array|WP_Error {
		if ( ! Post_Type::is_transactional_post( $post_id ) ) {
			return new WP_Error( 'wrong_post_type', 'Post is not a transactional email.' );
		}

		$delivery_mode = Post_Type::transactional_delivery_mode( $post_id );
		$audience_key  = get_post_meta( $post_id, 'prc_email_audience_option_key', true );
		$subject       = get_post_meta( $post_id, 'prc_email_subject', true ) ?: get_the_title( $post_id );
		$preview_text  = get_post_meta( $post_id, 'prc_email_preview_text', true );

		if ( 'mandrill' !== $delivery_mode ) {
			return new WP_Error( 'wrong_delivery_mode', 'Transactional sub-mode is not "mandrill".' );
		}
		if ( empty( $audience_key ) ) {
			return new WP_Error( 'missing_audience_key', 'No audience option key set for this newsletter.' );
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'mandrill_not_configured', 'Mandrill API key is not set.' );
		}

		$settings = Mailchimp::get_settings();
		$emails   = get_option( $audience_key, [] );

		if ( empty( $emails ) || ! is_array( $emails ) ) {
			return new WP_Error( 'empty_audience', sprintf( 'No emails found in option "%s".', $audience_key ) );
		}

		// Chunk into batches of BATCH_SIZE; the hash pins the checkpoint to this list.
		$batches       = array_chunk( $emails, self::BATCH_SIZE );
		$total_batches = count( $batches );
		$audience_hash = sha1( (string) wp_json_encode( $emails ) );

		// ── Acquire the send lock atomically ──────────────────────────────────
		// Done before any progress read/write so two concurrent invocations — or
		// a resume kicked off after a stale lock — can never process the same
		// batch twice and double-send to recipients. Only the worker that wins
		// the compare-and-swap proceeds; everyone else bails immediately.
		$lock_token = $this->acquire_lock( $post_id );
		if ( '' === $lock_token ) {
			return new WP_Error(
				'send_locked',
				sprintf(
					'A Mandrill send is already in progress for post %d; refusing to start a concurrent run.',
					$post_id
				)
			);
		}

		// ── Resolve checkpoint / resume state ─────────────────────────────────
		$progress      = $this->get_progress( $post_id );
		$prior_status  = get_post_meta( $post_id, self::STATUS_META, true );
		$completed     = [];

		if ( ! empty( $progress ) ) {
			if ( ( $progress['audience_hash'] ?? '' ) === $audience_hash ) {
				$completed = array_values( array_filter(
					(array) ( $progress['completed_batches'] ?? [] ),
					'is_int'
				) );
			} elseif ( in_array( $prior_status, [ 'partial', 'failed' ], true ) ) {
				// The recipient list changed under an in-progress send. Refuse to
				// auto-resume against shifted batch boundaries — require a manual reset.
				$this->release_lock( $post_id, $lock_token );
				return new WP_Error(
					'audience_changed',
					sprintf(
						'Audience for post %d changed since the last send. Reset progress before re-sending (wp prc email resend --post-id=%d --reset).',
						$post_id,
						$post_id
					)
				);
			}
			// Hash differs with no partial/failed prior state -> start fresh.
		}

		$completed_lookup = array_fill_keys( $completed, true );

		// ── Mark sending (lock already held) ─────────────────────────────────
		update_post_meta( $post_id, self::STATUS_META, 'sending' );

		$totals = [
			'batches'        => $total_batches,
			'sent'           => 0,
			'queued'         => 0,
			'rejected'       => 0,
			'invalid'        => 0,
			'failed_batches' => 0,
			'skipped_batches' => count( $completed ),
			'sent_at'        => current_time( 'mysql', true ),
		];

		foreach ( $batches as $index => $batch ) {
			// Skip batches already delivered in a prior run.
			if ( isset( $completed_lookup[ $index ] ) ) {
				continue;
			}

			// Fencing: if we no longer hold the lock (it went stale and another
			// worker reclaimed it), stop now so we never re-send a batch the new
			// owner is responsible for. Prevents duplicate deliveries on overlap.
			if ( ! $this->owns_lock( $post_id, $lock_token ) ) {
				error_log( sprintf(
					'[prc-email-builder] Mandrill send for post %d lost its lock mid-run; aborting before batch %d to avoid duplicate sends.',
					$post_id,
					$index
				) );
				break;
			}

			$from_email = (string) ( $settings['from_email'] ?? '' );
			$reply_to   = (string) ( $settings['reply_to'] ?? '' );
			if ( ! is_email( $reply_to ) ) {
				$reply_to = $from_email;
			}
			$base_tags = is_array( $settings['mandrill_tags'] ?? null ) ? $settings['mandrill_tags'] : [ 'prc-newsletter' ];

			$message = [
				'html'                => $html,
				'subject'             => $subject,
				'from_email'          => $from_email,
				'from_name'           => $settings['from_name'] ?? '',
				'headers'             => [ 'Reply-To' => $reply_to ],
				'track_opens'         => (bool) ( $settings['track_opens'] ?? true ),
				'track_clicks'        => (bool) ( $settings['track_clicks'] ?? true ),
				'tags'                => array_merge( $base_tags, [ 'bulk' ] ),
				'preserve_recipients' => false,
				'merge_language'      => 'mailchimp',
				'global_merge_vars'   => [
					[ 'name' => 'PREVIEW_TEXT', 'content' => $preview_text ],
				],
				'to'                  => array_map(
					fn( string $e ) => [ 'email' => $e, 'type' => 'to' ],
					$batch
				),
			];

			$subaccount = (string) ( $settings['mandrill_subaccount'] ?? '' );
			if ( '' !== $subaccount ) {
				$message['subaccount'] = $subaccount;
			}

			$payload = [
				'key'     => $api_key,
				'message' => $message,
				'async'   => true,
			];

			$response = wp_remote_post(
				self::API_URL . 'messages/send',
				[
					'timeout' => 30,
					'headers' => [ 'Content-Type' => 'application/json' ],
					'body'    => wp_json_encode( $payload ),
				]
			);

			if ( is_wp_error( $response ) ) {
				$totals['failed_batches']++;
				error_log( sprintf(
					'[prc-email-builder] Mandrill batch failed for post %d: %s',
					$post_id,
					$response->get_error_message()
				) );
				continue;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( $status_code >= 400 ) {
				$totals['failed_batches']++;
				$body   = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
				$detail = $body['message'] ?? $body['name'] ?? "HTTP {$status_code}";
				error_log( sprintf(
					'[prc-email-builder] Mandrill API error for post %d: %s',
					$post_id,
					$detail
				) );
				continue;
			}

			// Aggregate per-recipient status counts from the response.
			$results = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
			foreach ( (array) $results as $recipient ) {
				$status = $recipient['status'] ?? '';
				if ( isset( $totals[ $status ] ) ) {
					$totals[ $status ]++;
				}
			}

			// Checkpoint this batch immediately so a crash/timeout is recoverable.
			$completed[]                = $index;
			$completed_lookup[ $index ] = true;
			$this->save_progress(
				$post_id,
				[
					'audience_hash'     => $audience_hash,
					'total_batches'     => $total_batches,
					'completed_batches' => $completed,
					'updated_at'        => current_time( 'mysql', true ),
				]
			);

			// Heartbeat: extend the lock so a long but healthy send keeps the
			// lock fresh and never becomes eligible for a concurrent takeover.
			$this->refresh_lock( $post_id, $lock_token );
		}

		// ── Determine overall status ──────────────────────────────────────────
		$completed_count = count( $completed );
		if ( $completed_count === $total_batches ) {
			$send_status = 'sent';
		} elseif ( $completed_count > 0 ) {
			$send_status = 'partial';
		} else {
			$send_status = 'failed';
		}

		$totals['completed_batches'] = $completed_count;

		if ( $this->owns_lock( $post_id, $lock_token ) ) {
			update_post_meta( $post_id, self::STATUS_META, $send_status );
			update_post_meta( $post_id, self::SUMMARY_META, wp_json_encode( $totals ) );
		}
		// Owner-aware release: only drop the lock if we still hold it, so we
		// never clobber a lock a different worker legitimately reclaimed.
		$this->release_lock( $post_id, $lock_token );

		return $totals;
	}

	// -------------------------------------------------------------------------
	// Progress / lock helpers
	// -------------------------------------------------------------------------

	/**
	 * Read the decoded checkpoint for a post (empty array when none).
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return array
	 */
	public function get_progress( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::PROGRESS_META, true );
		if ( empty( $raw ) ) {
			return [];
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Persist the checkpoint for a post.
	 *
	 * @param int   $post_id  Newsletter post ID.
	 * @param array $progress Checkpoint payload.
	 */
	public function save_progress( int $post_id, array $progress ): void {
		update_post_meta( $post_id, self::PROGRESS_META, wp_json_encode( $progress ) );
	}

	/**
	 * Clear all send state for a post so the next send starts from scratch.
	 *
	 * @param int $post_id Newsletter post ID.
	 */
	public function reset_progress( int $post_id ): void {
		delete_post_meta( $post_id, self::PROGRESS_META );
		delete_post_meta( $post_id, self::STATUS_META );
		delete_post_meta( $post_id, self::SUMMARY_META );
		$this->clear_lock( $post_id );
	}

	/**
	 * Force-remove the send lock for a post regardless of owner.
	 *
	 * Used by the manual reset flow. The normal end-of-send path uses
	 * release_lock() instead, which only releases a lock we still own.
	 *
	 * @param int $post_id Newsletter post ID.
	 */
	public function clear_lock( int $post_id ): void {
		global $wpdb;
		$option_name = $this->lock_option_name( $post_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->options, [ 'option_name' => $option_name ] );
		wp_cache_delete( $option_name, 'options' );
		delete_post_meta( $post_id, self::LOCK_META );
	}

	/**
	 * Whether a fresh (non-expired) send lock is held for a post.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return bool
	 */
	public function is_locked( int $post_id ): bool {
		return $this->lock_expiry( $this->read_lock_value( $post_id ) ) >= time();
	}

	// -------------------------------------------------------------------------
	// Atomic lock primitives
	// -------------------------------------------------------------------------

	/**
	 * Option name holding the atomic send lock for a post.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return string
	 */
	private function lock_option_name( int $post_id ): string {
		return self::LOCK_OPTION_PREFIX . $post_id;
	}

	/**
	 * Option row that currently holds the send lock for a post.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return string Option name to read/update.
	 */
	private function resolve_lock_option_name( int $post_id ): string {
		return $this->lock_option_name( $post_id );
	}

	/**
	 * Read the raw lock value ("<token>|<expiry>") straight from the DB.
	 *
	 * Reads via $wpdb (not get_option) so the lock state is never served from a
	 * stale object cache while we are reasoning about ownership/freshness.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return string Empty string when no lock row exists.
	 */
	private function read_lock_value( int $post_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$this->resolve_lock_option_name( $post_id )
			)
		);
		return null === $value ? '' : (string) $value;
	}

	/**
	 * Parse the expiry timestamp out of a "<token>|<expiry>" lock value.
	 *
	 * @param string $lock_value Stored lock value.
	 * @return int Expiry timestamp, or 0 when unparseable/absent.
	 */
	private function lock_expiry( string $lock_value ): int {
		if ( '' === $lock_value ) {
			return 0;
		}
		$parts  = explode( '|', $lock_value );
		$expiry = end( $parts );
		return is_numeric( $expiry ) ? (int) $expiry : 0;
	}

	/**
	 * Atomically acquire the send lock for a post.
	 *
	 * Uses an INSERT (which fails on the UNIQUE option_name index) for the
	 * first acquisition and a compare-and-swap UPDATE to reclaim an expired
	 * lock. Either way only one concurrent worker can win, so the caller that
	 * receives a non-empty token is the sole owner.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return string Owner token on success, or '' when a fresh lock is held.
	 */
	private function acquire_lock( int $post_id ): string {
		global $wpdb;

		$token     = uniqid( '', true );
		$new_value = $token . '|' . ( time() + self::LOCK_TTL );

		$existing    = $this->read_lock_value( $post_id );
		$option_name = '' === $existing
			? $this->lock_option_name( $post_id )
			: $this->resolve_lock_option_name( $post_id );

		// No lock row yet -> atomic INSERT; the UNIQUE index rejects a loser.
		if ( '' === $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->insert(
				$wpdb->options,
				[
					'option_name'  => $option_name,
					'option_value' => $new_value,
					'autoload'     => 'no',
				]
			);
			if ( $inserted ) {
				wp_cache_delete( $option_name, 'options' );
				return $token;
			}
			// Lost the insert race; re-read what the winner stored.
			$existing = $this->read_lock_value( $post_id );
		}

		// A fresh lock is held by someone else — cannot acquire.
		if ( $this->lock_expiry( $existing ) >= time() ) {
			return '';
		}

		// Stale lock -> reclaim via compare-and-swap. Only the worker whose
		// UPDATE matches the exact prior value wins; the rest get 0 rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$reclaimed = $wpdb->update(
			$wpdb->options,
			[ 'option_value' => $new_value ],
			[
				'option_name'  => $option_name,
				'option_value' => $existing,
			]
		);

		if ( $reclaimed ) {
			wp_cache_delete( $option_name, 'options' );
			return $token;
		}

		return '';
	}

	/**
	 * Whether this worker still owns the lock for a post.
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $token   Owner token returned by acquire_lock().
	 * @return bool
	 */
	private function owns_lock( int $post_id, string $token ): bool {
		if ( '' === $token ) {
			return false;
		}
		return str_starts_with( $this->read_lock_value( $post_id ), $token . '|' );
	}

	/**
	 * Heartbeat the lock: extend its expiry, but only while we still own it.
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $token   Owner token returned by acquire_lock().
	 * @return bool True if we still hold the lock after the refresh.
	 */
	private function refresh_lock( int $post_id, string $token ): bool {
		global $wpdb;

		if ( '' === $token ) {
			return false;
		}

		$current = $this->read_lock_value( $post_id );
		if ( ! str_starts_with( $current, $token . '|' ) ) {
			return false; // Lost ownership; do not resurrect the lock.
		}

		$option_name = $this->resolve_lock_option_name( $post_id );
		$new_value   = $token . '|' . ( time() + self::LOCK_TTL );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->options,
			[ 'option_value' => $new_value ],
			[
				'option_name'  => $option_name,
				'option_value' => $current,
			]
		);
		wp_cache_delete( $option_name, 'options' );

		// Re-verify rather than trust the affected-row count, which is 0 when
		// the expiry happens to be identical within the same second.
		return $this->owns_lock( $post_id, $token );
	}

	/**
	 * Release the lock, but only if this worker still owns it.
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $token   Owner token returned by acquire_lock().
	 */
	private function release_lock( int $post_id, string $token ): void {
		global $wpdb;

		if ( '' === $token ) {
			return;
		}

		$current = $this->read_lock_value( $post_id );
		if ( ! str_starts_with( $current, $token . '|' ) ) {
			return; // Another worker reclaimed it; leave their lock intact.
		}

		$option_name = $this->resolve_lock_option_name( $post_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->options,
			[
				'option_name'  => $option_name,
				'option_value' => $current,
			]
		);
		wp_cache_delete( $option_name, 'options' );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function get_api_key(): string {
		if ( defined( self::API_KEY_CONSTANT ) ) {
			return (string) constant( self::API_KEY_CONSTANT );
		}
		return '';
	}
}
