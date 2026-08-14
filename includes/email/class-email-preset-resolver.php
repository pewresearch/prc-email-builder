<?php
declare(strict_types=1);
/**
 * Email Preset Resolver — theme.json presets to email-safe literals.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Resolves WordPress global settings (color palette, spacing, font families, font sizes) for email HTML.
 *
 * @package PRC\Platform\Email_Builder
 */
class Email_Preset_Resolver {

	/**
	 * @var array<string,array{light:string,dark:string}>|null
	 */
	private static ?array $color_map = null;

	/**
	 * @var array<string,string>|null slug => size value
	 */
	private static ?array $spacing_map = null;

	/**
	 * @var array<string,string>|null slug => raw fontFamily stack
	 */
	private static ?array $font_family_map = null;

	/**
	 * @var array<string,string>|null slug => px string
	 */
	private static ?array $font_size_map = null;

	/**
	 * Fallback spacing slugs when theme.json has no spacingSizes (common PRC scale).
	 *
	 * @var array<string,string>
	 */
	private const SPACING_FALLBACK = array(
		'20' => '16px',
		'30' => '24px',
		'40' => '32px',
		'50' => '40px',
		'60' => '48px',
	);

	/**
	 * Last-resort font-size slugs when theme.json fontSizes failed to load.
	 *
	 * @var array<string,string>
	 */
	private const FONT_SIZE_FALLBACK = array(
		'small'   => '14px',
		'medium'  => '16px',
		'large'   => '18px',
		'x-large' => '22px',
		'h-one'   => '28px',
		'h-two'   => '22px',
		'h-three' => '18px',
	);

	/**
	 * @param string $slug Color preset slug (e.g. ui-gray-very-light).
	 * @return array{light:string,dark:string}
	 */
	public static function color_pair( string $slug ): array {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return array( 'light' => '', 'dark' => '' );
		}

		self::load_color_map();
		return self::$color_map[ $slug ] ?? array( 'light' => '', 'dark' => '' );
	}

	/**
	 * @param string $slug Color preset slug.
	 * @return string Light-mode hex/color for inline CSS.
	 */
	public static function color_hex( string $slug ): string {
		$pair = self::color_pair( $slug );
		return $pair['light'];
	}

	/**
	 * Split light-dark(L, D) or return identical branches for plain values.
	 *
	 * @param string $value Raw CSS color value.
	 * @return array{light:string,dark:string}
	 */
	public static function parse_light_dark( string $value ): array {
		$value = trim( $value );
		if ( '' === $value ) {
			return array( 'light' => '', 'dark' => '' );
		}

		if ( preg_match( '/^light-dark\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)$/i', $value, $m ) ) {
			$light = trim( $m[1] );
			$dark  = trim( $m[2] );
			return array(
				'light' => Email_Style_Resolver::sanitize_css_value( $light ) ?: $light,
				'dark'  => Email_Style_Resolver::sanitize_css_value( $dark ) ?: $dark,
			);
		}

		$san = Email_Style_Resolver::sanitize_css_value( $value ) ?: $value;
		return array( 'light' => $san, 'dark' => $san );
	}

	/**
	 * Resolve a spacing preset or literal to an email-safe length.
	 *
	 * @param mixed $raw Preset ref, var(), or literal.
	 * @return string
	 */
	public static function spacing_value( $raw ): string {
		if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
			return '';
		}

		$str = is_numeric( $raw ) ? (string) $raw : trim( (string) $raw );
		if ( '' === $str ) {
			return '';
		}

		$slug = null;
		if ( preg_match( '/^var:preset\|spacing\|([a-z0-9\-]+)$/i', $str, $m ) ) {
			$slug = strtolower( $m[1] );
		} elseif ( preg_match( '/var\(--wp--preset--spacing--([a-z0-9\-]+)\)/i', $str, $m ) ) {
			$slug = strtolower( $m[1] );
		}

		if ( null !== $slug ) {
			self::load_spacing_map();
			$resolved = self::$spacing_map[ $slug ] ?? ( self::SPACING_FALLBACK[ $slug ] ?? '' );
			if ( '' !== $resolved ) {
				return self::rem_to_px( $resolved );
			}
		}

		if ( preg_match( '/^\d+(\.\d+)?(px|em|rem|%)$/', $str ) ) {
			return self::rem_to_px( $str );
		}

		return '';
	}

	/**
	 * Resolve a font-family preset slug to a flattened, email-safe stack.
	 *
	 * Nested `var(--wp--preset--font-family--*)` / `var:preset|font-family|*`
	 * references are expanded. Unknown slugs return empty unless the theme
	 * map failed to load, in which case serif / sans-serif fall back to the
	 * email constants.
	 *
	 * @param string $slug Font family preset slug.
	 * @return string
	 */
	public static function font_family_stack( string $slug ): string {
		return self::resolve_font_family_slug( sanitize_key( $slug ), array() );
	}

	/**
	 * Resolve a font-size preset slug to an email-safe px string.
	 *
	 * @param string $slug Font size preset slug.
	 * @return string
	 */
	public static function font_size_px( string $slug ): string {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return '';
		}

		self::load_font_size_map();
		if ( isset( self::$font_size_map[ $slug ] ) ) {
			return self::$font_size_map[ $slug ];
		}
		if ( empty( self::$font_size_map ) ) {
			return self::FONT_SIZE_FALLBACK[ $slug ] ?? '';
		}

		return '';
	}

	/**
	 * Replace surviving preset CSS variables with literals.
	 *
	 * @param string $css CSS fragment.
	 * @return string
	 */
	public static function resolve_css_vars( string $css ): string {
		if ( '' === $css ) {
			return '';
		}

		$css = preg_replace_callback(
			'/var\(--wp--preset--color--([a-z0-9\-]+)\)/i',
			static function ( array $m ): string {
				$hex = self::color_hex( $m[1] );
				return '' !== $hex ? $hex : $m[0];
			},
			$css
		) ?? $css;

		$css = preg_replace_callback(
			'/var\(--wp--preset--spacing--([a-z0-9\-]+)\)/i',
			static function ( array $m ): string {
				$val = self::spacing_value( 'var(--wp--preset--spacing--' . $m[1] . ')' );
				return '' !== $val ? $val : $m[0];
			},
			$css
		) ?? $css;

		return $css;
	}

	/**
	 * @param string $value Length value.
	 * @return string
	 */
	public static function rem_to_px( string $value ): string {
		$value = trim( $value );
		if ( preg_match( '/^(\d+(?:\.\d+)?)\s*rem$/i', $value, $m ) ) {
			return ( (int) round( (float) $m[1] * 16 ) ) . 'px';
		}
		if ( preg_match( '/^(\d+(?:\.\d+)?)\s*em$/i', $value, $m ) ) {
			return ( (int) round( (float) $m[1] * 16 ) ) . 'px';
		}
		return Email_Style_Resolver::sanitize_css_value( $value ) ?: $value;
	}

	/**
	 * Build padding shorthand from block spacing.padding object.
	 *
	 * @param array<string,mixed> $padding Padding sides.
	 * @return string e.g. padding:32px; or empty.
	 */
	public static function padding_shorthand_css( array $padding ): string {
		$sides = array( 'top', 'right', 'bottom', 'left' );
		$vals  = array();
		foreach ( $sides as $side ) {
			if ( ! isset( $padding[ $side ] ) ) {
				continue;
			}
			$v = self::spacing_value( $padding[ $side ] );
			if ( '' !== $v ) {
				$vals[ $side ] = $v;
			}
		}

		if ( empty( $vals ) ) {
			return '';
		}

		if ( count( $vals ) === 4
			&& $vals['top'] === $vals['right']
			&& $vals['right'] === $vals['bottom']
			&& $vals['bottom'] === $vals['left']
		) {
			return 'padding:' . $vals['top'] . ';';
		}

		$parts = array();
		foreach ( $sides as $side ) {
			if ( isset( $vals[ $side ] ) ) {
				$parts[] = 'padding-' . $side . ':' . $vals[ $side ];
			}
		}

		return implode( ';', $parts ) . ( $parts ? ';' : '' );
	}

	/**
	 * @return void
	 */
	private static function load_color_map(): void {
		if ( null !== self::$color_map ) {
			return;
		}

		self::$color_map = array();

		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return;
		}

		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		if ( ! is_array( $palette ) ) {
			return;
		}

		foreach ( self::flatten_origins( $palette ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['slug'] ) || empty( $entry['color'] ) ) {
				continue;
			}
			self::$color_map[ sanitize_key( (string) $entry['slug'] ) ] = self::parse_light_dark( (string) $entry['color'] );
		}
	}

	/**
	 * Flatten an origin-keyed preset structure (default/theme/custom) into a
	 * single ordered list. `wp_get_global_settings()` returns palette and
	 * spacingSizes keyed by origin; later origins override earlier ones, so we
	 * append in default -> theme -> custom order. A flat list (e.g. a test
	 * stub) is returned unchanged.
	 *
	 * @param array<mixed> $value Palette or spacingSizes node.
	 * @return array<int,mixed>
	 */
	private static function flatten_origins( array $value ): array {
		$is_origin_keyed = isset( $value['default'] ) || isset( $value['theme'] ) || isset( $value['custom'] );
		if ( ! $is_origin_keyed ) {
			return array_values( $value );
		}

		$entries = array();
		foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
			if ( ! empty( $value[ $origin ] ) && is_array( $value[ $origin ] ) ) {
				$entries = array_merge( $entries, array_values( $value[ $origin ] ) );
			}
		}
		return $entries;
	}

	/**
	 * @return void
	 */
	private static function load_spacing_map(): void {
		if ( null !== self::$spacing_map ) {
			return;
		}

		self::$spacing_map = self::SPACING_FALLBACK;

		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return;
		}

		$sizes = wp_get_global_settings( array( 'spacing', 'spacingSizes' ) );
		if ( ! is_array( $sizes ) ) {
			return;
		}

		foreach ( self::flatten_origins( $sizes ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['slug'] ) ) {
				continue;
			}
			$size = $entry['size'] ?? '';
			if ( is_string( $size ) && '' !== trim( $size ) ) {
				self::$spacing_map[ sanitize_key( (string) $entry['slug'] ) ] = trim( $size );
			}
		}
	}

	/**
	 * @param array<string,true> $seen Slugs already expanding (cycle guard).
	 */
	private static function resolve_font_family_slug( string $slug, array $seen ): string {
		if ( '' === $slug ) {
			return '';
		}
		if ( isset( $seen[ $slug ] ) ) {
			return '';
		}
		$seen[ $slug ] = true;

		self::load_font_family_map();

		if ( ! isset( self::$font_family_map[ $slug ] ) ) {
			if ( empty( self::$font_family_map ) ) {
				if ( 'serif' === $slug ) {
					return Email_Style_Resolver::EMAIL_FONT_SERIF;
				}
				if ( 'sans-serif' === $slug ) {
					return Email_Style_Resolver::EMAIL_FONT_FRANKLIN_SANS;
				}
			}
			return '';
		}

		$flattened = self::flatten_nested_font_vars( self::$font_family_map[ $slug ], $seen );
		return Email_Style_Resolver::sanitize_css_value( $flattened );
	}

	/**
	 * Expand nested font-family preset refs inside a theme.json stack.
	 *
	 * @param array<string,true> $seen
	 */
	private static function flatten_nested_font_vars( string $value, array $seen ): string {
		$replaced = preg_replace_callback(
			'/var:preset\|font-family\|([a-z0-9\-]+)|var\(--wp--preset--font-family--([a-z0-9\-]+)\)/i',
			static function ( array $m ) use ( $seen ): string {
				$nested = strtolower( '' !== ( $m[1] ?? '' ) ? $m[1] : ( $m[2] ?? '' ) );
				return self::resolve_font_family_slug( $nested, $seen );
			},
			$value
		);

		return is_string( $replaced ) ? trim( $replaced, " \t\n\r\0\x0B," ) : $value;
	}

	/**
	 * @return void
	 */
	private static function load_font_family_map(): void {
		if ( null !== self::$font_family_map ) {
			return;
		}

		self::$font_family_map = array();

		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return;
		}

		$families = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		if ( ! is_array( $families ) ) {
			return;
		}

		foreach ( self::flatten_origins( $families ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['slug'] ) ) {
				continue;
			}
			$stack = $entry['fontFamily'] ?? '';
			if ( is_string( $stack ) && '' !== trim( $stack ) ) {
				self::$font_family_map[ sanitize_key( (string) $entry['slug'] ) ] = trim( $stack );
			}
		}
	}

	/**
	 * @return void
	 */
	private static function load_font_size_map(): void {
		if ( null !== self::$font_size_map ) {
			return;
		}

		self::$font_size_map = array();

		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return;
		}

		$sizes = wp_get_global_settings( array( 'typography', 'fontSizes' ) );
		if ( ! is_array( $sizes ) ) {
			return;
		}

		foreach ( self::flatten_origins( $sizes ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['slug'] ) ) {
				continue;
			}
			$slug = sanitize_key( (string) $entry['slug'] );
			if ( '' === $slug ) {
				continue;
			}

			$raw = '';
			if ( isset( $entry['fluid'] ) && is_array( $entry['fluid'] ) ) {
				$max = $entry['fluid']['max'] ?? '';
				if ( is_string( $max ) && '' !== trim( $max ) ) {
					$raw = trim( $max );
				}
			}
			if ( '' === $raw ) {
				$size = $entry['size'] ?? '';
				if ( is_string( $size ) && '' !== trim( $size ) ) {
					$raw = trim( $size );
				}
			}
			if ( '' === $raw ) {
				continue;
			}

			self::$font_size_map[ $slug ] = self::rem_to_px( $raw );
		}
	}
}
