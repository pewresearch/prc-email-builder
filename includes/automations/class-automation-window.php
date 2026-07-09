<?php
declare(strict_types=1);
/**
 * Send-window resolution + due-date math for scheduled email automations.
 *
 * A "send window" is a fixed daily time-of-day (in an explicit timezone) at
 * which a follow-up step goes out. Windows cascade, most specific wins:
 *
 *   1. Per step        — step.send_window
 *   2. Per automation  — config.send_window
 *   3. Site default    — prc_email_automation_default_send_window option
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Stateless helpers for send-window resolution and due-date computation.
 */
class Automation_Window {

	/**
	 * Option holding the site-wide default send window (the final fallback).
	 */
	const SITE_DEFAULT_OPTION = 'prc_email_automation_default_send_window';

	/**
	 * Hard-coded fallback used when the site default option is unset/invalid.
	 * 9:00 AM US Eastern matches the plan's default.
	 */
	const HARD_DEFAULT = [
		'timezone' => 'America/New_York',
		'hour'     => 9,
		'minute'   => 0,
	];

	/**
	 * The site-wide default send window, always a valid window array.
	 *
	 * @return array{timezone:string, hour:int, minute:int}
	 */
	public static function get_site_default(): array {
		$saved  = get_option( self::SITE_DEFAULT_OPTION, [] );
		$window = self::sanitize( is_array( $saved ) ? $saved : [] );

		return $window ?? self::HARD_DEFAULT;
	}

	/**
	 * Persist the site-wide default send window.
	 *
	 * @param array<string, mixed> $window Raw window input.
	 * @return array{timezone:string, hour:int, minute:int} The stored (sanitized) window.
	 */
	public static function save_site_default( array $window ): array {
		$sanitized = self::sanitize( $window ) ?? self::HARD_DEFAULT;
		update_option( self::SITE_DEFAULT_OPTION, $sanitized, false );

		return $sanitized;
	}

	/**
	 * Validate + normalize a raw send-window shape.
	 *
	 * @param mixed $raw Candidate window (expects timezone/hour/minute keys).
	 * @return array{timezone:string, hour:int, minute:int}|null Null when invalid/empty.
	 */
	public static function sanitize( mixed $raw ): ?array {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return null;
		}

		$timezone = isset( $raw['timezone'] ) ? (string) $raw['timezone'] : '';
		if ( '' === $timezone || ! self::is_valid_timezone( $timezone ) ) {
			return null;
		}

		if ( ! isset( $raw['hour'] ) || ! is_numeric( $raw['hour'] ) ) {
			return null;
		}
		$hour = (int) $raw['hour'];
		if ( $hour < 0 || $hour > 23 ) {
			return null;
		}

		$minute = isset( $raw['minute'] ) && is_numeric( $raw['minute'] ) ? (int) $raw['minute'] : 0;
		if ( $minute < 0 || $minute > 59 ) {
			return null;
		}

		return [
			'timezone' => $timezone,
			'hour'     => $hour,
			'minute'   => $minute,
		];
	}

	/**
	 * Resolve the effective send window for a step (three-level cascade).
	 *
	 * @param array<string, mixed> $step   Step definition (may carry send_window).
	 * @param array<string, mixed> $config Automation config (may carry send_window).
	 * @return array{timezone:string, hour:int, minute:int}
	 */
	public static function resolve( array $step, array $config ): array {
		$step_window = self::sanitize( $step['send_window'] ?? null );
		if ( null !== $step_window ) {
			return $step_window;
		}

		$automation_window = self::sanitize( $config['send_window'] ?? null );
		if ( null !== $automation_window ) {
			return $automation_window;
		}

		return self::get_site_default();
	}

	/**
	 * Compute the UTC due datetime for a step.
	 *
	 * The anchor is a UTC datetime (initial send, or previous step's send). Its
	 * date is taken in the window's timezone, `delay_days` calendar days are
	 * added, and the window time-of-day is applied — then converted back to UTC
	 * for storage. Two recipients enrolled hours apart on the same local day
	 * therefore land on the identical due_at.
	 *
	 * @param string                                    $anchor_utc UTC datetime ('Y-m-d H:i:s') to count days from.
	 * @param int                                       $delay_days Calendar days to add (>= 0).
	 * @param array{timezone:string, hour:int, minute:int} $window  Resolved send window.
	 * @return string UTC datetime string ('Y-m-d H:i:s').
	 */
	public static function compute_due_at( string $anchor_utc, int $delay_days, array $window ): string {
		$delay_days = max( 0, $delay_days );

		$utc      = new DateTimeZone( 'UTC' );
		$local_tz = new DateTimeZone( $window['timezone'] );

		try {
			$anchor = new DateTimeImmutable( $anchor_utc, $utc );
		} catch ( \Exception $e ) {
			$anchor = new DateTimeImmutable( 'now', $utc );
		}

		// Localize the anchor to get its calendar date in the window's timezone.
		$local_anchor = $anchor->setTimezone( $local_tz );

		$target = $local_anchor
			->modify( sprintf( '+%d days', $delay_days ) )
			->setTime( $window['hour'], $window['minute'], 0 );

		return $target->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Whether a string is a valid PHP timezone identifier.
	 */
	public static function is_valid_timezone( string $timezone ): bool {
		if ( '' === $timezone ) {
			return false;
		}
		try {
			new DateTimeZone( $timezone );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
