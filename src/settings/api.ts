import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { store as settingsStore } from './store';
import type { ApiResponse } from './types';

const REST_PATH = '/prc-email-builder/v1/settings';

export async function fetchSettings(): Promise<ApiResponse> {
	const { setFromResponse } = dispatch(settingsStore);
	const response = (await apiFetch({ path: REST_PATH })) as ApiResponse;
	setFromResponse(response);
	return response;
}

export async function saveSettings(): Promise<ApiResponse> {
	const { setFromResponse } = dispatch(settingsStore);
	const { createSuccessNotice } = dispatch(noticesStore);
	const settings = select(settingsStore).getSettings();

	const response = (await apiFetch({
		path: REST_PATH,
		method: 'POST',
		data: settings,
	})) as ApiResponse;

	setFromResponse(response);
	createSuccessNotice(__('Settings saved.', 'prc-email-builder'), {
		type: 'snackbar',
	});
	return response;
}
