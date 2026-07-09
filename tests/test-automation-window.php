<?php
declare(strict_types=1);
/**
 * Coverage for Automation_Window: three-level send-window cascade + due-date
 * math across timezones, DST, and same-day batching.
 *
 * Run with:
 *   php plugins/prc-email-builder/tests/test-automation-window.php
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

	use PRC\Platform\Email_Builder\Automation_Window;

	// ── sanitize() ───────────────────────────────────────────────────────────
	assert_same(
		[ 'timezone' => 'America/New_York', 'hour' => 9, 'minute' => 0 ],
		Automation_Window::sanitize( [ 'timezone' => 'America/New_York', 'hour' => 9, 'minute' => 0 ] ),
		'sanitize accepts a valid window'
	);
	assert_same( null, Automation_Window::sanitize( [] ), 'sanitize rejects empty' );
	assert_same( null, Automation_Window::sanitize( null ), 'sanitize rejects null' );
	assert_same(
		null,
		Automation_Window::sanitize( [ 'timezone' => 'Not/AZone', 'hour' => 9, 'minute' => 0 ] ),
		'sanitize rejects an invalid timezone'
	);
	assert_same(
		null,
		Automation_Window::sanitize( [ 'timezone' => 'UTC', 'hour' => 24, 'minute' => 0 ] ),
		'sanitize rejects hour 24'
	);
	assert_same(
		null,
		Automation_Window::sanitize( [ 'timezone' => 'UTC', 'hour' => 9, 'minute' => 60 ] ),
		'sanitize rejects minute 60'
	);
	assert_same(
		[ 'timezone' => 'UTC', 'hour' => 14, 'minute' => 0 ],
		Automation_Window::sanitize( [ 'timezone' => 'UTC', 'hour' => '14' ] ),
		'sanitize coerces numeric strings and defaults minute to 0'
	);

	// ── get_site_default() falls back to the hard default ─────────────────────
	assert_same(
		Automation_Window::HARD_DEFAULT,
		Automation_Window::get_site_default(),
		'site default falls back to the hard default when unset'
	);

	// ── resolve() cascade: step > automation > site default ───────────────────
	$site_default = Automation_Window::HARD_DEFAULT;
	$automation   = [ 'send_window' => [ 'timezone' => 'America/New_York', 'hour' => 9, 'minute' => 0 ] ];
	$step_default = [ 'follow_up_post_id' => 1, 'delay_days' => 3 ];
	$step_custom  = [
		'follow_up_post_id' => 2,
		'delay_days'        => 7,
		'send_window'       => [ 'timezone' => 'America/New_York', 'hour' => 14, 'minute' => 0 ],
	];

	assert_same(
		[ 'timezone' => 'America/New_York', 'hour' => 9, 'minute' => 0 ],
		Automation_Window::resolve( $step_default, $automation ),
		'resolve inherits the automation window when the step has none'
	);
	assert_same(
		[ 'timezone' => 'America/New_York', 'hour' => 14, 'minute' => 0 ],
		Automation_Window::resolve( $step_custom, $automation ),
		'resolve uses the per-step window override'
	);
	assert_same(
		$site_default,
		Automation_Window::resolve( $step_default, [] ),
		'resolve falls back to the site default when neither step nor automation set a window'
	);

	// ── compute_due_at(): timezone conversion (EST, no DST) ───────────────────
	$et = [ 'timezone' => 'America/New_York', 'hour' => 9, 'minute' => 0 ];

	// Jan 5 2026 12:00 UTC = 07:00 EST. delay 0, window 9:00 EST = 14:00 UTC.
	assert_same(
		'2026-01-05 14:00:00',
		Automation_Window::compute_due_at( '2026-01-05 12:00:00', 0, $et ),
		'due_at applies the window time-of-day in EST'
	);
	// delay 3 calendar days → Jan 8 09:00 EST = 14:00 UTC.
	assert_same(
		'2026-01-08 14:00:00',
		Automation_Window::compute_due_at( '2026-01-05 12:00:00', 3, $et ),
		'due_at adds calendar days'
	);
	// 2:00 PM EST window → 19:00 UTC.
	$et_afternoon = [ 'timezone' => 'America/New_York', 'hour' => 14, 'minute' => 0 ];
	assert_same(
		'2026-01-08 19:00:00',
		Automation_Window::compute_due_at( '2026-01-05 12:00:00', 3, $et_afternoon ),
		'due_at honors a later window hour'
	);

	// ── compute_due_at(): DST (EDT, June, UTC-4) ──────────────────────────────
	// Jun 1 2026 10:00 UTC = 06:00 EDT. delay 3, 9:00 EDT = 13:00 UTC.
	assert_same(
		'2026-06-04 13:00:00',
		Automation_Window::compute_due_at( '2026-06-01 10:00:00', 3, $et ),
		'due_at accounts for daylight saving time'
	);

	// ── compute_due_at(): same-day batching ───────────────────────────────────
	// Two recipients enrolled hours apart on the same local day get identical due_at.
	$early = Automation_Window::compute_due_at( '2026-06-01 10:00:00', 7, $et ); // 06:00 EDT
	$late  = Automation_Window::compute_due_at( '2026-06-01 16:00:00', 7, $et ); // 12:00 EDT
	assert_same( $early, $late, 'same-day enrollments compute the same due_at (batch together)' );

	// ── compute_due_at(): UTC window ──────────────────────────────────────────
	assert_same(
		'2026-01-05 09:00:00',
		Automation_Window::compute_due_at( '2026-01-05 12:00:00', 0, [ 'timezone' => 'UTC', 'hour' => 9, 'minute' => 0 ] ),
		'due_at works with a UTC window'
	);

	fwrite( STDOUT, "OK: test-automation-window.php passed\n" );
}
