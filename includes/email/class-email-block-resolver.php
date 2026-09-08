<?php
/**
 * Email Block Resolver.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Block_Type_Registry;

/**
 * Resolves block-level email-HTML behavior from block.json metadata.
 *
 * Blocks may declare a `prcEmailHtml` key in their block.json that is
 * injected into `supports` at registration time (same mechanism as
 * prc-markdown-for-agents uses for `prcMarkdownForAgents`).
 *
 * Supported block.json metadata contract:
 * - prcEmailHtml.callback: callable string (e.g. 'My\Plugin\Class::method')
 * - prcEmailHtml.mode:     html-fallback | strip | children-only
 *
 * @package PRC\Platform\Email_Builder
 */
class Email_Block_Resolver {

	/**
	 * Inject prcEmailHtml block metadata into supports so it survives registration.
	 *
	 * Hook this onto `block_type_metadata` at init time.
	 *
	 * @param array $metadata Raw block metadata.
	 * @return array
	 */
	public static function inject_metadata_into_supports( array $metadata ): array {
		if ( empty( $metadata['prcEmailHtml'] ) || ! is_array( $metadata['prcEmailHtml'] ) ) {
			return $metadata;
		}

		if ( ! isset( $metadata['supports'] ) || ! is_array( $metadata['supports'] ) ) {
			$metadata['supports'] = array();
		}

		$metadata['supports']['prcEmailHtml'] = $metadata['prcEmailHtml'];

		return $metadata;
	}

	/**
	 * Get the prcEmailHtml config for a registered block, if any.
	 *
	 * @param string $block_name Block name.
	 * @return array|null
	 */
	public static function get_block_config( string $block_name ): ?array {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
		if ( ! $block_type || ! isset( $block_type->supports ) || ! is_array( $block_type->supports ) ) {
			return null;
		}

		$config = $block_type->supports['prcEmailHtml'] ?? null;
		if ( ! is_array( $config ) ) {
			return null;
		}

		return $config;
	}

	/**
	 * Resolve a block's email-HTML handling strategy from block.json metadata.
	 *
	 * Returns an array with:
	 *   handled  (bool)   — whether this resolver claimed the block
	 *   recurse  (bool)   — whether the caller should recurse into innerBlocks
	 *   html     (string) — produced HTML fragment (may be empty)
	 *
	 * @param string   $block_name Block name.
	 * @param array    $block      Parsed block array.
	 * @param \WP_Post $post       Post being converted.
	 * @return array{handled:bool,recurse:bool,html:string}
	 */
	public static function resolve_strategy( string $block_name, array $block, \WP_Post $post ): array {
		$config = self::get_block_config( $block_name );
		if ( null === $config ) {
			return array(
				'handled' => false,
				'recurse' => false,
				'html'    => '',
			);
		}

		$mode = isset( $config['mode'] ) ? sanitize_key( (string) $config['mode'] ) : 'html-fallback';

		if ( 'strip' === $mode ) {
			return array(
				'handled' => true,
				'recurse' => false,
				'html'    => '',
			);
		}

		if ( 'children-only' === $mode ) {
			return array(
				'handled' => true,
				'recurse' => true,
				'html'    => '',
			);
		}

		// html-fallback mode (or default): try the declared callback.
		$callback = $config['callback'] ?? null;
		if ( is_string( $callback ) && is_callable( $callback ) ) {
			return array(
				'handled' => true,
				'recurse' => false,
				'html'    => (string) call_user_func( $callback, $block, $post ),
			);
		}

		return array(
			'handled' => false,
			'recurse' => false,
			'html'    => '',
		);
	}
}
