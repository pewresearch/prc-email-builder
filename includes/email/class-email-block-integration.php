<?php
declare(strict_types=1);
/**
 * Email Block Integration — core block callbacks.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Registers email-HTML callbacks for all standard core blocks used in
 * PRC newsletters.
 *
 * Flow blocks (paragraph, heading, list) emit bare elements so text can wrap
 * around floated images. Block-level elements (spacer, separator, data table,
 * full-width image) emit tables that clear floats. Horizontal padding lives on
 * the body template content cell (see templates/default.php), not per block.
 *
 * @package PRC\Platform\Email_Builder
 */
class Email_Block_Integration {

	/**
	 * Maximum width (px) of the email card shell.
	 */
	const EMAIL_CARD_MAX_WIDTH = 600;

	/**
	 * Side padding (px) on the body content cell in email templates.
	 */
	const CONTENT_SIDE_PADDING = 32;

	/**
	 * Usable content width inside the email card minus side padding.
	 */
	const CONTENT_INNER_WIDTH = self::EMAIL_CARD_MAX_WIDTH - ( 2 * self::CONTENT_SIDE_PADDING );

	/**
	 * Default body link colour (inline fallback when <style> is stripped).
	 */
	const BODY_LINK_COLOR = '#2b6dad';

	public function __construct( $loader ) {
		$loader->add_action(
			'prc_email_builder_register_email_callbacks',
			$this,
			'register_block_callbacks'
		);
	}

	/**
	 * @hook prc_email_builder_register_email_callbacks
	 */
	public function register_block_callbacks(): void {
		Email_Block_Registry::register( 'core/paragraph', array( $this, 'paragraph' ) );
		Email_Block_Registry::register( 'core/heading', array( $this, 'heading' ) );
		Email_Block_Registry::register( 'core/post-date', array( $this, 'post_date' ) );
		Email_Block_Registry::register( 'core/list', array( $this, 'list_block' ) );
		Email_Block_Registry::register( 'core/list-item', array( $this, 'list_item' ) );
		Email_Block_Registry::register( 'core/table', array( $this, 'table' ) );
		Email_Block_Registry::register( 'core/image', array( $this, 'image' ) );
		Email_Block_Registry::register( 'core/spacer', array( $this, 'spacer' ) );
		Email_Block_Registry::register( 'core/separator', array( $this, 'separator' ) );
		Email_Block_Registry::register( 'core/quote', array( $this, 'quote' ) );
		Email_Block_Registry::register( 'core/pullquote', array( $this, 'quote' ) );
		Email_Block_Registry::register( 'core/group', array( $this, 'group' ) );
		Email_Block_Registry::register( 'core/buttons', array( $this, 'buttons' ) );
		Email_Block_Registry::register( 'core/button', array( $this, 'button' ) );
	}

	/**
	 * Maximum width (px) for center-aligned images in email.
	 */
	public static function get_center_image_max_width(): int {
		/**
		 * Filter the maximum pixel width for center-aligned email images.
		 *
		 * @param int $max_width Default 600.
		 */
		$max = (int) apply_filters( 'prc_email_builder_email_center_image_max_width', self::EMAIL_CARD_MAX_WIDTH );
		return max( 120, $max );
	}

	/**
	 * Clamp a declared image width for center alignment.
	 *
	 * @param int $width Declared width in pixels.
	 * @return int
	 */
	public static function clamp_center_width( int $width ): int {
		$max = self::get_center_image_max_width();
		if ( $width <= 0 ) {
			return $max;
		}
		return min( $width, $max );
	}

	/**
	 * Maximum width (px) for left/right floated images in email.
	 */
	public static function get_float_max_width(): int {
		/**
		 * Filter the maximum pixel width for floated email images.
		 *
		 * @param int $max_width Default 300.
		 */
		$max = (int) apply_filters( 'prc_email_builder_email_float_max_width', 300 );
		return max( 120, min( self::CONTENT_INNER_WIDTH - 40, $max ) );
	}

	/**
	 * Clamp a declared image width for float layout.
	 *
	 * @param int $width Declared width in pixels.
	 * @return int
	 */
	public static function clamp_float_width( int $width ): int {
		$max = self::get_float_max_width();
		if ( $width <= 0 ) {
			return $max;
		}
		return min( $width, $max );
	}

	/**
	 * Parse a pixel dimension from attrs or inline style (e.g. "368px", 368).
	 *
	 * @param mixed $value Raw value.
	 * @return int Pixels, or 0 if not parseable.
	 */
	public static function parse_pixel_width( $value ): int {
		if ( is_numeric( $value ) ) {
			return max( 0, (int) $value );
		}
		if ( ! is_string( $value ) ) {
			return 0;
		}
		$value = trim( $value );
		if ( preg_match( '/^(\d+(?:\.\d+)?)\s*px$/i', $value, $m ) ) {
			return (int) ceil( (float) $m[1] );
		}
		if ( preg_match( '/^(\d+)$/', $value, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * Build email image markup (bare float img or block table).
	 *
	 * Used by core/image and chart-builder integrations.
	 *
	 * @param string $url   Image URL.
	 * @param string $alt   Alt text.
	 * @param string $align left|right|center|wide|full or empty.
	 * @param int    $width Declared width in pixels (0 = default).
	 * @return string
	 */
	public static function build_image_markup( string $url, string $alt, string $align = '', int $width = 0 ): string {
		$align = sanitize_key( $align );

		if ( 'left' === $align || 'right' === $align ) {
			return self::build_float_image( $url, $alt, $align, $width );
		}

		if ( 'center' === $align ) {
			$w = self::clamp_center_width( $width );
			return sprintf(
				'<table width="100%%" cellpadding="0" cellspacing="0" border="0" role="presentation">'
				. '<tr><td align="center" style="padding:16px 0;">'
				. '<img src="%s" alt="%s" width="%d" style="display:block;width:100%%;max-width:%dpx;height:auto;border:0;" />'
				. '</td></tr></table>',
				esc_url( $url ),
				esc_attr( $alt ),
				$w,
				$w
			);
		}

		return sprintf(
			'<table width="100%%" cellpadding="0" cellspacing="0" border="0" role="presentation">'
			. '<tr><td style="padding:16px 0;">'
			. '<img src="%s" alt="%s" width="%d" style="display:block;max-width:100%%;height:auto;border:0;" />'
			. '</td></tr></table>',
			esc_url( $url ),
			esc_attr( $alt ),
			self::CONTENT_INNER_WIDTH
		);
	}

	/**
	 * Bare floated <img> so following paragraphs wrap (not a 100% width table).
	 *
	 * @param string $url   Image URL.
	 * @param string $alt   Alt text.
	 * @param string $align left|right.
	 * @param int    $width Declared width in pixels.
	 * @return string
	 */
	public static function build_float_image( string $url, string $alt, string $align, int $width ): string {
		$w = self::clamp_float_width( $width );
		$margin = 'left' === $align ? 'margin:0 16px 16px 0;' : 'margin:0 0 16px 16px;';

		return sprintf(
			'<img src="%s" alt="%s" align="%s" width="%d" style="display:block;%smax-width:%dpx;height:auto;border:0;" />',
			esc_url( $url ),
			esc_attr( $alt ),
			esc_attr( $align ),
			$w,
			$margin,
			$w
		);
	}

	// -------------------------------------------------------------------------
	// core/paragraph
	// -------------------------------------------------------------------------

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 */
	public function paragraph( array $block, \WP_Post $post ): string {
		$attrs = $block['attrs'] ?? array();
		$inner = $this->get_inner_html( $block );
		if ( '' === $inner ) {
			return '';
		}

		$base  = 'font-family:' . Email_Style_Resolver::EMAIL_FONT_SERIF
			. ';font-size:16px;line-height:26px;color:#333333;margin:0 0 16px 0;';
		$merged = Email_Style_Resolver::merge_block_style( $base, $attrs );

		return sprintf(
			'<p style="%s"%s>%s</p>',
			esc_attr( $merged['style'] ),
			$this->class_attr( $merged['class'] ),
			$this->rewrite_links( $inner )
		);
	}

	// -------------------------------------------------------------------------
	// core/heading
	// -------------------------------------------------------------------------

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 */
	public function heading( array $block, \WP_Post $post ): string {
		$attrs = $block['attrs'] ?? array();
		$level = isset( $attrs['level'] ) ? max( 1, min( 6, (int) $attrs['level'] ) ) : 2;
		$inner = $this->get_inner_html( $block );
		if ( '' === $inner ) {
			return '';
		}

		$size_map = array(
			1 => array( 'font-size:28px', 'line-height:34px', 'margin:0 0 16px 0' ),
			2 => array( 'font-size:22px', 'line-height:28px', 'margin:24px 0 12px 0' ),
			3 => array( 'font-size:18px', 'line-height:24px', 'color:#333333', 'margin:20px 0 8px 0' ),
			4 => array( 'font-size:16px', 'line-height:22px', 'color:#333333', 'margin:16px 0 8px 0' ),
			5 => array( 'font-size:14px', 'line-height:20px', 'color:#333333', 'margin:12px 0 6px 0' ),
			6 => array( 'font-size:13px', 'line-height:18px', 'color:#333333', 'margin:10px 0 4px 0' ),
		);

		$level_parts   = $size_map[ $level ] ?? $size_map[2];
		$base_style = 'font-family:' . Email_Style_Resolver::EMAIL_FONT_SERIF . ';font-weight:bold;color:#000000;padding:0;'
			. implode( ';', $level_parts ) . ';';
		$merged     = Email_Style_Resolver::merge_block_style( $base_style, $attrs );

		$tag = 'h' . $level;

		return sprintf(
			'<%1$s style="%2$s"%4$s>%3$s</%1$s>',
			$tag,
			esc_attr( $merged['style'] ),
			$this->rewrite_links( $inner ),
			$this->class_attr( $merged['class'] )
		);
	}

	// -------------------------------------------------------------------------
	// core/post-date
	// -------------------------------------------------------------------------

	/**
	 * Render core/post-date as an email-safe styled line.
	 *
	 * Honors the block's `displayType` (date|modified), `format`, `isLink`, and
	 * `textAlign` attributes, plus author color/typography/spacing overrides.
	 *
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 */
	public function post_date( array $block, \WP_Post $post ): string {
		$attrs   = $block['attrs'] ?? array();
		$display = ( ( $attrs['displayType'] ?? 'date' ) === 'modified' ) ? 'modified' : 'date';
		$format  = ! empty( $attrs['format'] ) && is_string( $attrs['format'] )
			? $attrs['format']
			: (string) get_option( 'date_format' );

		$date = 'modified' === $display
			? (string) get_the_modified_date( $format, $post )
			: (string) get_the_date( $format, $post );

		if ( '' === trim( $date ) ) {
			return '';
		}

		$align = '';
		if ( ! empty( $attrs['textAlign'] ) && in_array( $attrs['textAlign'], array( 'left', 'center', 'right' ), true ) ) {
			$align = 'text-align:' . $attrs['textAlign'] . ';';
		}

		$base = 'font-family:' . Email_Style_Resolver::EMAIL_FONT_FRANKLIN_SANS
			. ';font-size:13px;line-height:18px;color:#666666;margin:0 0 16px 0;' . $align;
		$merged = Email_Style_Resolver::merge_block_style( $base, $attrs );

		$content = esc_html( $date );
		if ( ! empty( $attrs['isLink'] ) ) {
			$permalink = get_permalink( $post );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$content = sprintf(
					'<a class="body-link" style="color:%s;text-decoration:underline;" href="%s">%s</a>',
					esc_attr( self::BODY_LINK_COLOR ),
					esc_url( $permalink ),
					$content
				);
			}
		}

		return sprintf(
			'<p style="%s"%s>%s</p>',
			esc_attr( $merged['style'] ),
			$this->class_attr( $merged['class'] ),
			$content
		);
	}

	// -------------------------------------------------------------------------
	// core/list + core/list-item
	// -------------------------------------------------------------------------

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 */
	public function list_block( array $block, \WP_Post $post ): string {
		$attrs   = $block['attrs'] ?? array();
		$ordered = ! empty( $attrs['ordered'] );
		$inner   = $block['innerBlocks'] ?? array();

		if ( empty( $inner ) ) {
			return '';
		}

		$list_base = 'font-family:' . Email_Style_Resolver::EMAIL_FONT_SERIF
			. ';font-size:16px;line-height:26px;color:#333333;margin:0 0 16px 0;padding-left:20px;';
		$list_merged = Email_Style_Resolver::merge_block_style( $list_base, $attrs );
		$item_style  = 'margin:0 0 8px 0;';

		$items = array();
		foreach ( $inner as $item_block ) {
			if ( ( $item_block['blockName'] ?? '' ) !== 'core/list-item' ) {
				continue;
			}
			$item_inner = $this->get_inner_html( $item_block );
			if ( '' === trim( wp_strip_all_tags( $item_inner ) ) ) {
				continue;
			}
			$item_attrs = $item_block['attrs'] ?? array();
			$item_merged = Email_Style_Resolver::merge_block_style( $item_style, $item_attrs );
			$items[]     = sprintf(
				'<li style="%s"%s>%s</li>',
				esc_attr( $item_merged['style'] ),
				$this->class_attr( $item_merged['class'] ),
				$this->rewrite_links( $item_inner )
			);
		}

		if ( empty( $items ) ) {
			return '';
		}

		$tag = $ordered ? 'ol' : 'ul';

		return sprintf(
			'<%1$s style="%2$s"%4$s>%3$s</%1$s>',
			$tag,
			esc_attr( $list_merged['style'] ),
			implode( '', $items ),
			$this->class_attr( $list_merged['class'] )
		);
	}

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 */
	public function list_item( array $block, \WP_Post $post ): string {
		$inner = $this->get_inner_html( $block );
		if ( '' === trim( wp_strip_all_tags( $inner ) ) ) {
			return '';
		}
		$base   = 'font-family:' . Email_Style_Resolver::EMAIL_FONT_SERIF
			. ';font-size:16px;line-height:26px;color:#333333;margin:0 0 8px 0;';
		$merged = Email_Style_Resolver::merge_block_style( $base, $block['attrs'] ?? array() );

		return sprintf(
			'<li style="%s"%s>%s</li>',
			esc_attr( $merged['style'] ),
			$this->class_attr( $merged['class'] ),
			$this->rewrite_links( $inner )
		);
	}

	// -------------------------------------------------------------------------
	// core/table
	// -------------------------------------------------------------------------

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 */
	public function table( array $block, \WP_Post $post ): string {
		$html = render_block( $block );
		if ( '' === trim( $html ) ) {
			return '';
		}
		return $this->convert_html_table_to_email( $html );
	}

	/**
	 * @param string $html Rendered block HTML.
	 * @return string
	 */
	private function convert_html_table_to_email( string $html ): string {
		$html = '<div>' . $html . '</div>';

		$doc = new \DOMDocument( '1.0', 'UTF-8' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		$xpath = new \DOMXPath( $doc );

		$rows_html = '';

		foreach ( $xpath->query( '//thead/tr' ) as $tr ) {
			$cells = '';
			foreach ( $xpath->query( 'th|td', $tr ) as $cell ) {
				$cells .= sprintf(
					'<th style="font-family:%s;font-size:14px;font-weight:bold;color:#333333;text-align:left;border-bottom:2px solid #d1d1d1;padding:8px;">%s</th>',
					esc_attr( Email_Style_Resolver::EMAIL_FONT_SERIF ),
					$doc->saveHTML( $cell )
				);
			}
			$rows_html .= '<tr style="background-color:#f2f2f2;">' . $cells . '</tr>';
		}

		foreach ( $xpath->query( '//tbody/tr' ) as $tr ) {
			$cells = '';
			foreach ( $xpath->query( 'td|th', $tr ) as $cell ) {
				$cells .= sprintf(
					'<td style="font-family:%s;font-size:14px;color:#333333;border-bottom:1px solid #eeeeee;padding:8px;">%s</td>',
					esc_attr( Email_Style_Resolver::EMAIL_FONT_SERIF ),
					$doc->saveHTML( $cell )
				);
			}
			$rows_html .= '<tr>' . $cells . '</tr>';
		}

		if ( '' === $rows_html ) {
			return '';
		}

		return sprintf(
			'<table width="100%%" cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;margin:16px 0;">%s</table>',
			$rows_html
		);
	}

	// -------------------------------------------------------------------------
	// core/image
	// -------------------------------------------------------------------------

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 */
	public function image( array $block, \WP_Post $post ): string {
		$parsed = $this->parse_image_block( $block );
		if ( '' === $parsed['url'] ) {
			return '';
		}

		return self::build_image_markup(
			$parsed['url'],
			$parsed['alt'],
			$parsed['align'],
			$parsed['width']
		);
	}

	/**
	 * Extract image URL, alt, width, and alignment from a core/image block.
	 *
	 * Prefers innerHTML (HTML-sourced attrs) over block JSON attrs.
	 *
	 * @param array $block Parsed block.
	 * @return array{url:string,alt:string,align:string,width:int}
	 */
	public function parse_image_block( array $block ): array {
		$attrs = $block['attrs'] ?? array();
		$url   = '';
		$alt   = '';
		$width = 0;
		$align = sanitize_key( (string) ( $attrs['align'] ?? '' ) );

		$inner = $block['innerHTML'] ?? '';
		if ( '' !== trim( $inner ) && class_exists( 'WP_HTML_Tag_Processor' ) ) {
			// Alignment from figure/wrapper class.
			if ( '' === $align && preg_match( '/\balign(left|right|center|wide|full)\b/', $inner, $m ) ) {
				$align = $m[1];
			}

			$processor = new \WP_HTML_Tag_Processor( $inner );
			if ( $processor->next_tag( 'IMG' ) ) {
				$url = (string) ( $processor->get_attribute( 'src' ) ?? '' );
				$alt = (string) ( $processor->get_attribute( 'alt' ) ?? '' );

				$width_attr = $processor->get_attribute( 'width' );
				if ( null !== $width_attr ) {
					$width = self::parse_pixel_width( $width_attr );
				}
				if ( 0 === $width ) {
					$style = (string) ( $processor->get_attribute( 'style' ) ?? '' );
					if ( preg_match( '/width\s*:\s*(\d+(?:\.\d+)?)\s*px/i', $style, $sm ) ) {
						$width = (int) ceil( (float) $sm[1] );
					}
				}
			}
		}

		if ( '' === $url && ! empty( $attrs['id'] ) ) {
			$url = (string) wp_get_attachment_url( (int) $attrs['id'] );
		}

		if ( '' === $url && ! empty( $attrs['url'] ) ) {
			$url = (string) $attrs['url'];
		}

		if ( '' === $alt && ! empty( $attrs['alt'] ) ) {
			$alt = (string) $attrs['alt'];
		}

		if ( 0 === $width ) {
			$width = self::parse_pixel_width( $attrs['width'] ?? 0 );
		}

		if ( '' === $align && ! empty( $attrs['align'] ) ) {
			$align = sanitize_key( (string) $attrs['align'] );
		}

		return array(
			'url'   => $url,
			'alt'   => $alt,
			'align' => $align,
			'width' => $width,
		);
	}

	// -------------------------------------------------------------------------
	// core/spacer, separator
	// -------------------------------------------------------------------------

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 */
	public function spacer( array $block, \WP_Post $post ): string {
		$attrs  = $block['attrs'] ?? array();
		$height = $attrs['height'] ?? '20px';

		if ( is_numeric( $height ) ) {
			$height = (int) $height;
		} elseif ( is_string( $height ) && preg_match( '/^(\d+(?:\.\d+)?)px$/', trim( $height ), $m ) ) {
			$height = (int) ceil( (float) $m[1] );
		} else {
			$height = 20;
		}

		$h = max( 1, min( 500, (int) $height ) );

		return sprintf(
			'<table width="100%%" cellpadding="0" cellspacing="0" border="0" role="presentation">'
			. '<tr><td height="%d" style="height:%dpx;line-height:%dpx;font-size:0;">&nbsp;</td></tr>'
			. '</table>',
			$h,
			$h,
			$h
		);
	}

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 */
	public function separator( array $block, \WP_Post $post ): string {
		return '<hr style="border:0;border-top:1px solid #d1d1d1;margin:20px 0;" />';
	}

	// -------------------------------------------------------------------------
	// core/quote + core/pullquote
	// -------------------------------------------------------------------------

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 */
	public function quote( array $block, \WP_Post $post ): string {
		$text_parts = array();
		foreach ( ( $block['innerBlocks'] ?? array() ) as $inner ) {
			$t = trim( $inner['innerHTML'] ?? '' );
			if ( '' !== $t ) {
				$text_parts[] = $t;
			}
		}

		if ( empty( $text_parts ) ) {
			$raw = trim( $block['innerHTML'] ?? '' );
			if ( '' !== $raw ) {
				$raw          = preg_replace( '#</?(?:blockquote|figure)[^>]*>#i', '', $raw );
				$text_parts[] = trim( (string) $raw );
			}
		}

		if ( empty( $text_parts ) ) {
			return '';
		}

		$content = implode( '<br />', $text_parts );

		$quote_base = 'font-family:' . Email_Style_Resolver::EMAIL_FONT_SERIF
			. ';font-size:16px;line-height:26px;color:#555555;font-style:italic;margin:0;';
		$merged     = Email_Style_Resolver::merge_block_style( $quote_base, $block['attrs'] ?? array() );

		return sprintf(
			'<table width="100%%" cellpadding="0" cellspacing="0" border="0" role="presentation">'
			. '<tr><td style="padding:16px 0 16px 20px;border-left:4px solid #d1d1d1;">'
			. '<p style="%s"%s>%s</p>'
			. '</td></tr></table>',
			esc_attr( $merged['style'] ),
			$this->class_attr( $merged['class'] ),
			$this->rewrite_links( $content )
		);
	}

	// -------------------------------------------------------------------------
	// core/group
	// -------------------------------------------------------------------------

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 */
	public function group( array $block, \WP_Post $post ): string {
		$attrs  = $block['attrs'] ?? array();
		$layout = $attrs['layout'] ?? array();
		if ( ! is_array( $layout ) ) {
			$layout = array();
		}

		$type        = $layout['type'] ?? 'constrained';
		$orientation = $layout['orientation'] ?? 'horizontal';
		$is_row      = ( 'flex' === $type && 'vertical' !== $orientation );
		$is_grid     = ( 'grid' === $type );

		$converter = new Email_Block_Converter();
		$inner     = $block['innerBlocks'] ?? array();

		if ( empty( $inner ) ) {
			return '';
		}

		if ( $is_row || $is_grid ) {
			$cells = array();
			foreach ( $inner as $child ) {
				$fragment = $converter->blocks_to_email_html( array( $child ), $post );
				if ( '' !== trim( $fragment ) ) {
					$cells[] = $fragment;
				}
			}
			if ( empty( $cells ) ) {
				return '';
			}
			$gap_raw = $attrs['style']['spacing']['blockGap'] ?? ( $layout['blockGap'] ?? '' );
			$gap_px  = 0;
			if ( is_string( $gap_raw ) || is_numeric( $gap_raw ) ) {
				$gap_resolved = Email_Preset_Resolver::spacing_value( $gap_raw );
				if ( preg_match( '/^(\d+)/', $gap_resolved, $gm ) ) {
					$gap_px = (int) $gm[1];
				}
			}
			$inner_html = $this->render_horizontal_row(
				$cells,
				array(
					'justify' => sanitize_key( (string) ( $layout['justifyContent'] ?? 'left' ) ) ?: 'left',
					'valign'  => sanitize_key( (string) ( $layout['verticalAlignment'] ?? 'top' ) ) ?: 'top',
					'gap_px'  => $gap_px,
				)
			);
		} else {
			$inner_html = $converter->blocks_to_email_html( $inner, $post );
		}

		if ( '' === trim( $inner_html ) ) {
			return '';
		}

		$wrapper = $this->group_cell_style( $attrs );

		return sprintf(
			'<table width="100%%" cellpadding="0" cellspacing="0" border="0" role="presentation">'
			. '<tr><td style="%s"%s>%s</td></tr></table>',
			esc_attr( $wrapper['style'] ),
			$this->class_attr( $wrapper['class'] ),
			$inner_html
		);
	}

	/**
	 * Resolve group wrapper cell styles (background, padding, border).
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return array{style:string,class:string}
	 */
	private function group_cell_style( array $attrs ): array {
		$resolved = Email_Style_Resolver::inline_css_with_classes( $attrs );

		return array(
			'style' => $resolved['css'],
			'class' => implode( ' ', array_values( array_unique( $resolved['classes'] ) ) ),
		);
	}

	// -------------------------------------------------------------------------
	// core/buttons + core/button
	// -------------------------------------------------------------------------

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 */
	public function buttons( array $block, \WP_Post $post ): string {
		$attrs   = $block['attrs'] ?? array();
		$layout  = $attrs['layout'] ?? array();
		$anchors = array();

		foreach ( ( $block['innerBlocks'] ?? array() ) as $inner ) {
			if ( ( $inner['blockName'] ?? '' ) !== 'core/button' ) {
				continue;
			}
			$anchor = $this->render_button_anchor( $inner );
			if ( '' !== $anchor ) {
				$anchors[] = $anchor;
			}
		}

		if ( empty( $anchors ) ) {
			return '';
		}

		$orientation = is_array( $layout ) ? ( $layout['orientation'] ?? 'horizontal' ) : 'horizontal';
		if ( 'vertical' === $orientation ) {
			return $this->render_horizontal_row(
				$anchors,
				array(
					'justify' => 'left',
					'valign'  => 'top',
					'gap_px'  => 8,
					'stack'   => true,
				)
			);
		}

		$gap_raw = $attrs['style']['spacing']['blockGap'] ?? ( $layout['blockGap'] ?? '' );
		$gap_px  = 12;
		if ( is_string( $gap_raw ) || is_numeric( $gap_raw ) ) {
			$gap_resolved = Email_Preset_Resolver::spacing_value( $gap_raw );
			if ( preg_match( '/^(\d+)/', $gap_resolved, $gm ) ) {
				$gap_px = (int) $gm[1];
			}
		}

		return $this->render_horizontal_row(
			$anchors,
			array(
				'justify' => sanitize_key( (string) ( $layout['justifyContent'] ?? 'left' ) ) ?: 'left',
				'valign'  => 'center',
				'gap_px'  => $gap_px,
			)
		);
	}

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 */
	public function button( array $block, \WP_Post $post ): string {
		$anchor = $this->render_button_anchor( $block );
		if ( '' === $anchor ) {
			return '';
		}

		return $this->render_horizontal_row(
			array( $anchor ),
			array(
				'justify' => 'left',
				'valign'  => 'center',
				'gap_px'  => 0,
			)
		);
	}

	/**
	 * Styled button anchor only (no wrapper table).
	 *
	 * @param array $block Parsed core/button block.
	 * @return string
	 */
	private function render_button_anchor( array $block ): string {
		$attrs = $block['attrs'] ?? array();
		$url   = (string) ( $attrs['url'] ?? '' );
		$text  = trim( wp_strip_all_tags( $block['innerHTML'] ?? '' ) );

		if ( '' === $text ) {
			return '';
		}

		$class_name = (string) ( $attrs['className'] ?? '' );
		$is_outline = str_contains( $class_name, 'is-style-outline' );

		$fill_color = '#2b6dad';
		if ( ! empty( $attrs['backgroundColor'] ) ) {
			$fill_color = Email_Preset_Resolver::color_hex( (string) $attrs['backgroundColor'] ) ?: $fill_color;
		} elseif ( ! empty( $attrs['style']['color']['background'] ) ) {
			$parsed     = Email_Preset_Resolver::parse_light_dark( (string) $attrs['style']['color']['background'] );
			$fill_color = $parsed['light'] ?: $fill_color;
		}

		$text_color = '#ffffff';
		if ( ! empty( $attrs['textColor'] ) ) {
			$text_color = Email_Preset_Resolver::color_hex( (string) $attrs['textColor'] ) ?: $text_color;
		}

		$radius = '3px';
		if ( ! empty( $attrs['style']['border']['radius'] ) ) {
			$radius = Email_Preset_Resolver::rem_to_px( (string) $attrs['style']['border']['radius'] );
		}

		if ( $is_outline ) {
			$anchor_style = sprintf(
				'display:inline-block;padding:10px 22px;background-color:transparent;color:%s;border:2px solid %s;font-family:%s;font-size:16px;font-weight:bold;text-decoration:none;border-radius:%s;',
				esc_attr( $fill_color ),
				esc_attr( $fill_color ),
				esc_attr( Email_Style_Resolver::EMAIL_FONT_FRANKLIN_SANS ),
				esc_attr( $radius )
			);
		} else {
			$anchor_style = sprintf(
				'display:inline-block;padding:12px 24px;background-color:%s;color:%s;font-family:%s;font-size:16px;font-weight:bold;text-decoration:none;border-radius:%s;',
				esc_attr( $fill_color ),
				esc_attr( $text_color ),
				esc_attr( Email_Style_Resolver::EMAIL_FONT_FRANKLIN_SANS ),
				esc_attr( $radius )
			);
		}

		if ( '' !== $url ) {
			return sprintf(
				'<a href="%s" style="%s">%s</a>',
				esc_url( $url ),
				esc_attr( $anchor_style ),
				esc_html( $text )
			);
		}

		return sprintf(
			'<span style="%s">%s</span>',
			esc_attr( $anchor_style ),
			esc_html( $text )
		);
	}

	/**
	 * Lay out pre-rendered fragments in one table row (email-safe flex substitute).
	 *
	 * @param string[]             $children HTML fragments (one per cell).
	 * @param array<string,mixed>  $opts     justify, valign, gap_px, stack, widths.
	 * @return string
	 */
	private function render_horizontal_row( array $children, array $opts ): string {
		$count = count( $children );
		if ( 0 === $count ) {
			return '';
		}

		$justify = sanitize_key( (string) ( $opts['justify'] ?? 'left' ) );
		if ( ! in_array( $justify, array( 'left', 'center', 'right', 'space-between' ), true ) ) {
			$justify = 'left';
		}

		$valign = sanitize_key( (string) ( $opts['valign'] ?? 'top' ) );
		if ( ! in_array( $valign, array( 'top', 'center', 'middle', 'bottom' ), true ) ) {
			$valign = 'top';
		}
		if ( 'center' === $valign || 'middle' === $valign ) {
			$valign = 'middle';
		}

		$gap_px = max( 0, (int) ( $opts['gap_px'] ?? 0 ) );
		$stack  = ! empty( $opts['stack'] );

		if ( $stack && 1 === $count ) {
			return sprintf(
				'<table width="100%%" cellpadding="0" cellspacing="0" border="0" role="presentation">'
				. '<tr><td align="%s" style="padding:16px 0;" valign="%s">%s</td></tr></table>',
				esc_attr( $justify ),
				esc_attr( $valign ),
				$children[0]
			);
		}

		if ( $stack ) {
			$rows = '';
			foreach ( $children as $i => $child ) {
				$pad = ( $i < $count - 1 ) ? 'padding-bottom:' . $gap_px . 'px;' : '';
				$rows .= sprintf(
					'<tr><td align="%s" style="padding:8px 0;%s" valign="%s">%s</td></tr>',
					esc_attr( $justify ),
					esc_attr( $pad ),
					esc_attr( $valign ),
					$child
				);
			}
			return '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">' . $rows . '</table>';
		}

		$align = $justify;
		if ( 'space-between' === $justify ) {
			$align = 'center';
		}

		$width_pct = (int) floor( 100 / $count );
		$cells     = '';
		foreach ( $children as $i => $child ) {
			$pad = ( $i < $count - 1 && $gap_px > 0 ) ? 'padding-right:' . $gap_px . 'px;' : 'padding:16px 0;';
			if ( $i < $count - 1 && $gap_px > 0 ) {
				$pad = 'padding:16px ' . $gap_px . 'px 16px 0;';
			} else {
				$pad = 'padding:16px 0;';
			}
			$cells .= sprintf(
				'<td align="%s" width="%d%%" valign="%s" style="%s">%s</td>',
				esc_attr( 'center' === $justify ? 'center' : 'left' ),
				$width_pct,
				esc_attr( $valign ),
				esc_attr( $pad ),
				$child
			);
		}

		return sprintf(
			'<table width="100%%" cellpadding="0" cellspacing="0" border="0" role="presentation">'
			. '<tr align="%s">%s</tr></table>',
			esc_attr( $align ),
			$cells
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @param string $classes Space-separated class names.
	 * @return string HTML class attribute or empty.
	 */
	private function class_attr( string $classes ): string {
		$classes = trim( $classes );
		return '' !== $classes ? ' class="' . esc_attr( $classes ) . '"' : '';
	}

	/**
	 * @param array $block Parsed block.
	 * @return string
	 */
	private function get_inner_html( array $block ): string {
		$html = trim( $block['innerHTML'] ?? '' );

		if ( '' !== $html ) {
			$html = preg_replace( '#^<[a-z][a-z0-9]*(?:\s[^>]*)?>(.+)<\/[a-z][a-z0-9]*>$#is', '$1', $html );
			return trim( (string) $html );
		}

		$inner_content = $block['innerContent'] ?? array();
		$parts         = array();
		foreach ( $inner_content as $part ) {
			if ( is_string( $part ) ) {
				$parts[] = trim( $part );
			}
		}
		return trim( implode( '', $parts ) );
	}

	/**
	 * Add body-link class and inline blue colour to content anchors.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private function rewrite_links( string $html ): string {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( 'A' ) ) {
			$existing_class = (string) ( $processor->get_attribute( 'class' ) ?? '' );
			if ( ! str_contains( $existing_class, 'body-link' ) ) {
				$new_class = trim( $existing_class . ' body-link' );
				$processor->set_attribute( 'class', $new_class );
			}

			$existing_style = (string) ( $processor->get_attribute( 'style' ) ?? '' );
			if ( ! str_contains( $existing_style, 'color' ) ) {
				$processor->set_attribute(
					'style',
					trim( 'color:' . self::BODY_LINK_COLOR . ';text-decoration:underline;' . ( '' !== $existing_style ? ' ' . $existing_style : '' ) )
				);
			}
		}

		return $processor->get_updated_html();
	}
}
