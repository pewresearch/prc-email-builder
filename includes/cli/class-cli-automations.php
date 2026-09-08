<?php
/**
 * WP-CLI commands for scheduled email automations (ops tooling).
 *
 * Usage:
 *   wp prc email automations list [--status=<status>] [--limit=<n>]
 *   wp prc email automations cancel <enrollment-id>
 *   wp prc email automations run-due [--limit=<n>]
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_CLI;
use WP_CLI\Utils;

/**
 * CLI Automations class.
 */
class CLI_Automations {

	/**
	 * List enrollment records.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status: active, completed, or cancelled. Omit for all.
	 *
	 * [--limit=<n>]
	 * : Max rows to show. Default 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email automations list --status=active
	 *
	 * @subcommand list
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public function list_enrollments( $args, $assoc_args ): void {
		$status = (string) Utils\get_flag_value( $assoc_args, 'status', '' );
		$limit  = (int) Utils\get_flag_value( $assoc_args, 'limit', 50 );

		if ( '' !== $status && ! in_array( $status, array( 'active', 'completed', 'cancelled' ), true ) ) {
			WP_CLI::error( 'Invalid --status. Use active, completed, or cancelled.' );
		}

		$rows = Automation_Enrollment::list( $status, $limit );
		if ( empty( $rows ) ) {
			WP_CLI::line( 'No enrollments found.' );
			return;
		}

		$table = array_map(
			static fn( array $row ): array => array(
				'id'        => $row['id'],
				'trigger'   => $row['trigger_post_id'],
				'recipient' => $row['recipient_email'],
				'status'    => $row['status'],
				'step'      => $row['current_step'],
				'follow_up' => $row['follow_up_post_id'],
				'due_at'    => $row['due_at'] ?? '',
				'attempts'  => $row['attempts'],
			),
			$rows
		);

		Utils\format_items(
			'table',
			$table,
			array( 'id', 'trigger', 'recipient', 'status', 'step', 'follow_up', 'due_at', 'attempts' )
		);
	}

	/**
	 * Cancel a pending enrollment.
	 *
	 * ## OPTIONS
	 *
	 * <enrollment-id>
	 * : The enrollment row ID to cancel.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email automations cancel 42
	 *
	 * @param array $args Positional args ([0] = enrollment ID).
	 */
	public function cancel( $args ): void {
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $id <= 0 ) {
			WP_CLI::error( 'A positive enrollment ID is required.' );
		}

		if ( Automation_Enrollment::cancel( $id ) ) {
			WP_CLI::success( sprintf( 'Enrollment %d cancelled.', $id ) );
		} else {
			WP_CLI::warning( sprintf( 'Enrollment %d was not active (already completed/cancelled or missing).', $id ) );
		}
	}

	/**
	 * Run the due-enrollment dispatcher immediately (manual trigger for ops).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Max enrollments to process this run. Default: the dispatcher cap.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email automations run-due
	 *     wp prc email automations run-due --limit=100
	 *
	 * @subcommand run-due
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public function run_due( $args, $assoc_args ): void {
		$limit = (int) Utils\get_flag_value( $assoc_args, 'limit', 0 );

		$summary = Automation_Scheduler::process_due( $limit );

		WP_CLI::line(
			sprintf(
				'Due: %d | Groups: %d | Sent: %d | Failed: %d',
				$summary['due'],
				$summary['groups'],
				$summary['sent'],
				$summary['failed']
			) 
		);
		WP_CLI::success( 'Dispatcher run complete.' );
	}
}
