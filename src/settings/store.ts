import { createReduxStore, register } from '@wordpress/data';
import type { Settings, SettingsStoreState, ApiResponse } from './types';

export const STORE_NAME = 'prc/email-builder-settings';

const DEFAULT_STATE: SettingsStoreState = {
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
	},
	isLoaded: false,
};

type SettingsFieldValue = Settings[keyof Settings];

type Action =
	| { type: 'SET_FROM_RESPONSE'; payload: ApiResponse }
	| {
			type: 'UPDATE_FIELD';
			field: keyof Settings;
			value: SettingsFieldValue;
	  };

const store = createReduxStore(STORE_NAME, {
	reducer(
		state: SettingsStoreState = DEFAULT_STATE,
		action: Action
	): SettingsStoreState {
		switch (action.type) {
			case 'SET_FROM_RESPONSE':
				return {
					...state,
					settings: action.payload.settings,
					isLoaded: true,
				};
			case 'UPDATE_FIELD':
				return {
					...state,
					settings: {
						...state.settings,
						[action.field]: action.value,
					},
				};
			default:
				return state;
		}
	},

	actions: {
		setFromResponse(response: ApiResponse) {
			return { type: 'SET_FROM_RESPONSE', payload: response } as const;
		},
		updateField(field: keyof Settings, value: SettingsFieldValue) {
			return { type: 'UPDATE_FIELD', field, value } as const;
		},
	},

	selectors: {
		getSettings(state: SettingsStoreState): Settings {
			return state.settings;
		},
		isLoaded(state: SettingsStoreState): boolean {
			return state.isLoaded;
		},
	},
});

register(store);
export { store };
