<?php
/**
 * Recurring Action Scheduler job that polls the Mailchimp API for campaign
 * status updates and caches the result in post meta.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use PRC\Primitives\Concurrency\Lease;
use PRC\Primitives\Concurrency\Meta_State;
use PRC\Primitives\Concurrency\Options_Claim_Store;

/**
 * Campaign Status Sync class.
 *
 * Owns every write of the cached campaign status. The key is a Meta_State
 * that only moves forward (save, schedule, sending, sent), so a stale answer
 * from Mailchimp, or a send that persists late, cannot bring an older status
 * back. Other code calls advance(). Nothing else writes the key.
 */
class Campaign_Status_Sync {

	const SYNC_HOOK    = 'prc_email_campaign_status_sync';
	const ACTION_GROUP = 'prc-email-builder';
	const INTERVAL     = 30 * MINUTE_IN_SECONDS;

	/**
	 * Tries advance() makes when another writer wins the move.
	 */
	const ADVANCE_MAX_TRIES = 4;

	/**
	 * Lease that pairs the campaign ID with its status. link() holds it while
	 * it stores a new campaign ID and resets the status. sync() holds it while
	 * it reads the campaign ID again and writes the status. So a status that
	 * sync() read for one campaign is never written after the next one is
	 * linked. It is not the send lease: a sync must never make a send fail
	 * with send_locked.
	 */
	const LINK_LOCK_OPTION_PREFIX = 'prc_email_mailchimp_status_link_lock_';
	const LINK_LOCK_TTL           = 30;

	/**
	 * Tries link() makes to take the link lease, and the wait between tries.
	 * sync() holds the lease for a few queries only.
	 */
	const LINK_LOCK_TRIES   = 10;
	const LINK_LOCK_WAIT_US = 50000;

	/**
	 * Forward-only state machine for the status key.
	 *
	 * @var Meta_State|null
	 */
	private static ?Meta_State $machine = null;

	/**
	 * Terminal cached status when Mailchimp returns 404 for the linked campaign
	 * (deleted/archived). Excludes the post from further status-sync polling.
	 */
	const UNAVAILABLE_STATUS = 'unavailable';

	/**
	 * Action Scheduler hook: deliver Slack publish-channel notification after
	 * a Mailchimp campaign reaches the "sent" state (see sync()).
	 */
	const SLACK_NOTIFY_HOOK = 'prc_email_builder_notify_mc_campaign_sent';

	/**
	 * Post meta: Mailchimp campaign ID for which a "campaign sent" Slack message
	 * was successfully delivered (dedupes retries / duplicate scheduling).
	 */
	const SENT_SLACK_NOTIFIED_META = 'prc_email_mailchimp_sent_slack_notified_campaign';

	/**
	 * Claim name prefix for the Mailchimp-sent Slack notice. The name ends with
	 * the Mailchimp campaign ID. The first worker to insert the row posts to
	 * Slack. The row stays after the post, so a later sync never posts again.
	 */
	const SENT_SLACK_CLAIM_PREFIX = 'mailchimp_sent_slack_';

	/**
	 * Max attempts to take the Slack claim for the Mailchimp-sent notice (initial
	 * + retries). Only a claim store error is retried: no claim exists then, so a
	 * retry cannot post twice. Action Scheduler marks a thrown exception as failed
	 * without re-queueing, so the handler schedules the next attempt explicitly.
	 */
	const SLACK_NOTIFY_MAX_ATTEMPTS = 3;

	/**
	 * Base delay (seconds) between Slack delivery attempts. Multiplied by attempt number.
	 */
	const SLACK_NOTIFY_RETRY_DELAY = 5 * MINUTE_IN_SECONDS;

	/**
	 * Register the sync hook and ensure the recurring job is scheduled.
	 */
	public static function init(): void {
		self::machine()->guard();
		add_action( self::SYNC_HOOK, array( __CLASS__, 'sync' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( self::SLACK_NOTIFY_HOOK, array( __CLASS__, 'handle_mailchimp_sent_slack_notification' ), 10, 3 );
	}

	/**
	 * The status key as a Meta_State.
	 */
	public static function machine(): Meta_State {
		if ( null === self::$machine ) {
			self::$machine = new Meta_State( Campaign_Linkage::META_CAMPAIGN_STATUS, Campaign_Status::class, Campaign_Status::edges() );
		}
		return self::$machine;
	}

	/**
	 * Move the stored status forward to $to.
	 *
	 * The only writer of the status key. A missing row is created. A status
	 * that is the same as $to, or ahead of it, stays: nothing moves back. Each
	 * try reads the primary database, so a status that another request just
	 * wrote is never judged from an old cache. Never throws, because callers run
	 * after Mailchimp accepted a send and must not turn that into an error.
	 *
	 * @param int             $post_id Newsletter post ID.
	 * @param Campaign_Status $to      Status to reach.
	 * @return bool True when this call changed the stored status.
	 */
	public static function advance( int $post_id, Campaign_Status $to ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		try {
			for ( $try = 1; $try <= self::ADVANCE_MAX_TRIES; $try++ ) {
				// A writer in another request does not clear this request's cache.
				wp_cache_delete( $post_id, 'post_meta' );
				// The raw read skips the registered meta default, which would make a missing row look like one blank row.
				$rows = get_metadata_raw( 'post', $post_id, Campaign_Linkage::META_CAMPAIGN_STATUS, false );
				$rows = is_array( $rows ) ? $rows : array();

				if ( array() === $rows ) {
					if ( self::machine()->init( $post_id, $to ) ) {
						return true;
					}
					continue;
				}

				if ( count( $rows ) > 1 ) {
					self::log( sprintf( 'post %d has %d status rows; status left unchanged.', $post_id, count( $rows ) ) );
					return false;
				}

				$stored = is_scalar( $rows[0] ) ? (string) $rows[0] : '';
				if ( '' === $stored ) {
					// The editor used to save an empty status when it unlinked a campaign.
					self::clear_value( $post_id, '' );
					continue;
				}

				$from = Campaign_Status::tryFrom( $stored );
				if ( null === $from ) {
					// An old build stored the raw Mailchimp status (paused, canceled, canceling, archived). It carries no rank, so it counts as no status.
					self::log( sprintf( 'post %d stores unknown status "%s"; cleared so the status can move.', $post_id, $stored ) );
					self::clear_value( $post_id, $stored );
					continue;
				}

				if ( ! $from->may_move_to( $to ) ) {
					return false;
				}

				if ( self::machine()->transition( $post_id, $from, $to ) ) {
					return true;
				}
			}
		} catch ( \Throwable $e ) {
			self::log( sprintf( 'could not move post %d to "%s": %s', $post_id, $to->value, $e->getMessage() ) );
			return false;
		}

		self::log( sprintf( 'post %d did not reach "%s" after %d tries.', $post_id, $to->value, self::ADVANCE_MAX_TRIES ) );
		return false;
	}

	/**
	 * Link a new Mailchimp campaign ID to the post, with the status $to.
	 *
	 * Removes every status row first. Unlink resets the status, but a sync()
	 * pass that read the old campaign can still write its status between the
	 * unlink and this call, and advance() would keep that row: a new draft
	 * would show as sent. Runs under the link lease, so a sync() pass that
	 * read the old ID cannot write between the new ID and the reset. When the
	 * lease stays held after LINK_LOCK_TRIES, it links anyway: the campaign
	 * exists in Mailchimp, so the link must be stored.
	 *
	 * @param int             $post_id     Newsletter post ID.
	 * @param string          $campaign_id Mailchimp campaign ID to link.
	 * @param Campaign_Status $to          Status of the new campaign.
	 */
	public static function link( int $post_id, string $campaign_id, Campaign_Status $to ): void {
		$lease = self::link_lease();
		$token = '';
		for ( $try = 1; $try <= self::LINK_LOCK_TRIES; $try++ ) {
			$token = $lease->acquire( $post_id );
			if ( '' !== $token ) {
				break;
			}
			if ( $try < self::LINK_LOCK_TRIES ) {
				usleep( self::LINK_LOCK_WAIT_US );
			}
		}
		if ( '' === $token ) {
			self::log( sprintf( 'post %d linked campaign %s without the link lease.', $post_id, $campaign_id ) );
		}

		try {
			update_post_meta( $post_id, Campaign_Linkage::META_CAMPAIGN_ID, $campaign_id );
			self::reset( $post_id );
			self::advance( $post_id, $to );
		} finally {
			$lease->release( $post_id, $token );
		}
	}

	/**
	 * Run $write under the link lease, only while the post still links $campaign_id.
	 *
	 * The sync() pass reads the campaign ID, then waits on Mailchimp. An unlink,
	 * and a new draft, can land in that wait. So the ID is read again under the
	 * lease, from the primary database. A held lease skips the post until the
	 * next pass.
	 *
	 * @param int      $post_id     Newsletter post ID.
	 * @param string   $campaign_id Campaign ID that sync() asked Mailchimp about.
	 * @param callable $write       Status write.
	 * @return bool True when $write ran.
	 */
	private static function while_linked( int $post_id, string $campaign_id, callable $write ): bool {
		$lease = self::link_lease();
		$token = $lease->acquire( $post_id );
		if ( '' === $token ) {
			return false;
		}

		try {
			if ( self::linked_campaign_id( $post_id ) !== $campaign_id ) {
				return false;
			}
			$write();
			return true;
		} finally {
			$lease->release( $post_id, $token );
		}
	}

	/**
	 * The campaign ID the post links now, read from the primary database.
	 *
	 * @param int $post_id Newsletter post ID.
	 */
	private static function linked_campaign_id( int $post_id ): string {
		global $wpdb;

		// HyperDB: send postmeta reads to the primary, as Meta_State does.
		if ( is_array( $wpdb->srtm ?? null ) ) {
			$wpdb->srtm[ $wpdb->postmeta ] = true;
		}
		wp_cache_delete( $post_id, 'post_meta' );
		return (string) get_post_meta( $post_id, Campaign_Linkage::META_CAMPAIGN_ID, true );
	}

	/**
	 * The link lease.
	 */
	private static function link_lease(): Lease {
		return new Lease( self::LINK_LOCK_OPTION_PREFIX, self::LINK_LOCK_TTL, 'mc_link_' );
	}

	/**
	 * Remove the stored status, so the next advance() starts from nothing.
	 *
	 * For Campaign_Linkage::clear() (unlink) and link(). The Meta_State guard refuses
	 * delete_post_meta() for the key, so this deletes by meta ID, the same way
	 * wp_delete_post() does. A status that moved on after the read is deleted too:
	 * an unlink wipes the whole link.
	 *
	 * @param int $post_id Newsletter post ID.
	 */
	public static function reset( int $post_id ): void {
		global $wpdb;

		// Raw SQL: get_post_meta() does not return meta IDs.
		$meta_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$post_id,
				Campaign_Linkage::META_CAMPAIGN_STATUS
			)
		);
		foreach ( (array) $meta_ids as $meta_id ) {
			delete_metadata_by_mid( 'post', (int) $meta_id );
		}
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Remove a stored status row that holds exactly $value.
	 *
	 * Used for the blank row the old editor saved on unlink and for the raw
	 * Mailchimp statuses an old build stored. One DELETE that matches the value.
	 * A status that another request wrote after our read does not match, so it
	 * stays.
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $value   The exact stored value to remove.
	 */
	private static function clear_value( int $post_id, string $value ): void {
		global $wpdb;

		// Raw SQL: delete_post_meta() is refused by the Meta_State guard, and it cannot test the value in the same statement.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND CAST(meta_value AS BINARY) = %s",
				$post_id,
				Campaign_Linkage::META_CAMPAIGN_STATUS,
				$value
			)
		);
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Log a status problem.
	 *
	 * @param string $message What happened.
	 */
	private static function log( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[prc-email-builder] Campaign status: ' . $message );
	}

	/**
	 * Schedule the recurring job if not already scheduled.
	 */
	public static function maybe_schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::SYNC_HOOK, array(), self::ACTION_GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::INTERVAL,
			self::INTERVAL,
			self::SYNC_HOOK,
			array(),
			self::ACTION_GROUP
		);
	}

	/**
	 * Poll Mailchimp for the status of all non-terminal, non-migrated campaigns.
	 */
	public static function sync(): void {
		$query = new \WP_Query(
			array(
				'post_type'      => Post_Type::CAMPAIGN_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => 'prc_email_mailchimp_campaign_id',
						'compare' => '!=',
						'value'   => '',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => 'prc_email_mailchimp_campaign_status',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => 'prc_email_mailchimp_campaign_status',
							'compare' => 'NOT IN',
							'value'   => array( 'sent', self::UNAVAILABLE_STATUS ),
						),
					),
					array(
						'key'     => Migration::MIGRATED_META_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			) 
		);

		$post_ids = $query->posts;

		if ( empty( $post_ids ) ) {
			return;
		}

		$mailchimp = new Mailchimp();

		foreach ( $post_ids as $index => $post_id ) {
			if ( ! Post_Type::is_campaign_post( (int) $post_id ) ) {
				continue;
			}

			$campaign_id = get_post_meta( (int) $post_id, 'prc_email_mailchimp_campaign_id', true );
			if ( empty( $campaign_id ) ) {
				continue;
			}

			$response = $mailchimp->get_campaign( $campaign_id );
			if ( is_wp_error( $response ) ) {
				$error_data = $response->get_error_data();
				$http       = is_array( $error_data ) ? (int) ( $error_data['status'] ?? 0 ) : 0;
				if ( 404 === $http ) {
					self::while_linked( (int) $post_id, (string) $campaign_id, fn() => self::mark_campaign_unavailable( (int) $post_id ) );
				}
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[prc-email-builder] Campaign status sync failed for post %d / campaign %s: %s',
						$post_id,
						$campaign_id,
						$response->get_error_message()
					) 
				);
				continue;
			}

			$api_status = sanitize_text_field( (string) ( $response['status'] ?? '' ) );
			if ( '' !== $api_status ) {
				$reached = Campaign_Status::from_api( $api_status );
				if ( null === $reached ) {
					self::log(
						sprintf(
							'unknown Mailchimp status "%s" for post %d / campaign %s; stored status left unchanged.',
							$api_status,
							$post_id,
							$campaign_id
						)
					);
				} else {
					$moved = false;
					self::while_linked(
						(int) $post_id,
						(string) $campaign_id,
						function () use ( $post_id, $reached, &$moved ): void {
							$moved = self::advance( (int) $post_id, $reached );
						}
					);
					if ( $moved && Campaign_Status::Sent === $reached ) {
						self::schedule_mailchimp_sent_slack_notification( (int) $post_id, (string) $campaign_id );
					}
				}
			}

			if ( $index > 0 && 0 === $index % 20 && function_exists( 'vip_inmemory_cleanup' ) ) {
				vip_inmemory_cleanup();
			}

			sleep( 1 );
		}
	}

	/**
	 * Stop polling a Mailchimp campaign that no longer exists (HTTP 404).
	 *
	 * Sets a terminal campaign status and aligns report sync state so neither
	 * recurring job keeps calling Mailchimp for the deleted ID.
	 *
	 * @param int $post_id Post id.
	 */
	public static function mark_campaign_unavailable( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		self::advance( $post_id, Campaign_Status::Unavailable );

		if ( class_exists( Reports\Report_Store::class ) ) {
			Reports\Report_Store::mark_unavailable( $post_id );
		}
	}

	/**
	 * Queue a Slack notification when Mailchimp reports the campaign as sent.
	 * Delivery runs async so status sync is not blocked on Slack.
	 *
	 * Deduplicated per (post, campaign): both values are passed as the action
	 * arguments with `$unique = true`. Action Scheduler 4.0.0 folds the arguments
	 * into the uniqueness key (3.x keyed on hook + group only), which makes this
	 * dedup correctly per post + campaign rather than collapsing every campaign's
	 * notification in the `prc-email-builder` group into one. The handler is
	 * additionally idempotent: it claims the campaign before it posts, so two
	 * handlers that run at once post once, and it skips a campaign already
	 * recorded in SENT_SLACK_NOTIFIED_META.
	 *
	 * @param int    $post_id Post id.
	 * @param string $campaign_id Campaign id.
	 */
	private static function schedule_mailchimp_sent_slack_notification( int $post_id, string $campaign_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::handle_mailchimp_sent_slack_notification( $post_id, $campaign_id, 1 );
			return;
		}

		as_enqueue_async_action(
			self::SLACK_NOTIFY_HOOK,
			array( $post_id, $campaign_id, 1 ),
			self::ACTION_GROUP,
			true
		);
	}

	/**
	 * Handle mailchimp sent slack notification.
	 *
	 * @param int|string $post_id     Newsletter post ID.
	 * @param string     $campaign_id Mailchimp campaign ID at the time of the sent transition.
	 * @param int|string $attempt     1-based delivery attempt (defaults for in-flight 2-arg actions).
	 */
	public static function handle_mailchimp_sent_slack_notification( $post_id, $campaign_id, $attempt = 1 ): void {
		$post_id     = (int) $post_id;
		$campaign_id = (string) $campaign_id;
		$attempt     = max( 1, (int) $attempt );
		if ( $post_id <= 0 || '' === $campaign_id ) {
			return;
		}

		$stored_campaign = (string) get_post_meta( $post_id, 'prc_email_mailchimp_campaign_id', true );
		if ( $stored_campaign !== $campaign_id ) {
			return;
		}

		$status = (string) get_post_meta( $post_id, 'prc_email_mailchimp_campaign_status', true );
		if ( 'sent' !== $status ) {
			return;
		}

		$already = (string) get_post_meta( $post_id, self::SENT_SLACK_NOTIFIED_META, true );
		if ( $already === $campaign_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Post_Type::is_campaign_post( $post ) ) {
			return;
		}

		if ( 'production' !== wp_get_environment_type() ) {
			return;
		}

		if ( ! function_exists( '\PRC\Platform\Slack\notify_in_post_thread' ) || ! function_exists( '\PRC\Platform\Slack\mrkdwn_text' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[prc-email-builder] Mailchimp campaign %s sent (post %d) but PRC Slack is unavailable; skipping Slack notification.',
					$campaign_id,
					$post_id
				) 
			);
			return;
		}

		$mc_title  = '';
		$mailchimp = new Mailchimp();
		$campaign  = $mailchimp->get_campaign( $campaign_id );
		if ( ! is_wp_error( $campaign ) ) {
			$settings  = $campaign['settings'] ?? null;
			$raw_title = '';
			if ( is_array( $settings ) ) {
				$raw_title = $settings['title'] ?? '';
			} elseif ( is_object( $settings ) && isset( $settings->title ) ) {
				$raw_title = $settings->title;
			}
			$mc_title = is_string( $raw_title ) ? \PRC\Platform\Slack\mrkdwn_text( sanitize_text_field( $raw_title ) ) : '';
		}

		$title        = sanitize_text_field( get_the_title( $post_id ) );
		$subject_meta = \PRC\Platform\Slack\mrkdwn_text( sanitize_text_field( (string) get_post_meta( $post_id, 'prc_email_subject', true ) ) );
		$edit_url     = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

		$lines   = array();
		$lines[] = sprintf(
			'Mailchimp campaign `%s` has been sent.',
			$campaign_id
		);
		$lines[] = sprintf(
			'*Newsletter:* %s',
			\PRC\Platform\Slack\mrkdwn_link( $edit_url, '' !== $title ? $title : __( '(no title)', 'prc-email-builder' ) )
		);
		if ( '' !== $subject_meta ) {
			$lines[] = sprintf( '*Subject:* %s', $subject_meta );
		}
		if ( '' !== $mc_title && $mc_title !== $subject_meta ) {
			$lines[] = sprintf( '*Mailchimp title:* %s', $mc_title );
		}

		$text = implode( "\n", $lines );

		$claimed = self::claim_sent_slack_notice( $post_id, $campaign_id );
		if ( null === $claimed ) {
			self::reschedule_mailchimp_sent_slack_notification( $post_id, $campaign_id, $attempt );
			return;
		}
		if ( ! $claimed ) {
			return;
		}

		// The claim is taken, so this run posts at most once. A failure from here
		// on is logged and never retried: a second post could duplicate a message
		// that Slack did accept. Losing one notice is the accepted trade.
		$failure = null;
		try {
			$result = \PRC\Platform\Slack\notify_in_post_thread(
				$post_id,
				array(
					'text' => $text,
				)
			);
			if ( $result instanceof \WP_Error ) {
				$failure = $result->get_error_message();
			} elseif ( null === $result ) {
				$failure = 'Slack returned no result';
			}
		} catch ( \Throwable $e ) {
			$failure = $e->getMessage();
		}

		if ( null !== $failure ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[prc-email-builder] Slack notification failed for Mailchimp send (post %d, campaign %s, attempt %d); the claim is kept and the post is not retried: %s',
					$post_id,
					$campaign_id,
					$attempt,
					$failure
				)
			);
			return;
		}

		update_post_meta( $post_id, self::SENT_SLACK_NOTIFIED_META, $campaign_id );
	}

	/**
	 * Take the Slack claim for a campaign.
	 *
	 * The claim row is the single decision to post. It is never released.
	 *
	 * @param int    $post_id     Post id.
	 * @param string $campaign_id Campaign id.
	 * @return bool|null True when this call took the claim. False when another run holds it. Null when the store failed and no claim exists.
	 */
	private static function claim_sent_slack_notice( int $post_id, string $campaign_id ): ?bool {
		try {
			$claimed = ( new Options_Claim_Store() )->add(
				self::SENT_SLACK_CLAIM_PREFIX . $campaign_id,
				sprintf( '%d|%d', $post_id, time() )
			);
		} catch ( \Throwable $e ) {
			$claimed = null;
		}

		if ( null === $claimed ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[prc-email-builder] Could not take the Slack claim for Mailchimp send (post %d, campaign %s).',
					$post_id,
					$campaign_id
				)
			);
		}

		return $claimed;
	}

	/**
	 * Schedule the next claim attempt, or give up after max attempts.
	 *
	 * Completes the current Action Scheduler action without throwing so it is
	 * not stuck in `failed` with no further work. Used only when the claim store
	 * failed, so no post has happened.
	 *
	 * @param int    $post_id Post id.
	 * @param string $campaign_id Campaign id.
	 * @param int    $attempt Attempt.
	 */
	private static function reschedule_mailchimp_sent_slack_notification( int $post_id, string $campaign_id, int $attempt ): void {
		$next = $attempt + 1;
		if ( $next > self::SLACK_NOTIFY_MAX_ATTEMPTS ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[prc-email-builder] Giving up Slack notify for Mailchimp send (post %d, campaign %s) after %d attempts.',
					$post_id,
					$campaign_id,
					$attempt
				) 
			);
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action(
			time() + ( self::SLACK_NOTIFY_RETRY_DELAY * $attempt ),
			self::SLACK_NOTIFY_HOOK,
			array( $post_id, $campaign_id, $next ),
			self::ACTION_GROUP
		);
	}
}
