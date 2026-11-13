<?php
/**
 * WP-CLI commands for inspecting and recovering a Mandrill system-email send.
 *
 * A batch that was attempted and never confirmed stays in "needs review". No
 * webhook comes for a batch that Mandrill never received. `retry-batch` asks
 * Mandrill whether the batch went out, then marks it accepted or sends it once.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WPCOM_VIP_CLI_Command' ) ) {
	return;
}

/**
 * CLI Send class.
 */
class CLI_Send extends \WPCOM_VIP_CLI_Command {

	/**
	 * Print the send state, every batch, and the recovery log of a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Transactional email post ID (prc_email_txn, delivery mode "mandrill").
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email send status 12345
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function status( $args, $assoc_args ): void {
		$post_id = $this->resolve_post_id( $args );
		$sender  = new Mandrill_Sender( new Loader() );
		$report  = $sender->batch_report( $post_id );

		WP_CLI::line( sprintf( 'Status:       %s', '' !== $report['status'] ? $report['status'] : '(none)' ) );
		WP_CLI::line( sprintf( 'Locked:       %s', $report['locked'] ? 'yes' : 'no' ) );
		WP_CLI::line( sprintf( 'Recipients:   %s', null === $report['recipients'] ? '(unknown)' : (string) $report['recipients'] ) );
		WP_CLI::line( sprintf( 'Batches:      %d', $report['total_batches'] ) );
		WP_CLI::line( sprintf( 'Last attempt: %s', $this->last_attempt( $report['batches'] ) ) );
		WP_CLI::line( sprintf( 'Updated:      %s', '' !== $report['updated_at'] ? $report['updated_at'] : '(n/a)' ) );
		WP_CLI::line( sprintf( 'Summary:      %s', '' !== $report['summary'] ? $report['summary'] : '(none)' ) );
		if ( false === $report['audience_matches'] ) {
			WP_CLI::warning( 'The recipient list changed since the send started. Batch numbers no longer match it.' );
		}

		if ( array() !== $report['batches'] ) {
			Utils\format_items(
				'table',
				array_map(
					static fn( array $batch ): array => array(
						'batch'        => $batch['index'],
						'state'        => $batch['state'],
						'attempted'    => $batch['attempted'] ? 'yes' : 'no',
						'accepted'     => $batch['accepted'] ? 'yes' : 'no',
						'recipients'   => null === $batch['recipients'] ? '' : $batch['recipients'],
						'attempted_at' => '' !== $batch['attempted_at'] ? $batch['attempted_at'] : '',
						'attempt_id'   => $batch['attempt_id'],
					),
					$report['batches']
				),
				array( 'batch', 'state', 'attempted', 'accepted', 'recipients', 'attempted_at', 'attempt_id' )
			);
		}

		if ( array() !== $report['recovery_log'] ) {
			WP_CLI::line( 'Recovery log:' );
			foreach ( $report['recovery_log'] as $entry ) {
				WP_CLI::line(
					sprintf(
						'  %s  batch %s  %s%s%s',
						(string) ( $entry['at'] ?? '' ),
						(string) ( $entry['batch'] ?? '' ),
						(string) ( $entry['action'] ?? '' ),
						! empty( $entry['forced'] ) ? '  (forced)' : '',
						isset( $entry['detail'] ) ? '  ' . $entry['detail'] : ''
					)
				);
			}
		}
	}

	/**
	 * Recover one batch that was attempted and never accepted.
	 *
	 * Takes the send lease. Asks Mandrill whether the batch already went out.
	 * Marks the batch accepted when Mandrill has it, and does not send it again.
	 * Sends the batch once when Mandrill does not have it. Refuses when Mandrill
	 * cannot answer, unless you pass --force. A duplicate is possible with --force.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Transactional email post ID (prc_email_txn, delivery mode "mandrill").
	 *
	 * <batch>
	 * : Batch number, as `status` prints it. The first batch is 0.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * [--force]
	 * : Send the batch even when Mandrill cannot confirm that it is missing.
	 *   Recipients can get the email twice.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email send status 12345
	 *     wp prc email send retry-batch 12345 0
	 *     wp prc email send retry-batch 12345 0 --yes
	 *
	 * @subcommand retry-batch
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function retry_batch( $args, $assoc_args ): void {
		$post_id = $this->resolve_post_id( $args );
		$batch   = $args[1] ?? '';
		if ( ! is_string( $batch ) || ! ctype_digit( $batch ) ) {
			WP_CLI::error( '<batch> is required and must be a batch number from `status`, such as 0.' );
		}
		$batch = (int) $batch;
		$force = (bool) Utils\get_flag_value( $assoc_args, 'force', false );

		$allowed = Send_Guard::assert_can_send();
		if ( is_wp_error( $allowed ) ) {
			WP_CLI::error( $allowed->get_error_message() );
		}

		$sender = new Mandrill_Sender( new Loader() );
		$report = $sender->batch_report( $post_id );
		$row    = $report['batches'][ $batch ] ?? null;
		if ( null === $row || 'needs_review' !== $row['state'] ) {
			WP_CLI::error(
				sprintf(
					'Batch %d of post %d is not attempted and waiting. Run `wp prc email send status %d` to see the batches.',
					$batch,
					$post_id,
					$post_id
				)
			);
		}

		if ( $report['locked'] ) {
			WP_CLI::error( sprintf( 'A send is in flight for post %d (fresh lock). Wait for it to end, then retry.', $post_id ) );
		}

		$html = Cached_Email_Html::resolve( $post_id );
		if ( is_wp_error( $html ) ) {
			WP_CLI::error( $html->get_error_message() );
		}

		if ( $force ) {
			WP_CLI::warning(
				sprintf(
					'Duplicate risk: --force sends batch %d even when Mandrill cannot say whether it already went out. Its recipients can get this email twice.',
					$batch
				)
			);
		}

		$question = sprintf(
			'Batch %d of post %d (%s recipients) was attempted at %s and never confirmed. Ask Mandrill whether it went out, then mark it accepted or send it once?',
			$batch,
			$post_id,
			null === $row['recipients'] ? 'unknown' : (string) $row['recipients'],
			'' !== $row['attempted_at'] ? $row['attempted_at'] : 'an unknown time'
		);
		if ( ! $this->confirm_retry( $question, $assoc_args ) ) {
			WP_CLI::line( 'Cancelled. Nothing sent.' );
			return;
		}

		$result = $sender->retry_batch( $post_id, $html, $batch, $force );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( $force && 'sent' === $result['outcome'] ) {
			WP_CLI::warning( sprintf( 'Batch %d was sent with --force. If it had already reached Mandrill, its recipients now have two copies. Check the Mandrill activity log.', $batch ) );
		}

		if ( in_array( $result['outcome'], array( 'accepted', 'sent' ), true ) ) {
			WP_CLI::success( $result['message'] . sprintf( ' Status: %s.', $result['status'] ) );
			return;
		}

		WP_CLI::warning( $result['message'] . sprintf( ' Status: %s.', $result['status'] ) );
		WP_CLI::halt( 1 );
	}

	/**
	 * Ask the operator to confirm, unless --yes is set.
	 *
	 * WP_CLI::confirm() exits without a word on "n". This prompt returns false, so
	 * the caller can say that nothing was sent.
	 *
	 * @param string $question   Question to print.
	 * @param array  $assoc_args Associative arguments.
	 */
	protected function confirm_retry( string $question, array $assoc_args ): bool {
		if ( (bool) Utils\get_flag_value( $assoc_args, 'yes', false ) ) {
			return true;
		}
		WP_CLI::out( $question . ' [y/n] ' );
		return 'y' === strtolower( trim( $this->read_answer() ) );
	}

	/**
	 * Read one line of operator input.
	 */
	protected function read_answer(): string {
		return (string) fgets( STDIN );
	}

	/**
	 * Validate the post for a Mandrill send command.
	 *
	 * @param array $args Positional arguments; the first is the post ID.
	 * @return int Post ID.
	 */
	private function resolve_post_id( array $args ): int {
		$post_id = isset( $args[0] ) && ctype_digit( (string) $args[0] ) ? (int) $args[0] : 0;
		if ( $post_id <= 0 ) {
			WP_CLI::error( '<post_id> is required and must be a positive integer.' );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! Post_Type::is_transactional_post( $post ) ) {
			WP_CLI::error(
				sprintf(
					'Post %d does not exist or is not a "%s" post.',
					$post_id,
					Post_Type::TRANSACTIONAL_POST_TYPE
				)
			);
		}

		if ( 'mandrill' !== Post_Type::transactional_delivery_mode( $post ) ) {
			WP_CLI::error( sprintf( 'Post %d is not in "mandrill" transactional sub-mode.', $post_id ) );
		}

		return $post_id;
	}

	/**
	 * Latest attempt time over all batches.
	 *
	 * @param array[] $batches Rows from Mandrill_Sender::batch_report().
	 * @return string Time, or "(none)".
	 */
	private function last_attempt( array $batches ): string {
		$times = array_filter( array_column( $batches, 'attempted_at' ) );
		return array() === $times ? '(none)' : (string) max( $times );
	}
}
