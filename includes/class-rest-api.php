<?php
declare(strict_types=1);
/**
 * REST API endpoints for the block editor sidebar.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use wpdb;

/**
 * Provides read-only endpoints consumed by the sidebar panel:
 *  GET /prc-email-builder/v1/audiences                       — Mailchimp audience list
 *  GET /prc-email-builder/v1/connection                      — connection status + sender info
 *  GET /prc-email-builder/v1/audiences/{id}/segments         — Mailchimp saved segments for an audience
 *  GET /prc-email-builder/v1/audiences-system                — System-email audiences from wp_options
 *  POST /prc-email-builder/v1/send                           — Mandrill bulk send (explicit, edit_post scoped)
 */
class REST_API {
	const NAMESPACE = 'prc-email-builder/v1';

	public function __construct( Loader $loader ) {
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/audiences',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_audiences' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/connection',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_connection' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/audiences-system',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_system_audiences' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/send-system-email',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send_system_email' ],
				// Capability-gated: this endpoint can email arbitrary addresses,
				// so it is for editors / programmatic callers. Anonymous public
				// sends go through the nonce + captcha gated form action at
				// /prc-api/v3/form/send-system-email instead. Requires edit rights
				// on the *specific* transactional post (not just the generic
				// edit_posts cap) — see send_system_email_permission_check().
				'permission_callback' => [ $this, 'send_system_email_permission_check' ],
				'args'                => [
					'post_id'  => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'to_email' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => fn( $v ) => is_email( $v ),
					],
					'context'  => [
						'required' => false,
						'type'     => 'object',
						'default'  => [],
					],
					'dry_run'  => [
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/send',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send_newsletter' ],
				'permission_callback' => [ $this, 'send_newsletter_permission_check' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'reset'   => [
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/audiences/(?P<audience_id>[a-f0-9]+)/segments',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_segments' ],
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => [
					'audience_id' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

	}

	public function get_audiences( WP_REST_Request $request ): WP_REST_Response {
		$mailchimp = new Mailchimp();
		$audiences = $mailchimp->get_audiences();

		if ( is_wp_error( $audiences ) ) {
			return new WP_REST_Response(
				[ 'error' => $audiences->get_error_message() ],
				503
			);
		}

		$formatted = array_map(
			fn( $id, $name ) => [ 'id' => $id, 'name' => $name ],
			array_keys( $audiences ),
			array_values( $audiences )
		);

		return rest_ensure_response( $formatted );
	}

	public function get_connection( WP_REST_Request $request ): WP_REST_Response {
		$mailchimp = new Mailchimp();
		$settings  = Mailchimp::get_settings();

		return rest_ensure_response( [
			'connected'  => $mailchimp->is_connected(),
			'from_name'  => $settings['from_name'],
			'from_email' => $settings['from_email'],
		] );
	}

	public function list_system_audiences( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		// Fetch all _meta option names whose base key starts with prc_email_audience_
		// or legacy prc_newsletter_audience_. We query only the meta siblings to avoid
		// loading the (potentially large) email arrays.
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name ASC",
				$wpdb->esc_like( 'prc_email_audience_' ) . '%' . $wpdb->esc_like( '_meta' ),
				$wpdb->esc_like( 'prc_newsletter_audience_' ) . '%' . $wpdb->esc_like( '_meta' )
			)
		);

		$audiences = [];
		foreach ( $results as $meta_option_name ) {
			$meta = get_option( $meta_option_name, [] );
			// Mirror CLI_Audience::is_audience_meta_array(): skip empty / list-shaped
			// options (e.g. a raw email list whose key happens to end in "_meta") so
			// the picker never surfaces non-audience options the CLI would ignore.
			if (
				empty( $meta ) || ! is_array( $meta )
				|| array_is_list( $meta )
				|| ! ( isset( $meta['label'] ) || isset( $meta['built_at'] ) || isset( $meta['source'] ) )
			) {
				continue;
			}
			// The base audience key is the meta key without the _meta suffix.
			$audience_key = substr( $meta_option_name, 0, -5 );
			$audiences[]  = [
				'key'        => $audience_key,
				'label'      => $meta['label'] ?? $audience_key,
				'count'      => (int) ( $meta['count'] ?? 0 ),
				'dataset_id' => $meta['dataset_id'] ?? null,
				'built_at'   => $meta['built_at'] ?? null,
			];
		}

		return rest_ensure_response( $audiences );
	}

	/**
	 * Permission check for POST /send — caller must be able to edit the target newsletter.
	 *
	 * @param WP_REST_Request $request Request with post_id.
	 */
	public function send_newsletter_permission_check( WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );

		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * POST /send — deliver a published mandrill newsletter to its bulk audience.
	 *
	 * @param WP_REST_Request $request Request with post_id and optional reset flag.
	 */
	public function send_newsletter( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );
		$reset   = (bool) $request->get_param( 'reset' );

		$post = get_post( $post_id );
		if ( ! $post || ! Post_Type::is_transactional_post( $post ) ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Invalid transactional email post.', 'prc-email-builder' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return new \WP_Error(
				'not_published',
				__( 'Newsletter must be published before sending.', 'prc-email-builder' ),
				[ 'status' => 400 ]
			);
		}

		if ( Migration::is_migrated( $post_id ) ) {
			return new \WP_Error(
				'migrated',
				__( 'This newsletter has been migrated and cannot be sent from the builder.', 'prc-email-builder' ),
				[ 'status' => 400 ]
			);
		}

		if ( 'mandrill' !== Post_Type::transactional_delivery_mode( $post ) ) {
			return new \WP_Error(
				'wrong_delivery_mode',
				__( 'Only bulk-list transactional emails can be sent from this endpoint.', 'prc-email-builder' ),
				[ 'status' => 400 ]
			);
		}

		$current_status = (string) get_post_meta( $post_id, Mandrill_Sender::STATUS_META, true );
		if ( 'sent' === $current_status && ! $reset ) {
			return new \WP_Error(
				'already_sent',
				__( 'This newsletter was already sent. Pass reset=true to send again.', 'prc-email-builder' ),
				[ 'status' => 409 ]
			);
		}

		if ( $reset && 'sent' === $current_status ) {
			$sender = new Mandrill_Sender( null );
			$sender->reset_progress( $post_id );
		}

		$html = Cached_Email_Html::resolve( $post_id );
		if ( is_wp_error( $html ) ) {
			$html->add_data( [ 'status' => 409 ] );
			return $html;
		}

		$sender = new Mandrill_Sender( null );
		if ( $sender->is_locked( $post_id ) ) {
			return new \WP_Error(
				'send_locked',
				__( 'A send is already in progress for this newsletter.', 'prc-email-builder' ),
				[ 'status' => 409 ]
			);
		}

		$scheduled = $sender->schedule_send( $post_id );
		if ( is_wp_error( $scheduled ) ) {
			$code = $scheduled->get_error_code();
			if ( in_array( $code, [ 'send_locked', 'send_already_scheduled' ], true ) ) {
				$scheduled->add_data( [ 'status' => 409 ] );
			} else {
				$scheduled->add_data( [ 'status' => 500 ] );
			}
			return $scheduled;
		}

		return rest_ensure_response(
			[
				'status'  => 'sending',
				'summary' => [],
			]
		);
	}

	/**
	 * Permission check for POST /send-system-email — caller must be able to edit
	 * the specific transactional post being sent, not merely hold the generic
	 * edit_posts capability (which would otherwise let an editor send branded
	 * mail from a transactional template they cannot edit).
	 *
	 * @param WP_REST_Request $request Request with post_id.
	 */
	public function send_system_email_permission_check( WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );

		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	public function send_system_email( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$post_id  = (int) $request->get_param( 'post_id' );
		$to_email = (string) $request->get_param( 'to_email' );
		$context  = (array) $request->get_param( 'context' );

		// Dry run: render and return the email without sending it.
		if ( (bool) $request->get_param( 'dry_run' ) ) {
			$preview = System_Email_Sender::preview( $post_id, $context );
			if ( is_wp_error( $preview ) ) {
				return $preview;
			}
			return rest_ensure_response( array_merge( [ 'success' => true, 'dry_run' => true ], $preview ) );
		}

		$result = System_Email_Sender::send( $post_id, $to_email, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	public function get_segments( WP_REST_Request $request ): WP_REST_Response {
		$audience_id = (string) $request['audience_id'];
		$segments    = ( new Mailchimp() )->get_segments( $audience_id );

		if ( is_wp_error( $segments ) ) {
			return new WP_REST_Response(
				[ 'error' => $segments->get_error_message() ],
				503
			);
		}

		return rest_ensure_response( $segments );
	}

}
