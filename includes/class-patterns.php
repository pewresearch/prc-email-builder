<?php
declare(strict_types=1);
/**
 * Block pattern registration for newsletter campaigns.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Registers email pattern categories and bundled starting-point patterns.
 *
 * Campaign patterns use `email-campaign`; transactional patterns use
 * `email-transactional`. Editors can manage reusable layouts in the site
 * editor under the matching category.
 */
class Patterns {
	const CAMPAIGN_CATEGORY_SLUG      = 'email-campaign';
	const TRANSACTIONAL_CATEGORY_SLUG = 'email-transactional';

	public function __construct( Loader $loader ) {
		$loader->add_action( 'init', $this, 'register_category' );
		// $loader->add_action( 'init', $this, 'register_patterns' );
	}

	public function register_category(): void {
		register_block_pattern_category(
			self::CAMPAIGN_CATEGORY_SLUG,
			[ 'label' => _x( 'Email Campaign', 'Block pattern category', 'prc-email-builder' ) ]
		);
		register_block_pattern_category(
			self::TRANSACTIONAL_CATEGORY_SLUG,
			[ 'label' => _x( 'Transactional Email', 'Block pattern category', 'prc-email-builder' ) ]
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
