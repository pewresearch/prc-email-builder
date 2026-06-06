<?php
declare(strict_types=1);
/**
 * Recurring Action Scheduler job that polls the Mailchimp API for campaign
 * status updates and caches the result in post meta.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

class Campaign_Status_Sync {

	const SYNC_HOOK    = 'prc_email_campaign_status_sync';
	const ACTION_GROUP = 'prc-email-builder';
	const INTERVAL     = 30 * MINUTE_IN_SECONDS;

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
	 * Register the sync hook and ensure the recurring job is scheduled.
	 */
	public static function init(): void {
		add_action( self::SYNC_HOOK, [ __CLASS__, 'sync' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
		add_action( self::SLACK_NOTIFY_HOOK, [ __CLASS__, 'handle_mailchimp_sent_slack_notification' ], 10, 2 );
	}

	/**
	 * Schedule the recurring job if not already scheduled.
	 */
	public static function maybe_schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::SYNC_HOOK, [], self::ACTION_GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::INTERVAL,
			self::INTERVAL,
			self::SYNC_HOOK,
			[],
			self::ACTION_GROUP
		);
	}

	/**
	 * Poll Mailchimp for the status of all non-terminal, non-migrated campaigns.
	 */
	public static function sync(): void {
		$query = new \WP_Query( [
			'post_type'      => Post_Type::CAMPAIGN_POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'     => 'prc_email_mailchimp_campaign_id',
					'compare' => '!=',
					'value'   => '',
				],
				[
					'relation' => 'OR',
					[
						'key'     => 'prc_email_mailchimp_campaign_status',
						'compare' => 'NOT EXISTS',
					],
					[
						'key'     => 'prc_email_mailchimp_campaign_status',
						'compare' => '!=',
						'value'   => 'sent',
					],
				],
				[
					'key'     => Migration::MIGRATED_META_KEY,
					'compare' => 'NOT EXISTS',
				],
			],
		] );

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
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf(
					'[prc-email-builder] Campaign status sync failed for post %d / campaign %s: %s',
					$post_id,
					$campaign_id,
					$response->get_error_message()
				) );
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
	 * Queue a Slack notification when Mailchimp reports the campaign as sent.
	 * Uses Action Scheduler so delivery errors can retry without blocking status sync.
	 */
	private static function schedule_mailchimp_sent_slack_notification( int $post_id, string $campaign_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::handle_mailchimp_sent_slack_notification( $post_id, $campaign_id );
			return;
		}

		as_enqueue_async_action(
			self::SLACK_NOTIFY_HOOK,
			[ $post_id, $campaign_id ],
			self::ACTION_GROUP,
			true
		);
	}

	/**
	 * @param int|string $post_id     Newsletter post ID.
	 * @param string     $campaign_id Mailchimp campaign ID at the time of the sent transition.
	 * @throws \Throwable When Slack delivery fails so Action Scheduler can retry the action.
	 */
	public static function handle_mailchimp_sent_slack_notification( $post_id, $campaign_id ): void {
		$post_id     = (int) $post_id;
		$campaign_id = (string) $campaign_id;
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

		if ( ! class_exists( \PRC\Platform\Slack\Bot::class ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[prc-email-builder] Mailchimp campaign %s sent (post %d) but PRC Slack is unavailable; skipping Slack notification.',
				$campaign_id,
				$post_id
			) );
			return;
		}

		$mc_title = '';
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

		$lines   = [];
		$lines[] = sprintf(
			'Mailchimp campaign `%s` has been sent.',
			$campaign_id
		);
		$lines[] = sprintf(
			'*Newsletter:* <%s|%s>',
			esc_url_raw( $edit_url ),
			$title !== '' ? $title : __( '(no title)', 'prc-email-builder' )
		);
		if ( $subject_meta !== '' ) {
			$lines[] = sprintf( '*Subject:* %s', $subject_meta );
		}
		if ( $mc_title !== '' && $mc_title !== $subject_meta ) {
			$lines[] = sprintf( '*Mailchimp title:* %s', $mc_title );
		}

		$text = implode( "\n", $lines );

		$result = null;
		try {
			$slackbot = new \PRC\Platform\Slack\Bot();
			$result   = $slackbot->send_notification(
				[
					'channel' => '#publish',
					'text'    => $text,
				]
			);
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[prc-email-builder] Slack notification threw for Mailchimp send (post %d, campaign %s): %s',
				$post_id,
				$campaign_id,
				$e->getMessage()
			) );
			throw $e;
		}

		if ( $result instanceof \WP_Error ) {
			$message = sprintf(
				'Slack notification failed for Mailchimp send (post %d, campaign %s): %s',
				$post_id,
				$campaign_id,
				$result->get_error_message()
			);

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[prc-email-builder] ' . $message );
			throw new \RuntimeException( $message );
		}

		if ( null === $result ) {
			$message = sprintf(
				'Slack notification returned no result for Mailchimp send (post %d, campaign %s); not marking as delivered.',
				$post_id,
				$campaign_id
			);

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[prc-email-builder] ' . $message );
			throw new \RuntimeException( $message );
		}

		update_post_meta( $post_id, self::SENT_SLACK_NOTIFIED_META, $campaign_id );
	}
}
