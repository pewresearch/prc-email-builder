<?php
namespace PRC\Platform\Email_Builder;

use DEFAULT_TECHNICAL_CONTACT;

class Plugin_Deactivator {

	public static function deactivate() {
		flush_rewrite_rules();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'PRC Xxx Deactivated',
			'The PRC Xxx plugin has been deactivated on ' . get_site_url()
		);
	}
}
