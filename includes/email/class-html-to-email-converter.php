<?php
declare(strict_types=1);
/**
 * HTML to Email Converter — fallback for unregistered blocks.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Converts a raw HTML fragment (from render_block()) into a minimal
 * email-safe representation for blocks that have no explicit email callback.
 *
 * Emits a bare styled paragraph (no 100% width table) so content can wrap
 * around floated images in the same content cell.
 *
 * @package PRC\Platform\Email_Builder
 */
class Html_To_Email_Converter {

	const LINK_COLOR = Email_Block_Integration::BODY_LINK_COLOR;

	const DEFAULT_FONT_FAMILY = Email_Style_Resolver::EMAIL_FONT_SERIF;
	const DEFAULT_FONT_SIZE   = '16px';
	const DEFAULT_LINE_HEIGHT = '26px';
	const DEFAULT_COLOR       = '#333333';

	/**
	 * @param string $html Rendered block HTML.
	 * @return string
	 */
	public function convert( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		$html = $this->rewrite_link_colours( $html );

		return sprintf(
			'<p style="font-family:%s;font-size:%s;line-height:%s;color:%s;margin:0 0 16px 0;">%s</p>',
			esc_attr( self::DEFAULT_FONT_FAMILY ),
			esc_attr( self::DEFAULT_FONT_SIZE ),
			esc_attr( self::DEFAULT_LINE_HEIGHT ),
			esc_attr( self::DEFAULT_COLOR ),
			$html
		);
	}

	/**
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private function rewrite_link_colours( string $html ): string {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( 'A' ) ) {
			$existing_class = (string) ( $processor->get_attribute( 'class' ) ?? '' );
			if ( ! str_contains( $existing_class, 'body-link' ) ) {
				$processor->set_attribute( 'class', trim( $existing_class . ' body-link' ) );
			}

			$existing = (string) ( $processor->get_attribute( 'style' ) ?? '' );
			if ( ! preg_match( '/(^|;)\s*color\s*:/i', $existing ) ) {
				$processor->set_attribute(
					'style',
					trim( 'color:' . self::LINK_COLOR . ';text-decoration:underline;' . ( '' !== $existing ? ' ' . $existing : '' ) )
				);
			}
		}

		return $processor->get_updated_html();
	}
}
