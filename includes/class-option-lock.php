<?php
declare(strict_types=1);
/**
 * Per-resource atomic lock stored in the options table.
 *
 * Value format: "{token}|{expiry_unix}". Autoload is always "no".
 * Reads go through direct SQL (never get_option) so ownership/freshness is
 * never served from a stale options object cache. Every successful mutation
 * deletes the options-group cache entry for the lock row.
 *
 * Used by Mandrill bulk send and report sync. Do not reuse for the automation
 * dispatcher mutex, which intentionally uses the object cache instead.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Options-table compare-and-swap lock with optional ownership heartbeat.
 */
final class Option_Lock {

	/**
	 * @param string $option_prefix Prefix for the option_name row (e.g. prc_email_mandrill_lock_).
	 * @param int    $ttl_seconds   Freshness window before a lock may be reclaimed.
	 * @param string $token_prefix  Optional uniqid() prefix (e.g. report_).
	 */
	public function __construct(
		private readonly string $option_prefix,
		private readonly int $ttl_seconds,
		private readonly string $token_prefix = '',
	) {}

	/**
	 * Option name holding the lock for a resource.
	 *
	 * @param int $resource_id Post ID or other resource identifier.
	 */
	public function option_name( int $resource_id ): string {
		return $this->option_prefix . $resource_id;
	}

	/**
	 * Whether a fresh (non-expired) lock is held for a resource.
	 *
	 * @param int $resource_id Post ID or other resource identifier.
	 */
	public function is_held( int $resource_id ): bool {
		return $this->expiry( $this->read_value( $resource_id ) ) >= time();
	}

	/**
	 * Atomically acquire the lock for a resource.
	 *
	 * Uses an INSERT (fails on UNIQUE option_name) for first acquisition and a
	 * compare-and-swap UPDATE to reclaim an expired lock. Only one concurrent
	 * worker can win.
	 *
	 * @param int $resource_id Post ID or other resource identifier.
	 * @return string Owner token on success, or '' when a fresh lock is held.
	 */
	public function acquire( int $resource_id ): string {
		global $wpdb;

		$token       = uniqid( $this->token_prefix, true );
		$new_value   = $token . '|' . ( time() + $this->ttl_seconds );
		$option_name = $this->option_name( $resource_id );
		$existing    = $this->read_value( $resource_id );

		// No lock row yet -> atomic INSERT; the UNIQUE index rejects a loser.
		if ( '' === $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->insert(
				$wpdb->options,
				[
					'option_name'  => $option_name,
					'option_value' => $new_value,
					'autoload'     => 'no',
				]
			);
			if ( $inserted ) {
				wp_cache_delete( $option_name, 'options' );
				return $token;
			}
			// Lost the insert race; re-read what the winner stored.
			$existing = $this->read_value( $resource_id );
		}

		// A fresh lock is held by someone else — cannot acquire.
		if ( $this->expiry( $existing ) >= time() ) {
			return '';
		}

		// Stale lock -> reclaim via compare-and-swap. Only the worker whose
		// UPDATE matches the exact prior value wins; the rest get 0 rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$reclaimed = $wpdb->update(
			$wpdb->options,
			[ 'option_value' => $new_value ],
			[
				'option_name'  => $option_name,
				'option_value' => $existing,
			]
		);

		if ( $reclaimed ) {
			wp_cache_delete( $option_name, 'options' );
			return $token;
		}

		return '';
	}

	/**
	 * Whether this worker still owns the lock for a resource.
	 *
	 * @param int    $resource_id Post ID or other resource identifier.
	 * @param string $token       Owner token returned by acquire().
	 */
	public function owns( int $resource_id, string $token ): bool {
		if ( '' === $token ) {
			return false;
		}
		return str_starts_with( $this->read_value( $resource_id ), $token . '|' );
	}

	/**
	 * Heartbeat the lock: extend its expiry, but only while we still own it.
	 *
	 * @param int    $resource_id Post ID or other resource identifier.
	 * @param string $token       Owner token returned by acquire().
	 * @return bool True if we still hold the lock after the refresh.
	 */
	public function refresh( int $resource_id, string $token ): bool {
		global $wpdb;

		if ( '' === $token ) {
			return false;
		}

		$current = $this->read_value( $resource_id );
		if ( ! str_starts_with( $current, $token . '|' ) ) {
			return false; // Lost ownership; do not resurrect the lock.
		}

		$option_name = $this->option_name( $resource_id );
		$new_value   = $token . '|' . ( time() + $this->ttl_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->options,
			[ 'option_value' => $new_value ],
			[
				'option_name'  => $option_name,
				'option_value' => $current,
			]
		);
		wp_cache_delete( $option_name, 'options' );

		// Re-verify rather than trust the affected-row count, which is 0 when
		// the expiry happens to be identical within the same second.
		return $this->owns( $resource_id, $token );
	}

	/**
	 * Release the lock, but only if this worker still owns it.
	 *
	 * @param int    $resource_id Post ID or other resource identifier.
	 * @param string $token       Owner token returned by acquire().
	 */
	public function release( int $resource_id, string $token ): void {
		global $wpdb;

		if ( '' === $token ) {
			return;
		}

		$current = $this->read_value( $resource_id );
		if ( ! str_starts_with( $current, $token . '|' ) ) {
			return; // Another worker reclaimed it; leave their lock intact.
		}

		$option_name = $this->option_name( $resource_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->options,
			[
				'option_name'  => $option_name,
				'option_value' => $current,
			]
		);
		wp_cache_delete( $option_name, 'options' );
	}

	/**
	 * Force-delete the lock row regardless of owner (reset / CLI paths).
	 *
	 * @param int $resource_id Post ID or other resource identifier.
	 */
	public function clear( int $resource_id ): void {
		global $wpdb;

		$option_name = $this->option_name( $resource_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->options, [ 'option_name' => $option_name ] );
		wp_cache_delete( $option_name, 'options' );
	}

	/**
	 * Read the raw lock value ("token|expiry") straight from the DB.
	 *
	 * @param int $resource_id Post ID or other resource identifier.
	 * @return string Empty string when no lock row exists.
	 */
	private function read_value( int $resource_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$this->option_name( $resource_id )
			)
		);
		return null === $value ? '' : (string) $value;
	}

	/**
	 * Parse the expiry timestamp out of a "token|expiry" lock value.
	 *
	 * @param string $lock_value Stored lock value.
	 * @return int Expiry timestamp, or 0 when unparseable/absent.
	 */
	private function expiry( string $lock_value ): int {
		if ( '' === $lock_value ) {
			return 0;
		}
		$parts  = explode( '|', $lock_value );
		$expiry = end( $parts );
		return is_numeric( $expiry ) ? (int) $expiry : 0;
	}
}
