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

	public const META_SINCE       = 'prc_email_identity_since';
	public const METADATA_FIELD   = 'email_post_id';
	public const METADATA_MARK    = 'prc_email';
	public const METADATA_BATCH   = 'email_batch_key';
	public const METADATA_ATTEMPT = 'email_attempt_id';

	/** Bytes of JSON that Mandrill accepts as the metadata of one message. */
	public const METADATA_MAX_BYTES = 250;

	/**
	 * Ensure reserved metadata on a Mandrill message and record the first stamp.
	 *
	 * Call after `apply_filters( 'prc_email_mandrill_message', ... )` so reserved
	 * keys cannot be stripped or overwritten by a filter.
	 *
	 * @param array<string, mixed> $message Mandrill message payload.
	 * @param int                  $post_id   Email post ID (0 = no identity stamp).
	 * @param string               $batch_key Key from batch_key(), or '' for a message that is not one batch of a bulk send.
	 * @param string               $attempt_id Short id of one attempt to POST the batch, or ''. It tells two attempts at the same batch apart.
	 * @return array<string, mixed>
	 */
	public static function stamp( array $message, int $post_id, string $batch_key = '', string $attempt_id = '' ): array {
		if ( $post_id <= 0 ) {
			return $message;
		}

		if ( ! isset( $message['metadata'] ) || ! is_array( $message['metadata'] ) ) {
			$message['metadata'] = array();
		}

		$message['metadata'][ self::METADATA_FIELD ] = (string) $post_id;
		$message['metadata'][ self::METADATA_MARK ]  = '1';
		unset( $message['metadata'][ self::METADATA_BATCH ] );
		unset( $message['metadata'][ self::METADATA_ATTEMPT ] );
		if ( '' !== $batch_key ) {
			$message['metadata'][ self::METADATA_BATCH ] = $batch_key;
			if ( '' !== $attempt_id ) {
				$message['metadata'][ self::METADATA_ATTEMPT ] = $attempt_id;
			}
		}

		$message['metadata'] = self::fit_metadata( $message['metadata'] );

		self::ensure_since( $post_id );

		return $message;
	}

	/**
	 * Keep the metadata of a message inside the Mandrill size limit.
	 *
	 * The reserved keys always stay: they carry the identity of the send. Keys
	 * that a filter added are dropped, last first, until the JSON fits.
	 *
	 * @param array<string, mixed> $metadata Metadata with the reserved keys set.
	 * @return array<string, mixed>
	 */
	private static function fit_metadata( array $metadata ): array {
		$reserved = array( self::METADATA_FIELD, self::METADATA_MARK, self::METADATA_BATCH, self::METADATA_ATTEMPT );
		$extra    = array_values( array_diff( array_keys( $metadata ), $reserved ) );

		$bytes = strlen( (string) wp_json_encode( $metadata ) );
		while ( array() !== $extra && $bytes > self::METADATA_MAX_BYTES ) {
			$dropped = array_pop( $extra );
			unset( $metadata[ $dropped ] );
			$bytes = strlen( (string) wp_json_encode( $metadata ) );
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- record a dropped metadata key for ops.
				sprintf( '[prc-email-builder] Mandrill metadata is over %d bytes; dropped the key "%s".', self::METADATA_MAX_BYTES, $dropped )
			);
		}

		return $metadata;
	}

	/**
	 * Identity of one batch of one audience, as Mandrill echoes it in webhook events.
	 *
	 * The key changes with the recipient list, so an event for a batch of an older
	 * list never matches a batch of the current one.
	 *
	 * @param string $audience_hash Hash of the recipient list the batch was cut from.
	 * @param int    $index         Zero-based batch index.
	 */
	public static function batch_key( string $audience_hash, int $index ): string {
		return substr( sha1( $audience_hash . ':' . $index ), 0, 16 );
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
