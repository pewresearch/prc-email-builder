<?php
declare(strict_types=1);
/**
 * Durable recipient log for keyed dynamic system emails.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Appends successful system-email sends to the recipients table.
 */
class System_Email_Send_Log {

	/**
	 * @param Loader|null $loader Plugin loader; when null hooks are not registered (tests).
	 */
	public function __construct( ?Loader $loader = null ) {
		if ( null === $loader ) {
			return;
		}

		$loader->add_action( 'prc_email_builder_system_email_sent', $this, 'record_send', 10, 3 );
		$loader->add_action( 'admin_init', System_Email_Recipients_Table::class, 'maybe_create_table' );

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
	public function record_send( int $post_id, string $to_email, array $context = [] ): void {
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
	}
}
