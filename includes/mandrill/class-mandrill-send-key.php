<?php
/**
 * Per-post Mandrill identity: reserved metadata, not unique tags.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Stamps outbound Mandrill messages with a queryable post identity.
 *
 * Mandrill caps unique tags at 1000 per account, so identity lives in reserved
 * metadata (`email_post_id`) plus write-once post meta (`prc_email_identity_since`).
 * Operational tags `bulk` / `system-email` stay for account-level dashboards.
 */
class Mandrill_Send_Key {

	public const META_SINCE     = 'prc_email_identity_since';
	public const METADATA_FIELD = 'email_post_id';
	public const METADATA_MARK  = 'prc_email';

	/**
	 * Ensure reserved metadata on a Mandrill message and record the first stamp.
	 *
	 * Call after `apply_filters( 'prc_email_mandrill_message', ... )` so reserved
	 * keys cannot be stripped or overwritten by a filter.
	 *
	 * @param array<string, mixed> $message Mandrill message payload.
	 * @param int                  $post_id Email post ID (0 = no identity stamp).
	 * @return array<string, mixed>
	 */
	public static function stamp( array $message, int $post_id ): array {
		if ( $post_id <= 0 ) {
			return $message;
		}

		if ( ! isset( $message['metadata'] ) || ! is_array( $message['metadata'] ) ) {
			$message['metadata'] = array();
		}

		$message['metadata'][ self::METADATA_FIELD ] = (string) $post_id;
		$message['metadata'][ self::METADATA_MARK ]  = '1';

		self::ensure_since( $post_id );

		return $message;
	}

	/**
	 * Write-once ISO-8601 UTC timestamp of the first identity stamp.
	 *
	 * @param int $post_id Email post ID.
	 */
	public static function ensure_since( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$existing = get_post_meta( $post_id, self::META_SINCE, true );
		if ( is_string( $existing ) && '' !== $existing ) {
			return;
		}

		add_post_meta( $post_id, self::META_SINCE, gmdate( 'c' ), true );
	}

	/**
	 * First stamp timestamp, or empty when this post was never stamped.
	 *
	 * @param int $post_id Email post ID.
	 */
	public static function since( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		$value = get_post_meta( $post_id, self::META_SINCE, true );
		return is_string( $value ) ? $value : '';
	}
}
