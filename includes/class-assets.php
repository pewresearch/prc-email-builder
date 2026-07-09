<?php
declare(strict_types=1);
/**
 * Block editor asset registration.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Enqueues the React sidebar panel on email campaign and transactional edit screens.
 * Localizes template list (slug + label) from Template_Registry so the
 * sidebar can render the template picker without a REST round-trip.
 */
class Assets {
	const SCRIPT_HANDLE      = 'prc-email-builder-sidebar';
	const FORM_ACTION_HANDLE = 'prc-email-builder-form-action';

	public function __construct( Loader $loader ) {
		$loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_sidebar' );
		$loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_form_action' );
	}

	/**
	 * Registers the `sendSystemEmail` prc-block/form action in the editor.
	 *
	 * Enqueued on every block editor screen because forms can live on any post
	 * type; the bundle is tiny and side-effect-only.
	 */
	public function enqueue_form_action(): void {
		$asset_file = PRC_EMAIL_BUILDER_DIR . '/build/form-action/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::FORM_ACTION_HANDLE,
			plugins_url( 'build/form-action/index.js', PRC_EMAIL_BUILDER_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
	}

	public function enqueue_sidebar(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$post_type = $this->resolve_editor_post_type( $screen->post_type );
		if ( ! Post_Type::is_email_post_type( $post_type ) ) {
			return;
		}

		// Skip migrated newsletters.
		global $post;
		$current_post_id = $post->ID ?? ( isset( $_GET['post'] ) ? (int) $_GET['post'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $current_post_id && Migration::is_migrated( $current_post_id ) ) {
			return;
		}

		$asset_file = PRC_EMAIL_BUILDER_DIR . '/build/sidebar/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'build/sidebar/index.js', PRC_EMAIL_BUILDER_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( file_exists( PRC_EMAIL_BUILDER_DIR . '/build/sidebar/style-index.css' ) ) {
			wp_enqueue_style(
				self::SCRIPT_HANDLE,
				plugins_url( 'build/sidebar/style-index.css', PRC_EMAIL_BUILDER_FILE ),
				[],
				$asset['version']
			);
		}

		$settings  = Mailchimp::get_settings();
		$templates = array_values(
			array_map(
				static fn( array $t ): array => [
					'slug'  => $t['slug'],
					'label' => $t['label'],
				],
				Template_Registry::all()
			)
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'prcEmailBuilderConfig',
			[
				'restNamespace'         => REST_API::NAMESPACE,
				'postTypes'             => Post_Type::POST_TYPES,
				'campaignPostType'      => Post_Type::CAMPAIGN_POST_TYPE,
				'transactionalPostType' => Post_Type::TRANSACTIONAL_POST_TYPE,
				'campaignPatternCategorySlug'      => Patterns::CAMPAIGN_CATEGORY_SLUG,
				'transactionalPatternCategorySlug' => Patterns::TRANSACTIONAL_CATEGORY_SLUG,
				'templates'            => $templates,
				'nonce'                => wp_create_nonce( 'wp_rest' ),
				'defaults'             => [
					'from_name'  => $settings['from_name'] ?? '',
					'from_email' => $settings['from_email'] ?? '',
				],
			]
		);
	}

	/**
	 * Resolve the post type for the current block editor screen.
	 *
	 * `WP_Screen::$post_type` is not always populated during
	 * `enqueue_block_editor_assets` (e.g. some CPT edit screens report `post`),
	 * so fall back to the edited post or `post_type` query arg.
	 */
	private function resolve_editor_post_type( string $screen_post_type ): string {
		if ( Post_Type::is_email_post_type( $screen_post_type ) ) {
			return $screen_post_type;
		}

		global $post;
		if ( $post instanceof \WP_Post && Post_Type::is_email_post_type( $post->post_type ) ) {
			return $post->post_type;
		}

		if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_type = get_post_type( (int) $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_string( $post_type ) && Post_Type::is_email_post_type( $post_type ) ) {
				return $post_type;
			}
		}

		if ( isset( $_GET['post_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_type = sanitize_key( (string) wp_unslash( $_GET['post_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( Post_Type::is_email_post_type( $post_type ) ) {
				return $post_type;
			}
		}

		return $screen_post_type;
	}
}
