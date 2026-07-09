<?php
declare(strict_types=1);
/**
 * Coverage for Automation_Enrollment step-advance math and context hashing,
 * using a fake $wpdb that captures update() calls.
 *
 * Run with:
 *   php plugins/prc-email-builder/tests/test-automation-enrollment.php
 */

namespace {

	function wp_json_encode( $data, $options = 0, $depth = 512 ): string {
		return json_encode( $data, $options, $depth ) ?: '';
	}
	function is_email( string $email ): bool {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}
	// Fixed clock so due_at math is deterministic.
	function current_time( string $type, $gmt = 0 ): string {
		return '2026-01-05 12:00:00';
	}
	function get_option( string $name, $default = false ) {
		return $default;
	}
	function update_option( string $name, $value, $autoload = null ): bool {
		return true;
	}

	class Fake_WPDB {
		public string $prefix = 'wp_';
		/** @var array<int, array{data: array, where: array}> */
		public array $updates = [];

		public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
			$this->updates[] = [ 'data' => $data, 'where' => $where ];
			return 1;
		}
	}

	$GLOBALS['wpdb'] = new Fake_WPDB();

	function assert_same( mixed $expected, mixed $actual, string $msg ): void {
		if ( $expected !== $actual ) {
			fwrite(
				STDERR,
				"FAIL: {$msg}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n"
			);
			exit( 1 );
		}
	}
	function assert_true( bool $cond, string $msg ): void {
		if ( ! $cond ) {
			fwrite( STDERR, "FAIL: {$msg}\n" );
			exit( 1 );
		}
	}

	require dirname( __DIR__ ) . '/includes/automations/class-automation-window.php';
	require dirname( __DIR__ ) . '/includes/automations/class-automation-enrollment.php';

	use PRC\Platform\Email_Builder\Automation_Enrollment;

	// ── context_hash() is stable regardless of key order ──────────────────────
	$h1 = Automation_Enrollment::context_hash( [ 'b' => 2, 'a' => 1 ] );
	$h2 = Automation_Enrollment::context_hash( [ 'a' => 1, 'b' => 2 ] );
	assert_same( $h1, $h2, 'context_hash is order-independent' );
	assert_true(
		Automation_Enrollment::context_hash( [ 'a' => 1 ] ) !== Automation_Enrollment::context_hash( [ 'a' => 2 ] ),
		'context_hash differs for different context'
	);

	// ── decode_json_array() tolerates junk ────────────────────────────────────
	assert_same( [], Automation_Enrollment::decode_json_array( '' ), 'empty string decodes to []' );
	assert_same( [], Automation_Enrollment::decode_json_array( 'garbage' ), 'garbage decodes to []' );
	assert_same( [ 1, 2 ], Automation_Enrollment::decode_json_array( '[1,2]' ), 'json array decodes' );

	// ── advance(): more steps → next step scheduled with its own window ───────
	$config = [
		'send_window' => [ 'timezone' => 'America/New_York', 'hour' => 9, 'minute' => 0 ],
		'steps'       => [
			[ 'follow_up_post_id' => 10, 'delay_days' => 3 ],
			[
				'follow_up_post_id' => 11,
				'delay_days'        => 7,
				'send_window'       => [ 'timezone' => 'America/New_York', 'hour' => 14, 'minute' => 0 ],
			],
		],
	];

	$row = [
		'id'           => 1,
		'current_step' => 0,
		'step_log'     => '[]',
	];

	$GLOBALS['wpdb']->updates = [];
	Automation_Enrollment::advance( $row, $config, [ 'mandrill_status' => 'sent' ] );

	$update = $GLOBALS['wpdb']->updates[0]['data'];
	assert_same( 1, $update['current_step'], 'advance moves to step index 1' );
	assert_same( 11, $update['follow_up_post_id'], 'advance denormalizes next follow-up post id' );
	// Step 2 uses its own 14:00 EST window: 2026-01-08 12:00 (now) localized then +7 days at 14:00 EST.
	// now 2026-01-05 12:00 UTC = 07:00 EST; +7 = Jan 12 14:00 EST = 19:00 UTC.
	assert_same( '2026-01-12 19:00:00', $update['due_at'], 'advance recomputes due_at with the next step window' );
	assert_same(
		'{"timezone":"America\/New_York","hour":14,"minute":0}',
		$update['send_window_snapshot'],
		'advance snapshots the next step window'
	);
	assert_true( str_contains( $update['step_log'], 'sent' ), 'advance appends the step log entry' );

	// ── advance(): last step → completed ──────────────────────────────────────
	$row_last = [ 'id' => 2, 'current_step' => 1, 'step_log' => '[]' ];
	$GLOBALS['wpdb']->updates = [];
	Automation_Enrollment::advance( $row_last, $config, [ 'mandrill_status' => 'sent' ] );
	$done = $GLOBALS['wpdb']->updates[0]['data'];
	assert_same( 'completed', $done['status'], 'advancing past the last step completes the enrollment' );
	assert_same( null, $done['due_at'], 'completed enrollment clears due_at' );

	// ── record_failure(): increments attempts, dead-letters at MAX ────────────
	$row_fail = [ 'id' => 3, 'current_step' => 0, 'attempts' => 0, 'step_log' => '[]' ];
	$GLOBALS['wpdb']->updates = [];
	Automation_Enrollment::record_failure( $row_fail, 'mandrill down' );
	$fail = $GLOBALS['wpdb']->updates[0]['data'];
	assert_same( 1, $fail['attempts'], 'record_failure increments attempts' );
	assert_true( ! isset( $fail['status'] ), 'a single failure does not cancel the enrollment' );

	$row_fail_max = [
		'id'           => 4,
		'current_step' => 0,
		'attempts'     => Automation_Enrollment::MAX_ATTEMPTS - 1,
		'step_log'     => '[]',
	];
	$GLOBALS['wpdb']->updates = [];
	Automation_Enrollment::record_failure( $row_fail_max, 'still down' );
	$dead = $GLOBALS['wpdb']->updates[0]['data'];
	assert_same( 'cancelled', $dead['status'], 'hitting MAX_ATTEMPTS dead-letters the enrollment' );
	assert_same( null, $dead['due_at'], 'dead-lettered enrollment clears due_at' );

	fwrite( STDOUT, "OK: test-automation-enrollment.php passed\n" );
}
