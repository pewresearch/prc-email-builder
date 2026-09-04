<?php
declare(strict_types=1);
/**
 * Create a transactional email draft that targets an audience option.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Inserts a `prc_email_txn` draft for a stored Mandrill audience list.
 */
class Transactional_Draft {
	/**
	 * Create a draft transactional post for an audience option key.
	 *
	 * @param string               $audience_key wp_options key holding the recipient emails.
	 * @param array<string, mixed> $args {
	 *     Optional. Draft overrides.
	 *
	 *     @type string $title    Post title. Default "Update for {label}".
	 *     @type string $subject  `prc_email_subject` meta. Default "Update: {label}".
	 *     @type int    $quiz_id  When set, audience `_meta.quiz_id` must match.
	 * }
	 * @return array{id: int, edit_url: string, title: string}|\WP_Error
	 */
	public static function create_from_audience( string $audience_key, array $args = [] ): array|\WP_Error {
		$audience_key = trim( $audience_key );
		if ( ! self::is_audience_option_key( $audience_key ) ) {
			return new \WP_Error(
				'invalid_audience_key',
				'A valid audience option key is required.',
				[ 'status' => 400 ]
			);
		}

		if ( ! post_type_exists( Post_Type::TRANSACTIONAL_POST_TYPE ) ) {
			return new \WP_Error(
				'missing_post_type',
				'The transactional email post type is not registered.',
				[ 'status' => 500 ]
			);
		}

		if ( ! is_array( get_option( $audience_key, false ) ) ) {
			return new \WP_Error(
				'audience_not_found',
				sprintf( 'Audience option "%s" does not exist.', $audience_key ),
				[ 'status' => 404 ]
			);
		}

		$meta = get_option( $audience_key . '_meta', [] );
		if ( ! is_array( $meta ) ) {
			$meta = [];
		}

		$quiz_id = isset( $args['quiz_id'] ) ? (int) $args['quiz_id'] : 0;
		if ( $quiz_id > 0 ) {
			$meta_quiz_id = isset( $meta['quiz_id'] ) ? (int) $meta['quiz_id'] : 0;
			if ( $meta_quiz_id !== $quiz_id ) {
				return new \WP_Error(
					'audience_quiz_mismatch',
					'This audience does not belong to the requested quiz.',
					[ 'status' => 400 ]
				);
			}
		}

		$label = '';
		if ( isset( $meta['label'] ) && is_string( $meta['label'] ) ) {
			$label = trim( $meta['label'] );
		}
		if ( '' === $label ) {
			$label = $audience_key;
		}

		$title = '';
		if ( isset( $args['title'] ) && is_string( $args['title'] ) ) {
			$title = sanitize_text_field( $args['title'] );
		}
		if ( '' === $title ) {
			$title = sprintf( 'Update for %s', $label );
		}

		$subject = '';
		if ( isset( $args['subject'] ) && is_string( $args['subject'] ) ) {
			$subject = sanitize_text_field( $args['subject'] );
		}
		if ( '' === $subject ) {
			$subject = sprintf( 'Update: %s', $label );
		}

		$post_id = wp_insert_post(
			[
				'post_type'   => Post_Type::TRANSACTIONAL_POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => $title,
				'meta_input'  => [
					'prc_email_delivery_mode'       => 'mandrill',
					'prc_email_audience_option_key' => $audience_key,
					'prc_email_subject'             => $subject,
				],
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$edit_url = get_edit_post_link( (int) $post_id, 'raw' );
		if ( ! is_string( $edit_url ) || '' === $edit_url ) {
			$edit_url = admin_url( sprintf( 'post.php?post=%d&action=edit', (int) $post_id ) );
		}

		return [
			'id'       => (int) $post_id,
			'edit_url' => $edit_url,
			'title'    => $title,
		];
	}

	/**
	 * Whether a wp_options name is a Mandrill audience list key.
	 *
	 * Accepts current `prc_email_audience_*` keys and legacy
	 * `prc_newsletter_audience_*` keys. Rejects `_meta` companions and any
	 * other option name so REST/CLI callers cannot target arbitrary options.
	 *
	 * @param string $audience_key Trimmed option name.
	 * @return bool
	 */
	private static function is_audience_option_key( string $audience_key ): bool {
		if ( '' === $audience_key || str_ends_with( $audience_key, '_meta' ) ) {
			return false;
		}

		return str_starts_with( $audience_key, 'prc_email_audience_' )
			|| str_starts_with( $audience_key, 'prc_newsletter_audience_' );
	}
}
