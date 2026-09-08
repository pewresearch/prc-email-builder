<?php
/**
 * Auth-domain audience query parsing and matching.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Domain Contains Query class.
 */
final class Domain_Contains_Query {
	private const NEEDLE_PATTERN = '/^[a-z0-9][a-z0-9.-]{1,62}$/';

	/**
	 * Parse.
	 *
	 * @param array<string, mixed> $input Raw query fields.
	 * @return array{domainContains: string, verification: string}|WP_Error
	 */
	public static function parse( array $input ): array|WP_Error {
		$needle = self::parse_needle( $input['domainContains'] ?? '' );
		if ( is_wp_error( $needle ) ) {
			return $needle;
		}

		$verification = self::parse_verification( $input['verification'] ?? 'verified' );
		if ( is_wp_error( $verification ) ) {
			return $verification;
		}

		return array(
			'domainContains' => $needle,
			'verification'   => $verification,
		);
	}

	/**
	 * Parse needle.
	 *
	 * @param mixed $raw Raw.
	 */
	public static function parse_needle( mixed $raw ): string|WP_Error {
		if ( ! is_string( $raw ) ) {
			return self::invalid_query( 'Domain contains must be a string.' );
		}

		$needle = strtolower( trim( $raw ) );
		if ( ! preg_match( self::NEEDLE_PATTERN, $needle ) ) {
			return self::invalid_query(
				'Domain contains must use 2 to 63 ASCII hostname characters and cannot contain @.'
			);
		}

		return $needle;
	}

	/**
	 * Parse verification.
	 *
	 * @param mixed $raw Raw.
	 */
	public static function parse_verification( mixed $raw ): string|WP_Error {
		if ( ! is_string( $raw ) || ! in_array( $raw, array( 'verified', 'unverified', 'all' ), true ) ) {
			return self::invalid_query( 'Verification must be verified, unverified, or all.' );
		}

		return $raw;
	}

	/**
	 * Email domain contains.
	 *
	 * @param string $email Email.
	 * @param string $needle Needle.
	 */
	public static function email_domain_contains( string $email, string $needle ): bool {
		if ( preg_match( '/\s/', $email ) ) {
			return false;
		}

		$separator = strrpos( $email, '@' );
		if ( false === $separator || 0 === $separator || strlen( $email ) - 1 === $separator ) {
			return false;
		}

		$domain = substr( $email, $separator + 1 );

		return str_contains( strtolower( $domain ), strtolower( $needle ) );
	}

	/**
	 * Invalid query.
	 *
	 * @param string $message Message.
	 */
	private static function invalid_query( string $message ): WP_Error {
		return new WP_Error( 'invalid_query', $message, array( 'status' => 400 ) );
	}
}
