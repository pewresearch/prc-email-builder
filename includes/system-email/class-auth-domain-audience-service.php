<?php
/**
 * Firebase Auth email-domain audience build service.
 *
 * Shared by WP-CLI. Persists email lists in wp_options; does not create
 * newsletter drafts (callers may do that after build()).
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use PRC\Platform\CLI_Audience_Verification;
use WP_Error;

if ( ! trait_exists( '\\PRC\\Platform\\CLI_Audience_Verification' ) ) {
	require_once dirname( __DIR__, 3 ) . '/prc-firebase/includes/trait-cli-audience-verification.php';
}

/**
 * Builds Mandrill audiences from Firebase Auth users whose email domain
 * contains a given substring (for example k12).
 */
class Auth_Domain_Audience_Service {
	use CLI_Audience_Verification;

	const FIREBASE_ENDPOINT_KEY = 'email_domain';
	const SOURCE                = 'auth_email_domain';

	/**
	 * JSON body for buildEmailDomainAudience (verification contract).
	 *
	 * @param string $domain_contains Normalized needle.
	 * @param string $verification    verified|unverified|all
	 * @return array<string, mixed>
	 */
	public static function request_body( string $domain_contains, string $verification ): array {
		return self::build_audience_request_body(
			array( 'domain_contains' => $domain_contains ),
			$verification
		);
	}

	/**
	 * Call Firebase and persist the audience list + meta.
	 *
	 * @param string $domain_contains Domain substring (e.g. k12).
	 * @param string $verification    verified|unverified|all
	 * @param array  $args            Optional: dry_run (bool), label (string|null), audience_key (string|null).
	 * @return array|WP_Error Snapshot on success, or dry-run summary when dry_run.
	 */
	public static function build( string $domain_contains, string $verification, array $args = array() ) {
		$verification = self::normalize_verification_mode( $verification );
		if ( is_wp_error( $verification ) ) {
			return $verification;
		}

		$needle = Auth_Domain_Matcher::normalize_domain_contains( $domain_contains );
		if ( is_wp_error( $needle ) ) {
			return $needle;
		}

		$dry_run = ! empty( $args['dry_run'] );
		$label   = $args['label'] ?? null;

		$audience_key = $args['audience_key'] ?? null;
		if ( is_string( $audience_key ) && '' !== $audience_key ) {
			$key = $audience_key;
		} else {
			$key = Auth_Domain_Matcher::option_key( $needle, $verification );
			if ( is_wp_error( $key ) ) {
				return $key;
			}
		}

		$endpoints = apply_filters( 'prc_platform_firebase_audiences_endpoints', array() );
		$endpoint  = $endpoints[ self::FIREBASE_ENDPOINT_KEY ] ?? '';
		if ( empty( $endpoint ) ) {
			return new WP_Error(
				'missing_audience_endpoint',
				'No Firebase audiences endpoint configured for "email_domain". ' .
				'Ensure client-mu-plugins/firebase-audiences.php is loaded and ' .
				'PRC_PLATFORM_FIREBASE_PROJECT_ID is defined.',
				array( 'status' => 500 )
			);
		}

		$firebase = new \PRC\Platform\Firebase();
		$id_token = $firebase->get_id_token( $endpoint );
		if ( is_wp_error( $id_token ) ) {
			return new WP_Error(
				'firebase_token_failed',
				'Failed to mint Firebase ID token: ' . $id_token->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 540,
				'headers' => array(
					'Authorization' => 'Bearer ' . $id_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( self::request_body( $needle, $verification ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'firebase_request_failed',
				'HTTP request to Firebase function failed: ' . $response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code || empty( $body['success'] ) ) {
			$detail = is_array( $body ) ? ( $body['error'] ?? "HTTP {$status_code}" ) : "HTTP {$status_code}";
			return new WP_Error(
				'firebase_function_error',
				"Firebase function returned an error: {$detail}",
				array( 'status' => 502 )
			);
		}

		$verification = self::check_response_verification( $body, $verification );
		if ( is_wp_error( $verification ) ) {
			return $verification;
		}

		$emails        = self::emails_from_response( $body );
		$count         = (int) ( $body['count'] ?? count( $emails ) );
		$scanned_users = (int) ( $body['scanned_users'] ?? 0 );
		$matched_users = (int) ( $body['matched_users'] ?? 0 );
		$built_at      = $body['built_at'] ?? current_time( 'mysql', true );

		$final_label = is_string( $label ) && '' !== $label
			? $label
			: sprintf(
				'Firebase Auth domains containing "%s"%s',
				$needle,
				self::verification_label_suffix( $verification )
			);

		$summary = array(
			'key'             => $key,
			'label'           => $final_label,
			'count'           => $count,
			'verification'    => $verification,
			'domain_contains' => $needle,
			'scanned'         => $scanned_users,
			'matched'         => $matched_users,
			'built_at'        => $built_at,
			'dry_run'         => $dry_run,
		);

		if ( $dry_run ) {
			return $summary;
		}

		$meta = array(
			'label'           => $final_label,
			'count'           => $count,
			'built_at'        => $built_at,
			'source'          => self::SOURCE,
			'verification'    => $verification,
			'domain_contains' => $needle,
			'scanned_users'   => $scanned_users,
			'matched_users'   => $matched_users,
		);

		update_option( $key, $emails, false );
		update_option( $key . '_meta', $meta, false );

		return $summary;
	}

	/**
	 * Collect string emails from a Cloud Function JSON body. Does not log them.
	 *
	 * @param array $body Decoded CF response.
	 * @return array<int, string>
	 */
	private static function emails_from_response( array $body ): array {
		$raw = $body['emails'] ?? array();
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$emails = array();
		foreach ( $raw as $email ) {
			if ( is_string( $email ) && '' !== $email ) {
				$emails[] = $email;
			}
		}

		return $emails;
	}
}
