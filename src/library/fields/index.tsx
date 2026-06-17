import { __ } from '@wordpress/i18n';
import SendStatusBadge from '../components/send-status-badge';
import type { EmailLibraryRow } from '../hooks/use-emails';
import {
	getSendStatusFilterElements,
	resolveSendStatus,
} from '../utils/send-status';

declare global {
	interface Window {
		prcEmailLibrary?: {
			newsletterLists?: Array<{ slug: string; label: string }>;
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

function getTypeLabel(type: EmailLibraryRow['type']) {
	if (type === 'campaign') {
		return __('Campaign', 'prc-email-builder');
	}
	if (type === 'txn') {
		return __('Transactional', 'prc-email-builder');
	}
	return type;
}

const fields = [
	{
		id: 'title',
		type: 'text',
		label: __('Title', 'prc-email-builder'),
		getValue: ({ item }: { item: EmailLibraryRow }) => item?.title || '',
		enableGlobalSearch: true,
		enableSorting: true,
		enableHiding: false,
	},
	{
		id: 'type',
		label: __('Type', 'prc-email-builder'),
		getValue: ({ item }: { item: EmailLibraryRow }) =>
			getTypeLabel(item?.type),
		render: ({ item }: { item: EmailLibraryRow }) => (
			<span>{getTypeLabel(item?.type)}</span>
		),
		elements: [
			{
				value: 'campaign',
				label: __('Campaign', 'prc-email-builder'),
			},
			{
				value: 'txn',
				label: __('Transactional', 'prc-email-builder'),
			},
		],
		filterBy: {
			operators: ['isAny'],
			isPrimary: true,
		},
		enableSorting: false,
	},
	{
		id: 'newsletterLists',
		label: __('Newsletter List', 'prc-email-builder'),
		getValue: ({ item }: { item: EmailLibraryRow }) =>
			getNewsletterListLabels(item),
		render: ({ item }: { item: EmailLibraryRow }) => (
			<span>
				{getNewsletterListLabels(item) || __('—', 'prc-email-builder')}
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
				<SendStatusBadge label={resolved.label} tone={resolved.tone} />
			);
		},
		elements: getSendStatusFilterElements(),
		filterBy: {
			operators: ['isAny'],
		},
		enableSorting: false,
	},
	{
		id: 'subject',
		type: 'text',
		label: __('Subject', 'prc-email-builder'),
		getValue: ({ item }: { item: EmailLibraryRow }) => item?.subject || '',
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
		getValue: ({ item }: { item: EmailLibraryRow }) => item?.modified || '',
		enableSorting: true,
	},
	{
		id: 'status',
		label: __('Status', 'prc-email-builder'),
		getValue: ({ item }: { item: EmailLibraryRow }) => item?.status || '',
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

export default fields;
