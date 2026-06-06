<?php
declare(strict_types=1);
/**
 * Email preview REST endpoints.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Provides two REST endpoints used by the editor email preview modal:
 *
 *  GET  /prc-email-builder/v1/preview    — returns email HTML + metadata
 *  POST /prc-email-builder/v1/test-send  — sends a test email via Mandrill messages/send
 *
 * Preview HTML is rendered synchronously via Email_Block_Converter, so the
 * response always carries status='complete'.
 */
class Preview {

	const API_KEY_CONSTANT = 'PRC_PLATFORM_MANDRILL_KEY';
	const API_URL          = 'https://mandrillapp.com/api/1.0/';

	public function __construct( Loader $loader ) {
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	public function register_routes(): void {
		register_rest_route(
			REST_API::NAMESPACE,
			'/preview',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_preview' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			REST_API::NAMESPACE,
			'/test-send',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send_test' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'email'   => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => fn( $v ) => is_email( $v ),
					],
				],
			]
		);
	}

	/**
	 * GET /preview
	 *
	 * Renders synchronously and always returns status='complete'.
	 */
	public function get_preview( WP_REST_Request $request ): WP_REST_Response {
		$post_id = $request->get_param( 'post_id' );

		if ( Migration::is_migrated( $post_id ) ) {
			return rest_ensure_response( [
				'status' => 'error',
				'html'   => '',
				'error'  => 'Migrated newsletters do not support email preview.',
			] );
		}

		$settings = Mailchimp::get_settings();
		$meta     = [
			'from_name'    => $settings['from_name'] ?? '',
			'from_email'   => $settings['from_email'] ?? '',
			'subject'      => get_post_meta( $post_id, 'prc_email_subject', true ) ?: get_the_title( $post_id ),
			'preview_text' => get_post_meta( $post_id, 'prc_email_preview_text', true ),
		];

		$post = get_post( $post_id );
		if ( ! $post ) {
			return rest_ensure_response(
				array_merge( $meta, [
					'status'     => 'error',
					'html'       => '',
					'size_bytes' => 0,
					'error'      => 'Post not found.',
				] )
			);
		}

		$converter = new Email_Block_Converter();
		$content   = $converter->convert( $post );
		$html      = '' !== $content ? Email_Template::wrap( $content, $post_id ) : '';

		return rest_ensure_response(
			array_merge( $meta, [
				'status'     => 'complete',
				'html'       => $html,
				'size_bytes' => strlen( $html ),
			] )
		);
	}

	/**
	 * POST /test-send
	 *
	 * Sends the email HTML to a single address via Mandrill messages/send.
	 * The subject is prefixed with "[TEST]" so it's easy to spot in an inbox.
	 */
	public function send_test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = $request->get_param( 'post_id' );
		$email   = $request->get_param( 'email' );

		if ( Migration::is_migrated( $post_id ) ) {
			return new WP_Error(
				'migrated_post',
				'Migrated newsletters do not support test sends.',
				[ 'status' => 403 ]
			);
		}

		$html = Cached_Email_Html::resolve( $post_id );

		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$subject = get_post_meta( $post_id, 'prc_email_subject', true ) ?: get_the_title( $post_id );
		$sent    = $this->dispatch_test_email( $email, '[TEST] ' . $subject, $html );

		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	/**
	 * Send a test email via Mandrill messages/send (direct API).
	 *
	 * Bypasses wp_mail() / wpMandrill so the payload is not wrapped in a
	 * default Mandrill template.
	 */
	private function dispatch_test_email( string $to_email, string $subject, string $html ): true|WP_Error {
		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'mandrill_not_configured', 'Mandrill API key is not set.', [ 'status' => 500 ] );
		}

		$settings   = Mailchimp::get_settings();
		$from_email = (string) ( $settings['from_email'] ?? '' );
		if ( ! is_email( $from_email ) ) {
			return new WP_Error( 'missing_from_email', 'A valid from email address is required.', [ 'status' => 500 ] );
		}

		$reply_to = (string) ( $settings['reply_to'] ?? '' );
		if ( ! is_email( $reply_to ) ) {
			$reply_to = $from_email;
		}

		$base_tags = is_array( $settings['mandrill_tags'] ?? null ) ? $settings['mandrill_tags'] : [ 'prc-newsletter' ];

		$message = [
			'html'                => $html,
			'subject'             => $subject,
			'from_email'          => $from_email,
			'from_name'           => (string) ( $settings['from_name'] ?? '' ),
			'to'                  => [ [ 'email' => $to_email, 'type' => 'to' ] ],
			'headers'             => [ 'Reply-To' => $reply_to ],
			'track_opens'         => (bool) ( $settings['track_opens'] ?? true ),
			'track_clicks'        => (bool) ( $settings['track_clicks'] ?? true ),
			'tags'                => array_merge( $base_tags, [ 'test' ] ),
			'preserve_recipients' => false,
		];

		$subaccount = (string) ( $settings['mandrill_subaccount'] ?? '' );
		if ( '' !== $subaccount ) {
			$message['subaccount'] = $subaccount;
		}

		$payload = [
			'key'     => $api_key,
			'message' => $message,
			'async'   => false,
		];

		$response = wp_remote_post(
			self::API_URL . 'messages/send',
			[
				'timeout' => 30,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mandrill_request_failed',
				$response->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 400 ) {
			$body   = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
			$detail = $body['message'] ?? $body['name'] ?? "HTTP {$status_code}";
			return new WP_Error( 'mandrill_api_error', (string) $detail, [ 'status' => 500 ] );
		}

		return $this->parse_send_response( $response );
	}

	/**
	 * Evaluate a Mandrill messages/send response for a single recipient.
	 */
	private function parse_send_response( array $response ): true|WP_Error {
		$results = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $results ) || ! isset( $results[0]['status'] ) ) {
			return new WP_Error( 'mandrill_invalid_response', 'Mandrill did not return a recipient status.', [ 'status' => 500 ] );
		}

		$status = (string) $results[0]['status'];
		if ( in_array( $status, [ 'sent', 'queued', 'scheduled' ], true ) ) {
			return true;
		}

		$reason = (string) ( $results[0]['reject_reason'] ?? $status );
		return new WP_Error(
			'mandrill_send_rejected',
			sprintf( 'Mandrill rejected the email: %s.', $reason ),
			[ 'status' => 500 ]
		);
	}

	private function get_api_key(): string {
		if ( defined( self::API_KEY_CONSTANT ) ) {
			return (string) constant( self::API_KEY_CONSTANT );
		}
		return '';
	}

	/**
	 * Shared permission check: the caller must be able to edit the target post.
	 */
	public function permission_check( WP_REST_Request $request ): bool|WP_Error {
		$post_id = $request->get_param( 'post_id' );
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				'You do not have permission to preview this newsletter.',
				[ 'status' => 403 ]
			);
		}
		return true;
	}
}
