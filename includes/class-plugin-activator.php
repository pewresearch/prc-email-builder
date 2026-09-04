<?php
/**
 * Plugin Activator
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

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
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- CPT rewrite rules must refresh on activation.
		flush_rewrite_rules();

		System_Email_Recipients_Table::maybe_create_table();
		Mandrill_Event_Ledger::maybe_create_table();
		Automation_Enrollment::maybe_create_table();

		Automation_Scheduler::maybe_schedule();
	}
}
