<?php
/**
 * Ask Mandrill whether a bulk batch went out.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Looks up one batch in Mandrill by metadata that every batch message carries.
 *
 * The batch key hashes the recipient list and the batch index. It does not name
 * the post, so two newsletters to one audience share keys. A search therefore
 * always includes `email_post_id`. When the checkpoint has an attempt id, the
 * search uses `email_attempt_id` instead of the key: only one POST carries that id.
 *
 * The `messages/search` endpoint addresses an indexed custom metadata field as
 * `u_<field>:<value>` (Lucene syntax). The `metadata.<field>` form is only for
 * view templates and matches nothing in a search. A field is searchable only when
 * it is indexed in the Mandrill account (Settings, Custom Metadata). The caller
 * must not trust an empty answer on its own. Mandrill_Sender checks the same kind
 * of query against a batch that Mandrill is known to have accepted.
 *
 * Values are only digits and hex, so they need no Lucene quoting. find() still
 * strips every other character from a value, so a value can never add an operator.
 */
class Mandrill_Batch_Search {

	/** Seconds to wait for Mandrill. The operator waits at the terminal. */
	public const TIMEOUT = 5;

	/** Prefix of an indexed custom metadata field in a `messages/search` query. */
	public const TERM_PREFIX = 'u_';

	/** Days Mandrill keeps messages searchable. */
	public const RETENTION_DAYS = 30;

	/**
	 * Metadata fields that identify one batch of one post.
	 *
	 * @param int    $post_id    Newsletter post ID.
	 * @param string $batch_key  Key from Mandrill_Send_Key::batch_key().
	 * @param string $attempt_id Attempt id from the checkpoint, or an empty string when none is on record.
	 * @return array<string, string> Metadata field to value.
	 */
	public static function fields( int $post_id, string $batch_key, string $attempt_id = '' ): array {
		$fields = array( Mandrill_Send_Key::METADATA_FIELD => (string) $post_id );
		if ( '' !== $attempt_id ) {
			$fields[ Mandrill_Send_Key::METADATA_ATTEMPT ] = $attempt_id;
		} else {
			$fields[ Mandrill_Send_Key::METADATA_BATCH ] = $batch_key;
		}
		return $fields;
	}

	/**
	 * Search for messages that carry all of the given metadata values.
	 *
	 * @param string                $api_key Mandrill API key.
	 * @param array<string, string> $fields  Metadata field to value, from fields(). All must match.
	 * @param int                   $since   Unix time to search from. The search starts a day before it.
	 * @return bool|WP_Error True when Mandrill lists a message, false when it lists none, WP_Error when it cannot answer.
	 */
	public static function find( string $api_key, array $fields, int $since ): bool|WP_Error {
		$terms = array();
		foreach ( $fields as $field => $value ) {
			$terms[] = self::TERM_PREFIX . $field . ':' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
		}

		$response = wp_remote_post(
			Mandrill_Sender::API_URL . 'messages/search',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'key'       => $api_key,
						'query'     => implode( ' AND ', $terms ),
						'date_from' => gmdate( 'Y-m-d', $since - 86400 ),
						'date_to'   => gmdate( 'Y-m-d', time() + 86400 ),
						'limit'     => 1,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mandrill_search_error', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$detail = is_array( $body ) ? (string) ( $body['message'] ?? $body['name'] ?? '' ) : '';
			return new WP_Error( 'mandrill_search_error', '' !== $detail ? $detail : "HTTP {$code}" );
		}
		if ( ! is_array( $body ) || ( array() !== $body && ! array_is_list( $body ) ) ) {
			return new WP_Error( 'mandrill_search_error', 'Mandrill answered with something that is not a list of messages.' );
		}

		return array() !== $body;
	}
}
