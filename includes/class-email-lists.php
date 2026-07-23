<?php
declare( strict_types=1 );
/**
 * Scoped Campaign / Transactional DataViews admin list pages.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers per-post-type DataViews list screens, rewrites Emails menu
 * destinations, and redirects bare edit.php list URLs (with ?classic=1 escape).
 */
class Email_Lists {
	public const CAMPAIGNS_PAGE_SLUG      = 'prc-email-builder-campaigns';
	public const TRANSACTIONAL_PAGE_SLUG = 'prc-email-builder-transactional';
	public const SCRIPT_HANDLE           = 'prc-email-builder-library';

	public function __construct( Loader $loader ) {
		$loader->add_action( 'admin_menu', $this, 'register_admin_pages' );
		$loader->add_action( 'admin_menu', $this, 'rewrite_menu_destinations', 1000 );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
		$loader->add_action( 'load-edit.php', $this, 'redirect_classic_list_to_dataviews' );
		$loader->add_filter( 'parent_file', $this, 'filter_parent_file' );
		$loader->add_filter( 'submenu_file', $this, 'filter_submenu_file', 10, 2 );
	}

	/**
	 * Admin URL for a scoped DataViews page.
	 */
	public static function get_page_url( string $scope ): string {
		$page = 'txn' === $scope
			? self::TRANSACTIONAL_PAGE_SLUG
			: self::CAMPAIGNS_PAGE_SLUG;

		return admin_url(
			'edit.php?post_type=' . Post_Type::CAMPAIGN_POST_TYPE . '&page=' . $page
		);
	}

	/**
	 * Resolve scope from an admin page slug.
	 */
	public static function scope_from_page_slug( string $page_slug ): ?string {
		return match ( $page_slug ) {
			self::CAMPAIGNS_PAGE_SLUG => 'campaign',
			self::TRANSACTIONAL_PAGE_SLUG => 'txn',
			default => null,
		};
	}

	/** @hook admin_menu */
	public function register_admin_pages(): void {
		$parent = 'edit.php?post_type=' . Post_Type::CAMPAIGN_POST_TYPE;

		add_submenu_page(
			$parent,
			__( 'Campaigns', 'prc-email-builder' ),
			__( 'Campaigns', 'prc-email-builder' ),
			'edit_posts',
			self::CAMPAIGNS_PAGE_SLUG,
			[ $this, 'render_admin_page' ]
		);

		add_submenu_page(
			$parent,
			__( 'Transactional Emails', 'prc-email-builder' ),
			__( 'Transactional', 'prc-email-builder' ),
			'edit_posts',
			self::TRANSACTIONAL_PAGE_SLUG,
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		echo '<div id="prc-email-builder-library-admin"></div>';
	}

	/**
	 * Point Campaigns / Transactional submenu items at DataViews pages.
	 *
	 * Top-level Emails keeps the classic edit.php parent slug so $submenu
	 * parenting stays intact; load-edit.php redirects that URL to DataViews.
	 *
	 * @hook admin_menu (priority 1000)
	 */
	public function rewrite_menu_destinations(): void {
		global $submenu;

		$parent        = 'edit.php?post_type=' . Post_Type::CAMPAIGN_POST_TYPE;
		$campaign_list = 'edit.php?post_type=' . Post_Type::CAMPAIGN_POST_TYPE;
		$txn_list      = 'edit.php?post_type=' . Post_Type::TRANSACTIONAL_POST_TYPE;

		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		$seen     = [];
		$reordered = [];

		foreach ( $submenu[ $parent ] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item[2] ) ) {
				continue;
			}

			if ( $campaign_list === $item[2] ) {
				$item[2] = self::CAMPAIGNS_PAGE_SLUG;
				$item[0] = __( 'Campaigns', 'prc-email-builder' );
				$item[3] = __( 'Campaigns', 'prc-email-builder' );
			} elseif ( $txn_list === $item[2] ) {
				$item[2] = self::TRANSACTIONAL_PAGE_SLUG;
				$item[0] = __( 'Transactional', 'prc-email-builder' );
				$item[3] = __( 'Transactional Emails', 'prc-email-builder' );
			}

			if ( isset( $seen[ $item[2] ] ) ) {
				continue;
			}
			$seen[ $item[2] ] = true;
			$reordered[]      = $item;
		}

		$submenu[ $parent ] = array_values( $reordered );
	}

	/**
	 * Redirect bare CPT list tables to DataViews unless ?classic=1.
	 *
	 * Skips POST so classic list-table form submissions (bulk actions,
	 * screen options, empty trash) are not intercepted before edit.php runs.
	 *
	 * @hook load-edit.php
	 */
	public function redirect_classic_list_to_dataviews(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- request method check only
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['page'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['classic'] ) && '1' === (string) $_GET['classic'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type = isset( $_GET['post_type'] )
			? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) )
			: '';

		if ( ! Post_Type::is_email_post_type( $post_type ) ) {
			return;
		}

		$scope = Post_Type::TRANSACTIONAL_POST_TYPE === $post_type ? 'txn' : 'campaign';
		wp_safe_redirect( self::get_page_url( $scope ) );
		exit;
	}

	/**
	 * Keep Emails parent highlighted on DataViews pages.
	 *
	 * @hook parent_file
	 */
	public function filter_parent_file( string $parent_file ): string {
		$scope = $this->current_page_scope();
		if ( null !== $scope ) {
			return 'edit.php?post_type=' . Post_Type::CAMPAIGN_POST_TYPE;
		}
		return $parent_file;
	}

	/**
	 * Highlight the correct Campaigns / Transactional submenu item.
	 *
	 * @hook submenu_file
	 * @param string|null $submenu_file
	 * @param string      $parent_file
	 */
	public function filter_submenu_file( $submenu_file, string $parent_file ) {
		unset( $parent_file );
		$scope = $this->current_page_scope();
		if ( 'campaign' === $scope ) {
			return self::CAMPAIGNS_PAGE_SLUG;
		}
		if ( 'txn' === $scope ) {
			return self::TRANSACTIONAL_PAGE_SLUG;
		}
		return $submenu_file;
	}

	/** @hook admin_enqueue_scripts */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		$scope = $this->scope_from_hook_suffix( $hook_suffix );
		if ( null === $scope ) {
			return;
		}

		$asset_file = PRC_EMAIL_BUILDER_DIR . '/build/library/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset  = require $asset_file;
		$handle = self::SCRIPT_HANDLE;

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

		$page_title = 'txn' === $scope
			? __( 'Transactional Emails', 'prc-email-builder' )
			: __( 'Campaigns', 'prc-email-builder' );

		$page_description = 'txn' === $scope
			? __( 'Browse and manage transactional emails.', 'prc-email-builder' )
			: __( 'Browse and manage email campaigns.', 'prc-email-builder' );

		wp_localize_script(
			$handle,
			'prcEmailLibrary',
			[
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'restUrl'         => esc_url_raw( rest_url() ),
				'postEditUrl'     => esc_url_raw( admin_url( 'post.php' ) ),
				'postTypeScope'   => $scope,
				'pageTitle'       => $page_title,
				'pageDescription' => $page_description,
				'newsletterLists' => $this->get_newsletter_list_terms(),
				'sendStatuses'    => Send_Status::library_filter_options( $scope ),
				'researchTeams'   => $this->get_research_team_options(),
			]
		);
	}

	/**
	 * @return 'campaign'|'txn'|null
	 */
	private function current_page_scope(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		return self::scope_from_page_slug( $page );
	}

	/**
	 * @return 'campaign'|'txn'|null
	 */
	private function scope_from_hook_suffix( string $hook_suffix ): ?string {
		$campaigns_hook = Post_Type::CAMPAIGN_POST_TYPE . '_page_' . self::CAMPAIGNS_PAGE_SLUG;
		$txn_hook       = Post_Type::CAMPAIGN_POST_TYPE . '_page_' . self::TRANSACTIONAL_PAGE_SLUG;

		if ( $campaigns_hook === $hook_suffix ) {
			return 'campaign';
		}
		if ( $txn_hook === $hook_suffix ) {
			return 'txn';
		}
		return null;
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
