<?php
/**
 * Mandrill activity export helpers for audience backfill.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Requests, polls, downloads, and parses Mandrill activity exports.
 */
class Mandrill_Activity_Export {

	const API_URL          = 'https://mandrillapp.com/api/1.0/';
	const API_KEY_CONSTANT = 'PRC_PLATFORM_MANDRILL_KEY';

	const DEFAULT_POLL_INTERVAL = 5;
	const DEFAULT_POLL_TIMEOUT  = 600;

	/**
	 * CSV header aliases for the recipient email column, highest priority first.
	 *
	 * @var array<int, string>
	 */
	const EMAIL_COLUMN_ALIASES = array( 'recipient', 'email address', 'email', 'to' );

	/**
	 * Resolve the Mandrill API key from the platform constant.
	 */
	public static function get_api_key(): string {
		if ( defined( self::API_KEY_CONSTANT ) ) {
			return (string) constant( self::API_KEY_CONSTANT );
		}

		return '';
	}

	/**
	 * Begin a Mandrill activity export job.
	 *
	 * @param array<string, mixed> $params Export parameters (date_from, date_to, tags, states, etc.).
	 * @return string|WP_Error Job id on success.
	 */
	public static function request_export( array $params ): string|WP_Error {
		$api_key = self::get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'mandrill_not_configured', 'Mandrill API key is not set.' );
		}

		$payload = array_merge( array( 'key' => $api_key ), $params );
		$result  = self::post_json( 'exports/activity', $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$id = isset( $result['id'] ) ? (string) $result['id'] : '';
		if ( '' === $id ) {
			return new WP_Error( 'mandrill_invalid_response', 'Mandrill did not return an export job id.' );
		}

		return $id;
	}

	/**
	 * Poll an export job until it completes or times out.
	 *
	 * @param string $job_id           Export job id.
	 * @param int    $timeout_seconds  Maximum wait time.
	 * @param int    $interval_seconds Sleep between polls.
	 * @return array<string, mixed>|WP_Error Completed export info including result_url.
	 */
	public static function poll_export(
		string $job_id,
		int $timeout_seconds = self::DEFAULT_POLL_TIMEOUT,
		int $interval_seconds = self::DEFAULT_POLL_INTERVAL
	): array|WP_Error {
		$api_key = self::get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'mandrill_not_configured', 'Mandrill API key is not set.' );
		}

		$deadline = time() + max( 1, $timeout_seconds );

		while ( time() < $deadline ) {
			$result = self::post_json(
				'exports/info',
				array(
					'key' => $api_key,
					'id'  => $job_id,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$state = (string) ( $result['state'] ?? '' );
			if ( 'complete' === $state ) {
				$result_url = (string) ( $result['result_url'] ?? '' );
				if ( '' === $result_url ) {
					return new WP_Error( 'mandrill_invalid_response', 'Mandrill export completed without a result_url.' );
				}

				return $result;
			}

			if ( in_array( $state, array( 'failed', 'error' ), true ) ) {
				return new WP_Error(
					'mandrill_export_failed',
					sprintf( 'Mandrill export job failed (state: %s).', $state )
				);
			}

			sleep( max( 1, $interval_seconds ) );
		}

		return new WP_Error( 'mandrill_export_timeout', 'Timed out waiting for Mandrill export to complete.' );
	}

	/**
	 * Download a Mandrill export zip and extract activity.csv to a temp path.
	 *
	 * @param string $result_url Signed Mandrill export URL.
	 * @return string|WP_Error Path to extracted activity.csv.
	 */
	public static function download_activity_csv( string $result_url ): string|WP_Error {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$zip_path = wp_tempnam( 'mandrill-activity-export.zip' );
		if ( false === $zip_path ) {
			return new WP_Error( 'temp_file_failed', 'Could not create a temporary file for the Mandrill export.' );
		}

		$response = wp_remote_get(
			$result_url,
			array(
				'timeout'  => 120, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Mandrill zip export is a long-running job.
				'stream'   => true,
				'filename' => $zip_path,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $zip_path );
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 400 ) {
			wp_delete_file( $zip_path );
			return new WP_Error( 'mandrill_download_failed', sprintf( 'Mandrill export download failed (HTTP %d).', $status_code ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_delete_file( $zip_path );
			return new WP_Error( 'zip_unavailable', 'ZipArchive is required to extract Mandrill activity exports.' );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			wp_delete_file( $zip_path );
			return new WP_Error( 'zip_open_failed', 'Could not open the Mandrill export zip archive.' );
		}

		$csv_index = $zip->locateName( 'activity.csv', \ZipArchive::FL_NOCASE );
		if ( false === $csv_index ) {
			$csv_index = 0;
		}

		$csv_path = wp_tempnam( 'mandrill-activity.csv' );
		if ( false === $csv_path ) {
			$zip->close();
			wp_delete_file( $zip_path );
			return new WP_Error( 'temp_file_failed', 'Could not create a temporary CSV file.' );
		}

		$contents = $zip->getFromIndex( $csv_index );
		$zip->close();
		wp_delete_file( $zip_path );

		if ( false === $contents || '' === $contents ) {
			wp_delete_file( $csv_path );
			return new WP_Error( 'csv_missing', 'Mandrill export zip did not contain activity.csv.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $csv_path, $contents ) ) {
			wp_delete_file( $csv_path );
			return new WP_Error( 'csv_write_failed', 'Could not write the extracted activity.csv.' );
		}

		return $csv_path;
	}

	/**
	 * Parse a Mandrill activity.csv and return deduped recipient emails.
	 *
	 * @param string      $csv_path         Path to activity.csv.
	 * @param string|null $subject_pattern  Optional PCRE applied to the Subject column.
	 * @return array{emails: string[], rows_scanned: int, rows_matched: int}|WP_Error
	 */
	public static function parse_activity_csv( string $csv_path, ?string $subject_pattern = null ): array|WP_Error {
		if ( ! is_readable( $csv_path ) ) {
			return new WP_Error( 'csv_unreadable', 'Mandrill activity CSV is not readable.' );
		}

		$handle = fopen( $csv_path, 'rb' );
		if ( false === $handle ) {
			return new WP_Error( 'csv_open_failed', 'Could not open Mandrill activity CSV.' );
		}

		$header = self::read_csv_row( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle );
			return new WP_Error( 'csv_invalid', 'Mandrill activity CSV is missing a header row.' );
		}

		$email_index   = self::find_column_index( $header, self::EMAIL_COLUMN_ALIASES );
		$subject_index = self::find_column_index( $header, array( 'subject' ) );

		if ( null === $email_index ) {
			fclose( $handle );
			return new WP_Error(
				'csv_invalid',
				sprintf(
					'Mandrill activity CSV has no recognized recipient column. Found: %s',
					self::format_header_list( $header )
				)
			);
		}

		if ( null !== $subject_pattern && null === $subject_index ) {
			fclose( $handle );
			return new WP_Error(
				'csv_invalid',
				sprintf(
					'Mandrill activity CSV has no Subject column required for --subject-match. Found: %s',
					self::format_header_list( $header )
				)
			);
		}

		$emails       = array();
		$rows_scanned = 0;
		$rows_matched = 0;

		while ( ( $row = self::read_csv_row( $handle ) ) !== false ) {
			++$rows_scanned;

			if ( null !== $subject_pattern && null !== $subject_index ) {
				$subject = (string) ( $row[ $subject_index ] ?? '' );
				if ( 1 !== preg_match( $subject_pattern, $subject ) ) {
					continue;
				}
			}

			$email = strtolower( trim( (string) ( $row[ $email_index ] ?? '' ) ) );
			if ( '' === $email || ! is_email( $email ) ) {
				continue;
			}

			$emails[ $email ] = $email;
			++$rows_matched;
		}

		fclose( $handle );

		return array(
			'emails'       => array_values( $emails ),
			'rows_scanned' => $rows_scanned,
			'rows_matched' => $rows_matched,
		);
	}

	/**
	 * Locate a CSV column index by case-insensitive header aliases.
	 *
	 * @param array<int, string> $header  Header row cells.
	 * @param array<int, string> $aliases Candidate header names.
	 */
	public static function find_column_index( array $header, array $aliases ): ?int {
		foreach ( $aliases as $alias ) {
			$normalized_alias = self::normalize_header_label( (string) $alias );

			foreach ( $header as $index => $label ) {
				if ( self::normalize_header_label( (string) $label ) === $normalized_alias ) {
					return (int) $index;
				}
			}
		}

		return null;
	}

	/**
	 * Read one CSV row with an explicit escape character (PHP 8.4+).
	 *
	 * @param resource $handle Open CSV file handle.
	 * @return array<int, string>|false
	 */
	private static function read_csv_row( $handle ): array|false {
		return fgetcsv( $handle, 0, ',', '"', '\\' );
	}

	/**
	 * Normalize a CSV header label for alias matching.
	 *
	 * @param string $label Label.
	 */
	private static function normalize_header_label( string $label ): string {
		$label = trim( $label );

		if ( str_starts_with( $label, "\xEF\xBB\xBF" ) ) {
			$label = substr( $label, 3 );
		}

		if ( strlen( $label ) >= 2 && '"' === $label[0] && '"' === $label[ strlen( $label ) - 1 ] ) {
			$label = substr( $label, 1, -1 );
		}

		return strtolower( trim( $label ) );
	}

	/**
	 * Format detected CSV headers for error messages.
	 *
	 * @param array<int, string> $header Header row cells.
	 */
	private static function format_header_list( array $header ): string {
		$labels = array_map(
			static fn( $label ): string => trim( (string) $label ),
			$header
		);

		return implode( ', ', $labels );
	}

	/**
	 * Normalize a CLI date flag to Mandrill's UTC datetime format.
	 *
	 * @param string $raw Raw.
	 * @param bool   $end_of_day End of day.
	 */
	public static function normalize_export_datetime( string $raw, bool $end_of_day = false ): string|WP_Error {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return new WP_Error( 'invalid_date', 'Date value is required.' );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return $end_of_day ? $raw . ' 23:59:59' : $raw . ' 00:00:00';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw ) ) {
			return $raw;
		}

		return new WP_Error(
			'invalid_date',
			'Dates must be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS (UTC).'
		);
	}

	/**
	 * POST JSON to a Mandrill API endpoint.
	 *
	 * @param string               $endpoint API path without .json suffix.
	 * @param array<string, mixed> $payload  Request body.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function post_json( string $endpoint, array $payload ): array|WP_Error {
		$response = wp_remote_post(
			self::API_URL . $endpoint . '.json',
			array(
				'timeout' => 60, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Mandrill export API can exceed the default 5s.
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code >= 400 ) {
			$detail = is_array( $body )
				? (string) ( $body['message'] ?? $body['name'] ?? "HTTP {$status_code}" )
				: "HTTP {$status_code}";

			return new WP_Error( 'mandrill_api_error', $detail );
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'mandrill_invalid_response', 'Mandrill returned an invalid JSON response.' );
		}

		return $body;
	}
}
