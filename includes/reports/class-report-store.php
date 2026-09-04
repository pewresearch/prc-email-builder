<?php
/**
 * Persistence layer for normalized engagement reports.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder\Reports;

/**
 * Reads and writes report meta on campaign posts.
 */
class Report_Store {

	public const META_REPORT      = 'prc_email_report';
	public const META_OPEN_RATE   = 'prc_email_report_open_rate';
	public const META_CLICK_RATE  = 'prc_email_report_click_rate';
	public const META_SEND_TIME   = 'prc_email_report_send_time';
	public const META_LAST_SYNCED = 'prc_email_report_last_synced';
	public const META_SYNC_STATE  = 'prc_email_report_sync_state';

	public const STATE_OK          = 'ok';
	public const STATE_UNAVAILABLE = 'unavailable';
	public const STATE_ERROR       = 'error';
	public const STATE_PENDING     = 'pending';

	/**
	 * Decoded report or null when absent.
	 *
	 * @param int $post_id Email post ID.
	 * @return array<string, mixed>|null
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
	 * Sync state slug.
	 *
	 * @param int $post_id Email post ID.
	 * @return string
	 */
	public static function get_sync_state( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::META_SYNC_STATE, true );
	}

	/**
	 * UTC ISO-8601 last synced timestamp.
	 *
	 * @param int $post_id Email post ID.
	 * @return string
	 */
	public static function get_last_synced( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::META_LAST_SYNCED, true );
	}

	/**
	 * Persist a normalized report and denormalized sort keys.
	 *
	 * @param int                  $post_id    Email post ID.
	 * @param array<string, mixed> $normalized Report from Report_Schema.
	 */
	public static function save_report( int $post_id, array $normalized ): void {
		$summary = $normalized['summary'] ?? array();
		if ( ! is_array( $summary ) ) {
			$summary = array();
		}

		update_post_meta( $post_id, self::META_REPORT, wp_json_encode( $normalized ) );
		self::persist_rate( $post_id, self::META_OPEN_RATE, $summary['open_rate'] ?? null );
		self::persist_rate( $post_id, self::META_CLICK_RATE, $summary['click_rate'] ?? null );
		update_post_meta( $post_id, self::META_LAST_SYNCED, gmdate( 'c' ) );
		update_post_meta( $post_id, self::META_SYNC_STATE, self::STATE_OK );

		$existing_send_time = (string) get_post_meta( $post_id, self::META_SEND_TIME, true );
		if ( '' === $existing_send_time && ! empty( $normalized['send_time'] ) ) {
			update_post_meta( $post_id, self::META_SEND_TIME, sanitize_text_field( (string) $normalized['send_time'] ) );
		}
	}

	/**
	 * Mark a campaign as permanently unavailable in Mailchimp (404).
	 *
	 * @param int $post_id Email post ID.
	 */
	public static function mark_unavailable( int $post_id ): void {
		update_post_meta( $post_id, self::META_SYNC_STATE, self::STATE_UNAVAILABLE );
	}

	/**
	 * Delete all report meta for a post. Idempotent.
	 *
	 * @param int $post_id Email post ID.
	 */
	public static function clear( int $post_id ): void {
		delete_post_meta( $post_id, self::META_REPORT );
		delete_post_meta( $post_id, self::META_OPEN_RATE );
		delete_post_meta( $post_id, self::META_CLICK_RATE );
		delete_post_meta( $post_id, self::META_SEND_TIME );
		delete_post_meta( $post_id, self::META_LAST_SYNCED );
		delete_post_meta( $post_id, self::META_SYNC_STATE );
	}

	/**
	 * REST-facing envelope for the editor panel.
	 *
	 * @param int $post_id Email post ID.
	 * @return array<string, mixed>
	 */
	public static function envelope( int $post_id ): array {
		return Email_Reports::envelope( $post_id );
	}

	/**
	 * Persist a denormalized rate, or delete the key when the rate was not observed.
	 *
	 * @param int    $post_id Email post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $rate    Rate 0–1, or null when untracked.
	 */
	private static function persist_rate( int $post_id, string $key, mixed $rate ): void {
		if ( null === $rate || '' === $rate ) {
			delete_post_meta( $post_id, $key );
			return;
		}
		update_post_meta( $post_id, $key, (float) $rate );
	}
}
