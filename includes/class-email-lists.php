<?php
/**
 * Scoped Campaign / Transactional DataViews admin list pages.
 *
 * @package    PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use function PRC\Platform\Wp_Admin_Dataview\plain_text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers per-post-type DataViews list screens, rewrites Emails menu
 * destinations, and redirects bare edit.php list URLs (with ?classic=1 escape).
 */
class Email_Lists {
	public const CAMPAIGNS_PAGE_SLUG     = 'prc-email-builder-campaigns';
	public const TRANSACTIONAL_PAGE_SLUG = 'prc-email-builder-transactional';
	public const SCRIPT_HANDLE           = 'prc-email-builder-admin-dataview';

	/**
	 * Bind list registration, assets, and provider localization.
	 *
	 * @param Loader $loader Plugin loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'prc_wp_admin_dataview_register_lists', $this, 'register_lists' );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_provider_assets', 20 );
		$loader->add_filter( 'prc_wp_admin_dataview_localize', $this, 'localize_provider', 10, 2 );
	}

	/**
	 * Admin URL for a scoped DataViews page.
	 *
	 * @param string $scope campaign or txn.
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
	 *
	 * @param string $page_slug Admin page slug.
	 */
	public static function scope_from_page_slug( string $page_slug ): ?string {
		return match ( $page_slug ) {
			self::CAMPAIGNS_PAGE_SLUG => 'campaign',
			self::TRANSACTIONAL_PAGE_SLUG => 'txn',
			default => null,
		};
	}

	/**
	 * Register campaign and transactional lists with the shared shell.
	 *
	 * @param object $lists Shared list registry.
	 */
	public function register_lists( $lists ): void {
		if ( ! is_object( $lists ) || ! method_exists( $lists, 'register' ) ) {
			return;
		}

		$parent    = 'edit.php?post_type=' . Post_Type::CAMPAIGN_POST_TYPE;
		$duplicate = array(
			'includeMeta' => array(
				'prc_email_subject',
				'prc_email_preview_text',
				'prc_email_template_slug',
				'prc_email_mailchimp_audience_id',
				'prc_email_mailchimp_segment_id',
				'prc_email_delivery_mode',
				'prc_email_audience_option_key',
				'prc_email_mandrill_template',
			),
		);
		$lists->register(
			array(
				'postType'             => Post_Type::CAMPAIGN_POST_TYPE,
				'pageSlug'             => self::CAMPAIGNS_PAGE_SLUG,
				'menuTitle'            => __( 'Campaigns', 'prc-email-builder' ),
				'pageTitle'            => __( 'Campaigns', 'prc-email-builder' ),
				'restPath'             => '/prc-email-builder/v1/library',
				'menuParent'           => $parent,
				'hideDefaultNewButton' => true,
				'postTypeScope'        => 'campaign',
				'duplicate'            => $duplicate,
			)
		);

		$lists->register(
			array(
				'postType'             => Post_Type::TRANSACTIONAL_POST_TYPE,
				'pageSlug'             => self::TRANSACTIONAL_PAGE_SLUG,
				'menuTitle'            => __( 'Transactional', 'prc-email-builder' ),
				'pageTitle'            => __( 'Transactional Emails', 'prc-email-builder' ),
				'restPath'             => '/prc-email-builder/v1/library',
				'menuParent'           => $parent,
				'hideDefaultNewButton' => true,
				'postTypeScope'        => 'txn',
				'duplicate'            => $duplicate,
			)
		);
	}

	/**
	 * Add email options to the shell boot data.
	 *
	 * @param array  $localize Localized shell data.
	 * @param string $post_type Current post type.
	 * @return array
	 */
	public function localize_provider( $localize, $post_type ) {
		if ( ! Post_Type::is_email_post_type( $post_type ) ) {
			return $localize;
		}

		$scope                = Post_Type::TRANSACTIONAL_POST_TYPE === $post_type ? 'txn' : 'campaign';
		$localize['statuses'] = array(
			array(
				'value' => 'publish',
				'label' => __( 'Published', 'prc-email-builder' ),
			),
			array(
				'value' => 'draft',
				'label' => __( 'Draft', 'prc-email-builder' ),
			),
			array(
				'value' => 'private',
				'label' => __( 'Private', 'prc-email-builder' ),
			),
		);
		$localize['email']    = array(
			'postEditUrl'                 => esc_url_raw( admin_url( 'post.php' ) ),
			'campaignNewUrl'              => esc_url_raw( admin_url( 'post-new.php?post_type=' . Post_Type::CAMPAIGN_POST_TYPE ) ),
			'transactionalNewUrl'         => esc_url_raw( admin_url( 'post-new.php?post_type=' . Post_Type::TRANSACTIONAL_POST_TYPE ) ),
			'postTypeScope'               => $scope,
			'newsletterLists'             => $this->get_newsletter_list_terms(),
			'sendStatuses'                => Send_Status::library_filter_options( $scope ),
			'researchTeams'               => $this->get_research_team_options(),
			'campaignPostType'            => Post_Type::CAMPAIGN_POST_TYPE,
			'transactionalPostType'       => Post_Type::TRANSACTIONAL_POST_TYPE,
			'newsletterListTaxonomy'      => Post_Type::TAXONOMY,
			'campaignPatternCategorySlug' => Patterns::CAMPAIGN_CATEGORY_SLUG,
			'audienceBuilders'            => 'txn' === $scope
				? Audience_Builder_Registry::to_js()
				: array(),
		);

		return $localize;
	}

	/**
	 * Enqueue the email provider after the shared shell.
	 *
	 * @param string $hook_suffix Current admin hook.
	 */
	public function enqueue_provider_assets( string $hook_suffix ): void {
		unset( $hook_suffix );

		if ( ! wp_script_is( 'prc-wp-admin-dataview', 'enqueued' ) ) {
			return;
		}

		$asset_file = PRC_EMAIL_BUILDER_DIR . '/build/admin-dataview/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'build/admin-dataview/index.js', PRC_EMAIL_BUILDER_FILE ),
			array_merge( $asset['dependencies'], array( 'prc-wp-admin-dataview' ) ),
			$asset['version'],
			true
		);

		if ( file_exists( PRC_EMAIL_BUILDER_DIR . '/build/admin-dataview/style-index.css' ) ) {
			$style_deps = array( 'wp-components' );
			if ( in_array( 'prc-components', $asset['dependencies'], true ) ) {
				$style_deps[] = 'prc-components';
			}

			wp_enqueue_style(
				self::SCRIPT_HANDLE,
				plugins_url( 'build/admin-dataview/style-index.css', PRC_EMAIL_BUILDER_FILE ),
				$style_deps,
				$asset['version']
			);
		}
	}

	/**
	 * Newsletter list taxonomy terms for filter dropdowns and campaign create.
	 *
	 * @return array<int, array{termId: int, slug: string, label: string, campaignPattern: string}>
	 */
	private function get_newsletter_list_terms(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Post_Type::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$formatted = array_map(
			static function ( \WP_Term $term ) {
				$pattern = Newsletter_List::sanitize_campaign_pattern(
					(string) get_term_meta(
						$term->term_id,
						Newsletter_List::CAMPAIGN_PATTERN_META_KEY,
						true
					)
				);

				return array(
					'termId'          => (int) $term->term_id,
					'slug'            => $term->slug,
					'label'           => plain_text( (string) $term->name ),
					'campaignPattern' => $pattern,
				);
			},
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
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'research-teams',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$formatted = array_map(
			static fn( \WP_Term $term ) => array(
				'termId' => (int) $term->term_id,
				'slug'   => $term->slug,
				'label'  => plain_text( (string) $term->name ),
			),
			$terms
		);

		usort(
			$formatted,
			static fn( array $a, array $b ) => strcmp( $a['label'], $b['label'] )
		);

		return $formatted;
	}
}
