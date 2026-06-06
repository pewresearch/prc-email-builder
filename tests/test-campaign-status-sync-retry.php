<?php
declare(strict_types=1);
/**
 * Regression coverage for Mailchimp-sent Slack notification retries.
 *
 * Run with:
 * php plugins/prc-email-builder/tests/test-campaign-status-sync-retry.php
 */

namespace {
	class WP_Error {
		public function __construct(
			private readonly string $code,
			private readonly string $message
		) {}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	class WP_Post {
		public function __construct(
			public readonly int $ID,
			public readonly string $post_type
		) {}
	}

	$test_meta    = [];
	$updated_meta = [];
	$test_post    = null;

	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}

	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		global $test_meta;
		return $test_meta[ $post_id ][ $key ] ?? '';
	}

	function update_post_meta( int $post_id, string $key, string $value ): void {
		global $updated_meta;
		$updated_meta[ $post_id ][ $key ] = $value;
	}

	function get_post( int $post_id ) {
		global $test_post;
		return $test_post;
	}

	function wp_get_environment_type(): string {
		return 'production';
	}

	function sanitize_text_field( string $value ): string {
		return trim( $value );
	}

	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}

	function get_the_title( int $post_id ): string {
		return 'Newsletter Title';
	}

	function admin_url( string $path ): string {
		return 'https://example.test/wp-admin/' . $path;
	}

	function esc_url_raw( string $url ): string {
		return $url;
	}

	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

namespace PRC\Platform\Email_Builder {
	require_once dirname( __DIR__ ) . '/includes/class-post-type.php';

	class Mailchimp {
		public function get_campaign( string $campaign_id ): array {
			return [
				'settings' => [
					'title' => 'Mailchimp Campaign Title',
				],
			];
		}
	}
}

namespace PRC\Platform\Slack {
	class Bot {
		public static mixed $next_result = true;

		public function send_notification( array $args ) {
			if ( self::$next_result instanceof \Throwable ) {
				throw self::$next_result;
			}

			return self::$next_result;
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/includes/class-campaign-status-sync.php';

	use PRC\Platform\Email_Builder\Campaign_Status_Sync;
	use PRC\Platform\Slack\Bot;

	function reset_test_state(): int {
		global $test_meta, $updated_meta, $test_post;

		$post_id      = 123;
		$test_meta    = [
			$post_id => [
				'prc_email_mailchimp_campaign_id'     => 'campaign-123',
				'prc_email_mailchimp_campaign_status' => 'sent',
				'prc_email_subject'                   => 'Newsletter Subject',
			],
		];
		$updated_meta = [];
		$test_post    = new WP_Post( $post_id, 'prc_email_campaign' );
		Bot::$next_result = true;

		return $post_id;
	}

	function assert_throws( callable $callback, string $message ): void {
		try {
			$callback();
		} catch ( \Throwable ) {
			return;
		}

		throw new \RuntimeException( $message );
	}

	function assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(
				sprintf( '%s Expected %s, got %s.', $message, var_export( $expected, true ), var_export( $actual, true ) )
			);
		}
	}

	function assert_empty( mixed $actual, string $message ): void {
		if ( ! empty( $actual ) ) {
			throw new \RuntimeException( sprintf( '%s Got %s.', $message, var_export( $actual, true ) ) );
		}
	}

	$post_id = reset_test_state();
	Bot::$next_result = new WP_Error( 'slack_timeout', 'Slack API timed out.' );
	assert_throws(
		fn() => Campaign_Status_Sync::handle_mailchimp_sent_slack_notification( $post_id, 'campaign-123' ),
		'Slack WP_Error results must throw so Action Scheduler can retry.'
	);
	assert_empty( $updated_meta, 'Failed Slack delivery must not set the dedupe meta.' );

	$post_id = reset_test_state();
	Bot::$next_result = new \RuntimeException( 'Slack API unavailable.' );
	assert_throws(
		fn() => Campaign_Status_Sync::handle_mailchimp_sent_slack_notification( $post_id, 'campaign-123' ),
		'Slack exceptions must bubble so Action Scheduler can retry.'
	);
	assert_empty( $updated_meta, 'Thrown Slack delivery failures must not set the dedupe meta.' );

	$post_id = reset_test_state();
	Bot::$next_result = true;
	Campaign_Status_Sync::handle_mailchimp_sent_slack_notification( $post_id, 'campaign-123' );
	assert_same(
		'campaign-123',
		$updated_meta[ $post_id ][ Campaign_Status_Sync::SENT_SLACK_NOTIFIED_META ] ?? null,
		'Successful Slack delivery should set the dedupe meta.'
	);

	echo "Campaign_Status_Sync retry tests passed.\n";
}
