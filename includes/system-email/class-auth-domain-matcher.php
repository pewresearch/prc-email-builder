<?php
/**
 * Domain substring matching for Firebase Auth email-domain audiences.
 *
 * Keep in sync with firebase/functions/src/email-domain.ts and the fixtures in
 * tests/prc-email-builder/phpunit/fixtures/email-domain-contains.json.
 *
 * Matching uses a case-insensitive substring of the domain only (the part after
 * the last @). The local-part is never searched. This is not a regex.
 *
 * PHP does not page Firebase Auth. The Cloud Function applies these rules at
 * scan time. This class exists so WP-CLI can validate input and so tests can
 * pin the shared cases.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Pure Auth email-domain matcher and needle sanitizer.
 */
class Auth_Domain_Matcher {

	const DOMAIN_CONTAINS_MAX_LENGTH = 64;

	const AUDIENCE_OPTION_PREFIX = 'prc_email_audience_auth_domain_';

	/**
	 * Normalize and validate a domain_contains needle.
	 *
	 * @param mixed $raw Raw CLI / request value.
	 * @return string|WP_Error Lowercased needle, or WP_Error.
	 */
	public static function normalize_domain_contains( $raw ) {
		if ( ! is_string( $raw ) ) {
			return new WP_Error(
				'invalid_domain_contains',
				'domain_contains is required and must be a string',
				array( 'status' => 400 )
			);
		}

		$needle = strtolower( trim( $raw ) );
		if ( '' === $needle ) {
			return new WP_Error(
				'invalid_domain_contains',
				'domain_contains is required',
				array( 'status' => 400 )
			);
		}
		if ( str_contains( $needle, '@' ) ) {
			return new WP_Error(
				'invalid_domain_contains',
				'domain_contains must be a domain substring, not an email address',
				array( 'status' => 400 )
			);
		}
		if ( preg_match( '/\s/', $needle ) ) {
			return new WP_Error(
				'invalid_domain_contains',
				'domain_contains must not contain whitespace',
				array( 'status' => 400 )
			);
		}
		if ( strlen( $needle ) > self::DOMAIN_CONTAINS_MAX_LENGTH ) {
			return new WP_Error(
				'invalid_domain_contains',
				sprintf(
					'domain_contains must be at most %d characters',
					self::DOMAIN_CONTAINS_MAX_LENGTH
				),
				array( 'status' => 400 )
			);
		}
		if ( ! preg_match( '/[a-z0-9]/', $needle ) ) {
			return new WP_Error(
				'invalid_domain_contains',
				'domain_contains must include a letter or digit',
				array( 'status' => 400 )
			);
		}

		return $needle;
	}

	/**
	 * True when the email's domain (after @) contains the needle as a substring.
	 *
	 * @param string $email           Address to inspect.
	 * @param string $domain_contains Normalized or raw needle.
	 */
	public static function email_domain_contains( string $email, string $domain_contains ): bool {
		if ( '' === $email || '' === $domain_contains ) {
			return false;
		}

		$at = strrpos( $email, '@' );
		if ( false === $at || 0 === $at || strlen( $email ) - 1 === $at ) {
			return false;
		}

		$domain = strtolower( trim( substr( $email, $at + 1 ) ) );
		$needle = strtolower( trim( $domain_contains ) );
		if ( '' === $domain || '' === $needle ) {
			return false;
		}

		return str_contains( $domain, $needle );
	}

	/**
	 * True when an Auth user record should be considered for domain matching.
	 *
	 * Mirrors firebase/functions/src/email-domain.ts authUserPassesAudienceFilters.
	 *
	 * @param array  $user   Keys: disabled, email, emailVerified.
	 * @param string $filter verified|unverified|all.
	 */
	public static function auth_user_passes_audience_filters( array $user, string $filter ): bool {
		if ( ! empty( $user['disabled'] ) ) {
			return false;
		}

		$email = isset( $user['email'] ) && is_string( $user['email'] )
			? trim( $user['email'] )
			: '';
		if ( '' === $email ) {
			return false;
		}

		$email_verified = ! empty( $user['emailVerified'] );

		if ( 'verified' === $filter && ! $email_verified ) {
			return false;
		}
		if ( 'unverified' === $filter && $email_verified ) {
			return false;
		}

		return true;
	}

	/**
	 * Slug for the option key (lowercase, alphanumeric + hyphen).
	 *
	 * @param string $needle Normalized domain_contains value.
	 */
	public static function sanitize_needle_slug( string $needle ): string {
		$slug = strtolower( trim( $needle ) );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug ) ?? '';
		return trim( $slug, '-' );
	}

	/**
	 * Default wp_options key: prc_email_audience_auth_domain_{slug}_{fingerprint}_{verification}.
	 *
	 * The slug is human-readable. Distinct needles that collapse to the same slug
	 * (for example k12 vs .k12., or k12.ca.us vs k12-ca-us) still get unique keys
	 * via a fingerprint of the exact needle.
	 *
	 * @param string $domain_contains Normalized needle.
	 * @param string $verification    verified|unverified|all.
	 * @return string|WP_Error
	 */
	public static function option_key( string $domain_contains, string $verification ) {
		$needle = strtolower( trim( $domain_contains ) );
		$slug   = self::sanitize_needle_slug( $needle );
		if ( '' === $slug ) {
			return new WP_Error(
				'invalid_domain_contains',
				'domain_contains must include a letter or digit',
				array( 'status' => 400 )
			);
		}

		$fingerprint = substr( hash( 'sha256', $needle ), 0, 12 );

		return self::AUDIENCE_OPTION_PREFIX . $slug . '_' . $fingerprint . '_' . $verification;
	}
}
