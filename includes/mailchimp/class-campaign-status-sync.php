<?php
/**
 * Recurring Action Scheduler job that polls the Mailchimp API for campaign
 * status updates and caches the result in post meta.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Campaign Status Sync class.
 */
class Campaign_Status_Sync {

	const SYNC_HOOK    = 'prc_email_campaign_status_sync';
	const ACTION_GROUP = 'prc-email-builder';
	const INTERVAL     = 30 * MINUTE_IN_SECONDS;

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
	 * Max delivery attempts for the Mailchimp-sent Slack notice (initial + retries).
	 * Action Scheduler marks a thrown exception as failed without re-queueing, so
	 * the handler schedules the next attempt explicitly.
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
		add_action( self::SYNC_HOOK, array( __CLASS__, 'sync' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( self::SLACK_NOTIFY_HOOK, array( __CLASS__, 'handle_mailchimp_sent_slack_notification' ), 10, 3 );
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
					self::mark_campaign_unavailable( (int) $post_id );
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

			$api_status = $response['status'] ?? '';
			$cached     = get_post_meta( (int) $post_id, 'prc_email_mailchimp_campaign_status', true );

			if ( $api_status && $api_status !== $cached ) {
				update_post_meta( (int) $post_id, 'prc_email_mailchimp_campaign_status', sanitize_text_field( $api_status ) );

				if ( 'sent' === $api_status && 'sent' !== (string) $cached ) {
					self::schedule_mailchimp_sent_slack_notification( (int) $post_id, (string) $campaign_id );
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

		update_post_meta( $post_id, 'prc_email_mailchimp_campaign_status', self::UNAVAILABLE_STATUS );

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
	 * additionally idempotent via the SENT_SLACK_NOTIFIED_META guard, and
	 * reschedules itself on delivery failure (AS does not auto-retry failed actions).
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

		if ( ! function_exists( '\PRC\Platform\Slack\notify_in_post_thread' ) ) {
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
			$mc_title = is_string( $raw_title ) ? sanitize_text_field( $raw_title ) : '';
		}

		$title        = sanitize_text_field( wp_strip_all_tags( get_the_title( $post_id ) ) );
		$subject_meta = sanitize_text_field( (string) get_post_meta( $post_id, 'prc_email_subject', true ) );
		$edit_url     = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

		$lines   = array();
		$lines[] = sprintf(
			'Mailchimp campaign `%s` has been sent.',
			$campaign_id
		);
		$lines[] = sprintf(
			'*Newsletter:* <%s|%s>',
			esc_url_raw( $edit_url ),
			'' !== $title ? $title : __( '(no title)', 'prc-email-builder' )
		);
		if ( '' !== $subject_meta ) {
			$lines[] = sprintf( '*Subject:* %s', $subject_meta );
		}
		if ( '' !== $mc_title && $mc_title !== $subject_meta ) {
			$lines[] = sprintf( '*Mailchimp title:* %s', $mc_title );
		}

		$text = implode( "\n", $lines );

		$result = null;
		try {
			$result = \PRC\Platform\Slack\notify_in_post_thread(
				$post_id,
				array(
					'text' => $text,
				)
			);
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[prc-email-builder] Slack notification threw for Mailchimp send (post %d, campaign %s, attempt %d): %s',
					$post_id,
					$campaign_id,
					$attempt,
					$e->getMessage()
				) 
			);
			self::reschedule_mailchimp_sent_slack_notification( $post_id, $campaign_id, $attempt );
			return;
		}

		if ( $result instanceof \WP_Error ) {
			$message = sprintf(
				'Slack notification failed for Mailchimp send (post %d, campaign %s, attempt %d): %s',
				$post_id,
				$campaign_id,
				$attempt,
				$result->get_error_message()
			);

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[prc-email-builder] ' . $message );
			self::reschedule_mailchimp_sent_slack_notification( $post_id, $campaign_id, $attempt );
			return;
		}

		if ( null === $result ) {
			$message = sprintf(
				'Slack notification returned no result for Mailchimp send (post %d, campaign %s, attempt %d); not marking as delivered.',
				$post_id,
				$campaign_id,
				$attempt
			);

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[prc-email-builder] ' . $message );
			self::reschedule_mailchimp_sent_slack_notification( $post_id, $campaign_id, $attempt );
			return;
		}

		update_post_meta( $post_id, self::SENT_SLACK_NOTIFIED_META, $campaign_id );
	}

	/**
	 * Schedule the next Slack delivery attempt, or give up after max attempts.
	 *
	 * Completes the current Action Scheduler action without throwing so it is
	 * not stuck in `failed` with no further work. The SENT_SLACK_NOTIFIED_META
	 * guard still prevents duplicate posts after a successful later attempt.
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
