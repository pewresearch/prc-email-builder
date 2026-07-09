<?php
declare(strict_types=1);
/**
 * Plugin class.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Plugin class.
 *
 * @package    PRC\Platform\Email_Builder
 */
class Plugin {
	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the platform as initialized by hooks.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->version     = '1.0.0';
		$this->plugin_name = 'prc-email-builder';

		$this->load_dependencies();
		$this->init_dependencies();
	}


	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		$includes = plugin_dir_path( __DIR__ ) . '/includes/';

		// Bootstrap and core WordPress integration.
		require_once $includes . 'class-loader.php';
		require_once $includes . 'class-rewrites.php';
		require_once $includes . 'class-post-type.php';
		require_once $includes . 'class-newsletter-list.php';
		require_once $includes . 'templates/class-template-resolver.php';
		require_once $includes . 'templates/class-template-registry.php';
		require_once $includes . 'class-rest-api.php';
		require_once $includes . 'class-assets.php';
		require_once $includes . 'class-patterns.php';
		require_once $includes . 'class-settings.php';
		require_once $includes . 'class-send-status.php';
		require_once $includes . 'class-library.php';
		require_once $includes . 'class-preview.php';

		// Mailchimp integration.
		require_once $includes . 'mailchimp/class-mailchimp.php';
		require_once $includes . 'mailchimp/class-campaign-status-sync.php';

		// Mandrill delivery.
		require_once $includes . 'mandrill/class-mandrill-sender.php';
		require_once $includes . 'mandrill/class-mandrill-activity-export.php';

		// System (transactional) email.
		require_once $includes . 'system-email/class-system-email-sender.php';
		require_once $includes . 'system-email/class-system-email-recipients-table.php';
		require_once $includes . 'system-email/class-system-email-send-log.php';
		require_once $includes . 'system-email/class-form-send-system-email.php';

		// Scheduled follow-up automations.
		require_once $includes . 'automations/class-automation-window.php';
		require_once $includes . 'automations/class-automation-config.php';
		require_once $includes . 'automations/class-automation-enrollment.php';
		require_once $includes . 'automations/class-automation-scheduler.php';

		// Email HTML pipeline.
		require_once $includes . 'email/class-cached-email-html.php';
		require_once $includes . 'email/class-email-merge-tags.php';
		require_once $includes . 'email/class-email-template.php';
		require_once $includes . 'email/class-email-block-registry.php';
		require_once $includes . 'email/class-email-block-resolver.php';
		require_once $includes . 'email/class-dark-mode-registry.php';
		require_once $includes . 'email/class-email-preset-resolver.php';
		require_once $includes . 'email/class-email-style-resolver.php';
		require_once $includes . 'email/class-html-to-email-converter.php';
		require_once $includes . 'email/class-email-block-converter.php';
		require_once $includes . 'email/class-email-block-integration.php';

		// NGL migration.
		require_once $includes . 'migration/class-migration.php';
		require_once $includes . 'migration/class-migration-scheduler.php';

		// Mailchimp analytics reports.
		require_once $includes . 'reports/interface-report-provider.php';
		require_once $includes . 'reports/class-report-schema.php';
		require_once $includes . 'reports/class-mailchimp-report-provider.php';
		require_once $includes . 'reports/class-report-store.php';
		require_once $includes . 'reports/class-report-sync.php';

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once $includes . 'cli/class-cli-migrate.php';
			require_once $includes . 'cli/class-cli-audience.php';
			require_once $includes . 'cli/class-cli-resend.php';
			require_once $includes . 'cli/class-cli-automations.php';
			require_once $includes . 'cli/class-cli-system-key-migrate.php';
		}

		// Initialize the loader.
		$this->loader = new Loader();
	}

	/**
	 * Initialize the dependencies.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function init_dependencies() {
		$this->loader->add_action( 'init', $this, 'register_blocks' );

		// Register the block.json prcEmailHtml metadata injection.
		$this->loader->add_filter( 'block_type_metadata', 'PRC\Platform\Email_Builder\Email_Block_Resolver', 'inject_metadata_into_supports' );

		// Fire the registration action at init priority 5, mirroring markdown-for-agents.
		$this->loader->add_action( 'init', $this, 'fire_register_email_callbacks', 5 );

		new Post_Type( $this->loader );
		new Rewrites( $this->loader );
		new Newsletter_List( $this->loader );
		new Mailchimp( $this->loader );
		new Mandrill_Sender( $this->loader );
		new REST_API( $this->loader );
		new Assets( $this->loader );
		new Patterns( $this->loader );
		new Form_Send_System_Email( $this->loader );
		new Automation_Config( $this->loader );
		new System_Email_Send_Log( $this->loader );
		new Settings( $this->loader );
		new Library( $this->loader );
		new Preview( $this->loader );
		new Email_Block_Integration( $this->loader );

		Migration_Scheduler::init();
		Campaign_Status_Sync::init();
		Automation_Scheduler::init();
		\PRC\Platform\Email_Builder\Reports\Report_Sync::init();

		$this->loader->add_action( 'plugins_loaded', $this, 'register_wp_ai_features', 11 );

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'prc email migrate', CLI_Migrate::class );
			\WP_CLI::add_command( 'prc email migrate-system-keys', CLI_System_Key_Migrate::class );
			\WP_CLI::add_command( 'prc email audience', CLI_Audience::class );
			\WP_CLI::add_command( 'prc email resend', CLI_Resend::class );
			\WP_CLI::add_command( 'prc email automations', CLI_Automations::class );
		}
	}

	/**
	 * Register WP AI features when the WordPress AI plugin is available.
	 *
	 * @since 1.0.0
	 */
	public function register_wp_ai_features(): void {
		if ( ! class_exists( '\WordPress\AI\Abstracts\Abstract_Feature' ) ) {
			return;
		}

		require_once plugin_dir_path( __DIR__ ) . '/includes/ai/trait-newsletter-ai-ability-helpers.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/ai/class-suggest-subject-ability.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/ai/class-suggest-preview-text-ability.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/ai/class-generate-links-newsletter-ability.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/ai/class-newsletter-builder-ai-feature.php';

		add_action(
			'wpai_register_features',
			static function ( $registry ) {
				$registry->register_feature( new Email_Builder_AI_Feature() );
			}
		);
	}

	/**
	 * Fire the registration action so plugins can register email-HTML callbacks.
	 *
	 * @hook init (priority 5)
	 */
	public function fire_register_email_callbacks(): void {
		/**
		 * Register email-HTML callbacks for block types.
		 *
		 * Fires at init priority 5 so callbacks are in place before any
		 * newsletter content is converted. Each callback has the signature:
		 *   fn(array $block, \WP_Post $post): string
		 * and should return an email-safe HTML fragment.
		 *
		 * Example:
		 *   add_action( 'prc_email_builder_register_email_callbacks', function() {
		 *       Email_Block_Registry::register( 'my/block', fn($block, $post) => '<p>...</p>' );
		 *   } );
		 */
		do_action( 'prc_email_builder_register_email_callbacks' );
	}

	/**
	 * Register newsletter-builder blocks using the blocks manifest.
	 *
	 * Called on the `init` hook. Uses wp_register_block_metadata_collection()
	 * (WP 6.7+) to preload block metadata in one filesystem read.
	 *
	 * Dynamic-recipient transactional emails use prc_email_txn posts
	 * (sub-mode "dynamic") and are sent via System_Email_Sender; no bespoke
	 * block types are registered here.
	 */
	public function register_blocks(): void {
		$build_dir = PRC_EMAIL_BUILDER_DIR . '/build';
		$manifest  = $build_dir . '/blocks-manifest.php';

		if ( file_exists( $manifest ) ) {
			wp_register_block_metadata_collection( $build_dir, $manifest );
		}
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    PRC\Platform\Email_Builder\Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
