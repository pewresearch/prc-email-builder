<?php
declare(strict_types=1);
/**
 * Query Loop hardening for the Newsletter Campaigns variation.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Forces published campaign query vars for core/query blocks
 * using the prc-email-builder/campaign-query namespace.
 */
class Campaign_Query {

	const NAMESPACE = 'prc-email-builder/campaign-query';

	/** @var int Nesting depth of matching query blocks currently rendering. */
	private int $query_block_depth = 0;

	/** @var callable|null Active query_loop_block_query_vars callback. */
	private $query_loop_filter_callback = null;

	public function __construct( Loader $loader ) {
		$loader->add_filter( 'pre_render_block', $this, 'maybe_add_query_loop_filter', 10, 3 );
		$loader->add_filter( 'render_block', $this, 'maybe_remove_query_loop_filter', 10, 2 );
	}

	/**
	 * When our namespaced core/query is about to render, scope query vars.
	 *
	 * @hook pre_render_block
	 *
	 * @param mixed                $pre_render   Pre-render value.
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @param mixed                $parent_block Parent block (unused).
	 * @return mixed
	 */
	public function maybe_add_query_loop_filter( $pre_render, $parsed_block, $parent_block = null ) {
		if ( ! is_array( $parsed_block ) || 'core/query' !== ( $parsed_block['blockName'] ?? '' ) ) {
			return $pre_render;
		}

		$attrs = $parsed_block['attrs'] ?? array();
		if ( self::NAMESPACE !== ( $attrs['namespace'] ?? '' ) ) {
			return $pre_render;
		}

		++$this->query_block_depth;
		if ( 1 !== $this->query_block_depth ) {
			return $pre_render;
		}

		$this->query_loop_filter_callback = array( $this, 'filter_query_loop_vars' );
		add_filter( 'query_loop_block_query_vars', $this->query_loop_filter_callback, 10, 2 );

		return $pre_render;
	}

	/**
	 * After our namespaced core/query finishes, tear down the scoped filter.
	 *
	 * @hook render_block
	 *
	 * @param string               $block_content HTML.
	 * @param array<string, mixed> $block         Parsed block.
	 * @return string
	 */
	public function maybe_remove_query_loop_filter( string $block_content, array $block ): string {
		if ( 'core/query' !== ( $block['blockName'] ?? '' ) ) {
			return $block_content;
		}

		$attrs = $block['attrs'] ?? array();
		if ( self::NAMESPACE !== ( $attrs['namespace'] ?? '' ) ) {
			return $block_content;
		}

		if ( $this->query_block_depth < 1 ) {
			return $block_content;
		}

		if ( 1 === $this->query_block_depth && null !== $this->query_loop_filter_callback ) {
			remove_filter( 'query_loop_block_query_vars', $this->query_loop_filter_callback, 10 );
			$this->query_loop_filter_callback = null;
		}

		--$this->query_block_depth;

		return $block_content;
	}

	/**
	 * Force listing-safe query vars without pinning result count.
	 *
	 * Unlike Latest_Campaign_Query, this variation keeps the author's
	 * posts_per_page and includes migrated archive campaigns.
	 *
	 * @param array<string, mixed> $query Query vars.
	 * @param mixed                $block Block instance (unused).
	 * @return array<string, mixed>
	 */
	public function filter_query_loop_vars( $query, $block = null ) {
		if ( ! is_array( $query ) ) {
			$query = array();
		}

		$query['post_type']           = Post_Type::CAMPAIGN_POST_TYPE;
		$query['post_status']         = 'publish';
		$query['ignore_sticky_posts'] = true;
		$query['has_password']        = false;

		return $query;
	}
}
