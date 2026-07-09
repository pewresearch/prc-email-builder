<?php
declare(strict_types=1);
/**
 * Normalized engagement report schema and Mailchimp mapping.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder\Reports;

/**
 * Channel-agnostic report shape shared by all Report_Provider implementations.
 */
class Report_Schema {

	public const CHANNEL_MAILCHIMP = 'mailchimp';

	/**
	 * Default cap on click-by-URL rows persisted in meta.
	 */
	public const DEFAULT_CLICK_URL_CAP = 50;

	/**
	 * Default refresh window in days after send_time.
	 */
	public const DEFAULT_REFRESH_WINDOW_DAYS = 30;

	/**
	 * Default minimum seconds between on-demand refreshes per campaign.
	 */
	public const DEFAULT_REFRESH_MIN_INTERVAL = 300;

	/**
	 * @return int Max click URLs to store.
	 */
	public static function click_url_cap(): int {
		$cap = (int) apply_filters( 'prc_email_builder_report_click_url_cap', self::DEFAULT_CLICK_URL_CAP );
		return max( 1, min( 500, $cap ) );
	}

	/**
	 * @return int Days after send_time the scheduled sync keeps re-pulling.
	 */
	public static function refresh_window_days(): int {
		$days = (int) apply_filters( 'prc_email_builder_report_refresh_window_days', self::DEFAULT_REFRESH_WINDOW_DAYS );
		return max( 1, $days );
	}

	/**
	 * @return int Minimum seconds between manual refresh requests.
	 */
	public static function refresh_min_interval(): int {
		$seconds = (int) apply_filters( 'prc_email_builder_report_refresh_min_interval', self::DEFAULT_REFRESH_MIN_INTERVAL );
		return max( 60, $seconds );
	}

	/**
	 * Map Mailchimp report + click-details payloads into the normalized shape.
	 *
	 * Member-level fields are never copied; only aggregate keys are read.
	 *
	 * @param array<string, mixed> $report        Campaign report summary.
	 * @param array<string, mixed> $click_details Click-details payload (may be empty).
	 * @return array<string, mixed>
	 */
	public static function normalize_mailchimp( array $report, array $click_details ): array {
		$opens  = self::arrayish( $report['opens'] ?? null );
		$clicks = self::arrayish( $report['clicks'] ?? null );
		$bounces = self::arrayish( $report['bounces'] ?? null );

		$emails_sent = (int) ( $report['emails_sent'] ?? 0 );

		$open_rate  = self::normalize_rate( $opens['open_rate'] ?? $report['open_rate'] ?? 0 );
		$click_rate = self::normalize_rate( $clicks['click_rate'] ?? $report['click_rate'] ?? 0 );

		$send_time = '';
		if ( ! empty( $report['send_time'] ) ) {
			$send_time = sanitize_text_field( (string) $report['send_time'] );
		}

		$urls = self::normalize_click_urls( $click_details );

		return [
			'channel'       => self::CHANNEL_MAILCHIMP,
			'summary'       => [
				'emails_sent'   => $emails_sent,
				'opens_total'   => (int) ( $opens['opens_total'] ?? $report['opens_total'] ?? 0 ),
				'opens_unique'  => (int) ( $opens['unique_opens'] ?? $report['unique_opens'] ?? 0 ),
				'open_rate'     => $open_rate,
				'clicks_total'  => (int) ( $clicks['clicks_total'] ?? $report['clicks_total'] ?? 0 ),
				'clicks_unique' => (int) ( $clicks['unique_clicks'] ?? $report['unique_clicks'] ?? 0 ),
				'click_rate'    => $click_rate,
				'bounces_hard'  => (int) ( $bounces['hard_bounces'] ?? $report['hard_bounces'] ?? 0 ),
				'bounces_soft'  => (int) ( $bounces['soft_bounces'] ?? $report['soft_bounces'] ?? 0 ),
				'unsubscribes'  => (int) ( $report['unsubscribed'] ?? 0 ),
				'abuse_reports' => (int) ( $report['abuse_reports'] ?? 0 ),
			],
			'clicks_by_url' => $urls,
			'send_time'     => $send_time,
		];
	}

	/**
	 * @param mixed $value Object or array from SDK.
	 * @return array<string, mixed>
	 */
	private static function arrayish( mixed $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			return (array) $value;
		}
		return [];
	}

	/**
	 * @param mixed $rate Provider rate (may be 0–1 or 0–100).
	 */
	private static function normalize_rate( mixed $rate ): float {
		$value = (float) $rate;
		if ( $value > 1.0 ) {
			$value = $value / 100.0;
		}
		return max( 0.0, min( 1.0, $value ) );
	}

	/**
	 * @param array<string, mixed> $click_details Click-details API payload.
	 * @return array<int, array{url: string, clicks: int}>
	 */
	private static function normalize_click_urls( array $click_details ): array {
		$raw = $click_details['urls_clicked'] ?? $click_details['urls'] ?? [];
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$rows = [];
		foreach ( $raw as $row ) {
			$item = is_object( $row ) ? (array) $row : $row;
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			$clicks = (int) ( $item['total_clicks'] ?? $item['clicks'] ?? 0 );
			$rows[] = [
				'url'    => $url,
				'clicks' => $clicks,
			];
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				if ( $a['clicks'] !== $b['clicks'] ) {
					return $b['clicks'] <=> $a['clicks'];
				}
				return strcmp( $a['url'], $b['url'] );
			}
		);

		return array_slice( $rows, 0, self::click_url_cap() );
	}
}
