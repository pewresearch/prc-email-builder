import { createSettingsStore } from '@prc/components';
import type { Settings, SettingsStoreState, ApiResponse } from './types';

export const STORE_NAME = 'prc/email-builder-settings';

type SettingsFieldValue = Settings[keyof Settings];

export const store = createSettingsStore<
	Settings,
	SettingsStoreState,
	ApiResponse
>({
	name: STORE_NAME,
	defaultState: {
		settings: {
			mailchimp_api_key: '',
			from_name: '',
			from_email: '',
			track_opens: true,
			track_clicks: true,
			reply_to: '',
			mandrill_subaccount: '',
			mandrill_tags: ['prc-newsletter'],
			connected: false,
			api_key_via_constant: false,
			mandrill_configured: false,
			automation_default_send_window: {
				timezone: 'America/New_York',
				hour: 9,
				minute: 0,
			},
		},
		isLoaded: false,
	},
	extraActions: {
		updateField(field: keyof Settings, value: SettingsFieldValue) {
			return { type: 'UPDATE_FIELD', field, value };
		},
	},
	extraReducer: (state, action) => {
		if (action.type === 'UPDATE_FIELD') {
			return {
				...state,
				settings: {
					...state.settings,
					[action.field as keyof Settings]:
						action.value as SettingsFieldValue,
				},
			};
		}
		return null;
	},
});
