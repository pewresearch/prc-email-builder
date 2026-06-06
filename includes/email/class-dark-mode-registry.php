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

		$class = 'dm-' . substr( md5( $property . '|' . $light . '|' . $dark ), 0, 8 );
		$prop  = preg_replace( '/[^a-z\-]/', '', strtolower( $property ) );
		if ( '' === $prop ) {
			return '';
		}

		$declaration = $prop . ':' . Email_Style_Resolver::sanitize_css_value( $dark ) . '!important;';
		if ( '' === Email_Style_Resolver::sanitize_css_value( $dark ) ) {
			return '';
		}

		self::add( $class, $declaration );
		return $class;
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
