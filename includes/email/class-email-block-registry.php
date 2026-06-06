<?php
declare(strict_types=1);
/**
 * Email Block Registry.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Static registry mapping block names to email-HTML callbacks.
 *
 * Other plugins register their callbacks on the
 * `prc_email_builder_register_email_callbacks` action, which fires
 * at init priority 5. Each callback receives the parsed block array and
 * the WP_Post being converted, and returns an email-safe HTML fragment.
 *
 * Example:
 *   add_action( 'prc_email_builder_register_email_callbacks', function() {
 *       Email_Block_Registry::register(
 *           'my-plugin/my-block',
 *           function( array $block, \WP_Post $post ): string {
 *               return '<p style="...">' . esc_html( $block['attrs']['text'] ?? '' ) . '</p>';
 *           }
 *       );
 *   } );
 *
 * @package PRC\Platform\Email_Builder
 */
class Email_Block_Registry {

	/**
	 * Registered block-name → callable map.
	 *
	 * @var array<string, callable>
	 */
	private static array $callbacks = array();

	/**
	 * Register an email-HTML callback for a block type.
	 *
	 * @param string   $block_name Fully-qualified block name (e.g. 'core/paragraph').
	 * @param callable $callback   fn(array $block, \WP_Post $post): string
	 *                             Returns an email-safe HTML fragment, or empty
	 *                             string to suppress the block entirely.
	 */
	public static function register( string $block_name, callable $callback ): void {
		self::$callbacks[ $block_name ] = $callback;
	}

	/**
	 * Get the registered callback for a block type, if any.
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return callable|null
	 */
	public static function get( string $block_name ): ?callable {
		return self::$callbacks[ $block_name ] ?? null;
	}

	/**
	 * Check if a block type has a registered email-HTML callback.
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return bool
	 */
	public static function has( string $block_name ): bool {
		return isset( self::$callbacks[ $block_name ] );
	}

	/**
	 * Return all registered block names (useful for tests/introspection).
	 *
	 * @return string[]
	 */
	public static function all_block_names(): array {
		return array_keys( self::$callbacks );
	}

	/**
	 * Reset the registry (test use only).
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$callbacks = array();
	}
}
