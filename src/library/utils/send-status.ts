import { __ } from '@wordpress/i18n';
import type { EmailLibraryRow } from '../hooks/use-emails';

export type SendStatusTone = 'success' | 'warning' | 'error' | 'neutral';

export interface ResolvedSendStatus {
	label: string;
	tone: SendStatusTone;
	raw: string;
}

const MAILCHIMP_LABELS: Record<string, string> = {
	__empty__: __('No Campaign', 'prc-email-builder'),
	save: __('MC Draft', 'prc-email-builder'),
	sent: __('MC Sent', 'prc-email-builder'),
	sending: __('Sending', 'prc-email-builder'),
	schedule: __('Scheduled', 'prc-email-builder'),
	paused: __('Paused', 'prc-email-builder'),
};

const MANDRILL_LABELS: Record<string, string> = {
	__empty__: __('Not Sent', 'prc-email-builder'),
	active: __('Active', 'prc-email-builder'),
	sent: __('Sent', 'prc-email-builder'),
	queued: __('Queued', 'prc-email-builder'),
	sending: __('Sending', 'prc-email-builder'),
	failed: __('Failed', 'prc-email-builder'),
	partial: __('Partial', 'prc-email-builder'),
};

function normalizeKey(status: string): string {
	return status || '__empty__';
}

export function toneForMailchimp(status: string): SendStatusTone {
	switch (status) {
		case 'sent':
			return 'success';
		case 'failed':
		case 'partial':
			return 'error';
		default:
			return 'warning';
	}
}

export function toneForMandrill(status: string): SendStatusTone {
	switch (status) {
		case 'sent':
		case 'active':
			return 'success';
		case 'failed':
		case 'partial':
			return 'error';
		default:
			return 'warning';
	}
}

export function resolveSendStatus(item: EmailLibraryRow): ResolvedSendStatus {
	const raw =
		item.type === 'campaign'
			? item.mailchimp_status || ''
			: item.mandrill_status || '';

	if (item.type === 'campaign') {
		const key = normalizeKey(raw);
		return {
			label: MAILCHIMP_LABELS[key] || raw || __('—', 'prc-email-builder'),
			tone: toneForMailchimp(raw),
			raw,
		};
	}

	if (item.delivery_mode === 'dynamic') {
		if (!raw) {
			return {
				label: __('Waiting for first send', 'prc-email-builder'),
				tone: 'warning',
				raw,
			};
		}
		if (raw === 'active') {
			return {
				label: __('Active', 'prc-email-builder'),
				tone: 'success',
				raw,
			};
		}
	}

	const key = normalizeKey(raw);
	return {
		label: MANDRILL_LABELS[key] || raw || __('—', 'prc-email-builder'),
		tone: toneForMandrill(raw),
		raw,
	};
}

declare global {
	interface Window {
		prcEmailLibrary?: {
			sendStatuses?: Array<{ value: string; label: string }>;
			postTypeScope?: 'campaign' | 'txn';
		};
	}
}

export function getSendStatusFilterElements(scope?: 'campaign' | 'txn') {
	const elements = window?.prcEmailLibrary?.sendStatuses || [];
	if (!scope) {
		return elements;
	}
	const prefix = `${scope}:`;
	return elements.filter((element) => element.value.startsWith(prefix));
}
