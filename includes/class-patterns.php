<?php
declare(strict_types=1);
/**
 * Block pattern registration for newsletter campaigns.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Registers a prc-newsletter pattern category and the bundled email
 * starting-point patterns. Patterns are scoped to email CPTs so they
 * only appear in the inserter when editing a newsletter campaign.
 */
class Patterns {
	const CATEGORY_SLUG = 'prc-newsletter';

	public function __construct( Loader $loader ) {
		$loader->add_action( 'init', $this, 'register_category' );
		$loader->add_action( 'init', $this, 'register_patterns' );
	}

	public function register_category(): void {
		register_block_pattern_category(
			self::CATEGORY_SLUG,
			[ 'label' => _x( 'Newsletter', 'Block pattern category', 'prc-email-builder' ) ]
		);
	}

	public function register_patterns(): void {
		$patterns_dir = PRC_EMAIL_BUILDER_DIR . '/patterns/';
		$pattern_files = glob( $patterns_dir . '*.php' );

		if ( empty( $pattern_files ) ) {
			return;
		}

		foreach ( $pattern_files as $file ) {
			$pattern = include $file;
			if ( ! is_array( $pattern ) || empty( $pattern['slug'] ) ) {
				continue;
			}
			register_block_pattern( $pattern['slug'], $pattern );
		}
	}
}
