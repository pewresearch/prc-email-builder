<?php
/**
 * Email subject domain model and publish gate.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Parse, require, display, and gate publish on `prc_email_subject`.
 */
class Email_Subject {
	public const META_KEY   = 'prc_email_subject';
	public const ERROR_CODE = 'prc_email_subject_required';

	/**
	 * Construct.
	 *
	 * @param Loader $loader Hook loader.
	 */
	public function __construct( $loader ) {
		foreach ( Post_Type::POST_TYPES as $post_type ) {
			$loader->add_filter(
				"rest_pre_insert_{$post_type}",
				$this,
				'gate_rest_publish',
				10,
				2
			);
		}
		$loader->add_filter( 'wp_insert_post_data', $this, 'gate_classic_publish', 10, 4 );
	}

	/**
	 * Parse.
	 *
	 * @param mixed $raw Stored or incoming meta.
	 * @return Ready_Subject|null
	 */
	public static function parse( $raw ): ?Ready_Subject {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$line = trim( $raw );
		if ( '' === $line ) {
			return null;
		}
		return new Ready_Subject( $line );
	}

	/**
	 * Require for send.
	 *
	 * @param int $post_id Email post ID.
	 * @return Ready_Subject|WP_Error
	 */
	public static function require_for_send( int $post_id ): Ready_Subject|WP_Error {
		$ready = self::parse( get_post_meta( $post_id, self::META_KEY, true ) );
		if ( null === $ready ) {
			return new WP_Error(
				self::ERROR_CODE,
				__( 'A subject line is required to send this email.', 'prc-email-builder' ),
				array( 'status' => 400 )
			);
		}
		return $ready;
	}

	/**
	 * Preview chrome. Falls back to the post title when meta is empty.
	 *
	 * @param int $post_id Email post ID.
	 * @return string
	 */
	public static function display( int $post_id ): string {
		$ready = self::parse( get_post_meta( $post_id, self::META_KEY, true ) );
		if ( null !== $ready ) {
			return $ready->line();
		}
		return (string) get_the_title( $post_id );
	}

	/**
	 * Block REST publish / schedule when the incoming or stored subject is empty.
	 *
	 * @param \stdClass        $prepared Prepared post.
	 * @param \WP_REST_Request $request  REST request.
	 * @return \stdClass|WP_Error
	 */
	public function gate_rest_publish( $prepared, $request ) {
		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( str_contains( $route, '/autosaves' ) ) {
			return $prepared;
		}

		if ( ! $this->is_outbound_status( $prepared->post_status ?? '' ) ) {
			return $prepared;
		}

		$meta = $request->get_param( 'meta' );
		if ( is_array( $meta ) && array_key_exists( self::META_KEY, $meta ) ) {
			$raw = $meta[ self::META_KEY ];
		} elseif ( ! empty( $prepared->ID ) ) {
			$raw = get_post_meta( (int) $prepared->ID, self::META_KEY, true );
		} else {
			$raw = '';
		}

		if ( null === self::parse( $raw ) ) {
			return new WP_Error(
				self::ERROR_CODE,
				__( 'A subject line is required to publish this email.', 'prc-email-builder' ),
				array( 'status' => 400 )
			);
		}

		return $prepared;
	}

	/**
	 * Revert non-REST publish / schedule to draft when the subject is empty.
	 *
	 * @param array $data                Sanitized post data.
	 * @param array $postarr             Unsanitized post data.
	 * @param array $unsanitized_postarr Unsanitized post array.
	 * @param bool  $update              Whether this is an update.
	 * @return array
	 */
	public function gate_classic_publish( $data, $postarr, $unsanitized_postarr, $update ) {
		unset( $unsanitized_postarr, $update );

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $data;
		}

		$post_type = $data['post_type'] ?? '';
		if ( ! in_array( $post_type, Post_Type::POST_TYPES, true ) ) {
			return $data;
		}

		if ( ! $this->is_outbound_status( $data['post_status'] ?? '' ) ) {
			return $data;
		}

		$post_id = isset( $data['ID'] ) ? (int) $data['ID'] : 0;
		$raw     = '';
		if (
			is_array( $postarr )
			&& isset( $postarr['meta_input'] )
			&& is_array( $postarr['meta_input'] )
			&& array_key_exists( self::META_KEY, $postarr['meta_input'] )
		) {
			$raw = $postarr['meta_input'][ self::META_KEY ];
		} elseif ( $post_id > 0 ) {
			$raw = get_post_meta( $post_id, self::META_KEY, true );
		}

		if ( null === self::parse( $raw ) ) {
			$data['post_status'] = 'draft';
		}

		return $data;
	}

	/**
	 * Is outbound status.
	 *
	 * @param string $status Post status.
	 * @return bool
	 */
	private function is_outbound_status( string $status ): bool {
		return in_array( $status, array( 'publish', 'future' ), true );
	}
}
