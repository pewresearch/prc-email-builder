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

		System_Email_Recipients_Table::maybe_create_table();
		Automation_Enrollment::maybe_create_table();

		Migration_Scheduler::schedule_dispatch();
		Automation_Scheduler::maybe_schedule();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'PRC Newsletter Builder Activated',
			'The PRC Newsletter Builder plugin has been activated on ' . get_site_url()
		);
	}
}
