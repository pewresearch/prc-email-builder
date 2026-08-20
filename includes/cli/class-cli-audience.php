<?php
declare(strict_types=1);
/**
 * WP-CLI commands for managing system-email (Mandrill) newsletter audiences.
 *
 * Audiences are stored as wp_options pairs:
 *   prc_email_audience_{key}      — array of email strings
 *   prc_email_audience_{key}_meta — label, count, built_at, etc.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use PRC\Platform\CLI_Audience_Verification;
use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( ! trait_exists( '\\PRC\\Platform\\CLI_Audience_Verification' ) ) {
	require_once dirname( __DIR__, 3 ) . '/prc-firebase/includes/trait-cli-audience-verification.php';
}

/**
 * Create, list, and delete system-email audience lists for Mandrill newsletters.
 */
class CLI_Audience {

	use CLI_Audience_Verification;

	/**
	 * wp_options key prefix for audience email lists.
	 */
	const AUDIENCE_OPTION_PREFIX        = 'prc_email_audience_';
	const LEGACY_AUDIENCE_OPTION_PREFIX = 'prc_newsletter_audience_';

	/**
	 * Create or replace a test audience from comma-separated email addresses.
	 *
	 * ## OPTIONS
	 *
	 * --emails=<list>
	 * : Comma-separated email addresses.
	 *
	 * [--label=<text>]
	 * : Human-readable label for the sidebar picker. Default: "Test audience".
	 *
	 * [--key=<slug>]
	 * : Slug appended to the option prefix. Default: "test" (key: prc_email_audience_test).
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email audience create --emails=you@example.com,qa@example.com
	 *
	 *     wp prc email audience create --emails=you@example.com --label="QA Mandrill test" --key=qa
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function create( $args, $assoc_args ): void {
		$raw_emails = Utils\get_flag_value( $assoc_args, 'emails', '' );
		$label      = Utils\get_flag_value( $assoc_args, 'label', 'Test audience' );
		$key_slug   = Utils\get_flag_value( $assoc_args, 'key', 'test' );

		if ( '' === trim( (string) $raw_emails ) ) {
			WP_CLI::error( '--emails is required (comma-separated list).' );
		}

		$parsed = $this->parse_and_validate_emails( (string) $raw_emails );
		if ( empty( $parsed['valid'] ) ) {
			WP_CLI::error( 'No valid email addresses found.' );
		}

		foreach ( $parsed['invalid'] as $invalid ) {
			WP_CLI::warning( sprintf( 'Skipping invalid email: %s', $invalid ) );
		}

		$audience_key = $this->normalize_audience_key( (string) $key_slug );
		$meta_key     = $audience_key . '_meta';
		$emails       = $parsed['valid'];
		$count        = count( $emails );

		$existing = get_option( $audience_key, false );
		if ( false !== $existing && is_array( $existing ) && ! empty( $existing ) ) {
			WP_CLI::warning( sprintf(
				'Overwriting existing audience "%s" (%d email(s)).',
				$audience_key,
				count( $existing )
			) );
		}

		$built_at = current_time( 'mysql', true );

		update_option( $audience_key, $emails, false );
		update_option(
			$meta_key,
			array(
				'label'    => $label,
				'count'    => $count,
				'built_at' => $built_at,
				'source'   => 'cli_test',
			),
			false
		);

		WP_CLI::success( sprintf(
			'Audience saved → %s (%d email(s), label: "%s")',
			$audience_key,
			$count,
			$label
		) );
	}

	/**
	 * Delete a system-email audience and its meta companion.
	 *
	 * ## OPTIONS
	 *
	 * --key=<key>
	 * : Full option key (e.g. prc_email_audience_test) or slug only (e.g. test).
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email audience delete --key=test
	 *
	 *     wp prc email audience delete --key=prc_email_audience_test --yes
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ): void {
		$key_raw = Utils\get_flag_value( $assoc_args, 'key', '' );
		$yes     = Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( '' === trim( (string) $key_raw ) ) {
			WP_CLI::error( '--key is required.' );
		}

		$audience_key = $this->normalize_audience_key( (string) $key_raw );
		$meta_key     = $audience_key . '_meta';

		$existing = get_option( $audience_key, false );
		if ( false === $existing ) {
			WP_CLI::error( sprintf( 'Audience option "%s" does not exist.', $audience_key ) );
		}

		$referencing = $this->find_newsletters_using_audience( $audience_key );
		if ( ! empty( $referencing ) ) {
			WP_CLI::warning( sprintf(
				'%d newsletter post(s) reference this audience: %s',
				count( $referencing ),
				implode( ', ', $referencing )
			) );
		}

		if ( ! $yes ) {
			WP_CLI::confirm( sprintf( 'Delete audience "%s" and its meta?', $audience_key ) );
		}

		delete_option( $audience_key );
		delete_option( $meta_key );

		WP_CLI::success( sprintf( 'Deleted audience "%s".', $audience_key ) );
	}

	/**
	 * List all system-email audiences stored in wp_options.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email audience list
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function list( $args, $assoc_args ): void {
		$audiences = $this->fetch_audience_meta_rows();

		if ( empty( $audiences ) ) {
			WP_CLI::warning( 'No system-email audiences found.' );
			return;
		}

		$rows = array_map(
			static function ( array $audience ): array {
				return array(
					'key'      => $audience['key'],
					'label'    => $audience['label'],
					'count'    => $audience['count'],
					'built_at' => $audience['built_at'] ?? '—',
				);
			},
			$audiences
		);

		Utils\format_items( 'table', $rows, array( 'key', 'label', 'count', 'built_at' ) );
		WP_CLI::success( sprintf( '%d audience(s) found.', count( $rows ) ) );
	}

	/**
	 * Build an audience from a Mandrill activity export (historical backfill).
	 *
	 * ## OPTIONS
	 *
	 * --key=<slug>
	 * : Audience option slug (stored as prc_email_audience_{slug}).
	 *
	 * --date-from=<date>
	 * : UTC start date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS). Required for API exports; optional when --csv-file is set.
	 *
	 * [--date-to=<date>]
	 * : UTC end date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS). Defaults to now. Ignored when --csv-file is set.
	 *
	 * [--tag=<tag>]
	 * : Mandrill tag filter for API exports. Default: system-email.
	 *
	 * [--states=<list>]
	 * : Comma-separated Mandrill delivery states for API exports. Default: sent.
	 *
	 * [--subject-match=<regex>]
	 * : Optional PCRE applied to the CSV Subject column after export (post-export refinement).
	 *
	 * [--csv-file=<path>]
	 * : Parse a local Mandrill activity.csv instead of requesting an API export.
	 *
	 * [--label=<text>]
	 * : Human-readable audience label.
	 *
	 * [--dry-run]
	 * : Report counts without writing wp_options or creating a draft post.
	 *
	 * [--create-post]
	 * : Create a draft prc_email_txn newsletter targeting the audience.
	 *
	 * ## EXAMPLES
	 *
	 *     # API export: --tag=system-email scopes typology system-email sends; no subject filter needed.
	 *     wp prc email audience build-from-mandrill \
	 *       --key=typology-2026-requesters \
	 *       --date-from=2026-05-01 \
	 *       --label="Political typology system-email requesters" \
	 *       --dry-run
	 *
	 *     # Local CSV import (e.g. UI export already filtered by tag):
	 *     wp prc email audience build-from-mandrill \
	 *       --key=typology-2026-requesters \
	 *       --csv-file=/path/to/mandrill_activity.csv \
	 *       --label="Political typology system-email requesters"
	 *
	 *     # Optional post-export subject refinement (typology subjects are "Quiz result: …"):
	 *     wp prc email audience build-from-mandrill \
	 *       --key=typology-2026-requesters \
	 *       --csv-file=/path/to/mandrill_activity.csv \
	 *       --subject-match='/Quiz result/i' \
	 *       --dry-run
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function build_from_mandrill( $args, $assoc_args ): void {
		System_Email_Recipients_Table::maybe_create_table();

		$key_slug       = Utils\get_flag_value( $assoc_args, 'key', '' );
		$date_from_raw  = Utils\get_flag_value( $assoc_args, 'date-from', '' );
		$date_to_raw    = Utils\get_flag_value( $assoc_args, 'date-to', '' );
		$tag            = Utils\get_flag_value( $assoc_args, 'tag', 'system-email' );
		$states_raw     = Utils\get_flag_value( $assoc_args, 'states', 'sent' );
		$subject_match  = Utils\get_flag_value( $assoc_args, 'subject-match', null );
		$csv_file       = Utils\get_flag_value( $assoc_args, 'csv-file', '' );
		$label          = Utils\get_flag_value( $assoc_args, 'label', '' );
		$dry_run        = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$create_post    = (bool) Utils\get_flag_value( $assoc_args, 'create-post', false );

		if ( '' === trim( (string) $key_slug ) ) {
			WP_CLI::error( '--key is required.' );
		}

		$csv_file_path = trim( (string) $csv_file );
		$use_local_csv = '' !== $csv_file_path;

		if ( ! $use_local_csv && '' === trim( (string) $date_from_raw ) ) {
			WP_CLI::error( '--date-from is required unless --csv-file is provided.' );
		}

		$subject_pattern = $this->parse_subject_match_flag( $subject_match );

		$audience_key = $this->normalize_audience_key( (string) $key_slug );
		$final_label  = '' !== trim( (string) $label )
			? (string) $label
			: sprintf( 'Mandrill export (%s)', $audience_key );

		if ( $use_local_csv ) {
			if ( ! is_readable( $csv_file_path ) ) {
				WP_CLI::error( sprintf( '--csv-file is not readable: %s', $csv_file_path ) );
			}

			WP_CLI::line( sprintf( 'Parsing local Mandrill activity CSV: %s', $csv_file_path ) );

			$parsed = Mandrill_Activity_Export::parse_activity_csv( $csv_file_path, $subject_pattern );
			if ( is_wp_error( $parsed ) ) {
				WP_CLI::error( $parsed->get_error_message() );
			}

			$emails = $parsed['emails'];
			WP_CLI::line(
				sprintf(
					'Parsed %d row(s); %d matched; %d unique email(s).',
					(int) $parsed['rows_scanned'],
					(int) $parsed['rows_matched'],
					count( $emails )
				)
			);

			$this->finalize_audience_build(
				$audience_key,
				$emails,
				$final_label,
				array(
					'source'        => 'mandrill_csv_import',
					'csv_file'      => $csv_file_path,
					'subject_match' => $subject_pattern,
					'rows_scanned'  => (int) $parsed['rows_scanned'],
					'rows_matched'  => (int) $parsed['rows_matched'],
				),
				$dry_run,
				$create_post,
				sprintf( 'Update for %s recipients', $final_label ),
				sprintf( 'Update: %s', $final_label )
			);

			return;
		}

		$date_from = Mandrill_Activity_Export::normalize_export_datetime( (string) $date_from_raw );
		if ( is_wp_error( $date_from ) ) {
			WP_CLI::error( $date_from->get_error_message() );
		}

		if ( '' === trim( (string) $date_to_raw ) ) {
			$date_to = gmdate( 'Y-m-d H:i:s' );
		} else {
			$date_to = Mandrill_Activity_Export::normalize_export_datetime( (string) $date_to_raw, true );
			if ( is_wp_error( $date_to ) ) {
				WP_CLI::error( $date_to->get_error_message() );
			}
		}

		$states = array_values(
			array_filter(
				array_map( 'trim', explode( ',', (string) $states_raw ) ),
				static fn( string $state ): bool => '' !== $state
			)
		);
		if ( empty( $states ) ) {
			WP_CLI::error( '--states must include at least one delivery state.' );
		}

		WP_CLI::line(
			sprintf(
				'Requesting Mandrill activity export (tag=%s, %s → %s)…',
				$tag,
				$date_from,
				$date_to
			)
		);

		$export_params = array(
			'date_from' => $date_from,
			'date_to'   => $date_to,
			'tags'      => array( (string) $tag ),
			'states'    => $states,
		);

		$job_id = Mandrill_Activity_Export::request_export( $export_params );
		if ( is_wp_error( $job_id ) ) {
			WP_CLI::error( $job_id->get_error_message() );
		}

		WP_CLI::line( sprintf( 'Export job queued (id: %s). Polling…', $job_id ) );

		$export_info = Mandrill_Activity_Export::poll_export( $job_id );
		if ( is_wp_error( $export_info ) ) {
			WP_CLI::error( $export_info->get_error_message() );
		}

		$csv_path = Mandrill_Activity_Export::download_activity_csv( (string) $export_info['result_url'] );
		if ( is_wp_error( $csv_path ) ) {
			WP_CLI::error( $csv_path->get_error_message() );
		}

		$parsed = Mandrill_Activity_Export::parse_activity_csv( $csv_path, $subject_pattern );
		wp_delete_file( $csv_path );

		if ( is_wp_error( $parsed ) ) {
			WP_CLI::error( $parsed->get_error_message() );
		}

		$emails = $parsed['emails'];
		WP_CLI::line(
			sprintf(
				'Parsed %d row(s); %d matched; %d unique email(s).',
				(int) $parsed['rows_scanned'],
				(int) $parsed['rows_matched'],
				count( $emails )
			)
		);

		$this->finalize_audience_build(
			$audience_key,
			$emails,
			$final_label,
			array(
				'source'         => 'mandrill_export',
				'date_from'      => $date_from,
				'date_to'        => $date_to,
				'tag'            => (string) $tag,
				'states'         => $states,
				'subject_match'  => $subject_pattern,
				'rows_scanned'   => (int) $parsed['rows_scanned'],
				'rows_matched'   => (int) $parsed['rows_matched'],
				'mandrill_job_id'=> $job_id,
			),
			$dry_run,
			$create_post,
			sprintf( 'Update for %s recipients', $final_label ),
			sprintf( 'Update: %s', $final_label )
		);
	}

	/**
	 * Build an audience from the durable system-email recipients log.
	 *
	 * ## OPTIONS
	 *
	 * --system-email-key=<keys>
	 * : Exact key, comma-separated keys, or prefix wildcard (e.g. typology-2026-*).
	 *
	 * --key=<slug>
	 * : Audience option slug (stored as prc_email_audience_{slug}).
	 *
	 * [--label=<text>]
	 * : Human-readable audience label.
	 *
	 * [--dry-run]
	 * : Report counts without writing wp_options or creating a draft post.
	 *
	 * [--create-post]
	 * : Create a draft prc_email_txn newsletter targeting the audience.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email audience build-from-log \
	 *       --system-email-key=typology-2026-* \
	 *       --key=typology-2026-requesters \
	 *       --dry-run
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function build_from_log( $args, $assoc_args ): void {
		System_Email_Recipients_Table::maybe_create_table();

		$system_email_key_raw = Utils\get_flag_value( $assoc_args, 'system-email-key', '' );
		$key_slug             = Utils\get_flag_value( $assoc_args, 'key', '' );
		$label                = Utils\get_flag_value( $assoc_args, 'label', '' );
		$dry_run              = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$create_post          = (bool) Utils\get_flag_value( $assoc_args, 'create-post', false );

		if ( '' === trim( (string) $system_email_key_raw ) ) {
			WP_CLI::error( '--system-email-key is required.' );
		}
		if ( '' === trim( (string) $key_slug ) ) {
			WP_CLI::error( '--key is required.' );
		}

		$filters = System_Email_Recipients_Table::parse_key_filters( (string) $system_email_key_raw );
		if ( empty( $filters ) ) {
			WP_CLI::error( 'No valid --system-email-key filters were parsed.' );
		}

		$emails       = System_Email_Recipients_Table::get_distinct_emails( $filters );
		$audience_key = $this->normalize_audience_key( (string) $key_slug );
		$final_label  = '' !== trim( (string) $label )
			? (string) $label
			: sprintf( 'System email log (%s)', (string) $system_email_key_raw );

		WP_CLI::line(
			sprintf(
				'Found %d unique recipient(s) for filter(s): %s',
				count( $emails ),
				(string) $system_email_key_raw
			)
		);

		$this->finalize_audience_build(
			$audience_key,
			$emails,
			$final_label,
			array(
				'source'            => 'send_log',
				'system_email_keys' => (string) $system_email_key_raw,
				'filters'           => $filters,
			),
			$dry_run,
			$create_post,
			sprintf( 'Update for %s recipients', $final_label ),
			sprintf( 'Update: %s', $final_label )
		);
	}

	/**
	 * Build an audience from Firebase Auth users whose email domain contains a substring.
	 *
	 * Pages Auth via the buildEmailDomainAudience Cloud Function. Matches the
	 * domain only (the part after @). Example: --domain-contains=k12 matches
	 * teacher@lausd.k12.ca.us and does not match k12fan@gmail.com.
	 *
	 * Default verification is verified-only. Unlike dataset/quiz builders, this
	 * command does not create a draft newsletter unless --create-post is passed.
	 *
	 * Does not print recipient emails (PII). Dry-run still calls the Cloud
	 * Function and reports counts only.
	 *
	 * ## OPTIONS
	 *
	 * --domain-contains=<needle>
	 * : Case-insensitive substring of the email domain (not a regex, not the local-part).
	 *
	 * [--dry-run]
	 * : Call the Cloud Function and report counts without writing wp_options or creating a post.
	 *
	 * [--create-post]
	 * : After a successful persist, draft a prc_email_txn with Mandrill delivery
	 *   targeting the new audience option. Off by default.
	 *
	 * [--label=<text>]
	 * : Human-readable label for the sidebar picker. Default: Firebase Auth domains containing "<needle>" (mode).
	 *
	 * [--key=<slug>]
	 * : Override the option key (full key or slug). Default: prc_email_audience_auth_domain_{slug}_{fingerprint}_{verification}.
	 *
	 * [--only-verified]
	 * : Include only users with a verified Firebase email (default when no verification flag is passed).
	 *
	 * [--only-unverified]
	 * : Include only users with an unverified Firebase email (must have an email on file).
	 *
	 * [--include-unverified]
	 * : Include all users with an email on file (verified and unverified). Mutually exclusive with the other verification flags.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email audience build-from-auth-domain --domain-contains=k12 --dry-run
	 *
	 *     wp prc email audience build-from-auth-domain --domain-contains=k12 --label="K-12 school domains (verified)"
	 *
	 *     wp prc email audience build-from-auth-domain --domain-contains=k12 --create-post
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function build_from_auth_domain( $args, $assoc_args ): void {
		$domain_contains = Utils\get_flag_value( $assoc_args, 'domain-contains', '' );
		$dry_run         = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$create_post     = (bool) Utils\get_flag_value( $assoc_args, 'create-post', false );
		$label           = Utils\get_flag_value( $assoc_args, 'label', null );
		$key_raw         = Utils\get_flag_value( $assoc_args, 'key', '' );
		$verification    = self::resolve_verification_mode( $assoc_args );

		if ( '' === trim( (string) $domain_contains ) ) {
			WP_CLI::error(
				'--domain-contains is required (e.g. k12). Match is a case-insensitive substring of the email domain only, not the local-part.'
			);
		}

		$audience_key = null;
		if ( '' !== trim( (string) $key_raw ) ) {
			$audience_key = $this->normalize_audience_key( (string) $key_raw );
		}

		WP_CLI::line(
			sprintf(
				'Calling buildEmailDomainAudience (domain_contains=%s, verification=%s)…',
				(string) $domain_contains,
				$verification
			)
		);

		$result = Auth_Domain_Audience_Service::build(
			(string) $domain_contains,
			$verification,
			array(
				'dry_run'      => $dry_run,
				'label'        => is_string( $label ) ? $label : null,
				'audience_key' => $audience_key,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::line(
			sprintf(
				'Scanned %s Auth users → %s matched → %s email(s) (%s).',
				number_format( (int) ( $result['scanned'] ?? 0 ) ),
				number_format( (int) ( $result['matched'] ?? 0 ) ),
				number_format( (int) ( $result['count'] ?? 0 ) ),
				(string) ( $result['verification'] ?? $verification )
			)
		);

		if ( $dry_run ) {
			WP_CLI::success(
				sprintf(
					'Dry-run complete. No data written. Would save to %s.',
					(string) $result['key']
				)
			);
			return;
		}

		WP_CLI::line( sprintf( 'Audience saved → option key: %s', (string) $result['key'] ) );

		$count = (int) ( $result['count'] ?? 0 );
		if ( $create_post && $count > 0 ) {
			$post_label = (string) ( $result['label'] ?? $result['key'] );
			$this->maybe_create_newsletter_draft(
				(string) $result['key'],
				sprintf( 'Update for %s', $post_label ),
				sprintf( 'Update: %s', $post_label )
			);
		} elseif ( $create_post && 0 === $count ) {
			WP_CLI::warning( 'Audience is empty. Skipping draft post creation.' );
		}

		WP_CLI::success(
			sprintf(
				'Done. Audience option: %s  |  %s email(s)',
				(string) $result['key'],
				number_format( $count )
			)
		);
	}

	/**
	 * Validate and normalize the optional --subject-match PCRE flag.
	 *
	 * @param mixed $subject_match Raw CLI flag value.
	 * @return string|null Valid pattern or null when omitted.
	 */
	private function parse_subject_match_flag( mixed $subject_match ): ?string {
		if ( null === $subject_match || '' === trim( (string) $subject_match ) ) {
			return null;
		}

		$subject_pattern = (string) $subject_match;
		set_error_handler(
			static function ( int $severity, string $message ): bool {
				throw new \ErrorException( $message, 0, $severity );
			}
		);
		try {
			if ( false === @preg_match( $subject_pattern, '' ) ) {
				WP_CLI::error( '--subject-match is not a valid PCRE.' );
			}
		} catch ( \Throwable $e ) {
			WP_CLI::error( '--subject-match is not a valid PCRE: ' . $e->getMessage() );
		} finally {
			restore_error_handler();
		}

		return $subject_pattern;
	}

	/**
	 * Shared dry-run / persist / optional draft-post flow for audience builders.
	 *
	 * @param string               $audience_key Full wp_options audience key.
	 * @param array<int, string>   $emails       Deduped recipient emails.
	 * @param string               $label        Human-readable label.
	 * @param array<string, mixed> $meta_extra   Provenance metadata.
	 * @param bool                 $dry_run      Skip writes when true.
	 * @param bool                 $create_post  Create a draft newsletter post.
	 * @param string               $post_title   Draft post title when --create-post.
	 * @param string               $post_subject Draft post subject when --create-post.
	 */
	private function finalize_audience_build(
		string $audience_key,
		array $emails,
		string $label,
		array $meta_extra,
		bool $dry_run,
		bool $create_post,
		string $post_title,
		string $post_subject
	): void {
		$count = count( $emails );

		if ( 0 === $count ) {
			WP_CLI::warning( 'No recipient emails matched the requested filters.' );
			if ( $dry_run ) {
				WP_CLI::success( 'Dry-run complete. No data written.' );
			}
			return;
		}

		if ( $dry_run ) {
			$sample = array_slice( $emails, 0, 5 );
			WP_CLI::line( 'Sample recipients: ' . implode( ', ', $sample ) );
			WP_CLI::success(
				sprintf(
					'Dry-run complete. Would save %d email(s) to %s.',
					$count,
					$audience_key
				)
			);
			return;
		}

		$this->persist_audience( $audience_key, $emails, $label, $meta_extra );

		if ( $create_post ) {
			$this->maybe_create_newsletter_draft( $audience_key, $post_title, $post_subject );
		}

		WP_CLI::success(
			sprintf(
				'Done. Audience option: %s  |  %s email(s)',
				$audience_key,
				number_format( $count )
			)
		);
	}

	/**
	 * Persist an audience option pair.
	 *
	 * @param string               $audience_key Full wp_options audience key.
	 * @param array<int, string>   $emails       Recipient emails.
	 * @param string               $label        Human-readable label.
	 * @param array<string, mixed> $meta_extra   Provenance metadata merged into _meta.
	 */
	private function persist_audience(
		string $audience_key,
		array $emails,
		string $label,
		array $meta_extra
	): void {
		$meta_key = $audience_key . '_meta';
		$count    = count( $emails );
		$built_at = current_time( 'mysql', true );

		$existing = get_option( $audience_key, false );
		if ( false !== $existing && is_array( $existing ) && ! empty( $existing ) ) {
			WP_CLI::warning(
				sprintf(
					'Overwriting existing audience "%s" (%d email(s)).',
					$audience_key,
					count( $existing )
				)
			);
		}

		update_option( $audience_key, array_values( $emails ), false );
		update_option(
			$meta_key,
			array_merge(
				array(
					'label'    => $label,
					'count'    => $count,
					'built_at' => $built_at,
				),
				$meta_extra
			),
			false
		);

		WP_CLI::line(
			sprintf(
				'Audience saved → %s (%d email(s), label: "%s")',
				$audience_key,
				$count,
				$label
			)
		);
	}

	/**
	 * Create a draft Mandrill newsletter post targeting an audience option.
	 *
	 * @param string $audience_key Audience option key.
	 * @param string $post_title   Draft post title.
	 * @param string $post_subject Email subject meta.
	 */
	private function maybe_create_newsletter_draft(
		string $audience_key,
		string $post_title,
		string $post_subject
	): void {
		if ( ! post_type_exists( Post_Type::TRANSACTIONAL_POST_TYPE ) ) {
			WP_CLI::warning(
				'The "prc_email_txn" post type is not registered. Skipping draft post creation.'
			);
			return;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => Post_Type::TRANSACTIONAL_POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => $post_title,
				'meta_input'  => array(
					'prc_email_delivery_mode'       => 'mandrill',
					'prc_email_audience_option_key' => $audience_key,
					'prc_email_subject'             => $post_subject,
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			WP_CLI::warning( 'Could not create newsletter draft: ' . $post_id->get_error_message() );
			return;
		}

		$edit_url = admin_url( "post.php?post={$post_id}&action=edit" );
		WP_CLI::line( sprintf( 'Newsletter draft created → %s', $edit_url ) );
	}

	/**
	 * Parse comma-separated emails; validate and dedupe (lowercase).
	 *
	 * @param string $raw Comma-separated addresses.
	 * @return array{valid: string[], invalid: string[]}
	 */
	private function parse_and_validate_emails( string $raw ): array {
		$parts   = preg_split( '/\s*,\s*/', trim( $raw ), -1, PREG_SPLIT_NO_EMPTY );
		$valid   = array();
		$invalid = array();

		foreach ( $parts as $part ) {
			$email = strtolower( trim( $part ) );
			if ( '' === $email ) {
				continue;
			}
			if ( is_email( $email ) ) {
				$valid[ $email ] = $email;
			} else {
				$invalid[] = $part;
			}
		}

		return array(
			'valid'   => array_values( $valid ),
			'invalid' => $invalid,
		);
	}

	/**
	 * Normalize a slug or full option key to prc_email_audience_{slug}.
	 *
	 * @param string $key_or_slug Full key or slug.
	 * @return string
	 */
	private function normalize_audience_key( string $key_or_slug ): string {
		$key_or_slug = trim( $key_or_slug );

		if ( str_starts_with( $key_or_slug, self::LEGACY_AUDIENCE_OPTION_PREFIX ) ) {
			return $key_or_slug;
		}

		if ( str_starts_with( $key_or_slug, self::AUDIENCE_OPTION_PREFIX ) ) {
			return $key_or_slug;
		}

		$slug = sanitize_key( $key_or_slug );
		if ( '' === $slug ) {
			WP_CLI::error( 'Invalid --key: must contain alphanumeric characters.' );
		}

		return self::AUDIENCE_OPTION_PREFIX . $slug;
	}

	/**
	 * Fetch audience metadata rows (same discovery as REST list_system_audiences).
	 *
	 * @return array<int, array{key: string, label: string, count: int, built_at: string|null}>
	 */
	private function fetch_audience_meta_rows(): array {
		global $wpdb;

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name ASC",
				$wpdb->esc_like( self::AUDIENCE_OPTION_PREFIX ) . '%' . $wpdb->esc_like( '_meta' ),
				$wpdb->esc_like( self::LEGACY_AUDIENCE_OPTION_PREFIX ) . '%' . $wpdb->esc_like( '_meta' )
			)
		);

		$audiences = array();
		foreach ( $results as $meta_option_name ) {
			$meta = get_option( $meta_option_name, array() );
			if ( empty( $meta ) || ! is_array( $meta ) || ! $this->is_audience_meta_array( $meta ) ) {
				continue;
			}

			$audience_key = substr( $meta_option_name, 0, -5 );
			$audiences[]  = array(
				'key'      => $audience_key,
				'label'    => (string) ( $meta['label'] ?? $audience_key ),
				'count'    => (int) ( $meta['count'] ?? 0 ),
				'built_at' => isset( $meta['built_at'] ) ? (string) $meta['built_at'] : null,
			);
		}

		return $audiences;
	}

	/**
	 * Distinguish meta companion options from audience email lists (slug may end in "_meta").
	 *
	 * @param array $meta Option value.
	 * @return bool
	 */
	private function is_audience_meta_array( array $meta ): bool {
		if ( array_is_list( $meta ) ) {
			return false;
		}

		return isset( $meta['label'] ) || isset( $meta['built_at'] ) || isset( $meta['source'] );
	}

	/**
	 * Find email post IDs that reference an audience option key.
	 *
	 * @param string $audience_key Option key.
	 * @return int[] Post IDs.
	 */
	private function find_newsletters_using_audience( string $audience_key ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'prc_email_audience_option_key',
				$audience_key
			)
		);

		return array_map( 'intval', $ids ?: array() );
	}
}
