<?php
/**
 * Form action: send a dynamic-recipient system email for prc-block/form.
 *
 * @package    PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Registers the `sendSystemEmail` REST form action consumed by prc-block/form.
 *
 * The form block submits to `/prc-api/v3/form/send-system-email` (the camelCase
 * action `sendSystemEmail` is slugified by the form view script). The submission
 * body is the standard prc-block/form envelope:
 *
 *   { formName, formId, actionConfig, formFields: [ { name, type, value, ... } ] }
 *
 * This handler:
 *   1. for non-privileged submissions (callers without `edit_post` on the
 *      resolved template), verifies the Cloudflare
 *      Turnstile captcha token carried in the form envelope (see {@see verify_captcha()})
 *   2. reads the recipient address from the first `email` field
 *   3. resolves the target dynamic newsletter from a `newsletter_post_id` or
 *      `system_email_key` field (set on the form by the consumer, e.g. mapped
 *      from quiz state)
 *   4. enforces per-IP and per-recipient send throttling on public submissions
 *      so a single client cannot automate unsolicited outbound sends (see
 *      {@see enforce_send_throttle()})
 *   5. builds the merge context via the
 *      `prc_email_builder_system_email_form_context` filter (client
 *      `merge:*` fields are only trusted for capability-gated requests; public
 *      sends rely on the consumer filter to build context server-side)
 *   6. delegates to {@see System_Email_Sender::send()}
 *
 * Resolution by `system_email_key` uses the published newsletter's
 * post slug (see {@see Post_Type::get_by_system_email_key()}),
 * so a form can map an abstract key (e.g. a quiz group slug) to the newsletter
 * without hard-coding post IDs.
 */
class Form_Send_System_Email {
	const ROUTE = 'form/send-system-email';

	/**
	 * Object-cache group for the public send throttle buckets.
	 */
	const THROTTLE_CACHE_GROUP = 'prc_email_system_email_throttle';

	/**
	 * Default per-IP send limit and window (fixed window) for public sends.
	 */
	const THROTTLE_IP_LIMIT  = 10;
	const THROTTLE_IP_WINDOW = 10 * MINUTE_IN_SECONDS;

	/**
	 * Default per-recipient send limit and window (fixed window) for public sends.
	 */
	const THROTTLE_EMAIL_LIMIT  = 5;
	const THROTTLE_EMAIL_WINDOW = HOUR_IN_SECONDS;

	/**
	 * Construct.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( ?Loader $loader = null ) {
		if ( null === $loader ) {
			return;
		}
		$loader->add_action( 'rest_api_init', $this, 'register_rest_endpoints' );
	}

	/**
	 * Hook callback for @hook.
	 *
	 * @hook rest_api_init
	 */
	public function register_rest_endpoints(): void {
		register_rest_route(
			'prc-api/v3',
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_submission' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle a sendSystemEmail form submission.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_submission( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form_data = json_decode( $request->get_body(), true );
		if ( ! is_array( $form_data ) ) {
			return new WP_Error( 'invalid_form_data', 'Invalid form data provided.', array( 'status' => 400 ) );
		}

		$form_fields = $form_data['formFields'] ?? array();
		if ( ! is_array( $form_fields ) || empty( $form_fields ) ) {
			return new WP_Error( 'empty_form_fields', 'No form fields provided.', array( 'status' => 400 ) );
		}

		// Resolve the recipient and the target newsletter up front so the
		// privilege check below can be scoped to that *specific* transactional
		// template rather than the generic edit_posts capability.
		$email = $this->find_email( $form_fields );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'A valid email address is required.', array( 'status' => 400 ) );
		}

		$post_id = $this->resolve_post_id( $form_fields );
		if ( null === $post_id ) {
			return new WP_Error(
				'newsletter_not_found',
				'Could not resolve a dynamic newsletter to send. Provide a newsletter_post_id or system_email_key field.',
				array( 'status' => 422 )
			);
		}

		// Trust boundary: client-supplied merge:* values, the captcha/throttle
		// bypass, and the dry_run preview are only granted to a user who can edit
		// *this specific* transactional template — not anyone merely holding the
		// generic edit_posts cap. Public (logged-out) submissions have merge
		// context built entirely server-side by a consumer filter (see build_context()).
		//
		// The intended privileged use case is the capability-gated dry_run below:
		// an editor in wp-admin verifying wiring / merge:* rendering for a template
		// they own. Public quiz visitors are not logged in, so this is always false
		// for them and their merge:* fields are ignored in favor of the server-side
		// typology filter. A lower-privileged user who can edit some posts but not
		// this template is likewise treated as public (captcha + throttle enforced).
		$is_privileged      = current_user_can( 'edit_post', $post_id );
		$allow_client_merge = $is_privileged;

		// Captcha gate: for non-privileged submissions we require a valid Cloudflare
		// Turnstile token (the prc-block/form-captcha block submits it in the form
		// envelope). Users who can edit this template are exempt — their wp-admin
		// previews/tests render no widget.
		if ( ! $is_privileged ) {
			$captcha_token = function_exists( '\\PRC\\Platform\\find_captcha_token_in_form_fields' )
				? \PRC\Platform\find_captcha_token_in_form_fields( $form_fields )
				: '';
			$captcha       = $this->verify_captcha( $captcha_token );
			if ( is_wp_error( $captcha ) ) {
				return $captcha;
			}
		}

		$context = $this->build_context( $form_fields, $post_id, $allow_client_merge );

		// Capability-gated dry run: render and return the email (and resolved
		// context) without sending. Lets editors verify wiring + merge fields.
		if ( (bool) $request->get_param( 'dry_run' ) && $allow_client_merge ) {
			$preview = System_Email_Sender::preview( $post_id, $context );
			if ( is_wp_error( $preview ) ) {
				return $preview;
			}
			return new WP_REST_Response(
				array_merge(
					array(
						'status'  => 'success',
						'dry_run' => true,
						'post_id' => $post_id,
						'context' => $context,
					),
					$preview
				),
				200
			);
		}

		// Defense in depth: throttle public sends per-IP and per-recipient so a
		// single client cannot fan out unsolicited mail even with a valid captcha.
		// Editors are exempt. Counted here, immediately before dispatch, so only
		// real send attempts consume a slot.
		if ( ! $is_privileged ) {
			$throttled = $this->enforce_send_throttle( $email );
			if ( is_wp_error( $throttled ) ) {
				return $throttled;
			}
		}

		$result = System_Email_Sender::send( $post_id, $email, $context );
		if ( is_wp_error( $result ) ) {
			// A failed send should never lose the submission.
			$this->log_response( $form_data, $form_fields, 'send_failed' );
			return $result;
		}

		$newsletter_signup = $this->maybe_subscribe_to_mailchimp( $email, $form_fields, $post_id, $form_data, $request );

		$this->log_response( $form_data, $form_fields, 'sent', $newsletter_signup );

		$response = array(
			'status'  => 'success',
			'message' => __( 'Check your inbox — your email is on its way.', 'prc-email-builder' ),
		);
		if ( null !== $newsletter_signup ) {
			$response['newsletter_signup'] = $newsletter_signup;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Log the submission to the prc-block-library form responses table.
	 *
	 * Opt-in integration: guarded by class_exists so prc-email-builder never
	 * hard-depends on prc-block-library. Dry runs return before this is
	 * reached, so editor previews are never logged. The log API sanitizes
	 * the fields and strips captcha/nonce system tokens.
	 *
	 * @param array<string, mixed> $form_data         Parsed form envelope.
	 * @param array                $form_fields       Raw submitted form fields.
	 * @param string               $status            Response status: 'sent' or 'send_failed'.
	 * @param string|null          $newsletter_signup Mailchimp opt-in outcome, when a signup field was present.
	 */
	private function log_response( array $form_data, array $form_fields, string $status, ?string $newsletter_signup = null ): void {
		if ( ! class_exists( '\\PRC\\Platform\\Block_Forms\\Form_Response_Log' ) ) {
			return;
		}

		if ( null !== $newsletter_signup ) {
			$form_fields[] = array(
				'name'  => 'newsletter_signup_result',
				'label' => 'Newsletter Signup',
				'type'  => 'text',
				'value' => $newsletter_signup,
			);
		}

		\PRC\Platform\Block_Forms\Form_Response_Log::log(
			array(
				'form_post_id' => absint( $form_data['formPostId'] ?? 0 ),
				'form_name'    => sanitize_text_field( (string) ( $form_data['formName'] ?? '' ) ),
				'action'       => 'sendSystemEmail',
				'status'       => $status,
				'fields'       => $form_fields,
			)
		);
	}

	/**
	 * Find the first non-empty email field value.
	 *
	 * @param array $form_fields Submitted form fields.
	 */
	private function find_email( array $form_fields ): string {
		foreach ( $form_fields as $field ) {
			if ( isset( $field['type'], $field['value'] ) && 'email' === $field['type'] && '' !== (string) $field['value'] ) {
				return sanitize_email( (string) $field['value'] );
			}
		}
		return '';
	}

	/**
	 * Verify a Cloudflare Turnstile captcha token against the siteverify API.
	 *
	 * Mirrors the established server-side verification used by prc-mailchimp.
	 * When no secret key is configured (e.g. local dev without Turnstile env
	 * vars) verification is skipped — the per-IP / per-recipient send throttle
	 * still applies. When a key *is* configured, an empty or invalid token is
	 * rejected. Per-IP / per-recipient send throttling still applies when no key is configured.
	 *
	 * @param string $token The client-supplied Turnstile response token.
	 * @return true|WP_Error True when verified (or unenforceable); WP_Error otherwise.
	 */
	private function verify_captcha( string $token ): true|WP_Error {
		if ( ! function_exists( '\\PRC\\Platform\\verify_captcha' ) ) {
			return true;
		}

		$remote_ip = function_exists( '\\PRC\\Platform\\get_client_ip' )
			? \PRC\Platform\get_client_ip()
			: '';

		if ( ! \PRC\Platform\verify_captcha( $token, '' !== $remote_ip ? $remote_ip : null ) ) {
			return new WP_Error( 'captcha_failed', 'Captcha verification failed.', array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Enforce per-IP and per-recipient send throttling for public submissions.
	 *
	 * Uses a fixed-window counter in the object cache (Memcached on VIP):
	 * `wp_cache_add()` seeds the window TTL once, `wp_cache_incr()` bumps the
	 * count atomically without resetting it. Limits are filterable via
	 * `prc_email_builder_system_email_throttle`.
	 *
	 * @param string $email Validated recipient address.
	 * @return true|WP_Error True when within limits; a 429 WP_Error when exceeded.
	 */
	private function enforce_send_throttle( string $email ): true|WP_Error {
		if ( ! function_exists( '\\PRC\\Platform\\rate_limit_hit' ) ) {
			return true;
		}

		/**
		 * Filter the public send-throttle limits for the sendSystemEmail action.
		 *
		 * @param array{ip:array{limit:int,window:int},email:array{limit:int,window:int}} $limits
		 */
		$limits = apply_filters(
			'prc_email_builder_system_email_throttle',
			array(
				'ip'    => array(
					'limit'  => self::THROTTLE_IP_LIMIT,
					'window' => self::THROTTLE_IP_WINDOW,
				),
				'email' => array(
					'limit'  => self::THROTTLE_EMAIL_LIMIT,
					'window' => self::THROTTLE_EMAIL_WINDOW,
				),
			)
		);

		$ip = function_exists( '\\PRC\\Platform\\get_client_ip' )
			? \PRC\Platform\get_client_ip()
			: '';

		if ( '' !== $ip && isset( $limits['ip']['limit'], $limits['ip']['window'] ) ) {
			if ( \PRC\Platform\rate_limit_hit(
				'ip_' . md5( $ip ),
				(int) $limits['ip']['limit'],
				(int) $limits['ip']['window'],
				self::THROTTLE_CACHE_GROUP
			) ) {
				return new WP_Error( 'rate_limited', 'Too many requests. Please try again later.', array( 'status' => 429 ) );
			}
		}

		if ( isset( $limits['email']['limit'], $limits['email']['window'] ) ) {
			if ( \PRC\Platform\rate_limit_hit(
				'to_' . md5( strtolower( $email ) ),
				(int) $limits['email']['limit'],
				(int) $limits['email']['window'],
				self::THROTTLE_CACHE_GROUP
			) ) {
				return new WP_Error( 'rate_limited', 'This address has reached its send limit. Please try again later.', array( 'status' => 429 ) );
			}
		}

		return true;
	}

	/**
	 * Resolve the target newsletter post ID from the submitted fields.
	 *
	 * Accepts either a direct `newsletter_post_id` field or a
	 * `system_email_key` field resolved against the published newsletter slug.
	 *
	 * @param array $form_fields Submitted form fields.
	 */
	private function resolve_post_id( array $form_fields ): ?int {
		$values  = $this->field_values( $form_fields );
		$post_id = null;

		if ( isset( $values['newsletter_post_id'] ) && ctype_digit( (string) $values['newsletter_post_id'] ) ) {
			$candidate = (int) $values['newsletter_post_id'];
			if ( $candidate > 0 ) {
				$post_id = $candidate;
			}
		}

		if ( null === $post_id && isset( $values['system_email_key'] ) && '' !== (string) $values['system_email_key'] ) {
			$post_id = Post_Type::get_by_system_email_key( (string) $values['system_email_key'] );
		}

		/**
		 * Filter the resolved newsletter post ID for a sendSystemEmail form
		 * submission. Lets a consumer map a submitted identifier (e.g. a quiz
		 * group slug) to the correct dynamic newsletter server-side.
		 *
		 * @param int|null               $post_id      Post ID resolved from standard fields, or null.
		 * @param array<string, string>  $field_values Flat name => value map of all submitted fields.
		 */
		$post_id = apply_filters( 'prc_email_builder_system_email_resolve_post', $post_id, $values );

		return is_numeric( $post_id ) && (int) $post_id > 0 ? (int) $post_id : null;
	}

	/**
	 * Build the merge context passed to the sender.
	 *
	 * Trust boundary: client-supplied `merge:<key>` fields are attacker-
	 * controllable on a public send. If they were copied into the render
	 * context unconditionally, a caller could POST a direct `newsletter_post_id`
	 * plus crafted `merge:*` fields and have a Pew-branded email sent with
	 * attacker-controlled copy in the body (block bits) and `{{key}}` subject
	 * tokens. So we only seed the context from `merge:*` fields when the request
	 * is capability-gated (`$allow_client_merge`); for public sends the context
	 * starts empty and a consumer filter must build it server-side from
	 * validated identifiers.
	 *
	 * @param array $form_fields        Submitted form fields.
	 * @param int   $post_id            Resolved newsletter post ID.
	 * @param bool  $allow_client_merge Whether to honor client-supplied merge:* values.
	 * @return array<string, mixed>
	 */
	private function build_context( array $form_fields, int $post_id, bool $allow_client_merge = false ): array {
		$context = array();
		if ( $allow_client_merge ) {
			foreach ( $form_fields as $field ) {
				$name = isset( $field['name'] ) ? (string) $field['name'] : '';
				if ( str_starts_with( $name, 'merge:' ) ) {
					$key             = substr( $name, 6 );
					$context[ $key ] = sanitize_text_field( (string) ( $field['value'] ?? '' ) );
				}
			}
		}

		$values = $this->field_values( $form_fields );

		/**
		 * Filter the merge context built from a sendSystemEmail form submission.
		 *
		 * For public sends `$context` is empty: consumers MUST build trusted
		 * server-side data keyed off validated identifiers (e.g. a quiz group
		 * slug) rather than reading client-supplied `merge:*` copy. Client merge
		 * values are only pre-populated for capability-gated requests (editors
		 * previewing/testing), signalled by `$allow_client_merge`.
		 *
		 * @param array<string, mixed>  $context            Context (empty for public sends).
		 * @param array<string, string> $field_values       Flat name => value map of all fields.
		 * @param int                   $post_id            Resolved newsletter post ID.
		 * @param bool                  $allow_client_merge Whether the request is capability-gated.
		 */
		return (array) apply_filters(
			'prc_email_builder_system_email_form_context',
			$context,
			$values,
			$post_id,
			$allow_client_merge
		);
	}

	/**
	 * Subscribe the submitter to Mailchimp when a checked mailchimp_signup field is present.
	 *
	 * Non-fatal: failures are logged and never change the send response status.
	 *
	 * @param string               $email        Validated recipient address.
	 * @param array                $form_fields  Raw submitted form fields.
	 * @param int                  $post_id      Resolved newsletter post ID.
	 * @param array<string, mixed> $form_data    Parsed form envelope.
	 * @param WP_REST_Request      $request      Current REST request.
	 * @return 'subscribed'|'failed'|'skipped'|null Null when no mailchimp_signup field is present.
	 */
	private function maybe_subscribe_to_mailchimp(
		string $email,
		array $form_fields,
		int $post_id,
		array $form_data,
		WP_REST_Request $request
	): ?string {
		if ( ! $this->has_mailchimp_signup_field( $form_fields ) ) {
			return null;
		}

		$optin = $this->find_mailchimp_optin_target( $form_fields );
		if ( is_wp_error( $optin ) ) {
			return 'failed';
		}
		if ( null === $optin ) {
			return 'skipped';
		}

		$values    = $this->field_values( $form_fields );
		$list_id   = $optin['audience_id'];
		$interests = $optin['interests'];

		/**
		 * Filter Mailchimp interest IDs for a sendSystemEmail newsletter opt-in.
		 *
		 * Return an empty array to suppress signup for this submission.
		 *
		 * @param array<int, string>    $interests    Interest IDs to pass to subscribe_to_list().
		 * @param array<string, string> $field_values Flat name => value map of all submitted fields.
		 * @param int                   $post_id      Resolved newsletter post ID.
		 */
		$interests = apply_filters( 'prc_email_builder_system_email_mailchimp_optin', $interests, $values, $post_id );
		if ( ! is_array( $interests ) ) {
			return 'skipped';
		}

		$interests = array_values(
			array_filter(
				array_map(
					static fn( $id ) => is_string( $id ) ? $id : '',
					$interests
				),
				static fn( string $id ): bool => '' !== $id
			)
		);
		// Legacy interest-only opt-ins must resolve to at least one interest.
		// Audience/segment targeting may subscribe to the list with no interests
		// (entire audience, or a segment with no interest conditions).
		if ( empty( $interests ) && ! $optin['from_segment'] ) {
			return 'skipped';
		}

		$validated_interests = array();
		foreach ( $interests as $candidate ) {
			$sanitized = $this->sanitize_interest_id( $candidate );
			if ( null === $sanitized ) {
				return 'skipped';
			}
			// Legacy interest-only opt-ins still require the cached allowlist.
			// Audience + saved-segment opt-ins are resolved server-side already.
			if ( ! $optin['from_segment'] && ! $this->is_interest_id_allowed( $sanitized ) ) {
				return 'skipped';
			}
			$validated_interests[] = $sanitized;
		}

		if ( ! class_exists( '\PRC\Platform\Mailchimp_API' ) ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- persist missing Mailchimp API for ops.
				'Form_Send_System_Email: Mailchimp_API is not available for newsletter signup.'
			);
			return 'failed';
		}

		if ( '' === $list_id ) {
			$list_id = defined( '\PRC\Platform\Mailchimp\DEFAULT_LIST_ID' )
				? \PRC\Platform\Mailchimp\DEFAULT_LIST_ID
				: '3e953b9b70';
		}

		$origin_url = $this->resolve_origin_url( $form_data, $request );
		$form_id    = isset( $form_data['formId'] ) ? sanitize_text_field( (string) $form_data['formId'] ) : '';

		$api    = new \PRC\Platform\Mailchimp_API(
			$email,
			array(
				'api_key' => null,
				'list_id' => $list_id,
			)
		);
		$result = $api->subscribe_to_list(
			null,
			$validated_interests,
			'' !== $origin_url ? $origin_url : false,
			'' !== $form_id ? $form_id : false
		);

		if ( is_wp_error( $result ) ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- persist Mailchimp signup failures for ops.
				sprintf(
					'Form_Send_System_Email: Mailchimp signup failed for %s: %s',
					$email,
					$result->get_error_message()
				)
			);
			return 'failed';
		}

		return 'subscribed';
	}

	/**
	 * Whether the submission includes a mailchimp_signup field (checked or not).
	 *
	 * @param array $form_fields Submitted form fields.
	 */
	private function has_mailchimp_signup_field( array $form_fields ): bool {
		foreach ( $form_fields as $field ) {
			if ( isset( $field['name'] ) && 'mailchimp_signup' === (string) $field['name'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find a checked mailchimp_signup field and return its sanitized interest ID.
	 *
	 * Legacy helper kept for tests/back-compat. Prefer find_mailchimp_optin_target().
	 *
	 * @param array $form_fields Submitted form fields.
	 */
	private function find_mailchimp_optin( array $form_fields ): ?string {
		$target = $this->find_mailchimp_optin_target( $form_fields );
		if ( null === $target || is_wp_error( $target ) || empty( $target['interests'][0] ) ) {
			return null;
		}
		return (string) $target['interests'][0];
	}

	/**
	 * Find a checked mailchimp_signup field and resolve audience + interests.
	 *
	 * New forms store audienceId + saved-segment ID (value). Legacy forms store
	 * an interest ID in value with no audienceId.
	 *
	 * @param array $form_fields Submitted form fields.
	 * @return array{audience_id: string, interests: array<int, string>, from_segment: bool}|WP_Error|null
	 */
	private function find_mailchimp_optin_target( array $form_fields ) {
		foreach ( $form_fields as $field ) {
			if ( ! isset( $field['name'] ) || 'mailchimp_signup' !== (string) $field['name'] ) {
				continue;
			}
			if ( ! $this->is_field_checked( $field['checked'] ?? false ) ) {
				continue;
			}

			$value       = isset( $field['value'] ) ? (string) $field['value'] : '';
			$audience_id = '';
			if ( isset( $field['audienceId'] ) ) {
				$audience_id = sanitize_text_field( (string) $field['audienceId'] );
			}

			// Audience targeting: empty value = entire audience; digits = saved segment.
			if ( '' !== $audience_id ) {
				if ( '' === $value ) {
					return array(
						'audience_id'  => $audience_id,
						'interests'    => array(),
						'from_segment' => true,
					);
				}
				if ( ctype_digit( $value ) ) {
					if ( ! function_exists( '\PRC\Platform\Mailchimp\resolve_segment_interest_ids' ) ) {
						error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- persist missing segment resolver for ops.
							'Form_Send_System_Email: resolve_segment_interest_ids is unavailable.'
						);
						return null;
					}
					$resolved = \PRC\Platform\Mailchimp\resolve_segment_interest_ids(
						$audience_id,
						array( $value ),
						'mailchimp-form'
					);
					if ( is_wp_error( $resolved ) ) {
						error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- persist segment resolution failures for ops.
							sprintf(
								'Form_Send_System_Email: Mailchimp segment resolution failed: %s',
								$resolved->get_error_message()
							)
						);
						return $resolved;
					}
					if ( ! is_array( $resolved ) ) {
						$resolved = array();
					}
					return array(
						'audience_id'  => $audience_id,
						'interests'    => array_values( array_map( 'strval', $resolved ) ),
						'from_segment' => true,
					);
				}
			}

			if ( '' === $value ) {
				continue;
			}

			// Legacy interest-ID opt-in.
			$interest_id = $this->sanitize_interest_id( $value );
			if ( null !== $interest_id ) {
				return array(
					'audience_id'  => $audience_id,
					'interests'    => array( $interest_id ),
					'from_segment' => false,
				);
			}
		}
		return null;
	}

	/**
	 * Normalize checkbox checked state from loosely typed form envelope values.
	 *
	 * @param mixed $checked Submitted checked value.
	 */
	private function is_field_checked( mixed $checked ): bool {
		if ( true === $checked || 1 === $checked ) {
			return true;
		}
		if ( false === $checked || null === $checked || '' === $checked ) {
			return false;
		}
		if ( is_string( $checked ) ) {
			$normalized = strtolower( trim( $checked ) );
			if ( in_array( $normalized, array( 'false', '0', 'off', 'no' ), true ) ) {
				return false;
			}
			if ( in_array( $normalized, array( 'true', '1', 'on', 'yes' ), true ) ) {
				return true;
			}
		}
		return (bool) $checked;
	}

	/**
	 * Sanitize a Mailchimp interest ID from client input.
	 *
	 * @param string $raw Raw.
	 */
	private function sanitize_interest_id( string $raw ): ?string {
		$sanitized = preg_replace( '/[^a-zA-Z0-9]/', '', $raw );
		if ( null === $sanitized || '' === $sanitized || strlen( $sanitized ) > 32 ) {
			return null;
		}
		return $sanitized;
	}

	/**
	 * Validate an interest ID against the cached segment list when available.
	 *
	 * @param string $interest_id Interest id.
	 */
	private function is_interest_id_allowed( string $interest_id ): bool {
		$cached = get_option( 'prc_mailchimp_segment_ids', false );
		if ( ! is_array( $cached ) || empty( $cached ) ) {
			return true;
		}

		foreach ( $cached as $segment ) {
			if ( ! is_array( $segment ) || ! isset( $segment['interest_id'] ) ) {
				continue;
			}
			if ( (string) $segment['interest_id'] === $interest_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve the origin URL for Mailchimp merge fields.
	 *
	 * @param array<string, mixed> $form_data Parsed form envelope.
	 * @param WP_REST_Request      $request Request.
	 */
	private function resolve_origin_url( array $form_data, WP_REST_Request $request ): string {
		$action_config = $form_data['actionConfig'] ?? array();
		if ( ! is_array( $action_config ) ) {
			$action_config = array();
		}

		$origin_url = isset( $action_config['originUrl'] ) ? (string) $action_config['originUrl'] : '';
		if ( '' !== $origin_url && filter_var( $origin_url, FILTER_VALIDATE_URL ) ) {
			return esc_url_raw( $origin_url );
		}

		$referer = $request->get_header( 'referer' );
		if ( is_string( $referer ) && filter_var( $referer, FILTER_VALIDATE_URL ) ) {
			return esc_url_raw( $referer );
		}

		return '';
	}

	/**
	 * Flatten form fields into a name => value map.
	 *
	 * @param array $form_fields Submitted form fields.
	 * @return array<string, string>
	 */
	private function field_values( array $form_fields ): array {
		$out = array();
		foreach ( $form_fields as $field ) {
			if ( ! isset( $field['name'] ) ) {
				continue;
			}
			$out[ (string) $field['name'] ] = (string) ( $field['value'] ?? '' );
		}
		return $out;
	}
}
