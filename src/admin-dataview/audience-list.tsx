import apiFetch from '@wordpress/api-fetch';
import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { edit, external, trash } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';

import {
	DeleteAudienceModal,
	EditAudienceModal,
	EditNewsletterListModal,
	type ActionModalProps,
} from './audience-action-modals';
import {
	formatRate,
	getBuilderFilterElements,
	getMailchimpTargetLabel,
	isNewsletterList,
	isStoredAudience,
	MAILCHIMP_BUILDER,
	OTHER_BUILDER,
	type AudienceListRow,
	type AudienceRecordType,
} from './audience-catalog';
import { getAudienceErrorMessage } from './audience-builder-views';
import { createCampaignForList } from '../library/create-campaign-draft';
import { getEmailConfig } from '../library/types';

type FieldArgs = { item: AudienceListRow };

const VERIFICATION_LABELS: Record<string, string> = {
	verified: __('Verified only', 'prc-email-builder'),
	unverified: __('Unverified only', 'prc-email-builder'),
	all: __('All users', 'prc-email-builder'),
};

export const AUDIENCE_DEFAULT_VISIBLE_FIELDS = [
	'recordType',
	'status',
	'count',
	'builder',
	'sentCount',
	'avgOpenRate',
	'avgClickRate',
	'lastSentAt',
];

function getTypeLabel(recordType: AudienceRecordType): string {
	switch (recordType) {
		case 'newsletter-list':
			return __('Newsletter list', 'prc-email-builder');
		case 'audience':
			return __('Recipient list', 'prc-email-builder');
		case 'job':
			return __('Building', 'prc-email-builder');
		default: {
			const exhaustive: never = recordType;
			return exhaustive;
		}
	}
}

const RECORD_TYPES: AudienceRecordType[] = [
	'newsletter-list',
	'audience',
	'job',
];

function formatDate(value: string | null): string {
	return value ? new Date(value).toLocaleString() : '—';
}

function getStatusLabel(item: AudienceListRow): string {
	const status = item.status;
	switch (status) {
		case 'ready':
			return __('Ready', 'prc-email-builder');
		case 'queued':
			return __('Queued', 'prc-email-builder');
		case 'scanning':
			return __('Building', 'prc-email-builder');
		default: {
			const exhaustive: never = status;
			return exhaustive;
		}
	}
}

export function getAudienceFields() {
	return [
		{
			id: 'recordType',
			label: __('Type', 'prc-email-builder'),
			readOnly: true,
			enableSorting: false,
			elements: RECORD_TYPES.map((recordType) => ({
				value: recordType,
				label: getTypeLabel(recordType),
			})),
			filterBy: {
				operators: ['isAny'],
				isPrimary: true,
			},
			getValue: ({ item }: FieldArgs) => item.recordType,
			render: ({ item }: FieldArgs) => (
				<span>{getTypeLabel(item.recordType)}</span>
			),
		},
		{
			id: 'status',
			label: __('Status', 'prc-email-builder'),
			readOnly: true,
			enableSorting: false,
			filterBy: false,
			getValue: ({ item }: FieldArgs) => item.status,
			render: ({ item }: FieldArgs) => {
				const label = getStatusLabel(item);
				if (item.recordType !== 'job') {
					return <span>{label}</span>;
				}
				const progress =
					item.scannedUsers !== null
						? ` — ${item.scannedUsers.toLocaleString()} ${__(
								'scanned',
								'prc-email-builder'
							)}`
						: '';
				return (
					<span>
						{label}
						{progress}
					</span>
				);
			},
		},
		{
			id: 'count',
			type: 'integer',
			label: __('Recipients', 'prc-email-builder'),
			readOnly: true,
			enableSorting: true,
			filterBy: false,
			getValue: ({ item }: FieldArgs) => item.count ?? 0,
			render: ({ item }: FieldArgs) => {
				const count =
					item.count === null ? '—' : item.count.toLocaleString();
				if (!isNewsletterList(item) || !item.countSyncedAt) {
					return <span>{count}</span>;
				}
				return (
					<span
						title={`${__('Synced', 'prc-email-builder')} ${formatDate(
							item.countSyncedAt
						)}`}
					>
						{count}
					</span>
				);
			},
		},
		{
			id: 'builder',
			label: __('Built from', 'prc-email-builder'),
			readOnly: true,
			enableSorting: false,
			elements: [
				{
					value: MAILCHIMP_BUILDER,
					label: __('Mailchimp', 'prc-email-builder'),
				},
				...getBuilderFilterElements(
					getEmailConfig()?.audienceBuilders ?? [],
					__('Other', 'prc-email-builder')
				),
			],
			filterBy: {
				operators: ['isAny'],
			},
			getValue: ({ item }: FieldArgs) => item.builder ?? OTHER_BUILDER,
			render: ({ item }: FieldArgs) => (
				<span>
					{isNewsletterList(item)
						? getMailchimpTargetLabel(item)
						: item.builderLabel}
				</span>
			),
		},
		{
			id: 'sentCount',
			type: 'integer',
			label: __('Sent', 'prc-email-builder'),
			readOnly: true,
			enableSorting: false,
			filterBy: false,
			getValue: ({ item }: FieldArgs) => item.analytics.sentCount,
			render: ({ item }: FieldArgs) => (
				<span>
					{item.recordType === 'job'
						? '—'
						: item.analytics.sentCount.toLocaleString()}
				</span>
			),
		},
		{
			id: 'avgOpenRate',
			label: __('Avg. open', 'prc-email-builder'),
			readOnly: true,
			enableSorting: false,
			filterBy: false,
			getValue: ({ item }: FieldArgs) => item.analytics.avgOpenRate ?? '',
			render: ({ item }: FieldArgs) => (
				<span>{formatRate(item.analytics.avgOpenRate)}</span>
			),
		},
		{
			id: 'avgClickRate',
			label: __('Avg. click', 'prc-email-builder'),
			readOnly: true,
			enableSorting: false,
			filterBy: false,
			getValue: ({ item }: FieldArgs) =>
				item.analytics.avgClickRate ?? '',
			render: ({ item }: FieldArgs) => (
				<span>{formatRate(item.analytics.avgClickRate)}</span>
			),
		},
		{
			id: 'lastSentAt',
			type: 'datetime',
			label: __('Last sent', 'prc-email-builder'),
			readOnly: true,
			enableSorting: false,
			filterBy: false,
			getValue: ({ item }: FieldArgs) => item.analytics.lastSentAt ?? '',
			render: ({ item }: FieldArgs) => (
				<span>{formatDate(item.analytics.lastSentAt)}</span>
			),
		},
		{
			id: 'sourceTitle',
			label: __('Source', 'prc-email-builder'),
			readOnly: true,
			enableSorting: false,
			filterBy: false,
			getValue: ({ item }: FieldArgs) => item.sourceTitle ?? '',
			render: ({ item }: FieldArgs) => (
				<span>{item.sourceTitle || '—'}</span>
			),
		},
		{
			id: 'verification',
			label: __('Verification', 'prc-email-builder'),
			readOnly: true,
			enableSorting: false,
			filterBy: false,
			getValue: ({ item }: FieldArgs) => item.verification ?? '',
			render: ({ item }: FieldArgs) => (
				<span>
					{(item.verification &&
						VERIFICATION_LABELS[item.verification]) ||
						'—'}
				</span>
			),
		},
		{
			id: 'builtAt',
			type: 'datetime',
			label: __('Built', 'prc-email-builder'),
			readOnly: true,
			enableSorting: true,
			filterBy: false,
			getValue: ({ item }: FieldArgs) => item.builtAt ?? '',
			render: ({ item }: FieldArgs) => (
				<span>{formatDate(item.builtAt)}</span>
			),
		},
		{
			id: 'referenceCount',
			type: 'integer',
			label: __('Used by emails', 'prc-email-builder'),
			readOnly: true,
			enableSorting: false,
			filterBy: false,
			getValue: ({ item }: FieldArgs) => item.referenceCount,
			render: ({ item }: FieldArgs) => (
				<span>
					{isStoredAudience(item)
						? item.referenceCount.toLocaleString()
						: '—'}
				</span>
			),
		},
		{
			id: 'id',
			type: 'text',
			label: __('Key', 'prc-email-builder'),
			readOnly: true,
			enableSorting: false,
			filterBy: false,
			getValue: ({ item }: FieldArgs) => item.id,
		},
	];
}

async function createTransactionalFromAudience(item: AudienceListRow) {
	try {
		const result = await apiFetch<{ edit_url?: string }>({
			path: '/prc-email-builder/v1/transactional/create-from-audience',
			method: 'POST',
			data: { audience_key: item.id },
		});
		if (result?.edit_url) {
			window.location.assign(result.edit_url);
			return;
		}
		dispatch(noticesStore).createErrorNotice(
			__(
				'Draft created but no editor URL was returned.',
				'prc-email-builder'
			),
			{ type: 'snackbar' }
		);
	} catch (reason) {
		dispatch(noticesStore).createErrorNotice(
			getAudienceErrorMessage(reason),
			{
				type: 'snackbar',
			}
		);
	}
}

export async function createCampaignFromList(
	termId: number,
	campaignPattern: string
) {
	try {
		window.location.assign(
			await createCampaignForList(termId, campaignPattern)
		);
	} catch (reason) {
		dispatch(noticesStore).createErrorNotice(
			getAudienceErrorMessage(reason),
			{ type: 'snackbar' }
		);
	}
}

export function getAudienceActions(onRefresh?: () => void) {
	return [
		{
			id: 'create-campaign',
			label: __('Create campaign', 'prc-email-builder'),
			isPrimary: false,
			isEligible: (item: AudienceListRow) => isNewsletterList(item),
			callback: ([item]: AudienceListRow[]) => {
				if (isNewsletterList(item)) {
					void createCampaignFromList(
						item.termId,
						item.campaignPattern
					);
				}
			},
		},
		{
			id: 'view-newsletter-list',
			label: __('View', 'prc-email-builder'),
			icon: external,
			isEligible: (item: AudienceListRow) =>
				isNewsletterList(item) && !!item.viewUrl,
			callback: ([item]: AudienceListRow[]) => {
				if (isNewsletterList(item) && item.viewUrl) {
					window.open(item.viewUrl, '_blank', 'noopener');
				}
			},
		},
		{
			id: 'edit-newsletter-list',
			label: __('Edit', 'prc-email-builder'),
			icon: edit,
			modalHeader: __('Edit newsletter list', 'prc-email-builder'),
			modalSize: 'medium' as const,
			isEligible: (item: AudienceListRow) =>
				isNewsletterList(item) &&
				getEmailConfig()?.canManageLists === true,
			RenderModal: (props: ActionModalProps) => (
				<EditNewsletterListModal {...props} onRefresh={onRefresh} />
			),
		},
		{
			id: 'create-transactional-email',
			label: __('Create transactional email', 'prc-email-builder'),
			isPrimary: false,
			isEligible: (item: AudienceListRow) => isStoredAudience(item),
			callback: ([item]: AudienceListRow[]) =>
				createTransactionalFromAudience(item),
		},
		{
			id: 'edit-audience',
			label: __('Edit', 'prc-email-builder'),
			icon: edit,
			modalHeader: __('Edit audience', 'prc-email-builder'),
			isEligible: (item: AudienceListRow) => isStoredAudience(item),
			RenderModal: (props: ActionModalProps) => (
				<EditAudienceModal {...props} onRefresh={onRefresh} />
			),
		},
		{
			id: 'delete-audience',
			label: __('Delete permanently', 'prc-email-builder'),
			icon: trash,
			modalHeader: __('Delete audience', 'prc-email-builder'),
			isDestructive: true,
			isEligible: (item: AudienceListRow) => isStoredAudience(item),
			RenderModal: (props: ActionModalProps) => (
				<DeleteAudienceModal {...props} onRefresh={onRefresh} />
			),
		},
	];
}
