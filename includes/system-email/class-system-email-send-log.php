<?php
/**
 * Durable recipient log for keyed dynamic system emails.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Appends successful system-email sends to the recipients table.
 */
class System_Email_Send_Log {

	/**
	 * Send-status meta value for dynamic templates that have delivered at least once.
	 */
	public const ACTIVE_STATUS = 'active';

	/**
	 * Shared with Mandrill bulk sends; for dynamic templates stores waiting/active lifecycle.
	 */
	public const STATUS_META = 'prc_email_mandrill_send_status';

	/**
	 * Option flag so the one-time Active backfill runs only once.
	 */
	public const BACKFILL_OPTION = 'prc_email_dynamic_active_status_backfilled';

	/**
	 * Construct.
	 *
	 * @param Loader|null $loader Plugin loader; when null hooks are not registered (tests).
	 */
	public function __construct( ?Loader $loader = null ) {
		if ( null === $loader ) {
			return;
		}

		$loader->add_action( 'prc_email_builder_system_email_sent', $this, 'record_send', 10, 3 );
		$loader->add_action( 'admin_init', System_Email_Recipients_Table::class, 'maybe_create_table' );
		$loader->add_action( 'admin_init', $this, 'maybe_backfill_active_status' );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$loader->add_action( 'cli_init', System_Email_Recipients_Table::class, 'maybe_create_table' );
		}
	}

	/**
	 * Record a successful dynamic system email send.
	 *
	 * @hook prc_email_builder_system_email_sent
	 *
	 * @param int                  $post_id  Newsletter post ID.
	 * @param string               $to_email Recipient address.
	 * @param array<string, mixed> $context  Merge context (unused; reserved for future enrichment).
	 */
	public function record_send( int $post_id, string $to_email, array $context = array() ): void {
		unset( $context );

		$system_email_key = (string) get_post_field( 'post_name', $post_id );
		if ( '' === $system_email_key ) {
			return;
		}

		if ( ! is_email( $to_email ) ) {
			return;
		}

		System_Email_Recipients_Table::upsert_recipient(
			$system_email_key,
			$post_id,
			strtolower( $to_email )
		);

		self::mark_active( $post_id );
	}

	/**
	 * Mark a dynamic template as Active after its first successful send.
	 *
	 * @param int $post_id Post id.
	 */
	public static function mark_active( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$current = (string) get_post_meta( $post_id, self::STATUS_META, true );
		if ( self::ACTIVE_STATUS === $current ) {
			return;
		}

		update_post_meta( $post_id, self::STATUS_META, self::ACTIVE_STATUS );
	}

	/**
	 * One-time backfill: mark dynamic templates Active when recipient history exists.
	 *
	 * @hook admin_init
	 */
	public function maybe_backfill_active_status(): void {
		if ( get_option( self::BACKFILL_OPTION ) ) {
			return;
		}

		System_Email_Recipients_Table::maybe_create_table();
		if ( ! System_Email_Recipients_Table::table_exists() ) {
			update_option( self::BACKFILL_OPTION, '1', false );
			return;
		}

		global $wpdb;
		$table = System_Email_Recipients_Table::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$table} WHERE post_id > 0" );

		if ( ! is_array( $post_ids ) ) {
			$post_ids = array();
		}

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || ! Post_Type::is_transactional_post( $post ) ) {
				continue;
			}

			if ( 'dynamic' !== Post_Type::transactional_delivery_mode( $post ) ) {
				continue;
			}

			$current = (string) get_post_meta( $post_id, self::STATUS_META, true );
			if ( '' !== $current ) {
				continue;
			}

			self::mark_active( $post_id );
		}

		update_option( self::BACKFILL_OPTION, '1', false );
	}
}
