<?php
/**
 * PRC Email Builder
 *
 * @package           PRC_Email_Builder
 * @author            Seth Rubenstein
 * @copyright         2024 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC Email Builder
 * Plugin URI:        https://github.com/pewresearch/prc-email-builder
 * Description:       Provides Email Builder functionality for PRC Platform.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Seth Rubenstein
 * Author URI:        https://pewresearch.org
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-email-builder
 * Requires Plugins:  prc-scripts, prc-post-publish-pipeline
 */

namespace PRC\Platform\Email_Builder;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRC_EMAIL_BUILDER_FILE', __FILE__ );
define( 'PRC_EMAIL_BUILDER_DIR', __DIR__ );
define( 'PRC_EMAIL_BUILDER_VERSION', '1.0.0' );

// When running inside the PRC Platform monorepo the root autoloader already
// provides every dependency; skip per-plugin Jetpack Autoloader initialization.
if ( ! defined( 'PRC_PLATFORM' ) ) {
	$prc_email_builder_autoloader = __DIR__ . '/vendor/autoload_packages.php';
	if ( file_exists( $prc_email_builder_autoloader ) ) {
		require_once $prc_email_builder_autoloader;
	}
	unset( $prc_email_builder_autoloader );
}

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-plugin-activator.php
 */
function activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-loader.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-post-type.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-migration.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-migration-scheduler.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-activator.php';
	Plugin_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-plugin-deactivator.php
 */
function deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-deactivator.php';
	Plugin_Deactivator::deactivate();
}

register_activation_hook( __FILE__, '\PRC\Platform\Email_Builder\activate' );
register_deactivation_hook( __FILE__, '\PRC\Platform\Email_Builder\deactivate' );

/**
 * Helper utilities
 */
require plugin_dir_path( __FILE__ ) . 'includes/utils.php';

/**
 * The core plugin class that is used to define the hooks that initialize the various components.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-plugin.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_prc_email_builder() {
	$plugin = new Plugin();
	$plugin->run();
}
run_prc_email_builder();
