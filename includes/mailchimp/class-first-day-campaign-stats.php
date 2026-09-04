<?php
/**
 * First-day Mailchimp stats reply under a campaign's Slack publish thread.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use PRC\Platform\Email_Builder\Reports\Mailchimp_Report_Provider;
use PRC\Platform\Email_Builder\Reports\Report_Store;
use PRC\Platform\Slack\Bot;
use PRC\Platform\Slack\Posted_Message;
use WP_Error;

/**
 * Schedule and deliver a threaded Mailchimp summary 24 hours after send.
 */
class First_Day_Campaign_Stats {

	/**
	 * Action Scheduler hook.
	 */
	public const HOOK = 'prc_email_builder_first_day_campaign_stats';

	/**
	 * Action Scheduler group.
	 */
	public const GROUP = 'prc-email-builder';

	/**
	 * Post meta: campaign ID already reported.
	 */
	public const NOTIFIED_META = 'prc_email_mailchimp_first_day_stats_campaign';

	/**
	 * Max delivery attempts (initial + retries).
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Click-by-URL rows to include in the Slack message.
	 */
	public const LINK_LIMIT = 10;

	/**
	 * Base delay (seconds) between retries. Multiplied by attempt number.
	 */
	public const RETRY_DELAY = 5 * MINUTE_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'updated_post_meta', array( __CLASS__, 'maybe_schedule_on_sent' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'maybe_schedule_on_sent' ), 10, 4 );
		add_action( self::HOOK, array( __CLASS__, 'handle' ), 10, 3 );
	}

	/**
	 * Schedule when campaign status becomes sent.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	public static function maybe_schedule_on_sent( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( Campaign_Linkage::META_CAMPAIGN_STATUS !== $meta_key || 'sent' !== (string) $meta_value ) {
			return;
		}

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		$campaign_id = (string) get_post_meta( $post_id, Campaign_Linkage::META_CAMPAIGN_ID, true );
		self::schedule( $post_id, $campaign_id );
	}

	/**
	 * Schedule the first-day stats job for a sent campaign.
	 *
	 * @param int    $post_id     Campaign post ID.
	 * @param string $campaign_id Mailchimp campaign ID.
	 */
	public static function schedule( int $post_id, string $campaign_id ): void {
		if ( $post_id <= 0 || '' === $campaign_id ) {
			return;
		}

		if ( ! Post_Type::is_campaign_post( $post_id ) ) {
			return;
		}

		if ( Migration::is_migrated( $post_id ) ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		self::unschedule( $post_id, $campaign_id );

		as_schedule_single_action(
			time() + DAY_IN_SECONDS,
			self::HOOK,
			array( $post_id, $campaign_id, 1 ),
			self::GROUP,
			true
		);
	}

	/**
	 * Cancel pending first-day stats jobs for a campaign.
	 *
	 * @param int    $post_id     Campaign post ID.
	 * @param string $campaign_id Mailchimp campaign ID.
	 */
	public static function unschedule( int $post_id, string $campaign_id ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			as_unschedule_all_actions( self::HOOK, array( $post_id, $campaign_id, $attempt ), self::GROUP );
		}
	}

	/**
	 * Deliver the first-day Mailchimp stats reply.
	 *
	 * @param int|string $post_id     Campaign post ID.
	 * @param string     $campaign_id Mailchimp campaign ID.
	 * @param int|string $attempt     1-based attempt.
	 */
	public static function handle( $post_id, $campaign_id, $attempt = 1 ): void {
		$post_id     = (int) $post_id;
		$campaign_id = (string) $campaign_id;
		$attempt     = max( 1, (int) $attempt );

		if ( $post_id <= 0 || '' === $campaign_id ) {
			return;
		}

		if ( 'production' !== wp_get_environment_type() ) {
			return;
		}

		$stored_campaign = (string) get_post_meta( $post_id, Campaign_Linkage::META_CAMPAIGN_ID, true );
		if ( $stored_campaign !== $campaign_id ) {
			return;
		}

		$status = (string) get_post_meta( $post_id, Campaign_Linkage::META_CAMPAIGN_STATUS, true );
		if ( 'sent' !== $status ) {
			return;
		}

		if ( Migration::is_migrated( $post_id ) ) {
			return;
		}

		$already = (string) get_post_meta( $post_id, self::NOTIFIED_META, true );
		if ( $already === $campaign_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Post_Type::is_campaign_post( $post ) ) {
			return;
		}

		if ( ! function_exists( '\PRC\Platform\Slack\notify_in_post_thread' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[prc-email-builder] First-day Mailchimp stats skipped for post %d / campaign %s: PRC Slack is unavailable.',
					$post_id,
					$campaign_id
				)
			);
			return;
		}

		$announcement = Posted_Message::from_meta( get_post_meta( $post_id, Bot::ANNOUNCEMENT_META, true ) );
		if ( ! $announcement || ! $announcement->is_anchored() ) {
			return;
		}

		$report = self::resolve_report( $post_id, $campaign_id );
		if ( is_wp_error( $report ) ) {
			self::fail_and_reschedule( $post_id, $campaign_id, $attempt, $report->get_error_message() );
			return;
		}

		if ( ! is_array( $report ) ) {
			self::fail_and_reschedule( $post_id, $campaign_id, $attempt, 'Mailchimp report was empty' );
			return;
		}

		if ( class_exists( Report_Store::class ) ) {
			Report_Store::save_report( $post_id, $report );
		}

		$text   = self::format_message( $report );
		$result = null;
		try {
			$result = \PRC\Platform\Slack\notify_in_post_thread(
				$post_id,
				array(
					'text' => $text,
				)
			);
		} catch ( \Throwable $e ) {
			self::fail_and_reschedule( $post_id, $campaign_id, $attempt, $e->getMessage() );
			return;
		}

		if ( $result instanceof WP_Error ) {
			self::fail_and_reschedule( $post_id, $campaign_id, $attempt, $result->get_error_message() );
			return;
		}

		if ( null === $result ) {
			self::fail_and_reschedule( $post_id, $campaign_id, $attempt, 'Slack notification returned no result' );
			return;
		}

		update_post_meta( $post_id, self::NOTIFIED_META, $campaign_id );
	}

	/**
	 * Slack mrkdwn for first-day Mailchimp stats.
	 *
	 * @param array<string, mixed> $report Normalized Report_Schema payload.
	 */
	public static function format_message( array $report ): string {
		$summary = isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array();
		$links   = isset( $report['clicks_by_url'] ) && is_array( $report['clicks_by_url'] ) ? $report['clicks_by_url'] : array();

		$lines   = array();
		$lines[] = '*First 24 hours* (Mailchimp)';

		$sent = $summary['emails_sent'] ?? null;
		if ( self::has_metric( $sent ) ) {
			$lines[] = sprintf( '*Sent:* %s', self::format_count( $sent ) );
		}

		$opens_line = self::format_count_with_rate(
			$summary['opens_unique'] ?? null,
			$summary['open_rate'] ?? null
		);
		if ( null !== $opens_line ) {
			$lines[] = sprintf( '*Unique opens:* %s', $opens_line );
		}

		$clicks_line = self::format_count_with_rate(
			$summary['clicks_unique'] ?? null,
			$summary['click_rate'] ?? null
		);
		if ( null !== $clicks_line ) {
			$lines[] = sprintf( '*Unique clicks:* %s', $clicks_line );
		}

		$hard = $summary['bounces_hard'] ?? null;
		$soft = $summary['bounces_soft'] ?? null;
		if ( self::has_metric( $hard ) || self::has_metric( $soft ) ) {
			$lines[] = sprintf(
				'*Bounces:* %s hard, %s soft',
				self::format_count( self::has_metric( $hard ) ? $hard : 0 ),
				self::format_count( self::has_metric( $soft ) ? $soft : 0 )
			);
		}

		$unsubs = $summary['unsubscribes'] ?? null;
		if ( self::has_metric( $unsubs ) ) {
			$lines[] = sprintf( '*Unsubscribes:* %s', self::format_count( $unsubs ) );
		}

		$abuse = $summary['abuse_reports'] ?? null;
		if ( self::has_metric( $abuse ) && (float) $abuse > 0 ) {
			$lines[] = sprintf( '*Abuse reports:* %s', self::format_count( $abuse ) );
		}

		$lines[] = '';
		$lines[] = '*Top links:*';
		$listed  = 0;
		foreach ( $links as $row ) {
			if ( $listed >= self::LINK_LIMIT ) {
				break;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url    = isset( $row['url'] ) ? (string) $row['url'] : '';
			$clicks = $row['clicks'] ?? 0;
			if ( '' === $url ) {
				continue;
			}
			$lines[] = sprintf( '%d. %s — %s', $listed + 1, $url, self::format_count( $clicks ) );
			++$listed;
		}
		if ( 0 === $listed ) {
			$lines[] = 'No click data yet.';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Resolve a normalized report. Tests may supply one via filter.
	 *
	 * @param int    $post_id     Campaign post ID.
	 * @param string $campaign_id Mailchimp campaign ID.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function resolve_report( int $post_id, string $campaign_id ) {
		$filtered = apply_filters( 'prc_email_builder_first_day_campaign_stats_report', null, $post_id, $campaign_id );
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		if ( is_array( $filtered ) ) {
			return $filtered;
		}

		if ( ! class_exists( Mailchimp_Report_Provider::class ) ) {
			return new WP_Error(
				'report_provider_missing',
				'Mailchimp report provider is unavailable.'
			);
		}

		try {
			$provider = new Mailchimp_Report_Provider();
			return $provider->fetch( $campaign_id );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'report_fetch_failed', $e->getMessage() );
		}
	}

	/**
	 * Log a delivery failure and schedule the next attempt.
	 *
	 * @param int    $post_id     Campaign post ID.
	 * @param string $campaign_id Mailchimp campaign ID.
	 * @param int    $attempt     Current attempt.
	 * @param string $reason      Failure reason.
	 */
	private static function fail_and_reschedule( int $post_id, string $campaign_id, int $attempt, string $reason ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[prc-email-builder] First-day Mailchimp stats failed for post %d / campaign %s (attempt %d): %s',
				$post_id,
				$campaign_id,
				$attempt,
				$reason
			)
		);

		$next = $attempt + 1;
		if ( $next > self::MAX_ATTEMPTS ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[prc-email-builder] Giving up first-day Mailchimp stats for post %d / campaign %s after %d attempts.',
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
			time() + ( self::RETRY_DELAY * $attempt ),
			self::HOOK,
			array( $post_id, $campaign_id, $next ),
			self::GROUP
		);
	}

	/**
	 * Whether a metric should be shown (including zero).
	 *
	 * @param mixed $value Metric value.
	 */
	private static function has_metric( $value ): bool {
		return null !== $value && '' !== $value && is_numeric( $value );
	}

	/**
	 * Format a count for Slack.
	 *
	 * @param mixed $value Numeric value.
	 */
	private static function format_count( $value ): string {
		if ( function_exists( 'number_format_i18n' ) ) {
			return number_format_i18n( (float) $value );
		}

		return number_format( (float) $value );
	}

	/**
	 * Format "4,200 (40.0%)" when both count and rate exist.
	 *
	 * @param mixed $count Unique count.
	 * @param mixed $rate  0–1 rate.
	 */
	private static function format_count_with_rate( $count, $rate ): ?string {
		if ( ! self::has_metric( $count ) ) {
			return null;
		}

		$formatted = self::format_count( $count );
		if ( self::has_metric( $rate ) ) {
			$formatted .= sprintf( ' (%s)', self::format_rate( $rate ) );
		}

		return $formatted;
	}

	/**
	 * Format a 0–1 rate as a percentage.
	 *
	 * @param mixed $rate Normalized rate.
	 */
	private static function format_rate( $rate ): string {
		return number_format( (float) $rate * 100, 1 ) . '%';
	}
}
