<?php
/**
 * Writer of the Mandrill bulk send status.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use PRC\Primitives\Concurrency\Meta_State;

/**
 * Moves `prc_email_mandrill_send_status` with compare-and-swap writes.
 *
 * The send loop, the Action Scheduler fallback, and the failed-HTML path all
 * call this class. A late or stale writer cannot overwrite a status that has
 * moved on: the swap names the status it expects and loses when the row differs.
 *
 * Meta_State fires no core meta actions, so relay_core_meta_actions() fires
 * them after each winning write. Listeners that wait for a "sent" or "partial"
 * row (the CRM sent log, the report fetch) keep working without a change.
 */
final class Mandrill_Send_State {

	public const META_KEY = 'prc_email_mandrill_send_status';

	/**
	 * Tries begin() makes when another writer moves the row between read and swap.
	 */
	private const MAX_TRIES = 4;

	/**
	 * State machine for the key.
	 *
	 * @var Meta_State|null
	 */
	private static ?Meta_State $machine = null;

	/**
	 * Register the relay of core meta actions.
	 */
	public static function init(): void {
		add_action( Meta_State::TRANSITIONED_ACTION, array( __CLASS__, 'relay_core_meta_actions' ), 10, 4 );
	}

	/**
	 * The status key as a Meta_State.
	 */
	public static function machine(): Meta_State {
		if ( null === self::$machine ) {
			self::$machine = new Meta_State( self::META_KEY, Mandrill_Send_Status::class, Mandrill_Send_Status::edges() );
		}
		return self::$machine;
	}

	/**
	 * Move a post to Sending. Call it while holding the send lease.
	 *
	 * A missing row is created. A row that holds a blank or an unknown value is
	 * removed first: the lease holder owns the key for the whole run. Never
	 * throws, because a status write must not stop a send.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return bool True when this call changed the row. False when the row was already Sending.
	 */
	public static function begin( int $post_id ): bool {
		try {
			for ( $try = 1; $try <= self::MAX_TRIES; $try++ ) {
				$stored = self::read( $post_id );

				if ( null === $stored ) {
					if ( self::machine()->init( $post_id, Mandrill_Send_Status::Sending ) ) {
						return true;
					}
					continue;
				}

				$from = Mandrill_Send_Status::tryFrom( $stored );
				if ( null === $from ) {
					self::log( sprintf( 'post %d stores status "%s"; replacing it with "sending".', $post_id, $stored ) );
					self::clear( $post_id );
					continue;
				}

				if ( Mandrill_Send_Status::Sending === $from ) {
					return false;
				}

				if ( self::machine()->transition( $post_id, $from, Mandrill_Send_Status::Sending ) ) {
					return true;
				}
			}
		} catch ( \Throwable $e ) {
			self::log( sprintf( 'could not move post %d to "sending": %s', $post_id, $e->getMessage() ) );
			return false;
		}

		self::log( sprintf( 'post %d did not reach "sending" after %d tries.', $post_id, self::MAX_TRIES ) );
		return false;
	}

	/**
	 * Move a post from Sending to an end state.
	 *
	 * Loses, and returns false, when the row is not Sending any more.
	 *
	 * @param int                  $post_id Newsletter post ID.
	 * @param Mandrill_Send_Status $to      End state.
	 */
	public static function settle( int $post_id, Mandrill_Send_Status $to ): bool {
		try {
			return self::machine()->transition( $post_id, Mandrill_Send_Status::Sending, $to );
		} catch ( \Throwable $e ) {
			self::log( sprintf( 'could not move post %d to "%s": %s', $post_id, $to->value, $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Move a post from NeedsReview to Sent or Partial after the ledger accepted batches.
	 *
	 * Loses, and returns false, when the row is not NeedsReview any more.
	 *
	 * @param int                  $post_id Newsletter post ID.
	 * @param Mandrill_Send_Status $to      Sent or Partial.
	 */
	public static function reconcile( int $post_id, Mandrill_Send_Status $to ): bool {
		try {
			return self::machine()->transition( $post_id, Mandrill_Send_Status::NeedsReview, $to );
		} catch ( \Throwable $e ) {
			self::log( sprintf( 'could not move post %d from "needs_review" to "%s": %s', $post_id, $to->value, $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Mark a send that no live run owns as failed.
	 *
	 * Call it when the send lease is free. A post that is Sending, or has no
	 * status yet, becomes Failed. A blank row or a value the sender does not own
	 * counts as no status. Any other status stays. A write that loses a race is
	 * retried. A sending row that shows up on a retry belongs to a new run, so
	 * it stays.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return bool True when this call changed the row.
	 */
	public static function fail_if_stalled( int $post_id ): bool {
		try {
			for ( $try = 1; $try <= self::MAX_TRIES; $try++ ) {
				$stored = self::read( $post_id );

				if ( null === $stored ) {
					if ( self::machine()->init( $post_id, Mandrill_Send_Status::Failed ) ) {
						return true;
					}
					continue;
				}

				if ( null === Mandrill_Send_Status::tryFrom( $stored ) ) {
					self::clear_value( $post_id, $stored );
					continue;
				}

				// On a retry, a sending row is not the one the caller found stalled: a new run wrote it after our lost write.
				if ( Mandrill_Send_Status::Sending->value !== $stored || $try > 1 ) {
					return false;
				}
				return self::machine()->transition( $post_id, Mandrill_Send_Status::Sending, Mandrill_Send_Status::Failed );
			}
		} catch ( \Throwable $e ) {
			self::log( sprintf( 'could not mark post %d failed: %s', $post_id, $e->getMessage() ) );
			return false;
		}

		self::log( sprintf( 'post %d did not reach "failed" after %d tries.', $post_id, self::MAX_TRIES ) );
		return false;
	}

	/**
	 * Fire the core meta actions for a status that Meta_State wrote.
	 *
	 * @param int                       $post_id  Post ID.
	 * @param string                    $meta_key Meta key.
	 * @param Mandrill_Send_Status|null $from     Previous state, null for init().
	 * @param Mandrill_Send_Status      $to       New state.
	 */
	public static function relay_core_meta_actions( $post_id, $meta_key, $from, $to ): void {
		global $wpdb;

		if ( self::META_KEY !== $meta_key || ! $to instanceof Mandrill_Send_Status ) {
			return;
		}

		// Raw SQL: get_post_meta() does not return meta IDs.
		$meta_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
				(int) $post_id,
				self::META_KEY
			)
		);

		do_action( null === $from ? 'added_post_meta' : 'updated_post_meta', $meta_id, (int) $post_id, self::META_KEY, $to->value );
	}

	/**
	 * The stored status, read from the primary database.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return string The stored value, or '' when the post has no row.
	 */
	public static function current( int $post_id ): string {
		return self::read( $post_id ) ?? '';
	}

	/**
	 * HyperDB: send this request's postmeta reads to the primary, as Meta_State
	 * does. A replica can still show a status or a checkpoint that another
	 * request has replaced.
	 */
	public static function pin_primary(): void {
		global $wpdb;

		if ( is_array( $wpdb->srtm ?? null ) ) {
			$wpdb->srtm[ $wpdb->postmeta ] = true;
		}
	}

	/**
	 * The stored status as a string, read from the primary database.
	 *
	 * The raw read skips the registered meta default, which would make a
	 * missing row look like one blank row. Several rows count as one blank
	 * value, so the caller replaces them.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return string|null Null when the post has no row.
	 */
	private static function read( int $post_id ): ?string {
		// A replica that still shows sending would make begin() skip its swap.
		self::pin_primary();
		// A writer in another request does not clear this request's cache.
		wp_cache_delete( $post_id, 'post_meta' );
		$rows = get_metadata_raw( 'post', $post_id, self::META_KEY, false );
		$rows = is_array( $rows ) ? $rows : array();
		if ( array() === $rows ) {
			return null;
		}
		if ( count( $rows ) > 1 || ! is_scalar( $rows[0] ) ) {
			return '';
		}
		return (string) $rows[0];
	}

	/**
	 * Remove every status row of a post.
	 *
	 * @param int $post_id Newsletter post ID.
	 */
	private static function clear( int $post_id ): void {
		delete_post_meta( $post_id, self::META_KEY );
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Remove the status row that holds exactly $value.
	 *
	 * One DELETE that matches the value. A status that another writer put there
	 * after our read does not match, so it stays. The caller does not hold the
	 * send lease, so it must not remove a row it has not read.
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $value   The exact stored value to remove.
	 */
	private static function clear_value( int $post_id, string $value ): void {
		global $wpdb;

		// Raw SQL: delete_post_meta() cannot test the value in the same statement.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND CAST(meta_value AS BINARY) = %s",
				$post_id,
				self::META_KEY,
				$value
			)
		);
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Log a status problem.
	 *
	 * @param string $message What happened.
	 */
	private static function log( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[prc-email-builder] Mandrill send status: ' . $message );
	}
}
