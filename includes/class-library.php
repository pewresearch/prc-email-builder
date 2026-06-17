<?php
declare( strict_types=1 );
/**
 * Email Library admin page (DataViews listing of email posts).
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Library {
	const ADMIN_PAGE_SLUG = 'prc-email-builder-library';

	public function __construct( Loader $loader ) {
		$loader->add_action( 'admin_menu', $this, 'register_admin_page' );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
	}

	/** @hook admin_menu */
	public function register_admin_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::CAMPAIGN_POST_TYPE,
			__( 'Email Library', 'prc-email-builder' ),
			__( 'Library', 'prc-email-builder' ),
			'edit_posts',
			self::ADMIN_PAGE_SLUG,
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		echo '<div id="prc-email-builder-library-admin"></div>';
	}

	/** @hook admin_enqueue_scripts */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( Post_Type::CAMPAIGN_POST_TYPE . '_page_' . self::ADMIN_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$asset_file = PRC_EMAIL_BUILDER_DIR . '/build/library/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset  = require $asset_file;
		$handle = 'prc-email-builder-library';

		wp_enqueue_script(
			$handle,
			plugins_url( 'build/library/index.js', PRC_EMAIL_BUILDER_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( file_exists( PRC_EMAIL_BUILDER_DIR . '/build/library/style-index.css' ) ) {
			wp_enqueue_style(
				$handle,
				plugins_url( 'build/library/style-index.css', PRC_EMAIL_BUILDER_FILE ),
				[ 'wp-components' ],
				$asset['version']
			);
		}

		wp_localize_script(
			$handle,
			'prcEmailLibrary',
			[
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'restUrl'         => esc_url_raw( rest_url() ),
				'postEditUrl'     => esc_url_raw( admin_url( 'post.php' ) ),
				'newsletterLists' => $this->get_newsletter_list_terms(),
				'sendStatuses'    => Send_Status::library_filter_options(),
				'researchTeams'   => $this->get_research_team_options(),
			]
		);
	}

	/**
	 * Newsletter list taxonomy terms for filter dropdowns.
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	private function get_newsletter_list_terms(): array {
		$terms = get_terms(
			[
				'taxonomy'   => Post_Type::TAXONOMY,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		$formatted = array_map(
			static fn( \WP_Term $term ) => [
				'slug'  => $term->slug,
				'label' => $term->name,
			],
			$terms
		);

		usort(
			$formatted,
			static fn( array $a, array $b ) => strcmp( $a['label'], $b['label'] )
		);

		return $formatted;
	}

	/**
	 * Research team taxonomy terms for the links newsletter generator.
	 *
	 * @return array<int, array{termId: int, slug: string, label: string}>
	 */
	private function get_research_team_options(): array {
		if ( ! taxonomy_exists( 'research-teams' ) ) {
			return [];
		}

		$terms = get_terms(
			[
				'taxonomy'   => 'research-teams',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		$formatted = array_map(
			static fn( \WP_Term $term ) => [
				'termId' => (int) $term->term_id,
				'slug'   => $term->slug,
				'label'  => $term->name,
			],
			$terms
		);

		usort(
			$formatted,
			static fn( array $a, array $b ) => strcmp( $a['label'], $b['label'] )
		);

		return $formatted;
	}
}
