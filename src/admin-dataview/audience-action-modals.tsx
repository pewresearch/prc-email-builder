import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Flex,
	Notice,
	Spinner,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import type { Field } from '@wordpress/dataviews';
import { DataForm } from '@wordpress/dataviews/wp';
import { useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import type { ReactNode } from 'react';

import type {
	AudienceListRow,
	NewsletterListRow,
	StoredAudienceRow,
} from './audience-catalog';
import { getAudienceErrorMessage } from './audience-builder-views';
import { NewsletterListForm } from './newsletter-list-form';
import {
	canCreateNewsletterList,
	fromNewsletterListTerm,
	toNewsletterListPayload,
	type NewsletterListInput,
	type NewsletterListTerm,
} from './newsletter-list-input';
import { getEmailConfig } from '../library/types';

export type ActionModalProps = {
	readonly items: AudienceListRow[];
	readonly closeModal?: () => void;
	readonly onActionPerformed?: (items: AudienceListRow[]) => void;
};

type AudienceEdits = { label: string };

const AUDIENCE_FIELDS: Field<AudienceEdits>[] = [
	{
		id: 'label',
		type: 'text',
		label: __('Audience name', 'prc-email-builder'),
		description: __(
			'Only the display name changes. Transactional emails keep their link to this audience.',
			'prc-email-builder'
		),
		isValid: { required: true },
	},
];

const AUDIENCE_FORM = {
	layout: { type: 'regular' as const },
	fields: ['label'],
};

function ModalFooter({
	isBusy,
	canSave,
	onCancel,
	onSave,
	secondary,
}: {
	readonly isBusy: boolean;
	readonly canSave: boolean;
	readonly onCancel?: () => void;
	readonly onSave: () => void;
	readonly secondary?: ReactNode;
}) {
	return (
		<Flex justify={secondary ? 'space-between' : 'flex-end'} gap={2}>
			{secondary}
			<Flex justify="flex-end" gap={2} expanded={false}>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={onCancel}
					disabled={isBusy}
				>
					{__('Cancel', 'prc-email-builder')}
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={onSave}
					isBusy={isBusy}
					disabled={isBusy || !canSave}
				>
					{__('Save', 'prc-email-builder')}
				</Button>
			</Flex>
		</Flex>
	);
}

function ErrorNotice({ error }: { readonly error: string | null }) {
	return error ? (
		<Notice status="error" isDismissible={false}>
			{error}
		</Notice>
	) : null;
}

export function EditAudienceModal({
	items,
	closeModal,
	onActionPerformed,
	onRefresh,
}: ActionModalProps & { readonly onRefresh?: () => void }) {
	const item = items[0] as StoredAudienceRow;
	const [data, setData] = useState<AudienceEdits>({ label: item.title });
	const [isSaving, setIsSaving] = useState(false);
	const [error, setError] = useState<string | null>(null);

	const save = async () => {
		setIsSaving(true);
		setError(null);
		try {
			await apiFetch({
				path: `/prc-email-builder/v1/audiences-system/${encodeURIComponent(item.id)}`,
				method: 'PATCH',
				data: { label: data.label.trim() },
			});
			dispatch(noticesStore).createSuccessNotice(
				__('Audience updated.', 'prc-email-builder'),
				{ type: 'snackbar' }
			);
			onActionPerformed?.(items);
			onRefresh?.();
			closeModal?.();
		} catch (reason) {
			setError(getAudienceErrorMessage(reason));
		} finally {
			setIsSaving(false);
		}
	};

	return (
		<VStack spacing={4}>
			<DataForm<AudienceEdits>
				data={data}
				fields={AUDIENCE_FIELDS}
				form={AUDIENCE_FORM}
				onChange={(edits) =>
					setData((current) => ({ ...current, ...edits }))
				}
			/>
			<ErrorNotice error={error} />
			<ModalFooter
				isBusy={isSaving}
				canSave={data.label.trim() !== ''}
				onCancel={closeModal}
				onSave={() => void save()}
			/>
		</VStack>
	);
}

function getNewsletterListPath(termId: number): string {
	const taxonomy =
		getEmailConfig()?.newsletterListTaxonomy ?? 'prc_newsletter_list';
	return `/wp/v2/${taxonomy}/${termId}`;
}

export function EditNewsletterListModal({
	items,
	closeModal,
	onActionPerformed,
	onRefresh,
}: ActionModalProps & { readonly onRefresh?: () => void }) {
	const item = items[0] as NewsletterListRow;
	const path = getNewsletterListPath(item.termId);
	const [input, setInput] = useState<NewsletterListInput | null>(null);
	const [loadError, setLoadError] = useState<string | null>(null);
	const [isSaving, setIsSaving] = useState(false);
	const [error, setError] = useState<string | null>(null);

	useEffect(() => {
		let cancelled = false;
		apiFetch<NewsletterListTerm>({ path: `${path}?context=edit` })
			.then((term) => {
				if (!cancelled) {
					setInput(fromNewsletterListTerm(term, decodeEntities));
				}
			})
			.catch((reason: unknown) => {
				if (!cancelled) {
					setLoadError(getAudienceErrorMessage(reason));
				}
			});
		return () => {
			cancelled = true;
		};
	}, [path]);

	const save = async () => {
		if (!input) {
			return;
		}
		setIsSaving(true);
		setError(null);
		try {
			await apiFetch({
				path,
				method: 'POST',
				data: toNewsletterListPayload(input),
			});
			dispatch(noticesStore).createSuccessNotice(
				__('Newsletter list updated.', 'prc-email-builder'),
				{ type: 'snackbar' }
			);
			onActionPerformed?.(items);
			onRefresh?.();
			closeModal?.();
		} catch (reason) {
			setError(getAudienceErrorMessage(reason));
		} finally {
			setIsSaving(false);
		}
	};

	const moreSettings = item.editUrl ? (
		<Button
			__next40pxDefaultSize
			variant="link"
			href={item.editUrl}
			disabled={isSaving}
		>
			{__('Accent color and preview settings', 'prc-email-builder')}
		</Button>
	) : null;

	if (!input && !loadError) {
		return (
			<Flex justify="center" className="prc-email-list-edit__loading">
				<Spinner />
			</Flex>
		);
	}

	return (
		<VStack spacing={4}>
			{input ? (
				<NewsletterListForm
					input={input}
					onChange={setInput}
					disabled={isSaving}
				/>
			) : null}
			<ErrorNotice error={loadError ?? error} />
			<ModalFooter
				isBusy={isSaving}
				canSave={
					input !== null &&
					canCreateNewsletterList(input) &&
					getEmailConfig()?.mailchimpConnected !== false
				}
				onCancel={closeModal}
				onSave={() => void save()}
				secondary={moreSettings}
			/>
		</VStack>
	);
}

export function DeleteAudienceModal({
	items,
	closeModal,
	onActionPerformed,
	onRefresh,
}: ActionModalProps & { readonly onRefresh?: () => void }) {
	const item = items[0] as StoredAudienceRow;
	const [isDeleting, setIsDeleting] = useState(false);
	const [error, setError] = useState<string | null>(null);
	const isReferenced = item.referenceCount > 0;

	const remove = async () => {
		setIsDeleting(true);
		setError(null);
		try {
			await apiFetch({
				path: `/prc-email-builder/v1/audiences-system/${encodeURIComponent(item.id)}`,
				method: 'DELETE',
			});
			dispatch(noticesStore).createSuccessNotice(
				__('Audience permanently deleted.', 'prc-email-builder'),
				{ type: 'snackbar' }
			);
			onActionPerformed?.(items);
			onRefresh?.();
			closeModal?.();
		} catch (reason) {
			setError(getAudienceErrorMessage(reason));
		} finally {
			setIsDeleting(false);
		}
	};

	return (
		<div>
			{isReferenced ? (
				<Notice status="warning" isDismissible={false}>
					{item.referenceCount === 1
						? __(
								'One transactional email uses this audience. Remove that reference before you delete the audience.',
								'prc-email-builder'
							)
						: `${item.referenceCount.toLocaleString()} ${__(
								'transactional emails use this audience. Remove those references before you delete the audience.',
								'prc-email-builder'
							)}`}
				</Notice>
			) : (
				<p>
					{__(
						'This permanently removes the recipient addresses and audience metadata. This action cannot be undone.',
						'prc-email-builder'
					)}
				</p>
			)}
			{error ? (
				<Notice status="error" isDismissible={false}>
					{error}
				</Notice>
			) : null}
			<Flex justify="flex-end">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={closeModal}
					disabled={isDeleting}
				>
					{__('Cancel', 'prc-email-builder')}
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isDestructive
					onClick={remove}
					isBusy={isDeleting}
					disabled={isDeleting || isReferenced}
				>
					{__('Delete permanently', 'prc-email-builder')}
				</Button>
			</Flex>
		</div>
	);
}
