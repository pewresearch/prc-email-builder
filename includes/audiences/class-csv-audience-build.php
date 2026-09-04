<?php
/**
 * Local CSV audience builder. Writes recipient options immediately.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Registers the CSV upload builder and persists parsed emails.
 */
final class Csv_Audience_Build {
	private const AUDIENCE_OPTION_PREFIX = 'prc_email_audience_csv_';

	/**
	 * Register the CSV builder on the audience registry.
	 */
	public static function register_builder(): void {
		Audience_Builder_Registry::register(
			array(
				'slug'                  => 'csv-upload',
				'label'                 => 'CSV upload',
				'description'           => 'Upload a CSV of email addresses.',
				'option_prefix'         => self::AUDIENCE_OPTION_PREFIX,
				'form'                  => 'csv-upload',
				'job_id_prefix'         => 'cs_',
				'supports_create_draft' => true,
				'parse_input'           => array( self::class, 'parse_input' ),
				'import'                => array( self::class, 'import' ),
			)
		);
	}

	/**
	 * Parse REST input for a CSV audience.
	 *
	 * @param array<string, mixed> $input REST input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function parse_input( array $input ): array|WP_Error {
		$label = isset( $input['label'] ) && is_string( $input['label'] )
			? trim( $input['label'] )
			: '';
		if ( '' === $label ) {
			return new WP_Error( 'invalid_query', 'Audience name is required.', array( 'status' => 400 ) );
		}

		$csv    = isset( $input['csv'] ) && is_string( $input['csv'] ) ? $input['csv'] : '';
		$parsed = Csv_Email_List::parse( $csv );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return array(
			'query'  => array(
				'source'  => 'csv_upload',
				'skipped' => $parsed['skipped'],
			),
			'label'  => $label,
			'emails' => $parsed['emails'],
		);
	}

	/**
	 * Persist emails to the audience option pair.
	 *
	 * @param array<string, mixed> $job      Stored WordPress job (no emails).
	 * @param array<string, mixed> $artifact Parsed emails plus metadata.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function import( array $job, array $artifact ): array|WP_Error {
		$emails = $artifact['emails'] ?? null;
		if ( ! is_array( $emails ) || ! array_is_list( $emails ) || array() === $emails ) {
			return new WP_Error( 'csv_no_emails', 'The CSV file does not contain any valid email addresses.', array( 'status' => 400 ) );
		}

		$validated = array();
		foreach ( $emails as $email ) {
			if ( ! is_string( $email ) ) {
				return new WP_Error( 'csv_no_emails', 'The CSV file does not contain any valid email addresses.', array( 'status' => 400 ) );
			}
			$normalized = strtolower( trim( $email ) );
			if ( ! is_email( $normalized ) ) {
				continue;
			}
			$validated[ $normalized ] = $normalized;
		}
		$validated = array_values( $validated );
		if ( array() === $validated ) {
			return new WP_Error( 'csv_no_emails', 'The CSV file does not contain any valid email addresses.', array( 'status' => 400 ) );
		}

		$audience_key = self::AUDIENCE_OPTION_PREFIX . $job['jobId'];
		$built_at     = isset( $artifact['builtAt'] ) && is_string( $artifact['builtAt'] )
			? $artifact['builtAt']
			: gmdate( 'c' );
		$skipped      = isset( $artifact['skipped'] ) ? (int) $artifact['skipped'] : 0;
		$meta         = array(
			'label'    => $job['label'],
			'count'    => count( $validated ),
			'built_at' => $built_at,
			'source'   => 'csv_upload',
			'skipped'  => $skipped,
		);

		if ( ! add_option( $audience_key, $validated, '', false ) && null === get_option( $audience_key, null ) ) {
			return new WP_Error( 'csv_save_failed', 'Could not save the audience option.', array( 'status' => 500 ) );
		}

		$meta_key = $audience_key . '_meta';
		if ( ! add_option( $meta_key, $meta, '', false ) && null === get_option( $meta_key, null ) ) {
			return new WP_Error( 'csv_save_failed', 'Could not save the audience metadata.', array( 'status' => 500 ) );
		}

		return array(
			'key'     => $audience_key,
			'label'   => $job['label'],
			'count'   => count( $validated ),
			'builtAt' => $built_at,
		);
	}

	/**
	 * Register the builder if tests invoke this class first.
	 */
	public static function ensure_registered(): void {
		if ( null === Audience_Builder_Registry::get( 'csv-upload' ) ) {
			self::register_builder();
		}
	}
}
