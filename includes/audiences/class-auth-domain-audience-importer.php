<?php
/**
 * Imports a validated auth-domain audience artifact once.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Auth Domain Audience Importer class.
 */
final class Auth_Domain_Audience_Importer {
	/**
	 * Import.
	 *
	 * @param array<string, mixed> $job      Stored WordPress job.
	 * @param array<string, mixed> $artifact Decoded Cloud Storage artifact.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function import( array $job, array $artifact ): array|WP_Error {
		$validated = self::validate_artifact( $job, $artifact );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$audience_key = Auth_Domain_Audience_Build::audience_key( $job['jobId'] );
		$meta         = array(
			'label'           => $job['label'],
			'count'           => count( $validated ),
			'verification'    => $job['query']['verification'],
			'domain_contains' => $job['query']['domainContains'],
			'built_at'        => $artifact['builtAt'],
			'source'          => 'firebase_auth_domain',
		);

		if ( ! add_option( $audience_key, $validated, '', false ) && null === get_option( $audience_key, null ) ) {
			return self::artifact_error( 'Could not save the audience option.' );
		}

		$meta_key = $audience_key . '_meta';
		if ( ! add_option( $meta_key, $meta, '', false ) && null === get_option( $meta_key, null ) ) {
			return self::artifact_error( 'Could not save the audience metadata.' );
		}

		return array(
			'key'          => $audience_key,
			'label'        => $job['label'],
			'count'        => count( $validated ),
			'verification' => $job['query']['verification'],
			'builtAt'      => $artifact['builtAt'],
		);
	}

	/**
	 * Validate artifact.
	 *
	 * @param array<string, mixed> $job      Stored WordPress job.
	 * @param array<string, mixed> $artifact Decoded artifact.
	 * @return string[]|WP_Error
	 */
	private static function validate_artifact( array $job, array $artifact ): array|WP_Error {
		if (
			1 !== ( $artifact['schemaVersion'] ?? null )
			|| ( $artifact['jobId'] ?? null ) !== $job['jobId']
			|| ! isset( $artifact['query'] )
			|| ! is_array( $artifact['query'] )
			|| ( $artifact['query']['domainContains'] ?? null ) !== $job['query']['domainContains']
		) {
			return self::artifact_error( 'The audience artifact does not match this job.' );
		}

		if ( ( $artifact['query']['verification'] ?? null ) !== $job['query']['verification'] ) {
			return new WP_Error(
				'verification_mismatch',
				'The audience artifact verification mode does not match this job.',
				array( 'status' => 502 )
			);
		}

		$emails = $artifact['emails'] ?? null;
		if (
			! is_array( $emails )
			|| ! array_is_list( $emails )
			|| ! isset( $artifact['count'] )
			|| ! is_int( $artifact['count'] )
			|| count( $emails ) !== $artifact['count']
			|| ! isset( $artifact['builtAt'] )
			|| ! is_string( $artifact['builtAt'] )
		) {
			return self::artifact_error( 'The audience artifact has an invalid shape.' );
		}

		$validated = array();
		foreach ( $emails as $email ) {
			if ( ! is_string( $email ) ) {
				return self::artifact_error( 'The audience artifact contains an invalid email.' );
			}

			$normalized = strtolower( trim( $email ) );
			if ( ! is_email( $normalized ) ) {
				continue;
			}
			if ( ! Domain_Contains_Query::email_domain_contains(
				$normalized,
				$job['query']['domainContains']
			) ) {
				return self::artifact_error( 'The audience artifact contains an email outside the requested domain.' );
			}
			if ( isset( $validated[ $normalized ] ) ) {
				return self::artifact_error( 'The audience artifact contains duplicate emails.' );
			}

			$validated[ $normalized ] = $normalized;
		}

		return array_values( $validated );
	}

	/**
	 * Artifact error.
	 *
	 * @param string $message Message.
	 */
	private static function artifact_error( string $message ): WP_Error {
		return new WP_Error( 'artifact_invalid', $message, array( 'status' => 502 ) );
	}
}
