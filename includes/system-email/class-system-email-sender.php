<?php
/**
 * Dynamic-recipient "system email" sender.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

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
	 * Mandrill messages/send accepts at most 1,000 recipients per call.
	 */
	const MAX_RECIPIENTS_PER_CALL = 1000;

	/**
	 * Send a dynamic-recipient newsletter to a single email address.
	 *
	 * @param int                  $post_id  Newsletter post ID (delivery mode must be 'dynamic').
	 * @param string               $to_email Recipient email address.
	 * @param array<string, mixed> $context  Merge-field data made available to block bits
	 *                                        and to {{key}} subject tokens.
	 * @return true|WP_Error
	 */
	public static function send( int $post_id, string $to_email, array $context = array() ): true|WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! Post_Type::is_transactional_post( $post ) ) {
			return new WP_Error( 'invalid_post', 'Transactional email post not found.', array( 'status' => 404 ) );
		}
		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'not_published', 'Transactional email is not published.', array( 'status' => 409 ) );
		}
		if ( self::DELIVERY_MODE !== Post_Type::transactional_delivery_mode( $post ) ) {
			return new WP_Error( 'wrong_delivery_mode', 'Transactional sub-mode is not "dynamic".', array( 'status' => 409 ) );
		}
		if ( ! is_email( $to_email ) ) {
			return new WP_Error( 'invalid_email', 'A valid recipient email address is required.', array( 'status' => 400 ) );
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
		if ( is_wp_error( $subject ) ) {
			return $subject;
		}
		$result = self::dispatch( array( $to_email ), $subject, $html, $post_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! in_array( $to_email, $result['sent'], true ) ) {
			$reason = (string) ( $result['failed'][ $to_email ] ?? 'unknown' );
			return new WP_Error(
				'mandrill_send_rejected',
				sprintf( 'Mandrill rejected the email: %s.', $reason ),
				array( 'status' => 500 )
			);
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
	 * Send a dynamic-recipient newsletter to many recipients with one shared
	 * render.
	 *
	 * Unlike {@see self::send()}, the merge context here is recipient-agnostic
	 * (e.g. a quiz-group context shared by everyone in the batch): the body is
	 * rendered once and dispatched in a single Mandrill messages/send call per
	 * chunk of {@see self::MAX_RECIPIENTS_PER_CALL} recipients. The existing
	 * `preserve_recipients: false` flag means recipients never see each other.
	 *
	 * @param int                  $post_id   Newsletter post ID (delivery mode must be 'dynamic').
	 * @param array<int, string>   $to_emails Recipient email addresses.
	 * @param array<string, mixed> $context   Merge-field data shared by every recipient.
	 * @return array{sent: array<int, string>, failed: array<string, string>}|WP_Error
	 *         Per-recipient outcome partition, or WP_Error when nothing was
	 *         dispatched (invalid post, no valid recipients, render failure, or
	 *         a whole-call API failure on the first chunk).
	 */
	public static function send_many( int $post_id, array $to_emails, array $context = array() ): array|WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! Post_Type::is_transactional_post( $post ) ) {
			return new WP_Error( 'invalid_post', 'Transactional email post not found.', array( 'status' => 404 ) );
		}
		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'not_published', 'Transactional email is not published.', array( 'status' => 409 ) );
		}
		if ( self::DELIVERY_MODE !== Post_Type::transactional_delivery_mode( $post ) ) {
			return new WP_Error( 'wrong_delivery_mode', 'Transactional sub-mode is not "dynamic".', array( 'status' => 409 ) );
		}

		$recipients = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $to_emails ),
					static fn( string $email ): bool => (bool) is_email( $email )
				)
			)
		);
		if ( empty( $recipients ) ) {
			return new WP_Error( 'invalid_email', 'At least one valid recipient email address is required.', array( 'status' => 400 ) );
		}

		/** This filter is documented in {@see self::send()}; for batch sends the third argument is the recipient list. */
		$context = apply_filters( 'prc_email_builder_system_email_context', $context, $post_id, $recipients );

		$html = self::render( $post, $context );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$subject = self::resolve_subject( $post_id, $context );
		if ( is_wp_error( $subject ) ) {
			return $subject;
		}

		$sent   = array();
		$failed = array();

		foreach ( array_chunk( $recipients, self::MAX_RECIPIENTS_PER_CALL ) as $chunk ) {
			$result = self::dispatch( $chunk, $subject, $html, $post_id );

			if ( is_wp_error( $result ) ) {
				// Whole-call failure: nothing in this chunk was dispatched. If
				// no prior chunk succeeded either, surface the error so the
				// caller can retry the entire batch safely.
				if ( empty( $sent ) && empty( $failed ) ) {
					return $result;
				}
				foreach ( $chunk as $email ) {
					$failed[ $email ] = $result->get_error_message();
				}
				continue;
			}

			$sent   = array_merge( $sent, $result['sent'] );
			$failed = array_merge( $failed, $result['failed'] );
		}

		foreach ( $sent as $to_email ) {
			/** This action is documented in {@see self::send()}. */
			do_action( 'prc_email_builder_system_email_sent', $post_id, $to_email, $context );
		}

		return array(
			'sent'   => $sent,
			'failed' => $failed,
		);
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
	public static function preview( int $post_id, array $context = array() ): array|WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! Post_Type::is_transactional_post( $post ) ) {
			return new WP_Error( 'invalid_post', 'Transactional email post not found.', array( 'status' => 404 ) );
		}
		if ( self::DELIVERY_MODE !== Post_Type::transactional_delivery_mode( $post ) ) {
			return new WP_Error( 'wrong_delivery_mode', 'Transactional sub-mode is not "dynamic".', array( 'status' => 409 ) );
		}

		$html = self::render( $post, $context );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$subject = self::resolve_subject( $post_id, $context );
		if ( is_wp_error( $subject ) ) {
			return $subject;
		}

		return array(
			'subject' => $subject,
			'html'    => $html,
		);
	}

	/**
	 * Render the newsletter body for a recipient, resolving block bits against
	 * the supplied merge context, and wrap it in the email template.
	 *
	 * @param WP_Post              $post    Newsletter post.
	 * @param array<string, mixed> $context Merge-field data.
	 * @return string|WP_Error Full HTML email document, or error.
	 */
	public static function render( WP_Post $post, array $context = array() ): string|WP_Error {
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
			return new WP_Error( 'empty_content', 'Newsletter rendered to empty content.', array( 'status' => 422 ) );
		}

		return Email_Template::wrap( $content, $post->ID );
	}

	/**
	 * Resolve the subject line, substituting {{key}} tokens with context values.
	 *
	 * @param int                  $post_id Newsletter post ID.
	 * @param array<string, mixed> $context Merge-field data.
	 */
	private static function resolve_subject( int $post_id, array $context ): string|WP_Error {
		$ready = Email_Subject::require_for_send( $post_id );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return (string) preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
			static function ( array $m ) use ( $context ): string {
				$key = $m[1];
				return array_key_exists( $key, $context ) && is_scalar( $context[ $key ] )
					? (string) $context[ $key ]
					: '';
			},
			$ready->line()
		);
	}

	/**
	 * Send the rendered email via Mandrill messages/send (direct API).
	 *
	 * Bypasses wp_mail() / wpMandrill so the payload is not wrapped in a
	 * default Mandrill template. Accepts one or more recipients (callers must
	 * stay within {@see self::MAX_RECIPIENTS_PER_CALL}); the response is
	 * partitioned per recipient.
	 *
	 * @param array<int, string> $to_emails Recipient addresses.
	 * @param string             $subject   Resolved subject line.
	 * @param string             $html      Rendered email document.
	 * @param int                $post_id   Transactional post ID (0 in isolated tests).
	 * @return array{sent: array<int, string>, failed: array<string, string>}|WP_Error
	 *         Per-recipient outcomes, or WP_Error when the whole call failed
	 *         and nothing was dispatched.
	 */
	private static function dispatch( array $to_emails, string $subject, string $html, int $post_id = 0 ): array|WP_Error {
		$api_key = self::get_api_key();
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

		$message = array(
			'html'                => $html,
			'subject'             => $subject,
			'from_email'          => $from_email,
			'from_name'           => self::FROM_NAME,
			'to'                  => array_map(
				static fn( string $to_email ): array => array(
					'email' => $to_email,
					'type'  => 'to',
				),
				$to_emails
			),
			'headers'             => array( 'Reply-To' => $reply_to ),
			'track_opens'         => (bool) ( $settings['track_opens'] ?? true ),
			'track_clicks'        => (bool) ( $settings['track_clicks'] ?? true ),
			'tags'                => array_merge( $base_tags, array( 'system-email' ) ),
			'preserve_recipients' => false,
		);

		$subaccount = (string) ( $settings['mandrill_subaccount'] ?? '' );
		if ( '' !== $subaccount ) {
			$message['subaccount'] = $subaccount;
		}

		/**
		 * Filter the Mandrill message array before messages/send.
		 *
		 * @param array $message Mandrill message payload.
		 * @param int   $post_id Transactional post ID.
		 */
		$message = apply_filters( 'prc_email_mandrill_message', $message, $post_id );
		if ( ! is_array( $message ) ) {
			$message = array();
		}
		$message = Mandrill_Send_Key::stamp( $message, $post_id );

		$payload = array(
			'key'     => $api_key,
			'message' => $message,
			'async'   => false,
		);

		// phpcs:disable WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Mandrill batch send can exceed the VIP default.
		$response = wp_remote_post(
			self::API_URL . 'messages/send',
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		// phpcs:enable WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout

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

		return self::parse_batch_send_response( $response, $to_emails );
	}

	/**
	 * Partition a Mandrill messages/send response into per-recipient outcomes.
	 *
	 * Mandrill returns one `{ email, status, reject_reason }` entry per
	 * recipient. Statuses sent/queued/scheduled count as sent; anything else
	 * (rejected, invalid) is recorded against the recipient with its reject
	 * reason. Recipients missing from the response are treated as failed.
	 *
	 * @param array              $response  wp_remote_post() response array.
	 * @param array<int, string> $to_emails Recipients the call was made for.
	 * @return array{sent: array<int, string>, failed: array<string, string>}|WP_Error
	 */
	private static function parse_batch_send_response( array $response, array $to_emails ): array|WP_Error {
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
	 * Mandrill API key from the platform constant, or empty.
	 */
	private static function get_api_key(): string {
		if ( defined( self::API_KEY_CONSTANT ) ) {
			return (string) constant( self::API_KEY_CONSTANT );
		}
		return '';
	}
}
