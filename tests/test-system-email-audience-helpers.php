<?php
declare(strict_types=1);
/**
 * Coverage for Mandrill activity CSV parsing and audience helper utilities.
 *
 * Run with:
 *   php plugins/prc-email-builder/tests/test-system-email-audience-helpers.php
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/../../../../' );
	}

	function is_email( string $email ): bool {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	class WP_Error {
		public function __construct(
			private readonly string $code,
			private readonly string $message
		) {}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}

	require_once dirname( __DIR__ ) . '/includes/mandrill/class-mandrill-activity-export.php';

	use PRC\Platform\Email_Builder\Mandrill_Activity_Export;

	function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	function assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			fwrite(
				STDERR,
				sprintf(
					"FAIL: %s (expected %s, got %s)\n",
					$message,
					var_export( $expected, true ),
					var_export( $actual, true )
				)
			);
			exit( 1 );
		}
	}

	function assert_contains( string $needle, string $haystack, string $message ): void {
		if ( ! str_contains( $haystack, $needle ) ) {
			fwrite(
				STDERR,
				sprintf(
					"FAIL: %s (expected substring %s in %s)\n",
					$message,
					var_export( $needle, true ),
					var_export( $haystack, true )
				)
			);
			exit( 1 );
		}
	}

	$legacy_csv_path = sys_get_temp_dir() . '/mandrill-activity-legacy-test.csv';
	$legacy_csv_body = <<<CSV
Date,Email Address,Sender,Subject,Status,Tags,Opens,Clicks,Bounce Detail
2026-06-01 10:00:00,Alpha@Example.com,sender@example.com,Your typology result,sent,system-email,1,0,
2026-06-01 10:05:00,beta@example.com,sender@example.com,Other subject,sent,system-email,0,0,
2026-06-01 10:10:00,alpha@example.com,sender@example.com,Your typology result,sent,system-email,0,0,
CSV;
	file_put_contents( $legacy_csv_path, $legacy_csv_body );

	$parsed = Mandrill_Activity_Export::parse_activity_csv( $legacy_csv_path, '/typology/i' );
	assert_true( ! is_wp_error( $parsed ), 'Legacy Email Address CSV parsing must succeed.' );
	assert_same( 3, $parsed['rows_scanned'], 'Legacy parser must scan all data rows.' );
	assert_same( 2, $parsed['rows_matched'], 'Legacy subject filter must match two rows before dedupe.' );
	assert_same( 1, count( $parsed['emails'] ), 'Legacy duplicate addresses must dedupe to one email.' );
	assert_same( 'alpha@example.com', $parsed['emails'][0], 'Legacy emails must be lowercased.' );

	$recipient_csv_path = sys_get_temp_dir() . '/mandrill-activity-recipient-test.csv';
	$recipient_csv_body = <<<CSV
Date,Recipient,Sender,Subject,Status,Channel,Tags,Opens,Clicks,"Bounce Detail"
2026-06-01 10:00:00,Alpha@Example.com,sender@example.com,Quiz result: Leftward Progressives,sent,email,prc-newsletter;system-email,1,0,
2026-06-01 10:05:00,beta@example.com,sender@example.com,Other subject,sent,email,prc-newsletter;system-email,0,0,
2026-06-01 10:10:00,alpha@example.com,sender@example.com,Quiz result: Ambivalent Right,sent,email,prc-newsletter;system-email,0,0,
CSV;
	file_put_contents( $recipient_csv_path, $recipient_csv_body );

	$recipient_parsed = Mandrill_Activity_Export::parse_activity_csv( $recipient_csv_path, '/Quiz result/i' );
	assert_true( ! is_wp_error( $recipient_parsed ), 'Recipient-column CSV parsing must succeed.' );
	assert_same( 3, $recipient_parsed['rows_scanned'], 'Recipient parser must scan all data rows.' );
	assert_same( 2, $recipient_parsed['rows_matched'], 'Recipient subject filter must match two rows before dedupe.' );
	assert_same( 1, count( $recipient_parsed['emails'] ), 'Recipient duplicate addresses must dedupe to one email.' );
	assert_same( 'alpha@example.com', $recipient_parsed['emails'][0], 'Recipient emails must be lowercased.' );

	$bad_csv_path = sys_get_temp_dir() . '/mandrill-activity-bad-header-test.csv';
	file_put_contents(
		$bad_csv_path,
		"Date,Sender,Subject,Status\n2026-06-01 10:00:00,sender@example.com,Hello,sent\n"
	);

	$bad_parsed = Mandrill_Activity_Export::parse_activity_csv( $bad_csv_path );
	assert_true( is_wp_error( $bad_parsed ), 'Missing recipient column must return WP_Error.' );
	assert_contains(
		'Found: Date, Sender, Subject, Status',
		$bad_parsed->get_error_message(),
		'Missing recipient column error must list detected headers.'
	);

	$header = [ 'Date', 'Email Address', 'Subject' ];
	assert_same( 1, Mandrill_Activity_Export::find_column_index( $header, [ 'email address' ] ), 'Header lookup must be case-insensitive.' );
	assert_same( 2, Mandrill_Activity_Export::find_column_index( $header, [ 'subject' ] ), 'Subject column must be discoverable.' );
	assert_same(
		1,
		Mandrill_Activity_Export::find_column_index( $header, Mandrill_Activity_Export::EMAIL_COLUMN_ALIASES ),
		'EMAIL_COLUMN_ALIASES must resolve the legacy email column.'
	);

	$recipient_header = [ 'Date', 'Recipient', 'Subject' ];
	assert_same(
		1,
		Mandrill_Activity_Export::find_column_index( $recipient_header, Mandrill_Activity_Export::EMAIL_COLUMN_ALIASES ),
		'EMAIL_COLUMN_ALIASES must resolve the Recipient column.'
	);

	$date = Mandrill_Activity_Export::normalize_export_datetime( '2026-05-01' );
	assert_true( ! is_wp_error( $date ), 'Date-only values must normalize.' );
	assert_same( '2026-05-01 00:00:00', $date, 'Date-only start values must use midnight UTC.' );

	$end_date = Mandrill_Activity_Export::normalize_export_datetime( '2026-05-01', true );
	assert_same( '2026-05-01 23:59:59', $end_date, 'Date-only end values must use end-of-day UTC.' );

	unlink( $legacy_csv_path );
	unlink( $recipient_csv_path );
	unlink( $bad_csv_path );

	fwrite( STDOUT, "OK: test-system-email-audience-helpers.php\n" );
}
