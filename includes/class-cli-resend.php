<?php
declare(strict_types=1);
/**
 * WP-CLI command for resuming / inspecting Mandrill system-email sends.
 *
 * The Mandrill sender checkpoints each batch as it succeeds. A partial or
 * failed send can be resumed (only the missing batches go out) or reset and
 * re-sent from scratch. This command drives those flows deliberately, since a
 * partial otherwise only retries when the content transform happens to re-fire.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use WP_CLI;
use WP_CLI\Utils;

class CLI_Resend {

	const EMAIL_PROVIDER = 'email';

	/**
	 * Resume, reset, or inspect a Mandrill system-email send.
	 *
	 * ## OPTIONS
	 *
	 * --post-id=<id>
	 * : Transactional email post ID (prc_email_txn, delivery mode "mandrill").
	 *
	 * [--reset]
	 * : Clear send progress/status first, then send from scratch. Use when the
	 *   audience list changed and you intend to re-send everyone.
	 *
	 * [--status]
	 * : Print current send status and checkpoint progress without sending.
	 *
	 * ## EXAMPLES
	 *
	 *     # Inspect current send state
	 *     wp prc email resend --post-id=12345 --status
	 *
	 *     # Resume a partial send (only un-sent batches go out)
	 *     wp prc email resend --post-id=12345
	 *
	 *     # Reset progress and re-send the whole audience from scratch
	 *     wp prc email resend --post-id=12345 --reset
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ): void {
		$post_id     = (int) Utils\get_flag_value( $assoc_args, 'post-id', 0 );
		$reset       = (bool) Utils\get_flag_value( $assoc_args, 'reset', false );
		$status_only = (bool) Utils\get_flag_value( $assoc_args, 'status', false );

		if ( $post_id <= 0 ) {
			WP_CLI::error( '--post-id is required and must be a positive integer.' );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! Post_Type::is_transactional_post( $post ) ) {
			WP_CLI::error( sprintf(
				'Post %d does not exist or is not a "%s" post.',
				$post_id,
				Post_Type::TRANSACTIONAL_POST_TYPE
			) );
		}

		if ( 'mandrill' !== Post_Type::transactional_delivery_mode( $post ) ) {
			WP_CLI::error( sprintf( 'Post %d is not in "mandrill" transactional sub-mode.', $post_id ) );
		}

		$sender = new Mandrill_Sender( new Loader() );

		if ( $status_only ) {
			$this->print_status( $sender, $post_id );
			return;
		}

		if ( $reset ) {
			$sender->reset_progress( $post_id );
			WP_CLI::line( sprintf( 'Send progress reset for post %d.', $post_id ) );
		}

		if ( $sender->is_locked( $post_id ) ) {
			WP_CLI::error( sprintf(
				'A send is already in flight for post %d (fresh lock). Wait for it to finish or reset.',
				$post_id
			) );
		}

		$html = $this->get_cached_html( $post_id );
		if ( is_wp_error( $html ) ) {
			WP_CLI::error( $html->get_error_message() );
		}

		WP_CLI::line( sprintf( 'Sending post %d via Mandrill…', $post_id ) );

		$result = $sender->send_to_audience( $post_id, $html );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'Send failed: ' . $result->get_error_message() );
		}

		$status = get_post_meta( $post_id, Mandrill_Sender::STATUS_META, true );
		WP_CLI::line( sprintf(
			'Batches: %d total, %d completed, %d skipped (already sent), %d failed this run.',
			(int) ( $result['batches'] ?? 0 ),
			(int) ( $result['completed_batches'] ?? 0 ),
			(int) ( $result['skipped_batches'] ?? 0 ),
			(int) ( $result['failed_batches'] ?? 0 )
		) );

		if ( 'sent' === $status ) {
			WP_CLI::success( sprintf( 'Post %d fully sent.', $post_id ) );
		} elseif ( 'partial' === $status ) {
			WP_CLI::warning( sprintf(
				'Post %d partially sent. Re-run without --reset to resume the remaining batches.',
				$post_id
			) );
		} else {
			WP_CLI::warning( sprintf( 'Post %d send status: %s.', $post_id, (string) $status ) );
		}
	}

	/**
	 * Print current send status + checkpoint details for a post.
	 *
	 * @param Mandrill_Sender $sender  Sender instance.
	 * @param int             $post_id Newsletter post ID.
	 */
	private function print_status( Mandrill_Sender $sender, int $post_id ): void {
		$status   = get_post_meta( $post_id, Mandrill_Sender::STATUS_META, true );
		$progress = $sender->get_progress( $post_id );
		$summary  = get_post_meta( $post_id, Mandrill_Sender::SUMMARY_META, true );

		$completed = count( (array) ( $progress['completed_batches'] ?? [] ) );
		$total     = (int) ( $progress['total_batches'] ?? 0 );

		WP_CLI::line( sprintf( 'Status:     %s', $status ?: '(none)' ) );
		WP_CLI::line( sprintf( 'Locked:     %s', $sender->is_locked( $post_id ) ? 'yes' : 'no' ) );
		WP_CLI::line( sprintf( 'Batches:    %d / %d completed', $completed, $total ) );
		WP_CLI::line( sprintf( 'Updated:    %s', $progress['updated_at'] ?? '(n/a)' ) );
		WP_CLI::line( sprintf( 'Summary:    %s', $summary ?: '(none)' ) );
	}

	/**
	 * Resolve the cached email HTML for a post, mirroring the publish flow.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return string|\WP_Error
	 */
	private function get_cached_html( int $post_id ) {
		return Cached_Email_Html::resolve( $post_id );
	}
}
