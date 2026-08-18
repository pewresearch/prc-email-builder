<?php
declare(strict_types=1);
/**
 * Dark Mode Registry — accumulates @media (prefers-color-scheme: dark) rules.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Static accumulator for email dark-mode CSS rules (deduped by class name).
 *
 * Reset at the start of each Email_Block_Converter::convert() call.
 *
 * @package PRC\Platform\Email_Builder
 */
class Dark_Mode_Registry {

	/**
	 * @var array<string,string> class name => full rule body (includes selector + declarations).
	 */
	private static array $rules = array();

	/**
	 * Clear all accumulated rules (call once per email conversion).
	 */
	public static function reset(): void {
		self::$rules = array();
	}

	/**
	 * Store a dark-mode rule keyed by class name (dedupes by class).
	 *
	 * @param string $class       CSS class without leading dot (e.g. dm-a1b2c3).
	 * @param string $declaration Property:value pairs, should include !important.
	 */
	public static function add( string $class, string $declaration ): void {
		$class = sanitize_html_class( $class );
		if ( '' === $class || '' === trim( $declaration ) ) {
			return;
		}
		self::$rules[ $class ] = '.' . $class . '{' . $declaration . '}';
	}

	/**
	 * Register a light/dark color pair and return the generated class name.
	 *
	 * @param string $property CSS property (e.g. background-color).
	 * @param string $light    Light-mode value already inlined.
	 * @param string $dark     Dark-mode value.
	 * @return string Class name, or empty when light === dark.
	 */
	public static function register_color_pair( string $property, string $light, string $dark ): string {
		$light = trim( $light );
		$dark  = trim( $dark );
		if ( '' === $light || '' === $dark || $light === $dark ) {
			return '';
		}

		$prop = preg_replace( '/[^a-z\-]/', '', strtolower( $property ) );
		if ( '' === $prop ) {
			return '';
		}

		$sanitized = Email_Style_Resolver::sanitize_css_value( $dark );
		if ( '' === $sanitized ) {
			return '';
		}

		$class       = 'dm-' . substr( md5( $property . '|' . $light . '|' . $dark ), 0, 8 );
		$declaration = $prop . ':' . $sanitized . '!important;';
		if ( 'color' === $prop ) {
			// iOS Mail keeps inline `color` through auto-inversion; this property is what it honors.
			$declaration .= '-webkit-text-fill-color:' . $sanitized . '!important;';
		}

		self::add( $class, $declaration );
		return $class;
	}

	/**
	 * Dark-mode text rules for elements that never registered a color pair.
	 *
	 * Headings and paragraphs inline hardcoded dark hex. iOS Mail inverts light
	 * backgrounds and leaves that inline color, so those nodes need a stylesheet
	 * override with -webkit-text-fill-color.
	 *
	 * @return string Rule bodies (no @media wrapper).
	 */
	public static function get_fallback_text_css(): string {
		$text  = self::preset_dark( 'ui-black', '#f0f0f0' );
		$link  = self::preset_dark( 'ui-link-color', '#5B9BD5' );
		$muted = self::preset_dark( 'ui-gray-very-dark', '#a0a0a0' );

		// Omit strong/em/b/i so they inherit a parent .dm-* fill instead of flattening to body text.
		// Body fill inherits onto anchors; restore inline color for custom/plain link classes.
		return 'body,h1,h2,h3,h4,h5,h6,p,li,blockquote{'
			. 'color:' . $text . '!important;'
			. '-webkit-text-fill-color:' . $text . '!important;'
			. '}'
			. '.body-link,.body-link:link,.body-link:visited{'
			. 'color:' . $link . '!important;'
			. '-webkit-text-fill-color:' . $link . '!important;'
			. '}'
			. '.body-link-plain,.body-link-plain:link,.body-link-plain:visited,'
			. '.body-link-custom,.body-link-custom:link,.body-link-custom:visited{'
			. '-webkit-text-fill-color:currentcolor!important;'
			. '}'
			. '.footer-link,.footer-link:link,.footer-link:visited{'
			. 'color:' . $muted . '!important;'
			. '-webkit-text-fill-color:' . $muted . '!important;'
			. '}';
	}

	/**
	 * @param string $slug     Palette slug.
	 * @param string $fallback Hex used when the preset is missing.
	 */
	private static function preset_dark( string $slug, string $fallback ): string {
		$pair = Email_Preset_Resolver::color_pair( $slug );
		$dark = '' !== $pair['dark'] ? $pair['dark'] : $fallback;
		$san  = Email_Style_Resolver::sanitize_css_value( $dark );
		return '' !== $san ? $san : $fallback;
	}

	/**
	 * @return string Rule bodies (no @media wrapper) for injection into the shell.
	 */
	public static function get_css(): string {
		if ( empty( self::$rules ) ) {
			return '';
		}
		return implode( "\n", array_values( self::$rules ) );
	}

	/**
	 * @return bool
	 */
	public static function has_rules(): bool {
		return ! empty( self::$rules );
	}
}
