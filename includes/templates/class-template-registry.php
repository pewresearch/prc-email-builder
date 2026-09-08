<?php
/**
 * Template Registry — discovers PHP body templates from the templates/ directory.
 *
 * Each template file declares its metadata via WordPress-style header comments:
 *
 *   Template Name: My Template
 *   Description:   Optional description.
 *   Audience:      abc123         (Mailchimp audience/list ID for auto-matching)
 *   Segment:       seg456         (Mailchimp segment ID; leave empty to match whole audience)
 *
 * The shell template (newsletter-email-shell.php) and any file whose basename
 * starts with '_' are excluded from discovery.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Discovers and caches available newsletter body templates.
 * All methods are static; this class is never instantiated.
 */
final class Template_Registry {

	/**
	 * Request-level cache.  null = not yet populated.
	 *
	 * @var array<string, array{slug:string,label:string,description:string,audience:string,segment:string,path:string}>|null
	 */
	private static ?array $cache = null;

	/**
	 * Map of docblock header label → array key.
	 */
	private const HEADERS = array(
		'name'        => 'Template Name',
		'description' => 'Description',
		'audience'    => 'Audience',
		'segment'     => 'Segment',
	);

	/**
	 * Return all registered templates, keyed by slug.
	 *
	 * Results are cached for the duration of the request.
	 *
	 * @return array<string, array{slug:string,label:string,description:string,audience:string,segment:string,path:string}>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$dir   = PRC_EMAIL_BUILDER_DIR . '/templates/';
		$files = glob( $dir . '*.php' );
		$files = false !== $files ? $files : array();
		$out   = array();

		foreach ( $files as $file ) {
			$name = basename( $file, '.php' );

			// Skip the shell template and any private partials (prefixed with _).
			if ( str_starts_with( $name, '_' ) || 'newsletter-email-shell' === $name ) {
				continue;
			}

			$headers = get_file_data( $file, self::HEADERS );

			// Only register files that declare a Template Name.
			if ( empty( $headers['name'] ) ) {
				continue;
			}

			$out[ $name ] = array(
				'slug'        => $name,
				'label'       => $headers['name'],
				'description' => $headers['description'] ?? '',
				'audience'    => $headers['audience'] ?? '',
				'segment'     => $headers['segment'] ?? '',
				'path'        => $file,
			);
		}

		/**
		 * Filter the registered template list.
		 *
		 * Plugins can add, remove, or re-order templates.
		 *
		 * @param array<string, array> $templates Discovered templates keyed by slug.
		 */
		self::$cache = (array) apply_filters( 'prc_email_builder_templates', $out );

		return self::$cache;
	}

	/**
	 * Return a single template by slug, or null if not registered.
	 *
	 * @param string $slug File slug (basename without .php).
	 * @return array{slug:string,label:string,description:string,audience:string,segment:string,path:string}|null
	 */
	public static function get( string $slug ): ?array {
		return self::all()[ $slug ] ?? null;
	}

	/**
	 * Return the default template.
	 *
	 * Prefers the slug "default"; falls back to the first registered template.
	 *
	 * @return array{slug:string,label:string,description:string,audience:string,segment:string,path:string}|null
	 */
	public static function default(): ?array {
		$all = self::all();

		if ( isset( $all['default'] ) ) {
			return $all['default'];
		}

		$first_key = array_key_first( $all );
		return null !== $first_key ? $all[ $first_key ] : null;
	}

	/**
	 * Return the transactional email body template.
	 *
	 * @return array{slug:string,label:string,description:string,audience:string,segment:string,path:string}|null
	 */
	public static function transactional(): ?array {
		return self::get( 'transactional' );
	}
}
