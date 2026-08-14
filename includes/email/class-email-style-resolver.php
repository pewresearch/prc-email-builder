<?php
declare(strict_types=1);
/**
 * Email Style Resolver.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Shared utilities for resolving block attributes to email-safe inline CSS.
 *
 * @package PRC\Platform\Email_Builder
 */
class Email_Style_Resolver {

	const EMAIL_FONT_SERIF         = "Georgia,'Times New Roman',Times,serif";
	const EMAIL_FONT_FRANKLIN_SANS = "'franklin-gothic-urw',Verdana,Geneva,sans-serif";

	/**
	 * CSS properties that may receive dark-mode overrides.
	 *
	 * @var string[]
	 */
	private const DARK_MODE_PROPERTIES = array(
		'background-color',
		'color',
		'border-color',
		'border-top-color',
		'border-right-color',
		'border-bottom-color',
		'border-left-color',
	);

	/**
	 * Build full inline CSS from block attrs (typography + color/spacing/border).
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @param string              $html  Optional saved block HTML for class-based supports.
	 * @return string
	 */
	public static function inline_css( array $attrs, string $html = '' ): string {
		$result = self::inline_css_with_classes( $attrs, $html );
		return $result['css'];
	}

	/**
	 * Build inline CSS and collect dark-mode class names for the element.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @param string              $html  Optional saved block HTML for class-based supports.
	 * @return array{css:string,classes:string[]}
	 */
	public static function inline_css_with_classes( array $attrs, string $html = '' ): array {
		$classes = array();
		$parts   = self::build_style_declaration_parts( $attrs, $classes, $html );
		$parts   = self::collapse_padding_parts( $parts );

		if ( empty( $parts ) ) {
			return array( 'css' => '', 'classes' => $classes );
		}

		$css = '';
		foreach ( $parts as $property => $value ) {
			$css .= $property . ':' . $value . ';';
		}

		return array(
			'css'      => $css,
			'classes'  => array_values( array_unique( $classes ) ),
		);
	}

	/**
	 * Collapse four longhand padding declarations into a single `padding`
	 * shorthand when all sides are present. Outlook desktop handles the
	 * shorthand more reliably than individual `padding-top` etc. The shorthand
	 * is inserted where the first padding longhand appeared so source order is
	 * preserved.
	 *
	 * @param array<string,string> $parts property => value.
	 * @return array<string,string>
	 */
	private static function collapse_padding_parts( array $parts ): array {
		$sides = array( 'padding-top', 'padding-right', 'padding-bottom', 'padding-left' );
		foreach ( $sides as $side ) {
			if ( ! isset( $parts[ $side ] ) ) {
				return $parts;
			}
		}

		$top    = $parts['padding-top'];
		$right  = $parts['padding-right'];
		$bottom = $parts['padding-bottom'];
		$left   = $parts['padding-left'];

		if ( $top === $right && $right === $bottom && $bottom === $left ) {
			$shorthand = $top;
		} elseif ( $top === $bottom && $right === $left ) {
			$shorthand = $top . ' ' . $right;
		} else {
			$shorthand = $top . ' ' . $right . ' ' . $bottom . ' ' . $left;
		}

		$rebuilt  = array();
		$inserted = false;
		foreach ( $parts as $property => $value ) {
			if ( in_array( $property, $sides, true ) ) {
				if ( ! $inserted ) {
					$rebuilt['padding'] = $shorthand;
					$inserted           = true;
				}
				continue;
			}
			$rebuilt[ $property ] = $value;
		}

		return $rebuilt;
	}

	/**
	 * Merge base style string with resolved block overrides and class list.
	 *
	 * @param string              $base_style Existing inline CSS.
	 * @param array<string,mixed> $attrs      Block attrs.
	 * @param string              $html       Optional saved block HTML for class-based supports.
	 * @return array{style:string,class:string}
	 */
	public static function merge_block_style( string $base_style, array $attrs, string $html = '' ): array {
		$resolved = self::inline_css_with_classes( $attrs, $html );
		$style    = $base_style . $resolved['css'];
		$class    = implode( ' ', $resolved['classes'] );
		return array(
			'style' => $style,
			'class' => $class,
		);
	}

	/**
	 * @param array<string,mixed> $attrs   Block attrs.
	 * @param string[]            $classes Dark-mode classes (by reference).
	 * @param string              $html    Optional saved block HTML for class-based supports.
	 * @return array<string,string> property => light value
	 */
	private static function build_style_declaration_parts( array $attrs, array &$classes, string $html = '' ): array {
		$attrs      = self::hydrate_preset_color_attrs( $attrs, $html );
		$normalized = self::normalize_attrs_for_style_engine( $attrs );
		$parts      = array();

		if ( function_exists( 'wp_style_engine_get_styles' ) && ! empty( $normalized ) ) {
			$engine = wp_style_engine_get_styles( $normalized, array( 'convert_vars_to_classnames' => false ) );
			if ( is_array( $engine['declarations'] ?? null ) ) {
				foreach ( $engine['declarations'] as $property => $value ) {
					if ( ! is_string( $property ) || ! is_string( $value ) ) {
						continue;
					}
					if ( 'box-shadow' === $property || str_contains( $property, 'gradient' ) ) {
						continue;
					}
					$value = Email_Preset_Resolver::resolve_css_vars( $value );
					$value = self::resolve_declaration_value( $value );
					if ( '' === $value ) {
						continue;
					}
					$parts[ $property ] = $value;
					self::maybe_register_dark_class( $property, $value, $attrs, $classes );
				}
			}
		}

		$typo_parts = self::extract_typography_css_parts( $attrs, $html );
		foreach ( $typo_parts as $property => $value ) {
			if ( ! isset( $parts[ $property ] ) ) {
				$parts[ $property ] = $value;
			}
		}

		return $parts;
	}

	/**
	 * Copy named preset attrs into a style object the Style Engine understands.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return array<string,mixed>
	 */
	private static function normalize_attrs_for_style_engine( array $attrs ): array {
		$style = $attrs['style'] ?? array();
		if ( ! is_array( $style ) ) {
			$style = array();
		}

		if ( ! isset( $style['color'] ) || ! is_array( $style['color'] ) ) {
			$style['color'] = array();
		}

		if ( ! empty( $attrs['backgroundColor'] ) && is_string( $attrs['backgroundColor'] ) ) {
			$hex = Email_Preset_Resolver::color_hex( $attrs['backgroundColor'] );
			if ( '' !== $hex ) {
				$style['color']['background'] = $hex;
			}
		}

		if ( ! empty( $attrs['textColor'] ) && is_string( $attrs['textColor'] ) ) {
			$hex = Email_Preset_Resolver::color_hex( $attrs['textColor'] );
			if ( '' !== $hex ) {
				$style['color']['text'] = $hex;
			}
		}

		if ( ! empty( $attrs['borderColor'] ) && is_string( $attrs['borderColor'] ) ) {
			$hex = Email_Preset_Resolver::color_hex( $attrs['borderColor'] );
			if ( '' !== $hex ) {
				if ( ! isset( $style['border'] ) || ! is_array( $style['border'] ) ) {
					$style['border'] = array();
				}
				$style['border']['color'] = $hex;
			}
		}

		// Dereference preset refs already living in style.color.* (e.g.
		// "var:preset|color|ui-black") so the Style Engine sees literals.
		foreach ( array( 'background', 'text' ) as $color_key ) {
			if ( ! empty( $style['color'][ $color_key ] ) && is_string( $style['color'][ $color_key ] ) ) {
				$resolved = self::resolve_preset_color_value( $style['color'][ $color_key ] );
				if ( '' !== $resolved ) {
					$style['color'][ $color_key ] = $resolved;
				}
			}
		}

		if ( isset( $style['spacing'] ) && is_array( $style['spacing'] ) ) {
			$style['spacing'] = self::normalize_spacing_tree( $style['spacing'] );
		}

		return $style;
	}

	/**
	 * Resolve a color value that may be a preset ref into a literal hex/color.
	 *
	 * Handles "var:preset|color|slug", "var(--wp--preset--color--slug)", and
	 * passes through plain values. Returns the light branch for inline CSS.
	 *
	 * @param string $value Raw color value.
	 * @return string Resolved literal, or '' when nothing resolved.
	 */
	private static function resolve_preset_color_value( string $value ): string {
		if ( preg_match( '/^var:preset\|color\|([a-z0-9\-]+)$/i', $value, $m )
			|| preg_match( '/^var\(--wp--preset--color--([a-z0-9\-]+)\)$/i', $value, $m )
		) {
			return Email_Preset_Resolver::color_hex( $m[1] );
		}
		return self::resolve_declaration_value( $value );
	}

	/**
	 * @param array<string,mixed> $spacing Spacing subtree.
	 * @return array<string,mixed>
	 */
	private static function normalize_spacing_tree( array $spacing ): array {
		foreach ( $spacing as $key => $value ) {
			if ( is_array( $value ) ) {
				$spacing[ $key ] = self::normalize_spacing_tree( $value );
				continue;
			}
			if ( is_string( $value ) || is_numeric( $value ) ) {
				$resolved = Email_Preset_Resolver::spacing_value( $value );
				if ( '' !== $resolved ) {
					$spacing[ $key ] = $resolved;
				}
			}
		}
		return $spacing;
	}

	/**
	 * Take light branch from light-dark() in a resolved declaration value.
	 *
	 * @param string $value CSS value.
	 * @return string
	 */
	private static function resolve_declaration_value( string $value ): string {
		$pair = Email_Preset_Resolver::parse_light_dark( $value );
		return $pair['light'];
	}

	/**
	 * @param string              $property CSS property.
	 * @param string              $light    Light value already chosen for inline CSS.
	 * @param array<string,mixed> $attrs    Original block attrs (for slug lookup).
	 * @param string[]            $classes  Accumulator.
	 */
	private static function maybe_register_dark_class( string $property, string $light, array $attrs, array &$classes ): void {
		if ( ! in_array( $property, self::DARK_MODE_PROPERTIES, true ) ) {
			return;
		}

		$dark = self::dark_value_for_property( $property, $attrs );
		if ( '' === $dark ) {
			$pair = Email_Preset_Resolver::parse_light_dark( $light );
			$dark = $pair['dark'];
		}

		$class = Dark_Mode_Registry::register_color_pair( $property, $light, $dark );
		if ( '' !== $class ) {
			$classes[] = $class;
		}
	}

	/**
	 * Resolve dark value from named preset attrs when applicable.
	 *
	 * @param string              $property CSS property.
	 * @param array<string,mixed> $attrs    Block attrs.
	 * @return string
	 */
	private static function dark_value_for_property( string $property, array $attrs ): string {
		$slug      = '';
		$style_col = is_array( $attrs['style']['color'] ?? null ) ? $attrs['style']['color'] : array();
		if ( 'background-color' === $property ) {
			$slug = (string) ( $attrs['backgroundColor'] ?? '' );
			if ( '' === $slug ) {
				$slug = self::preset_color_slug( (string) ( $style_col['background'] ?? '' ) );
			}
		} elseif ( 'color' === $property ) {
			$slug = (string) ( $attrs['textColor'] ?? '' );
			if ( '' === $slug ) {
				$slug = self::preset_color_slug( (string) ( $style_col['text'] ?? '' ) );
			}
		} elseif ( str_contains( $property, 'border' ) && ! empty( $attrs['borderColor'] ) ) {
			$slug = (string) $attrs['borderColor'];
		}

		if ( '' === $slug ) {
			return '';
		}

		$pair = Email_Preset_Resolver::color_pair( sanitize_key( $slug ) );
		return $pair['dark'];
	}

	/**
	 * Extract a preset color slug from a "var:preset|color|slug" /
	 * "var(--wp--preset--color--slug)" string, else ''.
	 *
	 * @param string $value Raw color value.
	 * @return string
	 */
	private static function preset_color_slug( string $value ): string {
		if ( preg_match( '/^var:preset\|color\|([a-z0-9\-]+)$/i', $value, $m )
			|| preg_match( '/^var\(--wp--preset--color--([a-z0-9\-]+)\)$/i', $value, $m )
		) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Resolve text alignment from block attrs and optional saved HTML.
	 *
	 * Gutenberg often serializes alignment as `has-text-align-*` classes rather
	 * than Style Engine declarations. Order of precedence:
	 * 1. style.typography.textAlign
	 * 2. top-level attrs.textAlign
	 * 3. has-text-align-* in attrs.className
	 * 4. has-text-align-* in raw saved HTML (when attrs omit it)
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @param string              $html  Optional saved block HTML.
	 * @return string One of left|center|right|justify, or empty string.
	 */
	public static function text_align_from_attrs( array $attrs, string $html = '' ): string {
		$allowed = array( 'left', 'center', 'right', 'justify' );

		$style = $attrs['style'] ?? array();
		if ( is_array( $style ) && isset( $style['typography'] ) && is_array( $style['typography'] ) ) {
			$raw = $style['typography']['textAlign'] ?? '';
			if ( is_string( $raw ) ) {
				$align = sanitize_key( $raw );
				if ( in_array( $align, $allowed, true ) ) {
					return $align;
				}
			}
		}

		if ( ! empty( $attrs['textAlign'] ) && is_string( $attrs['textAlign'] ) ) {
			$align = sanitize_key( $attrs['textAlign'] );
			if ( in_array( $align, $allowed, true ) ) {
				return $align;
			}
		}

		if ( ! empty( $attrs['className'] ) && is_string( $attrs['className'] ) ) {
			$align = self::text_align_from_class_string( $attrs['className'] );
			if ( '' !== $align ) {
				return $align;
			}
		}

		if ( '' !== $html ) {
			$align = self::text_align_from_class_string( $html );
			if ( '' !== $align ) {
				return $align;
			}
		}

		return '';
	}

	/**
	 * Extract a whitelisted text-align value from a class string or HTML.
	 *
	 * @param string $haystack Class attribute or saved HTML.
	 * @return string One of left|center|right|justify, or empty string.
	 */
	private static function text_align_from_class_string( string $haystack ): string {
		if ( preg_match( '/\bhas-text-align-(left|center|right|justify)\b/', $haystack, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * Build a CSS `style=""` value string from block typography attributes.
	 *
	 * Returns an empty string when no typography overrides are present.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @param string              $html  Optional saved block HTML for class-based supports.
	 * @return string e.g. 'font-family:Georgia,...;font-size:16px;'
	 */
	public static function typography_inline_css( array $attrs, string $html = '' ): string {
		$pairs = self::extract_typography_css_parts( $attrs, $html );
		if ( empty( $pairs ) ) {
			return '';
		}

		$parts = array();
		foreach ( $pairs as $property => $value ) {
			$parts[] = $property . ':' . $value;
		}

		return implode( ';', $parts ) . ';';
	}

	/**
	 * Extract resolved CSS property → value pairs from block attrs.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @param string              $html  Optional saved block HTML for class-based supports.
	 * @return array<string,string>
	 */
	public static function extract_typography_css_parts( array $attrs, string $html = '' ): array {
		$out  = array();
		$typo = array();

		$style = $attrs['style'] ?? array();
		if ( is_array( $style ) && isset( $style['typography'] ) && is_array( $style['typography'] ) ) {
			$typo = $style['typography'];
		}

		$map = array(
			'fontFamily'     => 'font-family',
			'fontSize'       => 'font-size',
			'lineHeight'     => 'line-height',
			'fontWeight'     => 'font-weight',
			'fontStyle'      => 'font-style',
			'letterSpacing'  => 'letter-spacing',
			'textTransform'  => 'text-transform',
			'textDecoration' => 'text-decoration',
		);

		foreach ( $map as $attr_key => $css_key ) {
			if ( ! isset( $typo[ $attr_key ] ) || ! is_string( $typo[ $attr_key ] ) ) {
				continue;
			}
			$raw = trim( $typo[ $attr_key ] );
			if ( '' === $raw ) {
				continue;
			}
			if ( 'fontFamily' === $attr_key ) {
				$resolved = self::resolve_font_family( $raw );
				if ( '' !== $resolved ) {
					$out['font-family'] = $resolved;
				}
				continue;
			}
			if ( 'fontSize' === $attr_key ) {
				$size = self::resolve_font_size( $raw );
				if ( '' !== $size ) {
					$out['font-size'] = $size;
				}
				continue;
			}
			$san = self::sanitize_css_value( $raw );
			if ( '' !== $san ) {
				$out[ $css_key ] = $san;
			}
		}

		// Fallback to block-level preset attrs when style.typography didn't supply them.
		if ( ! isset( $out['font-family'] ) ) {
			$preset_font = $attrs['fontFamily'] ?? null;
			if ( is_string( $preset_font ) && '' !== trim( $preset_font ) ) {
				$resolved = self::resolve_font_family( $preset_font );
				if ( '' !== $resolved ) {
					$out['font-family'] = $resolved;
				}
			}
		}

		if ( ! isset( $out['font-family'] ) ) {
			$class_font = '';
			if ( ! empty( $attrs['className'] ) && is_string( $attrs['className'] ) ) {
				$class_font = self::font_family_slug_from_class_string( $attrs['className'] );
			}
			if ( '' === $class_font && '' !== $html ) {
				$class_font = self::font_family_slug_from_class_string( $html );
			}
			if ( '' !== $class_font ) {
				$resolved = self::resolve_font_family( $class_font );
				if ( '' !== $resolved ) {
					$out['font-family'] = $resolved;
				}
			}
		}

		if ( ! isset( $out['font-size'] ) ) {
			$preset_size = $attrs['fontSize'] ?? null;
			if ( is_string( $preset_size ) && '' !== trim( $preset_size ) ) {
				$size = self::resolve_font_size( $preset_size );
				if ( '' !== $size ) {
					$out['font-size'] = $size;
				}
			}
		}

		if ( ! isset( $out['font-size'] ) ) {
			$class_size = '';
			if ( ! empty( $attrs['className'] ) && is_string( $attrs['className'] ) ) {
				$class_size = self::preset_slug_from_has_class( $attrs['className'], 'font-size' );
			}
			if ( '' === $class_size && '' !== $html ) {
				$class_size = self::preset_slug_from_has_class( $html, 'font-size' );
			}
			if ( '' !== $class_size ) {
				$size = self::resolve_font_size( $class_size );
				if ( '' !== $size ) {
					$out['font-size'] = $size;
				}
			}
		}

		$align = self::text_align_from_attrs( $attrs, $html );
		if ( '' !== $align ) {
			$out['text-align'] = $align;
		}

		return $out;
	}

	/**
	 * Extract a font-family preset slug from `has-{slug}-font-family`.
	 *
	 * @param string $haystack Class attribute or saved HTML.
	 * @return string Slug or empty string.
	 */
	private static function font_family_slug_from_class_string( string $haystack ): string {
		return self::preset_slug_from_has_class( $haystack, 'font-family' );
	}

	/**
	 * Extract a Gutenberg `has-{slug}-{suffix}` preset slug.
	 *
	 * @param string $haystack Class attribute or saved HTML.
	 * @param string $suffix   Class suffix (font-family, font-size, background-color, …).
	 * @return string Lowercase slug or empty string.
	 */
	private static function preset_slug_from_has_class( string $haystack, string $suffix ): string {
		$quoted = preg_quote( $suffix, '/' );
		if ( preg_match( '/\bhas-([a-z0-9-]+)-' . $quoted . '\b/', $haystack, $matches ) ) {
			return sanitize_key( $matches[1] );
		}
		return '';
	}

	/**
	 * Copy named color slugs from `has-{slug}-*-color` classes onto attrs.
	 *
	 * Existing textColor / backgroundColor / borderColor values win.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @param string              $html  Optional saved block HTML.
	 * @return array<string,mixed>
	 */
	public static function hydrate_preset_color_attrs( array $attrs, string $html = '' ): array {
		$sources = array();
		if ( ! empty( $attrs['className'] ) && is_string( $attrs['className'] ) ) {
			$sources[] = $attrs['className'];
		}
		if ( '' !== $html ) {
			$sources[] = $html;
		}

		if ( empty( $attrs['backgroundColor'] ) || ! is_string( $attrs['backgroundColor'] ) ) {
			foreach ( $sources as $hay ) {
				$slug = self::preset_slug_from_has_class( $hay, 'background-color' );
				if ( '' !== $slug ) {
					$attrs['backgroundColor'] = $slug;
					break;
				}
			}
		}

		if ( empty( $attrs['borderColor'] ) || ! is_string( $attrs['borderColor'] ) ) {
			foreach ( $sources as $hay ) {
				$slug = self::preset_slug_from_has_class( $hay, 'border-color' );
				if ( '' !== $slug ) {
					$attrs['borderColor'] = $slug;
					break;
				}
			}
		}

		if ( empty( $attrs['textColor'] ) || ! is_string( $attrs['textColor'] ) ) {
			foreach ( $sources as $hay ) {
				if ( ! preg_match_all( '/\bhas-([a-z0-9-]+)-color\b/', $hay, $matches ) ) {
					continue;
				}
				foreach ( $matches[1] as $raw ) {
					$slug = sanitize_key( $raw );
					// has-text-color is Gutenberg's "text is colored" flag, not a slug.
					if ( '' === $slug || 'text' === $slug ) {
						continue;
					}
					if ( str_ends_with( $slug, '-background' ) || str_ends_with( $slug, '-border' ) ) {
						continue;
					}
					$attrs['textColor'] = $slug;
					break 2;
				}
			}
		}

		return $attrs;
	}

	/**
	 * Map theme/editor font tokens to email-safe font stacks.
	 *
	 * Preset slugs and CSS variables resolve through theme.json fontFamilies.
	 * Unknown refs return empty so the callback base stack wins.
	 *
	 * @param string $raw Raw font family value from block attrs.
	 * @return string Email-safe font-family value, or empty string if unmappable.
	 */
	public static function resolve_font_family( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$slug = self::slug_from_var_preset( $raw, 'font-family' );
		if ( null !== $slug ) {
			$raw = $slug;
		}

		if ( preg_match( '/^[a-z0-9-]+$/i', $raw ) ) {
			return Email_Preset_Resolver::font_family_stack( strtolower( $raw ) );
		}

		if ( str_contains( $raw, ',' ) || str_contains( $raw, "'" ) ) {
			return self::sanitize_css_value( $raw );
		}

		return '';
	}

	/**
	 * Resolve a font-size preset slug or concrete length to a px value where possible.
	 *
	 * @param string $raw Raw font size value from block attrs.
	 * @return string Email-safe font-size value, or empty string if unmappable.
	 */
	public static function resolve_font_size( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '/^\d+(\.\d+)?(px|em|rem|%)$/', $raw ) ) {
			return self::sanitize_css_value( $raw );
		}
		$preset_slug = self::slug_from_var_preset( $raw, 'font-size' );
		if ( null !== $preset_slug ) {
			return Email_Preset_Resolver::font_size_px( $preset_slug );
		}
		if ( preg_match( '/^[a-z0-9-]+$/i', $raw ) ) {
			return Email_Preset_Resolver::font_size_px( strtolower( $raw ) );
		}
		return '';
	}

	/**
	 * Convert a known font-size preset slug to its email px equivalent.
	 *
	 * @param string $slug Lowercase preset slug.
	 * @return string px value or empty string.
	 */
	public static function preset_slug_to_px( string $slug ): string {
		return Email_Preset_Resolver::font_size_px( $slug );
	}

	/**
	 * Extract the slug from a `var:preset|{group}|<slug>` or
	 * `var(--wp--preset--{group}--<slug>)` reference.
	 *
	 * @param string $var   The var: or var(-- reference string.
	 * @param string $group Preset group (font-size, font-family, color, …).
	 * @return string|null Slug or null on no match.
	 */
	public static function slug_from_var_preset( string $var, string $group = 'font-size' ): ?string {
		$group = sanitize_key( $group );
		if ( '' === $group ) {
			return null;
		}
		$quoted = preg_quote( $group, '/' );
		if ( preg_match( '/^var:preset\|' . $quoted . '\|([a-z0-9\-]+)$/i', $var, $m ) ) {
			return strtolower( $m[1] );
		}
		if ( preg_match( '/^var\(--wp--preset--' . $quoted . '--([a-z0-9\-]+)\)$/i', $var, $m ) ) {
			return strtolower( $m[1] );
		}
		return null;
	}

	/**
	 * Sanitize a CSS scalar value (e.g. a font-family stack or size).
	 *
	 * Allows: letters, digits, whitespace, commas, single quotes, percent,
	 * hash, dash, forward-slash, colon. Max 200 chars.
	 *
	 * @param string $value Raw CSS value.
	 * @return string Sanitized value, or empty string if invalid.
	 */
	public static function sanitize_css_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( strlen( $value ) > 200 ) {
			return '';
		}
		if ( ! preg_match( '/^[a-zA-Z0-9\s,.\'%#\-\/:]+$/', $value ) ) {
			return '';
		}
		return $value;
	}
}
