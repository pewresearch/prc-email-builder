<?php
declare(strict_types=1);
/**
 * Email Block Converter.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Converts a newsletter post's block content into an email-safe HTML fragment
 * suitable for injection into Email_Template::wrap().
 *
 * Walk order mirrors Markdown_Converter::blocks_to_markdown():
 *   1. block.json prcEmailHtml resolver (declarative callbacks + modes)
 *   2. Email_Block_Registry callback (imperative registration via action hook)
 *   3. Container fallback: recurse into innerBlocks
 *   4. Leaf fallback: render_block() → Html_To_Email_Converter
 *
 * The global $post is set before conversion so dynamic blocks that call
 * get_the_ID(), the_permalink(), etc. (e.g. prc-block/story-item,
 * prc-chart-builder/synced-chart) work correctly.
 *
 * @package PRC\Platform\Email_Builder
 */
class Email_Block_Converter {

	/**
	 * Block names that are treated as transparent containers: their own
	 * markup is discarded and their innerBlocks are recursed into.
	 *
	 * core/group covers the majority of structural wrapper cases.
	 * core/column / core/columns are listed here so their children still render
	 * even though proper multi-column email layout is out of scope (flagged as
	 * open risk in the plan).
	 *
	 * @var string[]
	 */
	private const CONTAINER_BLOCKS = array(
		'core/column',
		'core/columns',
	);

	/**
	 * Convert a newsletter post's content to an email HTML fragment.
	 *
	 * @param int|\WP_Post $post Post ID or post object.
	 * @return string Email-safe HTML fragment (no DOCTYPE / <html> / <body>).
	 */
	public function convert( $post ): string {
		$post_object = get_post( $post );
		if ( ! $post_object ) {
			return '';
		}

		// Set global $post so dynamic blocks render in post context.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		global $post;
		$saved_global = $post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post = $post_object;
		setup_postdata( $post );

		Dark_Mode_Registry::reset();

		$blocks = parse_blocks( $post_object->post_content );
		$html   = $this->blocks_to_email_html( $blocks, $post_object );

		wp_reset_postdata();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post = $saved_global;

		return trim( $html );
	}

	/**
	 * Recursively convert a flat array of parsed blocks to email HTML.
	 *
	 * @param array    $blocks Array of parsed block arrays from parse_blocks().
	 * @param \WP_Post $post   Post being converted.
	 * @return string Concatenated email-safe HTML fragment.
	 */
	public function blocks_to_email_html( array $blocks, \WP_Post $post ): string {
		$parts    = array();
		$fallback = new Html_To_Email_Converter();

		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? null;

			// Null blockName = freeform / classic content between blocks.
			if ( null === $block_name ) {
				$trimmed = trim( $block['innerHTML'] ?? '' );
				if ( '' !== $trimmed ) {
					$parts[] = $fallback->convert( $trimmed );
				}
				continue;
			}

			// 1. block.json declarative resolver.
			$resolved = Email_Block_Resolver::resolve_strategy( $block_name, $block, $post );
			if ( true === $resolved['handled'] ) {
				if ( true === $resolved['recurse'] ) {
					$inner = $block['innerBlocks'] ?? array();
					if ( ! empty( $inner ) ) {
						$inner_html = $this->blocks_to_email_html( $inner, $post );
						if ( '' !== trim( $inner_html ) ) {
							$parts[] = $inner_html;
						}
					}
					continue;
				}
				if ( '' !== trim( (string) $resolved['html'] ) ) {
					$parts[] = (string) $resolved['html'];
				}
				continue;
			}

			// 2. Imperative registry callback.
			$callback = Email_Block_Registry::get( $block_name );
			if ( $callback ) {
				$fragment = (string) call_user_func( $callback, $block, $post );

				/**
				 * Filter the email HTML output for a specific block type.
				 *
				 * Dynamic portion: block name (e.g. 'core/paragraph').
				 *
				 * @param string   $fragment Email HTML fragment.
				 * @param array    $block    Parsed block array.
				 * @param \WP_Post $post     Post being converted.
				 */
				$fragment = (string) apply_filters(
					'prc_email_builder_email_block_' . $block_name,
					$fragment,
					$block,
					$post
				);

				if ( '' !== trim( $fragment ) ) {
					$parts[] = $fragment;
				}
				continue;
			}

			// 3. Transparent container: recurse innerBlocks.
			$inner = $block['innerBlocks'] ?? array();
			if ( in_array( $block_name, self::CONTAINER_BLOCKS, true ) ) {
				if ( ! empty( $inner ) ) {
					$inner_html = $this->blocks_to_email_html( $inner, $post );
					if ( '' !== trim( $inner_html ) ) {
						$parts[] = $inner_html;
					}
				}
				continue;
			}

			// 4. Any other block with innerBlocks that has registered callbacks
			//    inside — recurse so those callbacks are honoured.
			if ( ! empty( $inner ) ) {
				$inner_html = $this->blocks_to_email_html( $inner, $post );
				if ( '' !== trim( $inner_html ) ) {
					$parts[] = $inner_html;
					continue;
				}
			}

			// 5. Leaf block with no callback — render to HTML and apply fallback.
			$rendered = render_block( $block );
			if ( '' !== trim( $rendered ) ) {
				$parts[] = $fallback->convert( $rendered );
			}
		}

		return implode( "\n", $parts );
	}
}
