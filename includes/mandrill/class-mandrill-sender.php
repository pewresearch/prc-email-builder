<?php
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
 * token, and is refreshed right before every batch POST. A run that lost the
 * lock stops before it POSTs, and a long-running send never goes stale.
 *
 * @package    PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use PRC\Primitives\Concurrency\Lease;
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
	 * new worker. The lock is refreshed before every batch POST, so this only has to
	 * exceed the worst-case time for a single batch (plus a healthy margin), not
	 * the runtime of the entire send.
	 */
	const LOCK_TTL = 600;

	const STATUS_META   = 'prc_email_mandrill_send_status';
	const SUMMARY_META  = 'prc_email_mandrill_send_summary';
	const PROGRESS_META = 'prc_email_mandrill_send_progress';

	/** Append-only log of operator recovery actions (`wp prc email send retry-batch`). */
	const RECOVERY_LOG_META = 'prc_email_mandrill_recovery_log';

	/** Entries kept in the recovery log. */
	const RECOVERY_LOG_MAX = 50;

	/**
	 * Seconds that Mandrill may need to index a message. A search that finds
	 * nothing is not trusted for an attempt younger than this.
	 */
	const SEARCH_LAG = 900;

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

	/**
	 * Options-table CAS lease for per-post Mandrill sends.
	 *
	 * @var Lease
	 */
	private Lease $send_lock;

	/**
	 * Constructor.
	 *
	 * @param Loader|null $loader Plugin loader.
	 */
	public function __construct( ?Loader $loader = null ) {
		$this->send_lock = new Lease( self::LOCK_OPTION_PREFIX, self::LOCK_TTL );
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
		$allowed = Send_Guard::assert_can_send();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$ready = Email_Subject::require_for_send( $post_id );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error(
				'action_scheduler_unavailable',
				'Action Scheduler is not available. Use WP-CLI: wp prc email resend.'
			);
		}

		$action_id = as_enqueue_async_action(
			self::SEND_HOOK,
			array( $post_id ),
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

		if ( ! Send_Guard::is_production() ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- record refused sends for ops.
				sprintf(
					'[prc-email-builder] Mandrill scheduled send for post %d skipped: sends are disabled outside production.',
					$post_id
				)
			);
			return;
		}

		$html = Cached_Email_Html::resolve( $post_id );
		if ( is_wp_error( $html ) ) {
			if ( ! $this->is_locked( $post_id ) ) {
				Mandrill_Send_State::fail_if_stalled( $post_id );
			}
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- persist scheduled-send failures for ops.
				sprintf(
					'[prc-email-builder] Mandrill scheduled send failed for post %d: %s',
					$post_id,
					$html->get_error_message()
				) 
			);
			return;
		}

		$result = $this->send_to_audience( $post_id, $html );

		if ( is_wp_error( $result ) ) {
			if ( 'send_locked' === $result->get_error_code() ) {
				if ( ! $this->is_locked( $post_id ) && Mandrill_Send_State::fail_if_stalled( $post_id ) ) {
					update_post_meta(
						$post_id,
						self::SUMMARY_META,
						wp_json_encode( array( 'error' => $result->get_error_message() ) )
					);
				}
				return;
			}

			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- persist send failures for ops.
				sprintf(
					'[prc-email-builder] Mandrill send failed for post %d: %s',
					$post_id,
					$result->get_error_message()
				) 
			);
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
		$context = $this->load_send_context( $post_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$ready        = $context['ready'];
		$preview_text = $context['preview_text'];
		$api_key      = $context['api_key'];
		$settings     = $context['settings'];
		$emails       = $context['emails'];

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
		// The reads above filled this request's meta cache. A webhook in another request may have settled the send since.
		$progress     = $this->fresh_progress( $post_id );
		$prior_status = Mandrill_Send_State::current( $post_id );
		$completed    = array();
		$attempted    = array();
		$attempts     = array();

		if ( ! empty( $progress ) ) {
			if ( ( $progress['audience_hash'] ?? '' ) === $audience_hash ) {
				$completed = self::int_list( $progress['completed_batches'] ?? array() );
				$attempted = array_values( array_diff( self::int_list( $progress['attempted_batches'] ?? array() ), $completed ) );
				$attempts  = self::attempt_map( $progress['attempts'] ?? array() );
			} elseif (
				in_array( $prior_status, array( 'partial', 'failed', Mandrill_Send_Status::NeedsReview->value ), true )
				|| array() !== array_diff( self::int_list( $progress['attempted_batches'] ?? array() ), self::int_list( $progress['completed_batches'] ?? array() ) )
			) {
				// The recipient list changed under an in-progress send, or a dead
				// run left an attempt that Mandrill never confirmed. Refuse to
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

		// A webhook or retry-batch accepted every batch of this list and settled
		// the send. A queued or reclaimed run has nothing to send. begin() would
		// move sent to sending and back, which records a second CRM sent event
		// for every recipient and replaces the summary. A resend resets the
		// progress first, so it does not stop here.
		if ( Mandrill_Send_Status::Sent->value === $prior_status && count( $completed ) === $total_batches ) {
			$this->release_lock( $post_id, $lock_token );
			return new WP_Error(
				'already_sent',
				sprintf(
					'Mandrill send for post %d is already sent; nothing to send. Reset progress to send it again (wp prc email resend --post-id=%d --reset).',
					$post_id,
					$post_id
				)
			);
		}

		$completed_lookup = array_fill_keys( $completed, true );

		// ── Mark sending (lock already held) ─────────────────────────────────
		Mandrill_Send_State::begin( $post_id );

		$totals = array(
			'batches'              => $total_batches,
			'sent'                 => 0,
			'queued'               => 0,
			'rejected'             => 0,
			'invalid'              => 0,
			'failed_batches'       => 0,
			'skipped_batches'      => count( $completed ),
			'needs_review_batches' => 0,
			'sent_at'              => current_time( 'mysql', true ),
		);

		foreach ( $batches as $index => $batch ) {
			// Skip batches already delivered in a prior run.
			if ( isset( $completed_lookup[ $index ] ) ) {
				continue;
			}

			// An earlier run wrote the attempt and never saw Mandrill accept the
			// batch. It may have gone out. Resending would email 500 people twice,
			// so the batch waits for a webhook event or an operator.
			if ( in_array( $index, $attempted, true ) ) {
				++$totals['needs_review_batches'];
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- persist the skip so ops can find unconfirmed batches.
					sprintf(
						'[prc-email-builder] Mandrill batch %d of post %d was attempted and never confirmed; not resending it. It needs review.',
						$index,
						$post_id
					)
				);
				continue;
			}

			$attempt_id = self::new_attempt_id();
			$payload    = array(
				'key'     => $api_key,
				'message' => $this->compose_message( $post_id, $html, $batch, $index, $audience_hash, $attempt_id, $settings, $ready->line(), $preview_text ),
				'async'   => true,
			);

			// Fencing: the refresh runs after the message is built and before the
			// POST. A run that lost its lease while it built the message, or
			// stalled before this point, stops here and never POSTs a batch the
			// new owner is responsible for.
			if ( ! $this->refresh_lock( $post_id, $lock_token ) ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- persist lock-loss so ops can diagnose duplicate-send fences.
					sprintf(
						'[prc-email-builder] Mandrill send for post %d lost its lock mid-run; aborting before batch %d to avoid duplicate sends.',
						$post_id,
						$index
					)
				);
				break;
			}

			// Write the attempt before the POST. A process that dies after Mandrill
			// accepts the batch and before the accepted checkpoint leaves this mark.
			$attempted[]        = $index;
			$attempts[ $index ] = array(
				'id' => $attempt_id,
				'at' => gmdate( 'c', time() ),
			);
			$this->save_progress( $post_id, $this->progress_payload( $audience_hash, $total_batches, $completed, $attempted, $attempts ) );

			$posted = $this->post_batch( $post_id, $index, $payload );

			if ( 'unknown' === $posted['outcome'] ) {
				++$totals['needs_review_batches'];
				continue;
			}

			if ( 'refused' === $posted['outcome'] ) {
				// Mandrill answered with its own error document: it did not take the batch.
				++$totals['failed_batches'];
				$attempted = array_values( array_diff( $attempted, array( $index ) ) );
				unset( $attempts[ $index ] );
				$this->save_progress( $post_id, $this->progress_payload( $audience_hash, $total_batches, $completed, $attempted, $attempts ) );
				continue;
			}

			// Aggregate per-recipient status counts from the response.
			foreach ( $posted['results'] as $recipient ) {
				$status = $recipient['status'] ?? '';
				if ( isset( $totals[ $status ] ) ) {
					++$totals[ $status ];
				}
			}

			$completed[]                = $index;
			$completed_lookup[ $index ] = true;
			$attempted                  = array_values( array_diff( $attempted, array( $index ) ) );
			$this->save_progress( $post_id, $this->progress_payload( $audience_hash, $total_batches, $completed, $attempted, $attempts ) );
		}

		// ── Determine overall status ──────────────────────────────────────────
		$completed_count = count( $completed );
		if ( $completed_count === $total_batches ) {
			$send_status = Mandrill_Send_Status::Sent;
		} elseif ( array() !== $attempted ) {
			$send_status = Mandrill_Send_Status::NeedsReview;
		} elseif ( $completed_count > 0 ) {
			$send_status = Mandrill_Send_Status::Partial;
		} else {
			$send_status = Mandrill_Send_Status::Failed;
		}

		$totals['completed_batches'] = $completed_count;

		if ( $this->owns_lock( $post_id, $lock_token ) ) {
			Mandrill_Send_State::settle( $post_id, $send_status );
			update_post_meta( $post_id, self::SUMMARY_META, wp_json_encode( $totals ) );
		}
		// Owner-aware release: only drop the lock if we still hold it, so we
		// never clobber a lock a different worker legitimately reclaimed.
		$this->release_lock( $post_id, $lock_token );

		return $totals;
	}

	/**
	 * Checks shared by a bulk send and a batch retry, and the data both need.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return array{ready: Ready_Subject, preview_text: string, api_key: string, settings: array, emails: array}|WP_Error
	 */
	private function load_send_context( int $post_id ): array|WP_Error {
		$allowed = Send_Guard::assert_can_send();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! Post_Type::is_transactional_post( $post_id ) ) {
			return new WP_Error( 'wrong_post_type', 'Post is not a transactional email.' );
		}

		$delivery_mode = Post_Type::transactional_delivery_mode( $post_id );
		$audience_key  = get_post_meta( $post_id, 'prc_email_audience_option_key', true );
		$ready         = Email_Subject::require_for_send( $post_id );
		$preview_text  = get_post_meta( $post_id, 'prc_email_preview_text', true );

		if ( 'mandrill' !== $delivery_mode ) {
			return new WP_Error( 'wrong_delivery_mode', 'Transactional sub-mode is not "mandrill".' );
		}
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( empty( $audience_key ) ) {
			return new WP_Error( 'missing_audience_key', 'No audience option key set for this newsletter.' );
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'mandrill_not_configured', 'Mandrill API key is not set.' );
		}

		$settings = Mailchimp::get_settings();
		$emails   = get_option( $audience_key, array() );

		if ( empty( $emails ) || ! is_array( $emails ) ) {
			return new WP_Error( 'empty_audience', sprintf( 'No emails found in option "%s".', $audience_key ) );
		}

		return array(
			'ready'        => $ready,
			'preview_text' => (string) $preview_text,
			'api_key'      => $api_key,
			'settings'     => $settings,
			'emails'       => $emails,
		);
	}

	/**
	 * Build the Mandrill message for one batch.
	 *
	 * @param int      $post_id       Newsletter post ID.
	 * @param string   $html          Email-safe HTML.
	 * @param string[] $batch         Recipients of the batch.
	 * @param int      $index         Zero-based batch index.
	 * @param string   $audience_hash Hash of the recipient list.
	 * @param string   $attempt_id    Short id of this attempt, echoed in webhook events and searchable.
	 * @param array    $settings      Mailchimp/Mandrill settings.
	 * @param string   $subject       Subject line.
	 * @param string   $preview_text  Preview text.
	 * @return array<string, mixed>
	 */
	private function compose_message( int $post_id, string $html, array $batch, int $index, string $audience_hash, string $attempt_id, array $settings, string $subject, string $preview_text ): array {
		$from_email = (string) ( $settings['from_email'] ?? '' );
		$reply_to   = (string) ( $settings['reply_to'] ?? '' );
		if ( ! is_email( $reply_to ) ) {
			$reply_to = $from_email;
		}
		$base_tags = is_array( $settings['mandrill_tags'] ?? null ) ? $settings['mandrill_tags'] : array( 'prc-newsletter' );

		$message = array(
			'html'                => $html,
			'subject'             => $subject,
			'from_email'          => $from_email,
			'from_name'           => $settings['from_name'] ?? '',
			'headers'             => array( 'Reply-To' => $reply_to ),
			'track_opens'         => (bool) ( $settings['track_opens'] ?? true ),
			'track_clicks'        => (bool) ( $settings['track_clicks'] ?? true ),
			'tags'                => array_merge( $base_tags, array( 'bulk' ) ),
			'preserve_recipients' => false,
			'merge_language'      => 'mailchimp',
			'global_merge_vars'   => array(
				array(
					'name'    => 'PREVIEW_TEXT',
					'content' => $preview_text,
				),
			),
			'to'                  => array_map(
				fn( string $e ) => array(
					'email' => $e,
					'type'  => 'to',
				),
				$batch
			),
		);

		$subaccount = (string) ( $settings['mandrill_subaccount'] ?? '' );
		if ( '' !== $subaccount ) {
			$message['subaccount'] = $subaccount;
		}

		/**
		 * Filter the Mandrill message array before messages/send.
		 *
		 * @param array $message Mandrill message payload.
		 * @param int   $post_id Transactional post ID.
		 */
		$message = apply_filters( 'prc_email_mandrill_message', $message, $post_id );
		if ( ! is_array( $message ) ) {
			$message = array();
		}
		return Mandrill_Send_Key::stamp( $message, $post_id, Mandrill_Send_Key::batch_key( $audience_hash, $index ), $attempt_id );
	}

	/**
	 * POST one batch to messages/send and classify the answer.
	 *
	 * `accepted`: Mandrill took the batch. `refused`: Mandrill answered with its
	 * own error document, so it did not take the batch. `unknown`: no reply, or
	 * a gateway answer, so Mandrill may have taken it.
	 *
	 * @param int   $post_id Newsletter post ID.
	 * @param int   $index   Zero-based batch index.
	 * @param array $payload messages/send payload.
	 * @return array{outcome: string, results: array, detail: string}
	 */
	private function post_batch( int $post_id, int $index, array $payload ): array {
		$response = wp_remote_post(
			self::API_URL . 'messages/send',
			array(
				'timeout' => 30, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Mandrill batch send.
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- persist batch transport failures for ops.
				sprintf(
					'[prc-email-builder] Mandrill batch %d failed for post %d with no reply: %s. Mandrill may have accepted it; not resending. It needs review.',
					$index,
					$post_id,
					$response->get_error_message()
				)
			);
			return array(
				'outcome' => 'unknown',
				'results' => array(),
				'detail'  => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 400 ) {
			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$body    = is_array( $body ) ? $body : array();
			$detail  = (string) ( $body['message'] ?? $body['name'] ?? "HTTP {$status_code}" );
			$refused = 'error' === ( $body['status'] ?? '' );
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- persist Mandrill API errors for ops.
				sprintf(
					'[prc-email-builder] Mandrill API error for post %d: %s',
					$post_id,
					$detail
				)
			);
			// A gateway answer says nothing about whether Mandrill took the batch.
			return array(
				'outcome' => $refused ? 'refused' : 'unknown',
				'results' => array(),
				'detail'  => $detail,
			);
		}

		$results = json_decode( wp_remote_retrieve_body( $response ), true ) ?? array();
		return array(
			'outcome' => 'accepted',
			'results' => (array) $results,
			'detail'  => '',
		);
	}

	/**
	 * Report the send state of every batch of a post, for `wp prc email send status`.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return array<string, mixed>
	 */
	public function batch_report( int $post_id ): array {
		$progress  = $this->get_progress( $post_id );
		$completed = self::int_list( $progress['completed_batches'] ?? array() );
		$attempted = array_values( array_diff( self::int_list( $progress['attempted_batches'] ?? array() ), $completed ) );
		$attempts  = self::attempt_map( $progress['attempts'] ?? array() );
		$total     = (int) ( $progress['total_batches'] ?? 0 );

		$audience_key = get_post_meta( $post_id, 'prc_email_audience_option_key', true );
		$emails       = empty( $audience_key ) ? array() : get_option( (string) $audience_key, array() );
		$recipients   = is_array( $emails ) && array() !== $emails ? count( $emails ) : null;
		$matches      = null;
		if ( null !== $recipients && array() !== $progress ) {
			$matches = ( $progress['audience_hash'] ?? '' ) === sha1( (string) wp_json_encode( $emails ) );
		}

		$batches = array();
		for ( $index = 0; $index < $total; $index++ ) {
			$is_accepted  = in_array( $index, $completed, true );
			$is_attempted = in_array( $index, $attempted, true );
			$batches[]    = array(
				'index'        => $index,
				'state'        => $is_accepted ? 'accepted' : ( $is_attempted ? 'needs_review' : 'not_sent' ),
				'attempted'    => $is_attempted || $is_accepted,
				'accepted'     => $is_accepted,
				'recipients'   => null === $recipients ? null : max( 0, min( self::BATCH_SIZE, $recipients - $index * self::BATCH_SIZE ) ),
				'attempted_at' => (string) ( $attempts[ $index ]['at'] ?? '' ),
				'attempt_id'   => (string) ( $attempts[ $index ]['id'] ?? '' ),
			);
		}

		$log = json_decode( (string) get_post_meta( $post_id, self::RECOVERY_LOG_META, true ), true );

		return array(
			'post_id'          => $post_id,
			'status'           => (string) get_post_meta( $post_id, self::STATUS_META, true ),
			'locked'           => $this->is_locked( $post_id ),
			'total_batches'    => $total,
			'recipients'       => $recipients,
			'audience_matches' => $matches,
			'updated_at'       => (string) ( $progress['updated_at'] ?? '' ),
			'summary'          => (string) get_post_meta( $post_id, self::SUMMARY_META, true ),
			'batches'          => $batches,
			'recovery_log'     => is_array( $log ) ? $log : array(),
		);
	}

	/**
	 * Recover one batch that is attempted and not accepted.
	 *
	 * Takes the send lease. Asks Mandrill whether the batch went out. Marks it
	 * accepted when Mandrill has it. Sends it once, through the normal checkpoint,
	 * when Mandrill does not have it. Refuses when Mandrill cannot answer, unless
	 * `$force` is set. A duplicate is possible with `$force`.
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $html    Email-safe HTML. Used only when the batch is sent.
	 * @param int    $batch   Zero-based batch index.
	 * @param bool   $force   Send even when Mandrill cannot confirm that the batch is missing.
	 * @return array{outcome: string, batch: int, status: string, message: string}|WP_Error
	 */
	public function retry_batch( int $post_id, string $html, int $batch, bool $force = false ): array|WP_Error {
		$context = $this->load_send_context( $post_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$api_key       = $context['api_key'];
		$batches       = array_chunk( $context['emails'], self::BATCH_SIZE );
		$total_batches = count( $batches );
		$audience_hash = sha1( (string) wp_json_encode( $context['emails'] ) );

		$token = $this->acquire_lock( $post_id );
		if ( '' === $token ) {
			return new WP_Error(
				'send_locked',
				sprintf( 'A Mandrill send is already in progress for post %d. Wait for it to end, then retry.', $post_id )
			);
		}

		try {
			$progress = $this->fresh_progress( $post_id );
			if ( ( $progress['audience_hash'] ?? '' ) !== $audience_hash ) {
				return new WP_Error(
					'audience_changed',
					sprintf( 'The recipient list of post %d changed since the batch was attempted, so batch numbers no longer match. Reset progress to send again.', $post_id )
				);
			}

			$completed = self::int_list( $progress['completed_batches'] ?? array() );
			$attempted = array_values( array_diff( self::int_list( $progress['attempted_batches'] ?? array() ), $completed ) );
			$attempts  = self::attempt_map( $progress['attempts'] ?? array() );
			if ( ! in_array( $batch, $attempted, true ) || ! isset( $batches[ $batch ] ) ) {
				return new WP_Error(
					'batch_not_pending',
					sprintf( 'Batch %d of post %d is not attempted and waiting. Only an attempted batch that Mandrill has not accepted can be retried.', $batch, $post_id )
				);
			}

			$attempted_at = self::attempt_time( $attempts[ $batch ] ?? array(), $progress );
			$since        = null === $attempted_at ? time() - 7 * DAY_IN_SECONDS : $attempted_at;
			$batch_key    = Mandrill_Send_Key::batch_key( $audience_hash, $batch );

			$attempt_id = (string) ( $attempts[ $batch ]['id'] ?? '' );
			$found      = false;
			foreach ( array_merge( array( $attempt_id ), $attempts[ $batch ]['previous'] ?? array() ) as $searched_id ) {
				$found = Mandrill_Batch_Search::find( $api_key, Mandrill_Batch_Search::fields( $post_id, $batch_key, $searched_id ), $since );
				if ( false !== $found ) {
					break;
				}
			}

			if ( true === $found ) {
				if ( $searched_id !== $attempt_id ) {
					$attempts[ $batch ]['id'] = $searched_id;
					$progress['attempts']     = $attempts;
				}
				$this->accept_batches( $post_id, $progress, array( $batch ) );
				$this->log_recovery( $post_id, 'found_in_mandrill', $batch, $force );
				return $this->retry_result( 'accepted', $post_id, $batch, sprintf( 'Mandrill already has batch %d. It is marked accepted and was not sent again.', $batch ) );
			}

			if ( is_wp_error( $found ) ) {
				if ( ! $force ) {
					$this->log_recovery( $post_id, 'refused_search_failed', $batch, $force, $found->get_error_message() );
					return new WP_Error(
						'search_failed',
						sprintf(
							'Mandrill could not say whether batch %d went out (%s). Sending it now can email its recipients twice: a duplicate is possible. Check Mandrill, or run again with --force to send anyway.',
							$batch,
							$found->get_error_message()
						)
					);
				}
			} elseif ( ! $force ) {
				$verdict = $this->check_not_found_is_trusted( $api_key, $post_id, $audience_hash, $completed, $attempts, '' !== $attempt_id, $attempted_at );
				if ( is_wp_error( $verdict ) ) {
					$this->log_recovery( $post_id, 'refused_' . $verdict->get_error_code(), $batch, $force, $verdict->get_error_message() );
					return $verdict;
				}
			}

			return $this->resend_batch( $post_id, $html, $batch, $batches[ $batch ], $context, $progress, $token, $force );
		} finally {
			$this->release_lock( $post_id, $token );
		}
	}

	/**
	 * Decide whether an empty search result proves that Mandrill has no such batch.
	 *
	 * The attempt must be older than Mandrill needs to index a message. A batch
	 * of the same send that Mandrill is known to have accepted must be found
	 * with the same kind of query, over the whole retention period of Mandrill.
	 * Otherwise the metadata search may not be indexed, and an empty result says
	 * nothing.
	 *
	 * @param string   $api_key       Mandrill API key.
	 * @param int      $post_id       Newsletter post ID.
	 * @param string   $audience_hash Hash of the recipient list.
	 * @param int[]    $completed     Accepted batches.
	 * @param array    $attempts      Attempt marks by batch index.
	 * @param bool     $by_attempt_id Whether the batch search used an attempt id.
	 * @param int|null $attempted_at  Attempt time of the pending batch, when known.
	 * @return true|WP_Error
	 */
	private function check_not_found_is_trusted( string $api_key, int $post_id, string $audience_hash, array $completed, array $attempts, bool $by_attempt_id, ?int $attempted_at ): true|WP_Error {
		if ( null !== $attempted_at && time() - $attempted_at < self::SEARCH_LAG ) {
			return new WP_Error(
				'attempt_too_recent',
				sprintf(
					'The attempt is only %d minutes old. Mandrill may not list the batch yet, so an empty search proves nothing and a duplicate is possible. Wait %d minutes, or run again with --force.',
					(int) floor( ( time() - $attempted_at ) / MINUTE_IN_SECONDS ),
					(int) ceil( self::SEARCH_LAG / MINUTE_IN_SECONDS )
				)
			);
		}

		$references = $by_attempt_id
			? array_values( array_filter( $completed, static fn( int $index ): bool => isset( $attempts[ $index ] ) ) )
			: $completed;
		if ( array() === $references ) {
			return new WP_Error(
				'search_inconclusive',
				'No batch of this send is known to be accepted with the same kind of mark, so the search cannot be checked against a known message. An empty result proves nothing: a duplicate is possible. Check Mandrill, or run again with --force.'
			);
		}

		$reference  = min( $references );
		$ref_fields = Mandrill_Batch_Search::fields(
			$post_id,
			Mandrill_Send_Key::batch_key( $audience_hash, $reference ),
			$by_attempt_id ? (string) $attempts[ $reference ]['id'] : ''
		);

		$control = Mandrill_Batch_Search::find( $api_key, $ref_fields, time() - Mandrill_Batch_Search::RETENTION_DAYS * DAY_IN_SECONDS );
		if ( true !== $control ) {
			return new WP_Error(
				'search_inconclusive',
				'The search did not find a batch that Mandrill accepted. Its metadata search may not be set up, so an empty result proves nothing: a duplicate is possible. Check Mandrill, or run again with --force.'
			);
		}

		return true;
	}

	/**
	 * Send one batch again through the normal checkpoint.
	 *
	 * The attempt mark is rewritten before the POST, so a process that dies at
	 * the POST leaves the batch attempted, never sent twice.
	 *
	 * @param int      $post_id  Newsletter post ID.
	 * @param string   $html     Email-safe HTML.
	 * @param int      $batch    Batch index.
	 * @param string[] $recipients Recipients of the batch.
	 * @param array    $context  Result of load_send_context().
	 * @param array    $progress Checkpoint read under the lease.
	 * @param string   $token    Lease owner token.
	 * @param bool     $force    Whether the operator forced the retry.
	 * @return array{outcome: string, batch: int, status: string, message: string}|WP_Error
	 */
	private function resend_batch( int $post_id, string $html, int $batch, array $recipients, array $context, array $progress, string $token, bool $force ): array|WP_Error {
		$hash      = (string) $progress['audience_hash'];
		$total     = (int) ( $progress['total_batches'] ?? 0 );
		$completed = self::int_list( $progress['completed_batches'] ?? array() );
		$attempted = array_values( array_diff( self::int_list( $progress['attempted_batches'] ?? array() ), $completed ) );
		$attempts  = self::attempt_map( $progress['attempts'] ?? array() );

		$attempt_id = self::new_attempt_id();
		$payload    = array(
			'key'     => $context['api_key'],
			'message' => $this->compose_message( $post_id, $html, $recipients, $batch, $hash, $attempt_id, $context['settings'], $context['ready']->line(), $context['preview_text'] ),
			'async'   => true,
		);

		if ( ! $this->refresh_lock( $post_id, $token ) ) {
			return new WP_Error( 'send_locked', sprintf( 'The send lease of post %d was lost. Nothing was sent.', $post_id ) );
		}

		Mandrill_Send_State::begin( $post_id );
		$this->log_recovery( $post_id, 'resend_started', $batch, $force );

		$previous           = $attempts[ $batch ]['previous'] ?? array();
		$previous_id        = $attempts[ $batch ]['id'] ?? '';
		$attempts[ $batch ] = array(
			'id' => $attempt_id,
			'at' => gmdate( 'c', time() ),
		);
		if ( '' !== $previous_id ) {
			$previous[] = $previous_id;
		}
		if ( array() !== $previous ) {
			$attempts[ $batch ]['previous'] = $previous;
		}
		$this->save_progress( $post_id, $this->progress_payload( $hash, $total, $completed, $attempted, $attempts ) );

		$posted = $this->post_batch( $post_id, $batch, $payload );

		if ( 'accepted' === $posted['outcome'] ) {
			$completed[] = $batch;
			sort( $completed );
			$attempted = array_values( array_diff( $attempted, array( $batch ) ) );
		} elseif ( 'refused' === $posted['outcome'] ) {
			$attempted = array_values( array_diff( $attempted, array( $batch ) ) );
			unset( $attempts[ $batch ] );
		}
		$this->save_progress( $post_id, $this->progress_payload( $hash, $total, $completed, $attempted, $attempts ) );
		$this->update_summary_counts( $post_id, $total, count( $completed ), count( $attempted ) );

		if ( count( $completed ) >= $total ) {
			$end = Mandrill_Send_Status::Sent;
		} elseif ( array() !== $attempted ) {
			$end = Mandrill_Send_Status::NeedsReview;
		} elseif ( array() !== $completed ) {
			$end = Mandrill_Send_Status::Partial;
		} else {
			$end = Mandrill_Send_Status::Failed;
		}
		Mandrill_Send_State::settle( $post_id, $end );

		$this->log_recovery( $post_id, 'accepted' === $posted['outcome'] ? 'resent' : 'resend_' . $posted['outcome'], $batch, $force, $posted['detail'] );

		$messages = array(
			'accepted' => sprintf( 'Batch %d was sent and Mandrill accepted it.', $batch ),
			'refused'  => sprintf( 'Mandrill refused batch %d (%s). It was not sent. Run `wp prc email resend` to send it.', $batch, $posted['detail'] ),
			'unknown'  => sprintf( 'Mandrill gave no clear answer for batch %d (%s). It may have gone out, so it stays in needs_review. A webhook event moves it to accepted.', $batch, $posted['detail'] ),
		);

		return $this->retry_result( 'accepted' === $posted['outcome'] ? 'sent' : ( 'refused' === $posted['outcome'] ? 'refused' : 'needs_review' ), $post_id, $batch, $messages[ $posted['outcome'] ] );
	}

	/**
	 * Shape the result of a retry.
	 *
	 * @param string $outcome Outcome name.
	 * @param int    $post_id Newsletter post ID.
	 * @param int    $batch   Batch index.
	 * @param string $message Operator message.
	 * @return array{outcome: string, batch: int, status: string, message: string}
	 */
	private function retry_result( string $outcome, int $post_id, int $batch, string $message ): array {
		wp_cache_delete( $post_id, 'post_meta' );
		return array(
			'outcome' => $outcome,
			'batch'   => $batch,
			'status'  => (string) get_post_meta( $post_id, self::STATUS_META, true ),
			'message' => $message,
		);
	}

	/**
	 * Append one entry to the recovery log of a post and to the PHP error log.
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $action  Action name.
	 * @param int    $batch   Batch index.
	 * @param bool   $forced  Whether the operator passed --force.
	 * @param string $detail  Extra detail.
	 */
	private function log_recovery( int $post_id, string $action, int $batch, bool $forced, string $detail = '' ): void {
		$log = json_decode( (string) get_post_meta( $post_id, self::RECOVERY_LOG_META, true ), true );
		$log = is_array( $log ) ? $log : array();

		$entry = array(
			'at'     => gmdate( 'c', time() ),
			'action' => $action,
			'batch'  => $batch,
			'forced' => $forced,
		);
		if ( '' !== $detail ) {
			$entry['detail'] = $detail;
		}
		$log[] = $entry;
		update_post_meta( $post_id, self::RECOVERY_LOG_META, wp_slash( wp_json_encode( array_slice( $log, -self::RECOVERY_LOG_MAX ) ) ) );

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- persist operator recovery actions for ops.
			sprintf(
				'[prc-email-builder] Mandrill batch recovery for post %d: %s batch %d%s%s',
				$post_id,
				$action,
				$batch,
				$forced ? ' (forced)' : '',
				'' !== $detail ? ': ' . $detail : ''
			)
		);
	}

	/**
	 * Move batches from attempted to accepted. The caller holds the send lease.
	 *
	 * @param int   $post_id  Newsletter post ID.
	 * @param array $progress Checkpoint read under the lease.
	 * @param int[] $accepted Batches that Mandrill accepted.
	 */
	private function accept_batches( int $post_id, array $progress, array $accepted ): void {
		$hash  = (string) ( $progress['audience_hash'] ?? '' );
		$total = (int) ( $progress['total_batches'] ?? 0 );

		$completed = array_values( array_unique( array_merge( self::int_list( $progress['completed_batches'] ?? array() ), $accepted ) ) );
		sort( $completed );
		$pending  = array_values( array_diff( self::int_list( $progress['attempted_batches'] ?? array() ), $accepted ) );
		$attempts = self::attempt_map( $progress['attempts'] ?? array() );
		$this->save_progress( $post_id, $this->progress_payload( $hash, $total, $completed, $pending, $attempts ) );
		$this->update_summary_counts( $post_id, $total, count( $completed ), count( $pending ) );

		// No run owns a stored `sending` status while the lease is ours. A dead run leaves it there.
		if ( array() === $pending ) {
			$end = count( $completed ) >= $total ? Mandrill_Send_Status::Sent : Mandrill_Send_Status::Partial;
			if ( ! Mandrill_Send_State::reconcile( $post_id, $end ) ) {
				Mandrill_Send_State::settle( $post_id, $end );
			}
		} else {
			Mandrill_Send_State::settle( $post_id, Mandrill_Send_Status::NeedsReview );
		}
	}

	/**
	 * Keep well-formed attempt marks of a checkpoint.
	 *
	 * @param mixed $value Stored `attempts` map: batch index to `id`, `at` and, after a retry, the `previous` ids.
	 * @return array<int, array{id: string, at: string, previous?: string[]}>
	 */
	private static function attempt_map( mixed $value ): array {
		$map = array();
		foreach ( (array) $value as $index => $entry ) {
			if ( is_array( $entry ) && is_string( $entry['id'] ?? null ) && is_string( $entry['at'] ?? null ) ) {
				$previous = array_values( array_filter( (array) ( $entry['previous'] ?? array() ), 'is_string' ) );

				$map[ (int) $index ] = array(
					'id' => $entry['id'],
					'at' => $entry['at'],
				);
				if ( array() !== $previous ) {
					$map[ (int) $index ]['previous'] = $previous;
				}
			}
		}
		return $map;
	}

	/**
	 * Unix time of an attempt. Falls back to the checkpoint time for a mark an older build wrote.
	 *
	 * @param array $attempt  One entry of the attempt map, or an empty array.
	 * @param array $progress Checkpoint.
	 * @return int|null Null when no time is on record.
	 */
	private static function attempt_time( array $attempt, array $progress ): ?int {
		$at = isset( $attempt['at'] ) ? strtotime( (string) $attempt['at'] ) : false;
		if ( false === $at && isset( $progress['updated_at'] ) ) {
			$at = strtotime( (string) $progress['updated_at'] . ' UTC' );
		}
		return false === $at ? null : $at;
	}

	/**
	 * Short random id for one attempt to POST a batch.
	 */
	private static function new_attempt_id(): string {
		return bin2hex( random_bytes( 4 ) );
	}

	// -------------------------------------------------------------------------
	// Progress / lock helpers
	// -------------------------------------------------------------------------

	/**
	 * Move batches from attempted to accepted when Mandrill reports events for them.
	 *
	 * Takes the send lease, so it never writes the checkpoint under a running
	 * send. A post whose send is running keeps its checkpoint; a later event
	 * for the batch retries this.
	 *
	 * A batch with an attempt id on record matches only an event of one of its
	 * attempts, the current one or one a retry replaced. The key alone repeats
	 * across sends of the same audience.
	 *
	 * @param int                                                $post_id Newsletter post ID.
	 * @param array<int, array{key: string, attempt_id: string}> $marks   Batch key and attempt id from webhook event metadata.
	 * @return int Batches moved to accepted.
	 */
	public function reconcile_batches( int $post_id, array $marks ): int {
		if ( $post_id <= 0 || array() === $marks ) {
			return 0;
		}

		// Almost every webhook event is for a post with nothing to reconcile.
		// Look before taking the lease.
		if ( array() === self::int_list( $this->get_progress( $post_id )['attempted_batches'] ?? array() ) ) {
			return 0;
		}

		$token = $this->acquire_lock( $post_id );
		if ( '' === $token ) {
			return 0;
		}

		try {
			$progress = $this->fresh_progress( $post_id );
			$hash     = (string) ( $progress['audience_hash'] ?? '' );
			$pending  = self::int_list( $progress['attempted_batches'] ?? array() );
			if ( '' === $hash || array() === $pending ) {
				return 0;
			}

			$attempts = self::attempt_map( $progress['attempts'] ?? array() );
			$accepted = array_values(
				array_filter(
					$pending,
					static function ( int $index ) use ( $hash, $attempts, $marks ): bool {
						$attempt_id = $attempts[ $index ]['id'] ?? '';
						$known_ids  = array_merge( array( $attempt_id ), $attempts[ $index ]['previous'] ?? array() );
						$batch_key  = Mandrill_Send_Key::batch_key( $hash, $index );
						foreach ( $marks as $mark ) {
							if ( '' !== $attempt_id ? in_array( $mark['attempt_id'], $known_ids, true ) : $mark['key'] === $batch_key ) {
								return true;
							}
						}
						return false;
					}
				)
			);
			if ( array() === $accepted ) {
				return 0;
			}

			$this->accept_batches( $post_id, $progress, $accepted );

			return count( $accepted );
		} finally {
			$this->release_lock( $post_id, $token );
		}
	}

	/**
	 * Keep the stored send summary in step with a reconciled checkpoint.
	 *
	 * A run that died before it finished never wrote a summary. The reconcile
	 * writes a small one then, so the sidebar can show the review count.
	 *
	 * @param int $post_id      Newsletter post ID.
	 * @param int $total        Batch count.
	 * @param int $completed    Accepted batch count.
	 * @param int $needs_review Batches still waiting for review.
	 */
	private function update_summary_counts( int $post_id, int $total, int $completed, int $needs_review ): void {
		$summary = json_decode( (string) get_post_meta( $post_id, self::SUMMARY_META, true ), true );
		if ( ! is_array( $summary ) ) {
			$summary = array( 'batches' => $total );
		}
		$summary['completed_batches']    = $completed;
		$summary['needs_review_batches'] = $needs_review;
		update_post_meta( $post_id, self::SUMMARY_META, wp_json_encode( $summary ) );
	}

	/**
	 * Checkpoint payload. `attempted_batches` lists batches that were POSTed and not confirmed.
	 *
	 * @param string $audience_hash Hash of the recipient list.
	 * @param int    $total_batches Batch count.
	 * @param int[]  $completed     Accepted batches.
	 * @param int[]  $attempted     Attempted batches with no accepted record.
	 * @param array  $attempts      Attempt marks (id and time) by batch index. Marks of attempted and accepted batches are kept.
	 * @return array<string, mixed>
	 */
	private function progress_payload( string $audience_hash, int $total_batches, array $completed, array $attempted, array $attempts = array() ): array {
		$payload  = array(
			'audience_hash'     => $audience_hash,
			'total_batches'     => $total_batches,
			'completed_batches' => $completed,
			'attempted_batches' => $attempted,
			'updated_at'        => current_time( 'mysql', true ),
		);
		$attempts = array_intersect_key( $attempts, array_flip( array_merge( $attempted, $completed ) ) );
		if ( array() !== $attempts ) {
			$payload['attempts'] = $attempts;
		}
		return $payload;
	}

	/**
	 * Keep integer entries of a stored list.
	 *
	 * @param mixed $value Stored list.
	 * @return int[]
	 */
	private static function int_list( mixed $value ): array {
		return array_values( array_filter( (array) $value, 'is_int' ) );
	}

	/**
	 * Read the checkpoint as the primary database holds it now.
	 *
	 * Call it under the send lease. Another request may have written the
	 * checkpoint since this request filled its meta cache, and a replica may
	 * not have that write yet. A stale checkpoint can send a batch again or
	 * drop a batch that a webhook accepted.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return array
	 */
	private function fresh_progress( int $post_id ): array {
		Mandrill_Send_State::pin_primary();
		wp_cache_delete( $post_id, 'post_meta' );
		return $this->get_progress( $post_id );
	}

	/**
	 * Read the decoded checkpoint for a post (empty array when none).
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return array
	 */
	public function get_progress( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::PROGRESS_META, true );
		if ( empty( $raw ) ) {
			return array();
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
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
		$this->send_lock->clear( $post_id );
		delete_post_meta( $post_id, self::LOCK_META );
	}

	/**
	 * Whether a fresh (non-expired) send lock is held for a post.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return bool
	 */
	public function is_locked( int $post_id ): bool {
		return $this->send_lock->is_held( $post_id );
	}

	// -------------------------------------------------------------------------
	// Atomic lock primitives (thin delegates over Lease)
	// -------------------------------------------------------------------------

	/**
	 * Atomically acquire the send lock for a post.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return string Owner token on success, or '' when a fresh lock is held.
	 */
	private function acquire_lock( int $post_id ): string {
		return $this->send_lock->acquire( $post_id );
	}

	/**
	 * Whether this worker still owns the lock for a post.
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $token   Owner token returned by acquire_lock().
	 * @return bool
	 */
	private function owns_lock( int $post_id, string $token ): bool {
		return $this->send_lock->owns( $post_id, $token );
	}

	/**
	 * Refresh the lock: extend its expiry, but only while we still own it.
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $token   Owner token returned by acquire_lock().
	 * @return bool True if we still hold the lock after the refresh.
	 */
	private function refresh_lock( int $post_id, string $token ): bool {
		return $this->send_lock->refresh( $post_id, $token );
	}

	/**
	 * Release the lock, but only if this worker still owns it.
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $token   Owner token returned by acquire_lock().
	 */
	private function release_lock( int $post_id, string $token ): void {
		$this->send_lock->release( $post_id, $token );
	}

	/**
	 * Mandrill API key from the platform constant, or empty when unset.
	 *
	 * @return string
	 */
	private function get_api_key(): string {
		if ( defined( self::API_KEY_CONSTANT ) ) {
			return (string) constant( self::API_KEY_CONSTANT );
		}
		return '';
	}
}
