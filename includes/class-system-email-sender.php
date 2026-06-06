<?php
declare(strict_types=1);
/**
 * Dynamic-recipient "system email" sender.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use WP_Error;
use WP_Post;
use PRC\Platform\Block_Bits\Bit_Render_Context;

/**
 * Renders a `dynamic` delivery-mode newsletter as a personalized transactional
 * email and sends it to a single recipient.
 *
 * Unlike the mailchimp/mandrill channels (which create a campaign or bulk-send
 * a fixed list on publish), a `dynamic` newsletter is a reusable template. It
 * is rendered on demand, once per recipient, so that block bits resolve against
 * recipient-specific merge data via {@see Bit_Render_Context}.
 *
 * Rendering pipeline (per send):
 *   1. set the render context (merge data)
 *   2. run the post content through Email_Block_Converter (the shared email-safe
 *      renderer) within the render-context window so the block-bits Walker still
 *      resolves callback bits against the context
 *   3. wrap the rendered content in the newsletter email shell/body template
 *   4. substitute {{key}} tokens in the subject line
 *   5. Mandrill messages/send (direct API — bypasses wpMandrill template wrap)
 *   6. clear the render context
 *
 * The AI content transformer is intentionally NOT used here: it is async and
 * model-backed, which is unsuitable for a synchronous, per-recipient public
 * send path. Dynamic system emails are short, personalized messages authored
 * with the email-friendly block set, then wrapped in the same branded shell as
 * the other channels.
 */
class System_Email_Sender {
	const DELIVERY_MODE    = 'dynamic';
	const FROM_NAME        = 'Pew Research Center';
	const API_KEY_CONSTANT = 'PRC_PLATFORM_MANDRILL_KEY';
	const API_URL          = 'https://mandrillapp.com/api/1.0/';

	/**
	 * Send a dynamic-recipient newsletter to a single email address.
	 *
	 * @param int                  $post_id  Newsletter post ID (delivery mode must be 'dynamic').
	 * @param string               $to_email Recipient email address.
	 * @param array<string, mixed> $context  Merge-field data made available to block bits
	 *                                        and to {{key}} subject tokens.
	 * @return true|WP_Error
	 */
	public static function send( int $post_id, string $to_email, array $context = [] ): true|WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! Post_Type::is_transactional_post( $post ) ) {
			return new WP_Error( 'invalid_post', 'Transactional email post not found.', [ 'status' => 404 ] );
		}
		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'not_published', 'Transactional email is not published.', [ 'status' => 409 ] );
		}
		if ( self::DELIVERY_MODE !== get_post_meta( $post_id, 'prc_email_delivery_mode', true ) ) {
			return new WP_Error( 'wrong_delivery_mode', 'Transactional sub-mode is not "dynamic".', [ 'status' => 409 ] );
		}
		if ( ! is_email( $to_email ) ) {
			return new WP_Error( 'invalid_email', 'A valid recipient email address is required.', [ 'status' => 400 ] );
		}

		/**
		 * Filter the merge context before rendering a dynamic system email.
		 *
		 * @param array<string, mixed> $context  Merge-field data.
		 * @param int                  $post_id  Newsletter post ID.
		 * @param string               $to_email Recipient address.
		 */
		$context = apply_filters( 'prc_email_builder_system_email_context', $context, $post_id, $to_email );

		$html = self::render( $post, $context );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$subject = self::resolve_subject( $post_id, $context );
		$sent    = self::dispatch( $to_email, $subject, $html );

		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		/**
		 * Fires after a dynamic system email is successfully sent via Mandrill.
		 *
		 * @param int                  $post_id  Newsletter post ID.
		 * @param string               $to_email Recipient address.
		 * @param array<string, mixed> $context  Merge-field data used for the render.
		 */
		do_action( 'prc_email_builder_system_email_sent', $post_id, $to_email, $context );

		return true;
	}

	/**
	 * Render a dynamic newsletter without sending it.
	 *
	 * Useful for previewing how merge fields resolve for a given context (and
	 * for testing the render pipeline). Validates the post the same way
	 * {@see self::send()} does.
	 *
	 * @param int                  $post_id Newsletter post ID (delivery mode must be 'dynamic').
	 * @param array<string, mixed> $context Merge-field data.
	 * @return array{subject:string, html:string}|WP_Error
	 */
	public static function preview( int $post_id, array $context = [] ): array|WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! Post_Type::is_transactional_post( $post ) ) {
			return new WP_Error( 'invalid_post', 'Transactional email post not found.', [ 'status' => 404 ] );
		}
		if ( self::DELIVERY_MODE !== get_post_meta( $post_id, 'prc_email_delivery_mode', true ) ) {
			return new WP_Error( 'wrong_delivery_mode', 'Transactional sub-mode is not "dynamic".', [ 'status' => 409 ] );
		}

		$html = self::render( $post, $context );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		return [
			'subject' => self::resolve_subject( $post_id, $context ),
			'html'    => $html,
		];
	}

	/**
	 * Render the newsletter body for a recipient, resolving block bits against
	 * the supplied merge context, and wrap it in the email template.
	 *
	 * @param WP_Post              $post    Newsletter post.
	 * @param array<string, mixed> $context Merge-field data.
	 * @return string|WP_Error Full HTML email document, or error.
	 */
	public static function render( WP_Post $post, array $context = [] ): string|WP_Error {
		$has_bits = class_exists( Bit_Render_Context::class );

		if ( $has_bits ) {
			Bit_Render_Context::set( $context );
		}

		try {
			// Render through the email-safe block converter — the same path the
			// Mandrill bulk / campaign sends use via Cached_Email_Html — so dynamic
			// system emails produce identical email-friendly HTML (chart PNG tables,
			// container flattening, etc.) instead of raw web-oriented do_blocks()
			// output. Block bits still resolve against the per-recipient merge
			// context because the converter renders within the Bit_Render_Context
			// window set above.
			$content = ( new Email_Block_Converter() )->convert( $post );
		} finally {
			if ( $has_bits ) {
				Bit_Render_Context::clear();
			}
		}

		if ( '' === trim( $content ) ) {
			return new WP_Error( 'empty_content', 'Newsletter rendered to empty content.', [ 'status' => 422 ] );
		}

		return Email_Template::wrap( $content, $post->ID );
	}

	/**
	 * Resolve the subject line, substituting {{key}} tokens with context values.
	 *
	 * @param int                  $post_id Newsletter post ID.
	 * @param array<string, mixed> $context Merge-field data.
	 */
	private static function resolve_subject( int $post_id, array $context ): string {
		$subject = (string) get_post_meta( $post_id, 'prc_email_subject', true );
		if ( '' === $subject ) {
			$subject = (string) get_the_title( $post_id );
		}

		return (string) preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
			static function ( array $m ) use ( $context ): string {
				$key = $m[1];
				return array_key_exists( $key, $context ) && is_scalar( $context[ $key ] )
					? (string) $context[ $key ]
					: '';
			},
			$subject
		);
	}

	/**
	 * Send the rendered email via Mandrill messages/send (direct API).
	 *
	 * Bypasses wp_mail() / wpMandrill so the payload is not wrapped in a
	 * default Mandrill template.
	 */
	private static function dispatch( string $to_email, string $subject, string $html ): true|WP_Error {
		$api_key = self::get_api_key();
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
			'from_name'           => self::FROM_NAME,
			'to'                  => [ [ 'email' => $to_email, 'type' => 'to' ] ],
			'headers'             => [ 'Reply-To' => $reply_to ],
			'track_opens'         => (bool) ( $settings['track_opens'] ?? true ),
			'track_clicks'        => (bool) ( $settings['track_clicks'] ?? true ),
			'tags'                => array_merge( $base_tags, [ 'system-email' ] ),
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

		return self::parse_send_response( $response );
	}

	/**
	 * Evaluate a Mandrill messages/send response for a single recipient.
	 */
	private static function parse_send_response( array $response ): true|WP_Error {
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

	private static function get_api_key(): string {
		if ( defined( self::API_KEY_CONSTANT ) ) {
			return (string) constant( self::API_KEY_CONSTANT );
		}
		return '';
	}
}
