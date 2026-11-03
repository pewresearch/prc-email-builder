import { getEmailConfig } from '../types';

export function getSendStatusFilterElements(scope?: 'campaign' | 'txn') {
	const elements = getEmailConfig()?.sendStatuses || [];
	if (!scope) {
		return elements;
	}
	const prefix = `${scope}:`;
	return elements.filter((element) => element.value.startsWith(prefix));
}
