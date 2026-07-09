<?php
declare(strict_types=1);
/**
 * Newsletter Glue to PRC Newsletter Builder migration engine.
 *
 * Transforms `newsletterglue` CPT posts into `prc_email_campaign` or `prc_email_txn` posts,
 * converting proprietary newsletterglue/* blocks to core equivalents
 * and mapping meta + taxonomy.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use WP_Error;
use WP_Post;
use DOMDocument;
use DOMXPath;

class Migration {

	const NGL_POST_TYPE        = 'newsletterglue';

	/**
	 * Post statuses included when discovering NGL posts for migration (Action Scheduler + CLI).
	 */
	const NGL_MIGRATION_POST_STATUSES = [ 'publish', 'draft', 'future' ];

	const NGL_TAXONOMY         = 'ngl_newsletter_cat';
	const NGL_META_KEY         = '_newsletterglue';
	const MIGRATED_META_KEY    = '_migrated_from_ngl_id';
	const UNMAPPED_META_KEY    = '_ngl_migration_unmapped_meta';

	/**
	 * Map of NGL meta array keys to prc_email_* meta keys.
	 */
	const META_MAP = [
		'subject'      => 'prc_email_subject',
		'preview_text' => 'prc_email_preview_text',
		'app'          => 'prc_email_delivery_mode',
		'lists'        => 'prc_email_mailchimp_audience_id',
		'segments'     => 'prc_email_mailchimp_segment_id',
	];

	/**
	 * Block name transform strategy map.
	 * Value is either a core block name string or 'drop'/'unwrap'/'html_passthrough'.
	 */
	const BLOCK_MAP = [
		'newsletterglue/text'         => 'core/paragraph',
		'newsletterglue/heading'      => 'core/heading',
		'newsletterglue/image'        => 'core/image',
		'newsletterglue/list'         => 'core/list',
		'newsletterglue/list-item'    => 'core/list-item',
		'newsletterglue/quote'        => 'core/quote',
		'newsletterglue/separator'    => 'core/separator',
		'newsletterglue/spacer'       => 'core/spacer',
		'newsletterglue/table'        => 'core/table',
		'newsletterglue/embed'        => 'core/embed',
		'newsletterglue/button'       => 'core/button',
		'newsletterglue/buttons'      => 'core/buttons',
		'newsletterglue/container'    => 'core/group',
		'newsletterglue/sections'     => 'core/columns',
		'newsletterglue/section'      => 'core/column',
		'newsletterglue/latest-posts' => 'core/latest-posts',
		'newsletterglue/post-embeds'  => 'core/latest-posts',
		'newsletterglue/social-icons' => 'core/social-links',
		'newsletterglue/social-icon'  => 'core/social-link',
		'newsletterglue/share-link'   => 'core/social-link',
		'newsletterglue/post-author'  => 'core/post-author',
		'newsletterglue/meta-data'    => 'core/group',
		'newsletterglue/html'         => 'core/html',
		'newsletterglue/optin'        => 'drop',
		'newsletterglue/showhide'     => 'unwrap',
		'newsletterglue/form'         => 'drop',
		'newsletterglue/article'      => 'core/group',
		'newsletterglue/author'       => 'core/post-author',
		'newsletterglue/callout'      => 'core/group',
		'newsletterglue/columns'      => 'core/columns',
		'newsletterglue/column'       => 'core/column',
		'newsletterglue/metadata'     => 'core/group',
		'newsletterglue/share'        => 'core/social-links',
		'newsletterglue/group'        => 'unwrap',
	];

	/**
	 * Check whether an email post was migrated from Newsletter Glue.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if the post has the migration marker meta.
	 */
	public static function is_migrated( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, self::MIGRATED_META_KEY, true );
	}

	/**
	 * Warnings collected during the current migration.
	 *
	 * @var string[]
	 */
	private array $warnings = [];

	/**
	 * Migrate a single newsletterglue post to a campaign or transactional email post.
	 *
	 * @param int  $ngl_post_id The source newsletterglue post ID.
	 * @param bool $force       Re-migrate even if already migrated.
	 * @return int|WP_Error New post ID on success, WP_Error on failure.
	 */
	public function migrate_single_post( int $ngl_post_id, bool $force = false ) {
		$this->reset_warnings_for_run();

		$ngl_post = get_post( $ngl_post_id );
		if ( ! $ngl_post instanceof WP_Post ) {
			return new WP_Error( 'post_not_found', "NGL post {$ngl_post_id} not found." );
		}

		if ( self::NGL_POST_TYPE !== $ngl_post->post_type ) {
			return new WP_Error( 'wrong_post_type', "Post {$ngl_post_id} is not a {" . self::NGL_POST_TYPE . "} post." );
		}

		$existing = $this->find_migrated_post( $ngl_post_id );
		if ( $existing && ! $force ) {
			return new WP_Error(
				'already_migrated',
				"NGL post {$ngl_post_id} was already migrated to email post {$existing}."
			);
		}

		$transformed_content = $this->transform_blocks( $ngl_post->post_content );
		$mapped_meta         = $this->map_meta( $ngl_post_id );

		$delivery_mode = $mapped_meta['mapped']['prc_email_delivery_mode'] ?? 'mailchimp';

		$post_data = [
			'post_type'     => Post_Type::post_type_for_delivery_mode( (string) $delivery_mode ),
			'post_title'    => $ngl_post->post_title,
			'post_name'     => $ngl_post->post_name,
			'post_content'  => $transformed_content,
			'post_status'  => $ngl_post->post_status,
			'post_date'    => $ngl_post->post_date,
			'post_date_gmt' => $ngl_post->post_date_gmt,
			'post_excerpt' => $ngl_post->post_excerpt,
			'post_author'  => $ngl_post->post_author,
		];

		if ( $existing && $force ) {
			$post_data['ID'] = $existing;
			$new_post_id = wp_update_post( $post_data, true );
		} else {
			$new_post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		update_post_meta( $new_post_id, self::MIGRATED_META_KEY, $ngl_post_id );

		foreach ( $mapped_meta['mapped'] as $key => $value ) {
			update_post_meta( $new_post_id, $key, $value );
		}

		// Campaign posts do not use delivery_mode; remove the marker after migration.
		if ( Post_Type::CAMPAIGN_POST_TYPE === $post_data['post_type'] ) {
			delete_post_meta( $new_post_id, 'prc_email_delivery_mode' );
		}

		if ( ! empty( $mapped_meta['unmapped'] ) ) {
			update_post_meta( $new_post_id, self::UNMAPPED_META_KEY, $mapped_meta['unmapped'] );
		}

		$this->map_taxonomy( $ngl_post_id, $new_post_id );

		if ( $ngl_post->_thumbnail_id ) {
			set_post_thumbnail( $new_post_id, (int) $ngl_post->_thumbnail_id );
		}

		return $new_post_id;
	}

	/**
	 * Get warnings from the last migration run.
	 *
	 * @return string[]
	 */
	public function get_warnings(): array {
		return $this->warnings;
	}

	/**
	 * Clear warnings before processing a post (CLI dry-run and migrate_single_post).
	 */
	public function reset_warnings_for_run(): void {
		$this->warnings = [];
	}

	/**
	 * Find an already-migrated email post for a given NGL post ID.
	 *
	 * @param int $ngl_post_id Original NGL post ID.
	 * @return int|null The email post ID, or null if not found.
	 */
	private function find_migrated_post( int $ngl_post_id ): ?int {
		$query = new \WP_Query( [
			'post_type'      => Post_Type::POST_TYPES,
			'post_status'    => 'any',
			'meta_key'       => self::MIGRATED_META_KEY,
			'meta_value'     => $ngl_post_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		return ! empty( $query->posts ) ? (int) $query->posts[0] : null;
	}

	/**
	 * Transform all blocks in post_content from NGL blocks to core blocks.
	 *
	 * @param string $post_content Raw post content with NGL block markup.
	 * @return string Transformed post content with core blocks.
	 */
	public function transform_blocks( string $post_content ): string {
		$blocks = parse_blocks( $post_content );
		$transformed = $this->transform_block_list( $blocks );
		return serialize_blocks( $transformed );
	}

	/**
	 * Recursively transform a list of parsed blocks.
	 *
	 * @param array $blocks Parsed block array.
	 * @return array Transformed block array.
	 */
	private function transform_block_list( array $blocks ): array {
		$result = [];

		foreach ( $blocks as $block ) {
			$transformed = $this->transform_single_block( $block );
			if ( null === $transformed ) {
				continue;
			}
			if ( isset( $transformed['__unwrap'] ) ) {
				foreach ( $transformed['__unwrap'] as $inner ) {
					$result[] = $inner;
				}
			} else {
				$result[] = $transformed;
			}
		}

		return $result;
	}

	/**
	 * Transform a single parsed block.
	 *
	 * @param array $block A single parsed block.
	 * @return array|null Transformed block, unwrap sentinel, or null to drop.
	 */
	private function transform_single_block( array $block ): ?array {
		$name = $block['blockName'] ?? null;

		if ( null === $name || '' === $name ) {
			return $block;
		}

		if ( ! str_starts_with( $name, 'newsletterglue/' ) ) {
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->transform_block_list( $block['innerBlocks'] );
			}
			return $block;
		}

		$strategy = self::BLOCK_MAP[ $name ] ?? null;

		if ( null === $strategy ) {
			$this->warnings[] = "Unrecognized NGL block: {$name}. Passing through as core/html.";
			return $this->fallback_to_html( $block );
		}

		if ( 'drop' === $strategy ) {
			return null;
		}

		if ( $this->is_hidden_from_web( $block ) ) {
			return null;
		}

		if ( 'unwrap' === $strategy ) {
			$inner = ! empty( $block['innerBlocks'] )
				? $this->transform_block_list( $block['innerBlocks'] )
				: [];
			return [ '__unwrap' => $inner ];
		}

		return match ( $name ) {
			'newsletterglue/text'         => $this->transform_text( $block ),
			'newsletterglue/heading'      => $this->transform_heading( $block ),
			'newsletterglue/image'        => $this->transform_image( $block ),
			'newsletterglue/list'         => $this->transform_list( $block ),
			'newsletterglue/list-item'    => $this->transform_list_item( $block ),
			'newsletterglue/quote'        => $this->transform_quote( $block ),
			'newsletterglue/separator'    => $this->transform_separator( $block ),
			'newsletterglue/spacer'       => $this->transform_spacer( $block ),
			'newsletterglue/table'        => $this->transform_table( $block ),
			'newsletterglue/embed'        => $this->transform_embed( $block ),
			'newsletterglue/button'       => $this->transform_button( $block ),
			'newsletterglue/buttons'      => $this->transform_buttons( $block ),
			'newsletterglue/container',
			'newsletterglue/article',
			'newsletterglue/callout'      => $this->transform_group( $block ),
			'newsletterglue/sections',
			'newsletterglue/columns'      => $this->transform_columns( $block ),
			'newsletterglue/section',
			'newsletterglue/column'       => $this->transform_column( $block ),
			'newsletterglue/social-icons',
			'newsletterglue/share'        => $this->transform_social_links( $block ),
			'newsletterglue/social-icon',
			'newsletterglue/share-link'   => $this->transform_social_link( $block ),
			'newsletterglue/post-author',
			'newsletterglue/author'       => $this->transform_post_author( $block ),
			'newsletterglue/meta-data',
			'newsletterglue/metadata'     => $this->transform_meta_data( $block ),
			'newsletterglue/html'         => $this->transform_html( $block ),
			'newsletterglue/latest-posts',
			'newsletterglue/post-embeds'  => $this->transform_latest_posts( $block ),
			default                       => $this->fallback_to_html( $block ),
		};
	}

	/**
	 * Check whether a block is explicitly hidden from web display.
	 *
	 * Covers three NGL visibility patterns:
	 * - showhide block: `show_in_web` attribute (default true)
	 * - group block: `showblog` attribute (default true per options, unset = show)
	 * - any NGL block: `show_in_web` attribute set to false
	 */
	private function is_hidden_from_web( array $block ): bool {
		$attrs = $block['attrs'] ?? [];
		$name  = $block['blockName'] ?? '';

		if ( isset( $attrs['show_in_web'] ) && false === $attrs['show_in_web'] ) {
			return true;
		}

		if ( 'newsletterglue/group' === $name && isset( $attrs['showblog'] ) && false === $attrs['showblog'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Extract inner HTML content from NGL table wrapper markup.
	 * NGL blocks wrap content in <table><tbody><tr><td class="ng-block-td">...content...</td></tr></tbody></table>.
	 *
	 * @param string $inner_html The block innerHTML.
	 * @return string Extracted content, or original if extraction fails.
	 */
	private function extract_from_table_wrapper( string $inner_html ): string {
		$inner_html = trim( $inner_html );
		if ( empty( $inner_html ) ) {
			return '';
		}

		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div>' . $inner_html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath = new DOMXPath( $doc );

		$td_nodes = $xpath->query( '//td[contains(@class, "ng-block-td")]' );
		if ( $td_nodes && $td_nodes->length > 0 ) {
			$td = $td_nodes->item( 0 );
			$content = '';
			foreach ( $td->childNodes as $child ) {
				$content .= $doc->saveHTML( $child );
			}
			return trim( $content );
		}

		return $inner_html;
	}

	/**
	 * newsletterglue/text -> core/paragraph
	 */
	private function transform_text( array $block ): array {
		$attrs = $block['attrs'] ?? [];
		$content = $attrs['content'] ?? '';

		if ( empty( $content ) ) {
			$content = $this->extract_from_table_wrapper( $this->get_inner_html( $block ) );
			$content = $this->extract_tag_content( $content, 'p' );
		}

		$new_attrs = [];
		if ( ! empty( $attrs['align'] ) && 'none' !== $attrs['align'] ) {
			$new_attrs['align'] = $attrs['align'];
		}

		$inner_html = "\n<p>{$content}</p>\n";

		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => $new_attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $inner_html,
			'innerContent' => [ $inner_html ],
		];
	}

	/**
	 * newsletterglue/heading -> core/heading
	 */
	private function transform_heading( array $block ): array {
		$attrs = $block['attrs'] ?? [];
		$level = ! empty( $attrs['level'] ) ? (int) $attrs['level'] : 2;
		$content = $attrs['content'] ?? '';

		if ( empty( $content ) ) {
			$content = $this->extract_from_table_wrapper( $this->get_inner_html( $block ) );
			$tag = 'h' . $level;
			$content = $this->extract_tag_content( $content, $tag );
		}

		$new_attrs = [ 'level' => $level ];
		if ( ! empty( $attrs['textAlign'] ) ) {
			$new_attrs['textAlign'] = $attrs['textAlign'];
		}

		$tag = 'h' . $level;
		$inner_html = "\n<{$tag}>{$content}</{$tag}>\n";

		return [
			'blockName'    => 'core/heading',
			'attrs'        => $new_attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $inner_html,
			'innerContent' => [ $inner_html ],
		];
	}

	/**
	 * newsletterglue/image -> core/image
	 */
	private function transform_image( array $block ): array {
		$attrs = $block['attrs'] ?? [];

		$url    = $attrs['url'] ?? '';
		$alt    = $attrs['alt'] ?? '';
		$width  = ! empty( $attrs['width'] ) ? (int) $attrs['width'] : null;
		$height = ! empty( $attrs['height'] ) ? (int) $attrs['height'] : null;
		$id     = ! empty( $attrs['id'] ) ? (int) $attrs['id'] : null;
		$href   = $attrs['href'] ?? '';
		$caption = $attrs['caption'] ?? '';
		$size_slug = $attrs['sizeSlug'] ?? 'full';

		if ( empty( $url ) ) {
			$extracted = $this->extract_from_table_wrapper( $this->get_inner_html( $block ) );
			$url = $this->extract_img_attr( $extracted, 'src' ) ?: $url;
			$alt = $this->extract_img_attr( $extracted, 'alt' ) ?: $alt;
		}

		$new_attrs = [ 'sizeSlug' => $size_slug ];
		if ( $id ) {
			$new_attrs['id'] = $id;
		}
		if ( $width ) {
			$new_attrs['width'] = $width;
		}
		if ( $height ) {
			$new_attrs['height'] = $height;
		}

		$img_classes = $id ? "wp-image-{$id}" : '';
		$img_tag = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"';
		if ( $img_classes ) {
			$img_tag .= ' class="' . esc_attr( $img_classes ) . '"';
		}
		$img_tag .= '/>';

		$figure_content = $img_tag;
		if ( $href ) {
			$figure_content = '<a href="' . esc_url( $href ) . '">' . $img_tag . '</a>';
			$new_attrs['linkDestination'] = 'custom';
		}

		$caption_html = '';
		if ( ! empty( $caption ) ) {
			$caption_html = '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
		}

		$inner_html = "\n<figure class=\"wp-block-image size-{$size_slug}\">{$figure_content}{$caption_html}</figure>\n";

		return [
			'blockName'    => 'core/image',
			'attrs'        => $new_attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $inner_html,
			'innerContent' => [ $inner_html ],
		];
	}

	/**
	 * newsletterglue/list -> core/list
	 */
	private function transform_list( array $block ): array {
		$attrs = $block['attrs'] ?? [];
		$ordered = ! empty( $attrs['ordered'] );

		$new_attrs = [];
		if ( $ordered ) {
			$new_attrs['ordered'] = true;
		}
		if ( ! empty( $attrs['start'] ) ) {
			$new_attrs['start'] = (int) $attrs['start'];
		}

		$inner_blocks = ! empty( $block['innerBlocks'] )
			? $this->transform_block_list( $block['innerBlocks'] )
			: [];

		$tag = $ordered ? 'ol' : 'ul';
		$inner_html = "\n<{$tag}>\n\n</{$tag}>\n";

		$inner_content = [ "\n<{$tag}>\n" ];
		foreach ( $inner_blocks as $ib ) {
			$inner_content[] = null;
			$inner_content[] = "\n";
		}
		$inner_content[] = "</{$tag}>\n";

		return [
			'blockName'    => 'core/list',
			'attrs'        => $new_attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * newsletterglue/list-item -> core/list-item
	 */
	private function transform_list_item( array $block ): array {
		$attrs = $block['attrs'] ?? [];
		$content = $attrs['content'] ?? '';

		if ( empty( $content ) ) {
			$raw = $this->get_inner_html( $block );
			if ( preg_match( '/<li[^>]*>(.*?)<\/li>/si', $raw, $m ) ) {
				$content = trim( $m[1] );
			}
		}

		$inner_blocks = ! empty( $block['innerBlocks'] )
			? $this->transform_block_list( $block['innerBlocks'] )
			: [];

		if ( ! empty( $inner_blocks ) ) {
			// When there are nested sub-lists, serialize_block() only emits inner
			// blocks at null positions in innerContent. Build the array with the
			// opening <li> + text content first, a null placeholder for each child
			// block, then the closing </li> — matching the pattern used by
			// transform_list, transform_group, and transform_columns.
			$inner_html    = "\n<li>{$content}\n\n</li>\n";
			$inner_content = [ "\n<li>{$content}\n" ];
			foreach ( $inner_blocks as $ib ) {
				$inner_content[] = null;
				$inner_content[] = "\n";
			}
			$inner_content[] = "</li>\n";
		} else {
			$inner_html    = "\n<li>{$content}</li>\n";
			$inner_content = [ $inner_html ];
		}

		return [
			'blockName'    => 'core/list-item',
			'attrs'        => [],
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * newsletterglue/quote -> core/quote
	 */
	private function transform_quote( array $block ): array {
		$attrs = $block['attrs'] ?? [];
		$content  = $attrs['content'] ?? '';
		$citation = $attrs['citation'] ?? '';

		if ( empty( $content ) ) {
			$extracted = $this->extract_from_table_wrapper( $this->get_inner_html( $block ) );
			$content = $this->extract_tag_content( $extracted, 'p' );
		}

		$cite_html = '';
		if ( ! empty( $citation ) ) {
			$cite_html = '<cite>' . $citation . '</cite>';
		}

		$inner_html = "\n<blockquote class=\"wp-block-quote\">\n<p>{$content}</p>\n{$cite_html}</blockquote>\n";

		return [
			'blockName'    => 'core/quote',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => $inner_html,
			'innerContent' => [ $inner_html ],
		];
	}

	/**
	 * newsletterglue/separator -> core/separator
	 */
	private function transform_separator( array $block ): array {
		$inner_html = "\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n";

		return [
			'blockName'    => 'core/separator',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => $inner_html,
			'innerContent' => [ $inner_html ],
		];
	}

	/**
	 * newsletterglue/spacer -> core/spacer
	 */
	private function transform_spacer( array $block ): array {
		$attrs = $block['attrs'] ?? [];
		$height = $attrs['height'] ?? '20px';

		if ( is_numeric( $height ) ) {
			$height .= 'px';
		}

		$new_attrs = [ 'height' => $height ];
		$inner_html = "\n<div style=\"height:{$height}\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n";

		return [
			'blockName'    => 'core/spacer',
			'attrs'        => $new_attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $inner_html,
			'innerContent' => [ $inner_html ],
		];
	}

	/**
	 * newsletterglue/table -> core/table
	 */
	private function transform_table( array $block ): array {
		$attrs = $block['attrs'] ?? [];

		$new_attrs = [];
		if ( ! empty( $attrs['hasFixedLayout'] ) ) {
			$new_attrs['hasFixedLayout'] = true;
		}
		if ( ! empty( $attrs['head'] ) ) {
			$new_attrs['head'] = $this->sanitize_table_section( $attrs['head'] );
		}
		if ( ! empty( $attrs['body'] ) ) {
			$new_attrs['body'] = $this->sanitize_table_section( $attrs['body'] );
		}
		if ( ! empty( $attrs['foot'] ) ) {
			$new_attrs['foot'] = $this->sanitize_table_section( $attrs['foot'] );
		}

		$table_html = $this->render_core_table( $new_attrs );
		$inner_html = "\n<figure class=\"wp-block-table\"><table>{$table_html}</table></figure>\n";

		return [
			'blockName'    => 'core/table',
			'attrs'        => $new_attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $inner_html,
			'innerContent' => [ $inner_html ],
		];
	}

	/**
	 * newsletterglue/embed -> core/embed
	 */
	private function transform_embed( array $block ): array {
		$attrs = $block['attrs'] ?? [];

		$new_attrs = [];
		if ( ! empty( $attrs['url'] ) ) {
			$new_attrs['url'] = $attrs['url'];
		}
		if ( ! empty( $attrs['type'] ) ) {
			$new_attrs['type'] = $attrs['type'];
		}
		if ( ! empty( $attrs['providerNameSlug'] ) ) {
			$new_attrs['providerNameSlug'] = $attrs['providerNameSlug'];
		}

		$url = $new_attrs['url'] ?? '';
		$inner_html = "\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\n{$url}\n</div></figure>\n";

		return [
			'blockName'    => 'core/embed',
			'attrs'        => $new_attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $inner_html,
			'innerContent' => [ $inner_html ],
		];
	}

	/**
	 * newsletterglue/button -> core/button
	 */
	private function transform_button( array $block ): array {
		$attrs = $block['attrs'] ?? [];

		$text = $attrs['text'] ?? '';
		$url  = $attrs['url'] ?? '';

		if ( empty( $text ) ) {
			$extracted = $this->get_inner_html( $block );
			if ( preg_match( '/<a[^>]*>(.*?)<\/a>/si', $extracted, $m ) ) {
				$text = trim( strip_tags( $m[1] ) );
			}
			if ( empty( $url ) && preg_match( '/href=["\']([^"\']+)["\']/i', $extracted, $m ) ) {
				$url = $m[1];
			}
		}

		$new_attrs = [];
		if ( ! empty( $url ) ) {
			$new_attrs['url'] = $url;
		}
		if ( ! empty( $attrs['linkTarget'] ) ) {
			$new_attrs['linkTarget'] = $attrs['linkTarget'];
		}

		$link_attrs = '';
		if ( $url ) {
			$link_attrs .= ' href="' . esc_url( $url ) . '"';
		}
		if ( ! empty( $attrs['linkTarget'] ) ) {
			$link_attrs .= ' target="' . esc_attr( $attrs['linkTarget'] ) . '"';
		}

		$inner_html = "\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\"{$link_attrs}>{$text}</a></div>\n";

		return [
			'blockName'    => 'core/button',
			'attrs'        => $new_attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $inner_html,
			'innerContent' => [ $inner_html ],
		];
	}

	/**
	 * newsletterglue/buttons -> core/buttons
	 */
	private function transform_buttons( array $block ): array {
		$inner_blocks = ! empty( $block['innerBlocks'] )
			? $this->transform_block_list( $block['innerBlocks'] )
			: [];

		$inner_html = "\n<div class=\"wp-block-buttons\">\n\n</div>\n";

		$inner_content = [ "\n<div class=\"wp-block-buttons\">\n" ];
		foreach ( $inner_blocks as $ib ) {
			$inner_content[] = null;
			$inner_content[] = "\n";
		}
		$inner_content[] = "</div>\n";

		return [
			'blockName'    => 'core/buttons',
			'attrs'        => [],
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * newsletterglue/container, article, callout -> core/group
	 */
	private function transform_group( array $block ): array {
		$attrs = $block['attrs'] ?? [];

		$inner_blocks = ! empty( $block['innerBlocks'] )
			? $this->transform_block_list( $block['innerBlocks'] )
			: [];

		$new_attrs = [ 'layout' => [ 'type' => 'constrained' ] ];

		if ( ! empty( $attrs['background'] ) ) {
			$new_attrs['style']['color']['background'] = $attrs['background'];
		}
		if ( ! empty( $attrs['color'] ) ) {
			$new_attrs['style']['color']['text'] = $attrs['color'];
		}

		$inner_html = "\n<div class=\"wp-block-group\">\n\n</div>\n";

		$inner_content = [ "\n<div class=\"wp-block-group\">\n" ];
		foreach ( $inner_blocks as $ib ) {
			$inner_content[] = null;
			$inner_content[] = "\n";
		}
		$inner_content[] = "</div>\n";

		return [
			'blockName'    => 'core/group',
			'attrs'        => $new_attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * newsletterglue/sections, columns -> core/columns
	 */
	private function transform_columns( array $block ): array {
		$inner_blocks = ! empty( $block['innerBlocks'] )
			? $this->transform_block_list( $block['innerBlocks'] )
			: [];

		$inner_html = "\n<div class=\"wp-block-columns\">\n\n</div>\n";

		$inner_content = [ "\n<div class=\"wp-block-columns\">\n" ];
		foreach ( $inner_blocks as $ib ) {
			$inner_content[] = null;
			$inner_content[] = "\n";
		}
		$inner_content[] = "</div>\n";

		return [
			'blockName'    => 'core/columns',
			'attrs'        => [],
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * newsletterglue/section, column -> core/column
	 */
	private function transform_column( array $block ): array {
		$attrs = $block['attrs'] ?? [];

		$inner_blocks = ! empty( $block['innerBlocks'] )
			? $this->transform_block_list( $block['innerBlocks'] )
			: [];

		$new_attrs = [];
		if ( ! empty( $attrs['width'] ) ) {
			$width = $attrs['width'];
			$new_attrs['width'] = is_numeric( $width ) ? $width . 'px' : $width;
		}

		$inner_html = "\n<div class=\"wp-block-column\">\n\n</div>\n";

		$inner_content = [ "\n<div class=\"wp-block-column\">\n" ];
		foreach ( $inner_blocks as $ib ) {
			$inner_content[] = null;
			$inner_content[] = "\n";
		}
		$inner_content[] = "</div>\n";

		return [
			'blockName'    => 'core/column',
			'attrs'        => $new_attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * newsletterglue/social-icons, share -> core/social-links
	 */
	private function transform_social_links( array $block ): array {
		$inner_blocks = ! empty( $block['innerBlocks'] )
			? $this->transform_block_list( $block['innerBlocks'] )
			: [];

		$inner_html = "\n<ul class=\"wp-block-social-links\">\n\n</ul>\n";

		$inner_content = [ "\n<ul class=\"wp-block-social-links\">\n" ];
		foreach ( $inner_blocks as $ib ) {
			$inner_content[] = null;
			$inner_content[] = "\n";
		}
		$inner_content[] = "</ul>\n";

		return [
			'blockName'    => 'core/social-links',
			'attrs'        => [],
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * newsletterglue/social-icon, share-link -> core/social-link
	 */
	private function transform_social_link( array $block ): array {
		$attrs = $block['attrs'] ?? [];

		$service = $attrs['service'] ?? '';
		$url     = $attrs['url'] ?? '';

		$new_attrs = [];
		if ( $service ) {
			$new_attrs['service'] = $service;
		}
		if ( $url ) {
			$new_attrs['url'] = $url;
		}

		$inner_html = "\n<li class=\"wp-social-link wp-social-link-{$service}\"><a href=\"" . esc_url( $url ) . "\"></a></li>\n";

		return [
			'blockName'    => 'core/social-link',
			'attrs'        => $new_attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $inner_html,
			'innerContent' => [ $inner_html ],
		];
	}

	/**
	 * newsletterglue/post-author, author -> core/post-author
	 */
	private function transform_post_author( array $block ): array {
		return [
			'blockName'    => 'core/post-author',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	/**
	 * newsletterglue/meta-data, metadata -> core/group wrapping post-date
	 */
	private function transform_meta_data( array $block ): array {
		$date_block = [
			'blockName'    => 'core/post-date',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$inner_html = "\n<div class=\"wp-block-group\">\n\n</div>\n";

		return [
			'blockName'    => 'core/group',
			'attrs'        => [ 'layout' => [ 'type' => 'constrained' ] ],
			'innerBlocks'  => [ $date_block ],
			'innerHTML'    => $inner_html,
			'innerContent' => [ "\n<div class=\"wp-block-group\">\n", null, "\n</div>\n" ],
		];
	}

	/**
	 * newsletterglue/html -> core/html
	 */
	private function transform_html( array $block ): array {
		$attrs = $block['attrs'] ?? [];
		$content = $attrs['content'] ?? '';

		if ( empty( $content ) ) {
			$content = $this->get_inner_html( $block );
		}

		return [
			'blockName'    => 'core/html',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => "\n{$content}\n",
			'innerContent' => [ "\n{$content}\n" ],
		];
	}

	/**
	 * newsletterglue/latest-posts, post-embeds -> core/latest-posts
	 */
	private function transform_latest_posts( array $block ): array {
		$attrs = $block['attrs'] ?? [];

		$new_attrs = [];
		if ( ! empty( $attrs['postsToShow'] ) ) {
			$new_attrs['postsToShow'] = (int) $attrs['postsToShow'];
		}
		if ( ! empty( $attrs['displayPostContent'] ) ) {
			$new_attrs['displayPostContent'] = true;
		}

		return [
			'blockName'    => 'core/latest-posts',
			'attrs'        => $new_attrs,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	/**
	 * Fallback: wrap unknown block innerHTML in core/html.
	 */
	private function fallback_to_html( array $block ): array {
		$content = $this->get_inner_html( $block );

		return [
			'blockName'    => 'core/html',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => "\n{$content}\n",
			'innerContent' => [ "\n{$content}\n" ],
		];
	}

	/**
	 * Get the full innerHTML from a parsed block.
	 */
	private function get_inner_html( array $block ): string {
		return $block['innerHTML'] ?? '';
	}

	/**
	 * Extract the inner content of a specific HTML tag.
	 */
	private function extract_tag_content( string $html, string $tag ): string {
		if ( preg_match( '/<' . preg_quote( $tag, '/' ) . '[^>]*>(.*?)<\/' . preg_quote( $tag, '/' ) . '>/si', $html, $m ) ) {
			return trim( $m[1] );
		}
		return $html;
	}

	/**
	 * Extract an attribute value from an <img> tag.
	 */
	private function extract_img_attr( string $html, string $attr ): string {
		if ( preg_match( '/<img[^>]+' . preg_quote( $attr, '/' ) . '=["\']([^"\']*)["\']/', $html, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Sanitize NGL table section data for core/table attributes.
	 */
	private function sanitize_table_section( array $rows ): array {
		$result = [];
		foreach ( $rows as $row ) {
			$cells = [];
			foreach ( ( $row['cells'] ?? [] ) as $cell ) {
				$cells[] = [
					'content' => $cell['content'] ?? '',
					'tag'     => $cell['tag'] ?? 'td',
				];
			}
			$result[] = [ 'cells' => $cells ];
		}
		return $result;
	}

	/**
	 * Render core/table HTML from structured attributes.
	 */
	private function render_core_table( array $attrs ): string {
		$html = '';

		foreach ( [ 'head' => 'thead', 'body' => 'tbody', 'foot' => 'tfoot' ] as $key => $tag ) {
			$rows = $attrs[ $key ] ?? [];
			if ( empty( $rows ) ) {
				continue;
			}

			$html .= "<{$tag}>";
			foreach ( $rows as $row ) {
				$html .= '<tr>';
				foreach ( $row['cells'] as $cell ) {
					$cell_tag = $cell['tag'] ?? 'td';
					$html .= "<{$cell_tag}>{$cell['content']}</{$cell_tag}>";
				}
				$html .= '</tr>';
			}
			$html .= "</{$tag}>";
		}

		return $html;
	}

	/**
	 * Map NGL post meta to prc_email_* meta keys.
	 *
	 * @param int $ngl_post_id NGL post ID.
	 * @return array{mapped: array<string, string>, unmapped: array<string, mixed>}
	 */
	public function map_meta( int $ngl_post_id ): array {
		$ngl_data = get_post_meta( $ngl_post_id, self::NGL_META_KEY, true );
		$mapped   = [];
		$unmapped = [];

		if ( ! is_array( $ngl_data ) ) {
			$ngl_data = [];
		}

		foreach ( $ngl_data as $key => $value ) {
			if ( isset( self::META_MAP[ $key ] ) ) {
				$meta_key = self::META_MAP[ $key ];
				if ( 'prc_email_delivery_mode' === $meta_key ) {
					$value = $this->normalize_delivery_mode( $this->stringify_mapped_meta_value( $value ) );
				} else {
					$value = $this->stringify_mapped_meta_value( $value );
				}
				$mapped[ $meta_key ] = $value;
			} else {
				$unmapped[ $key ] = $value;
			}
		}

		$subject = get_post_meta( $ngl_post_id, 'subject_line', true );
		if ( ! empty( $subject ) && empty( $mapped['prc_email_subject'] ) ) {
			$mapped['prc_email_subject'] = (string) $subject;
		}

		return [
			'mapped'   => $mapped,
			'unmapped' => $unmapped,
		];
	}

	/**
	 * Convert NGL meta values to strings for META_MAP targets (avoids array-to-string "Array").
	 *
	 * @param mixed $value Raw value from NGL meta array.
	 */
	private function stringify_mapped_meta_value( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( is_string( $item ) || is_int( $item ) || is_float( $item ) ) {
					return (string) $item;
				}
			}

			return '';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return '';
	}

	/**
	 * Normalize the delivery mode value.
	 */
	private function normalize_delivery_mode( string $app ): string {
		$app = strtolower( trim( $app ) );
		if ( str_contains( $app, 'mailchimp' ) ) {
			return 'mailchimp';
		}
		if ( str_contains( $app, 'mandrill' ) ) {
			return 'mandrill';
		}
		return $app ?: 'mailchimp';
	}

	/**
	 * Map NGL taxonomy terms to prc_newsletter_list terms.
	 *
	 * @param int $ngl_post_id  Source NGL post ID.
	 * @param int $new_post_id  Target email post ID.
	 */
	public function map_taxonomy( int $ngl_post_id, int $new_post_id ): void {
		$ngl_terms = wp_get_object_terms( $ngl_post_id, self::NGL_TAXONOMY );

		if ( is_wp_error( $ngl_terms ) || empty( $ngl_terms ) ) {
			return;
		}

		$new_term_ids = [];
		foreach ( $ngl_terms as $term ) {
			$existing = get_term_by( 'slug', $term->slug, Post_Type::TAXONOMY );
			if ( $existing ) {
				$new_term_ids[] = $existing->term_id;
			} else {
				$result = wp_insert_term( $term->name, Post_Type::TAXONOMY, [
					'slug'        => $term->slug,
					'description' => $term->description,
				] );
				if ( ! is_wp_error( $result ) ) {
					$new_term_ids[] = $result['term_id'];
				}
			}
		}

		if ( ! empty( $new_term_ids ) ) {
			wp_set_object_terms( $new_post_id, $new_term_ids, Post_Type::TAXONOMY );
		}

		$tags = wp_get_object_terms( $ngl_post_id, 'post_tag' );
		if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
			$cat_ids = [];
			foreach ( $tags as $tag ) {
				$cat = get_term_by( 'slug', $tag->slug, 'category' );
				if ( $cat ) {
					$cat_ids[] = $cat->term_id;
				} else {
					$result = wp_insert_term( $tag->name, 'category', [
						'slug' => $tag->slug,
					] );
					if ( ! is_wp_error( $result ) ) {
						$cat_ids[] = $result['term_id'];
					}
				}
			}
			if ( ! empty( $cat_ids ) ) {
				wp_set_object_terms( $new_post_id, $cat_ids, 'category' );
			}
		}
	}
}
