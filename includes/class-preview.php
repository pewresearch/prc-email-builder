<?php
/**
 * Email preview REST endpoints.
 *
 * @package    PRC\Platform\Email_Builder
 */

declare(strict_types=1);

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

	const API_KEY_CONSTANT    = 'PRC_PLATFORM_MANDRILL_KEY';
	const API_URL             = 'https://mandrillapp.com/api/1.0/';
	const MAX_TEST_RECIPIENTS = 10;

	/**
	 * Construct.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			REST_API::NAMESPACE,
			'/preview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_preview' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			REST_API::NAMESPACE,
			'/test-send',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_test' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'emails'  => array(
						'required'          => false,
						'type'              => 'array',
						'items'             => array(
							'type' => 'string',
						),
						'sanitize_callback' => array( $this, 'sanitize_emails_param' ),
						'validate_callback' => array( $this, 'validate_emails_param' ),
					),
					'email'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * GET /preview
	 *
	 * Renders synchronously and always returns status='complete'.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_preview( WP_REST_Request $request ): WP_REST_Response {
		$post_id = $request->get_param( 'post_id' );

		if ( Migration::is_migrated( $post_id ) ) {
			return rest_ensure_response(
				array(
					'status' => 'error',
					'html'   => '',
					'error'  => 'Migrated newsletters do not support email preview.',
				) 
			);
		}

		$settings = Mailchimp::get_settings();
		$meta     = array(
			'from_name'    => $settings['from_name'] ?? '',
			'from_email'   => $settings['from_email'] ?? '',
			'subject'      => Email_Subject::display( $post_id ),
			'preview_text' => get_post_meta( $post_id, 'prc_email_preview_text', true ),
		);

		$post = get_post( $post_id );
		if ( ! $post ) {
			return rest_ensure_response(
				array_merge(
					$meta,
					array(
						'status'     => 'error',
						'html'       => '',
						'size_bytes' => 0,
						'error'      => 'Post not found.',
					) 
				)
			);
		}

		$converter = new Email_Block_Converter();
		$content   = $converter->convert( $post );
		$html      = '' !== $content ? Email_Template::wrap( $content, $post_id ) : '';

		return rest_ensure_response(
			array_merge(
				$meta,
				array(
					'status'     => 'complete',
					'html'       => $html,
					'size_bytes' => strlen( $html ),
				) 
			)
		);
	}

	/**
	 * POST /test-send
	 *
	 * Sends the email HTML to one or more addresses via Mandrill messages/send.
	 * The subject is prefixed with "[TEST]" so it's easy to spot in an inbox.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function send_test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = $request->get_param( 'post_id' );
		$emails  = $this->resolve_test_emails( $request );

		if ( is_wp_error( $emails ) ) {
			return $emails;
		}

		if ( Migration::is_migrated( $post_id ) ) {
			return new WP_Error(
				'migrated_post',
				'Migrated newsletters do not support test sends.',
				array( 'status' => 403 )
			);
		}

		$html = Cached_Email_Html::resolve( $post_id );

		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$ready = Email_Subject::require_for_send( $post_id );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$result = $this->dispatch_test_email( $emails, '[TEST] ' . $ready->line(), $html );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$failed = $result['failed'];
		if ( empty( $result['sent'] ) && ! empty( $failed ) ) {
			$first_reason = (string) reset( $failed );
			return new WP_Error(
				'mandrill_send_rejected',
				sprintf( 'Mandrill rejected the email: %s.', $first_reason ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'sent'    => $result['sent'],
				'failed'  => (object) $failed,
			)
		);
	}

	/**
	 * Normalize REST email args into a deduped, validated recipient list.
	 *
	 * Accepts either `emails` (preferred) or legacy single/comma-separated `email`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, string>|WP_Error
	 */
	private function resolve_test_emails( WP_REST_Request $request ): array|WP_Error {
		$emails_param = $request->get_param( 'emails' );
		$email_param  = $request->get_param( 'email' );

		$raw = array();
		if ( is_array( $emails_param ) ) {
			$raw = $emails_param;
		} elseif ( is_string( $email_param ) && '' !== trim( $email_param ) ) {
			$split = preg_split( '/\s*,\s*/', trim( $email_param ), -1, PREG_SPLIT_NO_EMPTY );
			$raw   = false !== $split ? $split : array();
		}

		$parsed = $this->parse_and_validate_emails( $raw );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( empty( $parsed ) ) {
			return new WP_Error(
				'rest_invalid_param',
				'At least one valid email address is required.',
				array( 'status' => 400 )
			);
		}

		return $parsed;
	}

	/**
	 * Sanitize an `emails` REST array param.
	 *
	 * Trims string entries but preserves invalid / non-string values so
	 * `validate_emails_param` can reject the request (REST runs sanitize
	 * before validate).
	 *
	 * @param mixed $value Raw request value.
	 * @return array<int, mixed>
	 */
	public function sanitize_emails_param( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $value as $email ) {
			$sanitized[] = is_string( $email ) ? trim( $email ) : $email;
		}
		return $sanitized;
	}

	/**
	 * Validate an `emails` REST array param.
	 *
	 * @param mixed $value Sanitized request value.
	 */
	public function validate_emails_param( $value ): bool|WP_Error {
		if ( null === $value ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'rest_invalid_param',
				'emails must be an array of email addresses.',
				array( 'status' => 400 )
			);
		}

		$parsed = $this->parse_and_validate_emails( $value );
		return is_wp_error( $parsed ) ? $parsed : true;
	}

	/**
	 * Parse, validate, dedupe, and cap a list of email addresses.
	 *
	 * @param array<int, mixed> $raw Raw email values.
	 * @return array<int, string>|WP_Error
	 */
	private function parse_and_validate_emails( array $raw ): array|WP_Error {
		$emails = array();

		foreach ( $raw as $value ) {
			if ( ! is_string( $value ) ) {
				return new WP_Error(
					'rest_invalid_param',
					'Each recipient must be a valid email address.',
					array( 'status' => 400 )
				);
			}

			$email = sanitize_email( $value );
			if ( ! is_email( $email ) ) {
				return new WP_Error(
					'rest_invalid_param',
					sprintf( 'Invalid email address: %s.', $value ),
					array( 'status' => 400 )
				);
			}

			$emails[ strtolower( $email ) ] = $email;
		}

		$emails = array_values( $emails );
		if ( count( $emails ) > self::MAX_TEST_RECIPIENTS ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					'A maximum of %d test recipients is allowed.',
					self::MAX_TEST_RECIPIENTS
				),
				array( 'status' => 400 )
			);
		}

		return $emails;
	}

	/**
	 * Send a test email via Mandrill messages/send (direct API).
	 *
	 * Bypasses wp_mail() / wpMandrill so the payload is not wrapped in a
	 * default Mandrill template.
	 *
	 * @param array<int, string> $to_emails Recipient addresses.
	 * @param string             $subject Subject.
	 * @param string             $html Html.
	 * @return array{sent: array<int, string>, failed: array<string, string>}|WP_Error
	 */
	private function dispatch_test_email( array $to_emails, string $subject, string $html ): array|WP_Error {
		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'mandrill_not_configured', 'Mandrill API key is not set.', array( 'status' => 500 ) );
		}

		$settings   = Mailchimp::get_settings();
		$from_email = (string) ( $settings['from_email'] ?? '' );
		if ( ! is_email( $from_email ) ) {
			return new WP_Error( 'missing_from_email', 'A valid from email address is required.', array( 'status' => 500 ) );
		}

		$reply_to = (string) ( $settings['reply_to'] ?? '' );
		if ( ! is_email( $reply_to ) ) {
			$reply_to = $from_email;
		}

		$base_tags = is_array( $settings['mandrill_tags'] ?? null ) ? $settings['mandrill_tags'] : array( 'prc-newsletter' );

		$to = array_map(
			static fn( string $email ): array => array(
				'email' => $email,
				'type'  => 'to',
			),
			$to_emails
		);

		$message = array(
			'html'                => $html,
			'subject'             => $subject,
			'from_email'          => $from_email,
			'from_name'           => (string) ( $settings['from_name'] ?? '' ),
			'to'                  => $to,
			'headers'             => array( 'Reply-To' => $reply_to ),
			'track_opens'         => (bool) ( $settings['track_opens'] ?? true ),
			'track_clicks'        => (bool) ( $settings['track_clicks'] ?? true ),
			'tags'                => array_merge( $base_tags, array( 'test' ) ),
			'preserve_recipients' => false,
		);

		$subaccount = (string) ( $settings['mandrill_subaccount'] ?? '' );
		if ( '' !== $subaccount ) {
			$message['subaccount'] = $subaccount;
		}

		$payload = array(
			'key'     => $api_key,
			'message' => $message,
			'async'   => false,
		);

		$response = wp_remote_post(
			self::API_URL . 'messages/send',
			array(
				'timeout' => 30, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Mandrill send can exceed the default 5s.
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mandrill_request_failed',
				$response->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 400 ) {
			$body   = json_decode( wp_remote_retrieve_body( $response ), true ) ?? array();
			$detail = $body['message'] ?? $body['name'] ?? "HTTP {$status_code}";
			return new WP_Error( 'mandrill_api_error', (string) $detail, array( 'status' => 500 ) );
		}

		return $this->parse_batch_send_response( $response, $to_emails );
	}

	/**
	 * Partition a Mandrill messages/send response into per-recipient outcomes.
	 *
	 * @param array              $response  wp_remote_post() response array.
	 * @param array<int, string> $to_emails Recipients the call was made for.
	 * @return array{sent: array<int, string>, failed: array<string, string>}|WP_Error
	 */
	private function parse_batch_send_response( array $response, array $to_emails ): array|WP_Error {
		$results = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $results ) || ! isset( $results[0]['status'] ) ) {
			return new WP_Error( 'mandrill_invalid_response', 'Mandrill did not return a recipient status.', array( 'status' => 500 ) );
		}

		$statuses = array();
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || ! isset( $result['email'], $result['status'] ) ) {
				continue;
			}
			$statuses[ strtolower( (string) $result['email'] ) ] = array(
				'status' => (string) $result['status'],
				'reason' => (string) ( $result['reject_reason'] ?? $result['status'] ),
			);
		}

		$sent   = array();
		$failed = array();

		foreach ( $to_emails as $to_email ) {
			$entry = $statuses[ strtolower( $to_email ) ] ?? null;
			if ( null === $entry ) {
				$failed[ $to_email ] = 'no recipient status returned';
				continue;
			}
			if ( in_array( $entry['status'], array( 'sent', 'queued', 'scheduled' ), true ) ) {
				$sent[] = $to_email;
			} else {
				$failed[ $to_email ] = $entry['reason'];
			}
		}

		return array(
			'sent'   => $sent,
			'failed' => $failed,
		);
	}

	/**
	 * Get api key.
	 */
	private function get_api_key(): string {
		if ( defined( self::API_KEY_CONSTANT ) ) {
			return (string) constant( self::API_KEY_CONSTANT );
		}
		return '';
	}

	/**
	 * Shared permission check: the caller must be able to edit the target post.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function permission_check( WP_REST_Request $request ): bool|WP_Error {
		$post_id = $request->get_param( 'post_id' );
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				'You do not have permission to preview this newsletter.',
				array( 'status' => 403 )
			);
		}
		return true;
	}
}
