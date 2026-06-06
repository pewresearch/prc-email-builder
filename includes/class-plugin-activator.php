<?php
/**
 * Plugin Activator
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use DEFAULT_TECHNICAL_CONTACT;

/**
 * Plugin Activator
 *
 * @package PRC\Platform\Email_Builder
 */
class Plugin_Activator {

	/**
	 * Activate the plugin
	 */
	public static function activate() {
		flush_rewrite_rules();

		Migration_Scheduler::schedule_dispatch();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'PRC Newsletter Builder Activated',
			'The PRC Newsletter Builder plugin has been activated on ' . get_site_url()
		);
	}
}
