<?php
declare(strict_types=1);
/**
 * Persistence layer for normalized engagement reports.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder\Reports;

/**
 * Reads and writes report meta on campaign posts.
 */
class Report_Store {

	public const META_REPORT       = 'prc_email_report';
	public const META_OPEN_RATE    = 'prc_email_report_open_rate';
	public const META_CLICK_RATE   = 'prc_email_report_click_rate';
	public const META_SEND_TIME    = 'prc_email_report_send_time';
	public const META_LAST_SYNCED  = 'prc_email_report_last_synced';
	public const META_SYNC_STATE   = 'prc_email_report_sync_state';

	public const STATE_OK          = 'ok';
	public const STATE_UNAVAILABLE = 'unavailable';
	public const STATE_ERROR       = 'error';
	public const STATE_PENDING     = 'pending';

	/**
	 * @return array<string, mixed>|null Decoded report or null when absent.
	 */
	public static function get_report( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, self::META_REPORT, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * @return string Sync state slug.
	 */
	public static function get_sync_state( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::META_SYNC_STATE, true );
	}

	/**
	 * @return string UTC ISO-8601 last synced timestamp.
	 */
	public static function get_last_synced( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::META_LAST_SYNCED, true );
	}

	/**
	 * Persist a normalized report and denormalized sort keys.
	 *
	 * @param array<string, mixed> $normalized Report from Report_Schema.
	 */
	public static function save_report( int $post_id, array $normalized ): void {
		$summary = $normalized['summary'] ?? [];
		if ( ! is_array( $summary ) ) {
			$summary = [];
		}

		update_post_meta( $post_id, self::META_REPORT, wp_json_encode( $normalized ) );
		update_post_meta( $post_id, self::META_OPEN_RATE, (float) ( $summary['open_rate'] ?? 0 ) );
		update_post_meta( $post_id, self::META_CLICK_RATE, (float) ( $summary['click_rate'] ?? 0 ) );
		update_post_meta( $post_id, self::META_LAST_SYNCED, gmdate( 'c' ) );
		update_post_meta( $post_id, self::META_SYNC_STATE, self::STATE_OK );

		$existing_send_time = (string) get_post_meta( $post_id, self::META_SEND_TIME, true );
		if ( '' === $existing_send_time && ! empty( $normalized['send_time'] ) ) {
			update_post_meta( $post_id, self::META_SEND_TIME, sanitize_text_field( (string) $normalized['send_time'] ) );
		}
	}

	/**
	 * Mark a campaign as permanently unavailable in Mailchimp (404).
	 */
	public static function mark_unavailable( int $post_id ): void {
		update_post_meta( $post_id, self::META_SYNC_STATE, self::STATE_UNAVAILABLE );
	}

	/**
	 * @return array<string, mixed> REST-facing envelope for the editor panel.
	 */
	public static function envelope( int $post_id ): array {
		$report = self::get_report( $post_id );

		return [
			'report'       => $report,
			'sync_state'   => self::get_sync_state( $post_id ),
			'last_synced'  => self::get_last_synced( $post_id ),
			'open_rate'    => (float) get_post_meta( $post_id, self::META_OPEN_RATE, true ),
			'click_rate'   => (float) get_post_meta( $post_id, self::META_CLICK_RATE, true ),
			'mailchimp_status' => (string) get_post_meta( $post_id, 'prc_email_mailchimp_campaign_status', true ),
		];
	}
}
