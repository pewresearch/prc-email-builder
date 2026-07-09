<?php
declare(strict_types=1);
/**
 * Coverage for Automation_Config::sanitize() — normalization, guard rails, and
 * per-step window handling.
 *
 * Run with:
 *   php plugins/prc-email-builder/tests/test-automation-config.php
 */

namespace {

	$GLOBALS['__options'] = [];

	function get_option( string $name, $default = false ) {
		return $GLOBALS['__options'][ $name ] ?? $default;
	}
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['__options'][ $name ] = $value;
		return true;
	}
	function absint( $value ): int {
		return abs( (int) $value );
	}
	function current_user_can( string $cap ): bool {
		return true;
	}

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

	// Post_Type is referenced by Automation_Config but not exercised by sanitize().
	require dirname( __DIR__ ) . '/includes/automations/class-automation-window.php';

	// Minimal stub for Post_Type so the config class file loads.
	eval( 'namespace PRC\\Platform\\Email_Builder; class Post_Type { const TRANSACTIONAL_POST_TYPE = "prc_email_txn"; }' );

	require dirname( __DIR__ ) . '/includes/automations/class-automation-config.php';

	use PRC\Platform\Email_Builder\Automation_Config;

	// ── Empty / malformed input ───────────────────────────────────────────────
	assert_same(
		[ 'send_window' => null, 'steps' => [] ],
		Automation_Config::sanitize( [] ),
		'empty config normalizes to null window + empty steps'
	);
	assert_same(
		[ 'send_window' => null, 'steps' => [] ],
		Automation_Config::sanitize( 'not-json' ),
		'non-json string normalizes to empty'
	);

	// ── JSON string is decoded ────────────────────────────────────────────────
	$json = '{"send_window":{"timezone":"UTC","hour":8,"minute":30},"steps":[{"follow_up_post_id":5,"delay_days":2}]}';
	assert_same(
		[
			'send_window' => [ 'timezone' => 'UTC', 'hour' => 8, 'minute' => 30 ],
			'steps'       => [ [ 'follow_up_post_id' => 5, 'delay_days' => 2 ] ],
		],
		Automation_Config::sanitize( $json ),
		'JSON string input is decoded and sanitized'
	);

	// ── Steps: drop invalid, clamp delay, keep per-step window ────────────────
	$raw = [
		'send_window' => [ 'timezone' => 'America/New_York', 'hour' => 9, 'minute' => 0 ],
		'steps'       => [
			[ 'follow_up_post_id' => 0, 'delay_days' => 3 ],        // dropped: no post
			[ 'follow_up_post_id' => 10, 'delay_days' => -5 ],      // delay clamped to 0
			[ 'follow_up_post_id' => 11, 'delay_days' => 9999 ],    // delay clamped to MAX
			[
				'follow_up_post_id' => 12,
				'delay_days'        => 7,
				'send_window'       => [ 'timezone' => 'America/New_York', 'hour' => 14, 'minute' => 0 ],
			],
			[ 'follow_up_post_id' => 13, 'delay_days' => 3, 'send_window' => [ 'timezone' => 'Bad/Zone' ] ], // bad window dropped
			'not-an-array',                                         // ignored
		],
	];

	$result = Automation_Config::sanitize( $raw );

	assert_same( 4, count( $result['steps'] ), 'invalid steps are dropped, valid retained' );
	assert_same( 10, $result['steps'][0]['follow_up_post_id'], 'first valid step post id' );
	assert_same( 0, $result['steps'][0]['delay_days'], 'negative delay clamped to 0' );
	assert_same(
		Automation_Config::MAX_DELAY_DAYS,
		$result['steps'][1]['delay_days'],
		'oversized delay clamped to MAX_DELAY_DAYS'
	);
	assert_same(
		[ 'timezone' => 'America/New_York', 'hour' => 14, 'minute' => 0 ],
		$result['steps'][2]['send_window'],
		'valid per-step window retained'
	);
	assert_true(
		! isset( $result['steps'][3]['send_window'] ),
		'invalid per-step window is stripped (step inherits)'
	);

	// ── MAX_STEPS guard rail ──────────────────────────────────────────────────
	$many = [ 'steps' => [] ];
	for ( $i = 1; $i <= 25; $i++ ) {
		$many['steps'][] = [ 'follow_up_post_id' => $i, 'delay_days' => 1 ];
	}
	$capped = Automation_Config::sanitize( $many );
	assert_same(
		Automation_Config::MAX_STEPS,
		count( $capped['steps'] ),
		'steps are capped at MAX_STEPS'
	);

	fwrite( STDOUT, "OK: test-automation-config.php passed\n" );
}
