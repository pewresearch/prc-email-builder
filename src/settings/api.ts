import { __ } from '@wordpress/i18n';
import { createSettingsClient } from '@prc/components';

import { store } from './store';

export const { fetchSettings, saveSettings } = createSettingsClient({
	restPath: '/prc-email-builder/v1/settings',
	store,
	successMessage: __('Settings saved.', 'prc-email-builder'),
});
