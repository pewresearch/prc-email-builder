<?php
/**
 * Parse a CSV (or newline) list of recipient email addresses.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Boundary parser for CSV audience uploads.
 */
final class Csv_Email_List {
	public const MAX_BYTES  = 2097152;
	public const MAX_EMAILS = 25000;

	/**
	 * Extract unique valid emails from CSV text.
	 *
	 * @param string $csv Raw file contents.
	 * @return array{emails: list<string>, skipped: int}|WP_Error
	 */
	public static function parse( string $csv ): array|WP_Error {
		if ( strlen( $csv ) > self::MAX_BYTES ) {
			return new WP_Error(
				'csv_too_large',
				'The CSV file is larger than 2 MB.',
				array( 'status' => 400 )
			);
		}

		$csv = preg_replace( '/^\xEF\xBB\xBF/', '', $csv ) ?? $csv;
		$csv = str_replace( array( "\r\n", "\r" ), "\n", $csv );
		if ( '' === trim( $csv ) ) {
			return new WP_Error( 'csv_empty', 'The CSV file is empty.', array( 'status' => 400 ) );
		}

		$rows = array();
		foreach ( explode( "\n", $csv ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$rows[] = str_getcsv( $line, ',', '"', '' );
		}

		if ( array() === $rows ) {
			return new WP_Error( 'csv_empty', 'The CSV file is empty.', array( 'status' => 400 ) );
		}

		$header_column = self::email_column_index( $rows[0] );
		$start         = 0;
		$column        = $header_column;
		if ( null !== $header_column ) {
			$start = 1;
		} elseif ( ! self::row_has_at( $rows[0] ) ) {
			$start  = 1;
			$column = 0;
		}

		$emails  = array();
		$skipped = 0;

		for ( $i = $start, $count = count( $rows ); $i < $count; $i++ ) {
			$cells = null !== $column
				? array( $rows[ $i ][ $column ] ?? '' )
				: $rows[ $i ];

			foreach ( $cells as $cell ) {
				if ( ! is_string( $cell ) || '' === trim( $cell ) ) {
					continue;
				}
				foreach ( self::split_cell( $cell ) as $token ) {
					$normalized = strtolower( trim( $token ) );
					if ( '' === $normalized ) {
						continue;
					}
					if ( ! is_email( $normalized ) ) {
						++$skipped;
						continue;
					}
					if ( isset( $emails[ $normalized ] ) ) {
						continue;
					}
					if ( count( $emails ) >= self::MAX_EMAILS ) {
						return new WP_Error(
							'csv_too_many',
							'The CSV file has more than 25,000 unique email addresses.',
							array( 'status' => 400 )
						);
					}
					$emails[ $normalized ] = $normalized;
				}
			}
		}

		if ( array() === $emails ) {
			return new WP_Error(
				'csv_no_emails',
				'The CSV file does not contain any valid email addresses.',
				array( 'status' => 400 )
			);
		}

		return array(
			'emails'  => array_values( $emails ),
			'skipped' => $skipped,
		);
	}

	/**
	 * Index of an email-named header cell, if present.
	 *
	 * @param array<int, mixed> $header First CSV row.
	 */
	private static function email_column_index( array $header ): ?int {
		foreach ( $header as $index => $cell ) {
			if ( ! is_string( $cell ) ) {
				continue;
			}
			$normalized = strtolower( trim( $cell ) );
			$normalized = str_replace( array( '_', '-' ), ' ', $normalized );
			if ( in_array( $normalized, array( 'email', 'e mail', 'email address' ), true ) ) {
				return (int) $index;
			}
		}

		return null;
	}

	/**
	 * Whether any cell looks like it contains an email.
	 *
	 * @param array<int, mixed> $row CSV row.
	 */
	private static function row_has_at( array $row ): bool {
		foreach ( $row as $cell ) {
			if ( is_string( $cell ) && str_contains( $cell, '@' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Split a cell on common list separators.
	 *
	 * @param string $cell CSV cell text.
	 * @return list<string>
	 */
	private static function split_cell( string $cell ): array {
		$parts = preg_split( '/[;\s]+/', $cell, -1, PREG_SPLIT_NO_EMPTY );

		return false === $parts ? array() : $parts;
	}
}
