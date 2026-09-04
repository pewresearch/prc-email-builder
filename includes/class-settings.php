<?php
/**
 * Admin settings page and REST endpoint for Email Builder configuration.
 *
 * @package    PRC\Platform\Email_Builder
 */

declare( strict_types=1 );

namespace PRC\Platform\Email_Builder;

use PRC\Platform\Settings_Page_Boot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Email Builder settings admin page and REST endpoints.
 */
class Settings {
	const ADMIN_PAGE_SLUG = 'prc-email-builder-settings';

	/**
	 * Wire admin and REST hooks.
	 *
	 * @param Loader $loader Plugin loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'admin_menu', $this, 'register_admin_page' );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	// -------------------------------------------------------------------------
	// Admin page
	// -------------------------------------------------------------------------

	/** Register settings submenu. @hook admin_menu */
	public function register_admin_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::CAMPAIGN_POST_TYPE,
			__( 'Email Builder Settings', 'prc-email-builder' ),
			__( 'Settings', 'prc-email-builder' ),
			'manage_options',
			self::ADMIN_PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the React settings mount point.
	 */
	public function render_admin_page(): void {
		Settings_Page_Boot::render( 'prc-email-builder-settings-admin' );
	}

	/**
	 * Enqueue settings admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @hook admin_enqueue_scripts
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( Post_Type::CAMPAIGN_POST_TYPE . '_page_' . self::ADMIN_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$asset_file = PRC_EMAIL_BUILDER_DIR . '/build/settings/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset  = require $asset_file;
		$handle = 'prc-email-builder-settings';

		wp_enqueue_script(
			$handle,
			plugins_url( 'build/settings/index.js', PRC_EMAIL_BUILDER_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( file_exists( PRC_EMAIL_BUILDER_DIR . '/build/settings/style-index.css' ) ) {
			$style_deps = array( 'wp-components' );
			if ( in_array( 'prc-components', $asset['dependencies'], true ) ) {
				$style_deps[] = 'prc-components';
			}

			wp_enqueue_style(
				$handle,
				plugins_url( 'build/settings/style-index.css', PRC_EMAIL_BUILDER_FILE ),
				$style_deps,
				$asset['version']
			);
		}

		Settings_Page_Boot::enqueue(
			$handle,
			(string) $asset['version'],
			'prc-email-builder-settings-admin'
		);
	}

	// -------------------------------------------------------------------------
	// REST endpoints
	// -------------------------------------------------------------------------

	/** Register settings REST routes. @hook rest_api_init */
	public function register_routes(): void {
		register_rest_route(
			REST_API::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings_endpoint' ),
					'permission_callback' => fn(): bool => current_user_can( 'manage_options' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings_endpoint' ),
					'permission_callback' => fn(): bool => current_user_can( 'manage_options' ),
				),
			)
		);
	}

	/**
	 * Return current settings for the React app.
	 */
	public function get_settings_endpoint(): \WP_REST_Response {
		return rest_ensure_response( array( 'settings' => $this->get_response_settings() ) );
	}

	/**
	 * Persist settings submitted from the React app.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function save_settings_endpoint( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid payload.' ), 400 );
		}

		Mailchimp::save_settings( $body );

		if ( isset( $body['automation_default_send_window'] ) && is_array( $body['automation_default_send_window'] ) ) {
			Automation_Window::save_site_default( $body['automation_default_send_window'] );
		}

		return rest_ensure_response( array( 'settings' => $this->get_response_settings() ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Builds the settings payload for the React app, augmented with read-only
	 * connection status and a flag indicating whether the API key is managed
	 * via a constant (so the UI can hide the key field).
	 */
	private function get_response_settings(): array {
		$settings             = Mailchimp::get_settings();
		$mailchimp            = new Mailchimp();
		$api_key_via_constant = defined( Mailchimp::API_KEY_CONSTANT );

		// Never expose the raw key — return a placeholder when set via constant.
		if ( $api_key_via_constant ) {
			$settings['mailchimp_api_key'] = '';
		}

		// Mandrill has no separate stored key — it is only ever set via the
		// PRC_PLATFORM_MANDRILL_KEY constant (see Mandrill_Sender / System_Email_Sender).
		// Mirror the senders' guard: defined AND non-empty once cast to string.
		$mandrill_constant   = Mandrill_Sender::API_KEY_CONSTANT;
		$mandrill_configured = defined( $mandrill_constant )
			&& '' !== trim( (string) constant( $mandrill_constant ) );

		return array_merge(
			$settings,
			array(
				'connected'                      => $mailchimp->is_connected(),
				'api_key_via_constant'           => $api_key_via_constant,
				'mandrill_configured'            => $mandrill_configured,
				'automation_default_send_window' => Automation_Window::get_site_default(),
			) 
		);
	}
}
