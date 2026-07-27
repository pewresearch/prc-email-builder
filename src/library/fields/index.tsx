import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { chartBar } from '@wordpress/icons';
import SendStatusBadge from '../components/send-status-badge';
import type { EmailLibraryRow } from '../hooks/use-emails';
import type { EmailListScope } from '../types';
import {
	getSendStatusFilterElements,
	resolveSendStatus,
} from '../utils/send-status';

function formatEngagementRate(rate: number | null | undefined): string {
	if (rate === null || rate === undefined || Number.isNaN(rate)) {
		return '—';
	}
	if (rate <= 0 && rate !== 0) {
		return '—';
	}
	return `${(rate * 100).toFixed(1)}%`;
}

declare global {
	interface Window {
		prcEmailLibrary?: {
			newsletterLists?: Array<{
				termId?: number;
				slug: string;
				label: string;
				campaignPattern?: string;
			}>;
			postTypeScope?: EmailListScope;
		};
	}
}

function getNewsletterListElements() {
	const terms = window?.prcEmailLibrary?.newsletterLists || [];
	return terms.map((term) => ({
		value: term.slug,
		label: term.label,
	}));
}

function getNewsletterListLabels(item: EmailLibraryRow) {
	return item.newsletter_lists?.map((term) => term.label).join(', ') || '';
}

export function getDefaultVisibleFields(scope: EmailListScope): string[] {
	if (scope === 'txn') {
		return ['sendStatus', 'status', 'date'];
	}
	return [
		'newsletterLists',
		'sendStatus',
		'openRate',
		'clickRate',
		'stats',
		'status',
		'date',
	];
}

export interface GetFieldsOptions {
	onOpenStats?: (item: EmailLibraryRow) => void;
}

export function getFieldsForScope(
	scope: EmailListScope,
	options: GetFieldsOptions = {}
) {
	const { onOpenStats } = options;
	const fields = [
		{
			id: 'title',
			type: 'text',
			label: __('Title', 'prc-email-builder'),
			getValue: ({ item }: { item: EmailLibraryRow }) =>
				item?.title || '',
			enableGlobalSearch: true,
			enableSorting: true,
			enableHiding: false,
		},
		{
			id: 'newsletterLists',
			label: __('Newsletter List', 'prc-email-builder'),
			getValue: ({ item }: { item: EmailLibraryRow }) =>
				getNewsletterListLabels(item),
			render: ({ item }: { item: EmailLibraryRow }) => (
				<span>
					{getNewsletterListLabels(item) ||
						__('—', 'prc-email-builder')}
				</span>
			),
			elements: getNewsletterListElements(),
			filterBy: {
				operators: ['isAny'],
				isPrimary: true,
			},
			enableSorting: false,
		},
		{
			id: 'sendStatus',
			label: __('Send Status', 'prc-email-builder'),
			getValue: ({ item }: { item: EmailLibraryRow }) =>
				resolveSendStatus(item).label,
			render: ({ item }: { item: EmailLibraryRow }) => {
				const resolved = resolveSendStatus(item);
				return (
					<SendStatusBadge
						label={resolved.label}
						tone={resolved.tone}
					/>
				);
			},
			elements: getSendStatusFilterElements(scope),
			filterBy: {
				operators: ['isAny'],
			},
			enableSorting: false,
		},
		{
			id: 'openRate',
			label: __('Open rate', 'prc-email-builder'),
			getValue: ({ item }: { item: EmailLibraryRow }) =>
				formatEngagementRate(item?.open_rate),
			render: ({ item }: { item: EmailLibraryRow }) => (
				<span>{formatEngagementRate(item?.open_rate)}</span>
			),
			enableSorting: true,
		},
		{
			id: 'clickRate',
			label: __('Click rate', 'prc-email-builder'),
			getValue: ({ item }: { item: EmailLibraryRow }) =>
				formatEngagementRate(item?.click_rate),
			render: ({ item }: { item: EmailLibraryRow }) => (
				<span>{formatEngagementRate(item?.click_rate)}</span>
			),
			enableSorting: true,
		},
		{
			id: 'stats',
			label: __('Stats', 'prc-email-builder'),
			getValue: ({ item }: { item: EmailLibraryRow }) =>
				item?.mailchimp_status === 'sent'
					? __('Available', 'prc-email-builder')
					: __('Unavailable', 'prc-email-builder'),
			render: ({ item }: { item: EmailLibraryRow }) => {
				const isSent = item?.mailchimp_status === 'sent';
				return (
					<Button
						icon={chartBar}
						label={__('View stats', 'prc-email-builder')}
						size="compact"
						disabled={!isSent}
						onClick={(event) => {
							event.stopPropagation();
							if (!isSent || !onOpenStats) {
								return;
							}
							onOpenStats(item);
						}}
					/>
				);
			},
			enableSorting: false,
			enableHiding: true,
		},
		{
			id: 'subject',
			type: 'text',
			label: __('Subject', 'prc-email-builder'),
			getValue: ({ item }: { item: EmailLibraryRow }) =>
				item?.subject || '',
			enableGlobalSearch: false,
			enableSorting: false,
		},
		{
			id: 'date',
			type: 'datetime',
			label: __('Date', 'prc-email-builder'),
			getValue: ({ item }: { item: EmailLibraryRow }) => item?.date || '',
			enableSorting: true,
		},
		{
			id: 'modified',
			type: 'datetime',
			label: __('Last Modified', 'prc-email-builder'),
			getValue: ({ item }: { item: EmailLibraryRow }) =>
				item?.modified || '',
			enableSorting: true,
		},
		{
			id: 'status',
			label: __('Status', 'prc-email-builder'),
			getValue: ({ item }: { item: EmailLibraryRow }) =>
				item?.status || '',
			elements: [
				{
					value: 'publish',
					label: __('Published', 'prc-email-builder'),
				},
				{
					value: 'draft',
					label: __('Draft', 'prc-email-builder'),
				},
				{
					value: 'private',
					label: __('Private', 'prc-email-builder'),
				},
			],
			filterBy: {
				operators: ['isAny'],
			},
			enableSorting: false,
		},
	];

	if (scope === 'txn') {
		return fields.filter(
			(field) =>
				field.id !== 'newsletterLists' &&
				field.id !== 'openRate' &&
				field.id !== 'clickRate' &&
				field.id !== 'stats'
		);
	}

	return fields;
}

export default getFieldsForScope;
