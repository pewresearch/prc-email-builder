<?php
/**
 * Authoring layer for scheduled email automations.
 *
 * Stores follow-up automation configuration on the *trigger* email (a dynamic
 * `prc_email_txn` post) in the `prc_email_automation_config` object meta, and
 * exposes the eligible follow-up templates to the editor sidebar.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers + sanitizes the automation config meta and its REST helpers.
 */
class Automation_Config {

	/**
	 * Object post meta holding the automation definition (steps + send window).
	 */
	const META_KEY = 'prc_email_automation_config';

	/**
	 * Guard rail: cap chain length so a mis-config cannot fan out forever.
	 */
	const MAX_STEPS = 10;

	/**
	 * Guard rail: cap per-step delay (calendar days).
	 */
	const MAX_DELAY_DAYS = 365;

	/**
	 * Construct.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( ?Loader $loader = null ) {
		if ( null === $loader ) {
			return;
		}
		// Must run after Post_Type::register_post_types (priority 20): register_post_meta()
		// with 'revisions_enabled' => true is rejected unless the subtype already exists
		// and supports revisions.
		$loader->add_action( 'init', $this, 'register_meta', 30 );
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * Register the object meta on transactional posts, exposed to REST so the
	 * editor sidebar can read/write it through the standard entity meta flow.
	 *
	 * @hook init
	 */
	public function register_meta(): void {
		register_post_meta(
			Post_Type::TRANSACTIONAL_POST_TYPE,
			self::META_KEY,
			array(
				'single'            => true,
				'type'              => 'object',
				'description'       => 'Scheduled follow-up automation config (send window + ordered steps) for a dynamic system email.',
				'revisions_enabled' => true,
				'default'           => array(
					'send_window' => null,
					'steps'       => array(),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
				'show_in_rest'      => array(
					'schema' => self::rest_schema(),
				),
			)
		);
	}

	/**
	 * REST schema for the automation config object.
	 *
	 * @return array<string, mixed>
	 */
	public static function rest_schema(): array {
		$window_schema = array(
			'type'                 => array( 'object', 'null' ),
			'additionalProperties' => false,
			'properties'           => array(
				'timezone' => array( 'type' => 'string' ),
				'hour'     => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 23,
				),
				'minute'   => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 59,
				),
			),
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'send_window' => $window_schema,
				'steps'       => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'follow_up_post_id' => array( 'type' => 'integer' ),
							'delay_days'        => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
							'send_window'       => $window_schema,
						),
					),
				),
			),
		);
	}

	/**
	 * Sanitize + normalize the automation config object before persistence.
	 *
	 * @param mixed $raw Candidate config from REST/editor.
	 * @return array{send_window: array|null, steps: array<int, array<string, mixed>>}
	 */
	public static function sanitize( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$send_window = Automation_Window::sanitize( $raw['send_window'] ?? null );

		$steps     = array();
		$raw_steps = isset( $raw['steps'] ) && is_array( $raw['steps'] ) ? $raw['steps'] : array();
		foreach ( $raw_steps as $raw_step ) {
			if ( ! is_array( $raw_step ) ) {
				continue;
			}

			$follow_up_post_id = isset( $raw_step['follow_up_post_id'] ) ? absint( $raw_step['follow_up_post_id'] ) : 0;
			if ( $follow_up_post_id <= 0 ) {
				continue;
			}

			$delay_days = isset( $raw_step['delay_days'] ) ? (int) $raw_step['delay_days'] : 0;
			$delay_days = max( 0, min( self::MAX_DELAY_DAYS, $delay_days ) );

			$step = array(
				'follow_up_post_id' => $follow_up_post_id,
				'delay_days'        => $delay_days,
			);

			$step_window = Automation_Window::sanitize( $raw_step['send_window'] ?? null );
			if ( null !== $step_window ) {
				$step['send_window'] = $step_window;
			}

			$steps[] = $step;

			if ( count( $steps ) >= self::MAX_STEPS ) {
				break;
			}
		}

		return array(
			'send_window' => $send_window,
			'steps'       => $steps,
		);
	}

	/**
	 * Read the (sanitized) automation config for a trigger post.
	 *
	 * @param int $post_id Trigger post ID.
	 * @return array{send_window: array|null, steps: array<int, array<string, mixed>>}
	 */
	public static function get( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		return self::sanitize( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Whether a trigger post has at least one valid follow-up step.
	 *
	 * @param int $post_id Trigger post ID.
	 */
	public static function has_steps( int $post_id ): bool {
		$config = self::get( $post_id );
		return ! empty( $config['steps'] );
	}

	/**
	 * Whether a follow-up post is eligible to receive automation sends: a
	 * published, dynamic-delivery-mode transactional email.
	 *
	 * @param int $post_id Follow-up post ID.
	 */
	public static function is_eligible_follow_up( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! Post_Type::is_transactional_post( $post ) ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		return 'dynamic' === Post_Type::transactional_delivery_mode( $post );
	}

	/**
	 * Hook callback for @hook.
	 *
	 * @hook rest_api_init
	 */
	public function register_routes(): void {
		register_rest_route(
			REST_API::NAMESPACE,
			'/automation-templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_follow_up_templates' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'exclude' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * GET /automation-templates — published dynamic system emails usable as
	 * follow-up steps (excludes the current trigger to avoid trivial self-loops).
	 *
	 * @param WP_REST_Request $request Request with optional `exclude` post ID.
	 */
	public function list_follow_up_templates( WP_REST_Request $request ): WP_REST_Response {
		$exclude = (int) $request->get_param( 'exclude' );

		$query = new \WP_Query(
			array(
				'post_type'              => Post_Type::TRANSACTIONAL_POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 200,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'post__not_in'           => $exclude > 0 ? array( $exclude ) : array(),
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					Post_Type::dynamic_delivery_mode_meta_query(),
				),
			)
		);

		$templates = array_map(
			static function ( WP_Post $post ): array {
				$title = get_the_title( $post );
				return array(
					'id'    => $post->ID,
					'title' => '' !== $title ? $title : sprintf( '#%d', $post->ID ),
					'key'   => (string) $post->post_name,
				);
			},
			$query->posts
		);

		return rest_ensure_response( $templates );
	}
}
