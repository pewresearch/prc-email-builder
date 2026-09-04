import {
	ComboboxControl,
	SelectControl,
	TextControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

import type { AudienceBuilder } from './audience-catalog';
import type { VerificationMode } from './auth-domain-audience-types';

interface SourceEntityFormProps {
	readonly builder: AudienceBuilder;
	readonly sourceId: number;
	readonly sourceName: string;
	readonly verification: VerificationMode;
	readonly label: string;
	readonly onChange: (next: {
		sourceId: number;
		sourceName: string;
		verification: VerificationMode;
		label: string;
	}) => void;
}

interface EntityRecord {
	readonly id: number;
	readonly title?: { readonly rendered?: string };
}

export function SourceEntityForm({
	builder,
	sourceId,
	sourceName,
	verification,
	label,
	onChange,
}: SourceEntityFormProps) {
	const [search, setSearch] = useState('');
	const postType = builder.sourcePostType || '';
	const records = useSelect(
		(select) => {
			if (!postType) {
				return [] as EntityRecord[];
			}
			return (
				(
					select(coreStore) as {
						getEntityRecords: (
							kind: string,
							name: string,
							query: Record<string, unknown>
						) => EntityRecord[] | null;
					}
				).getEntityRecords('postType', postType, {
					search: search || undefined,
					per_page: 20,
					status: 'publish,draft,private',
					_fields: 'id,title',
				}) || []
			);
		},
		[postType, search]
	);

	const options = records.map((record) => ({
		value: String(record.id),
		label: decodeEntities(record.title?.rendered || '') || `#${record.id}`,
	}));
	if (
		sourceId > 0 &&
		!options.some((option) => option.value === String(sourceId))
	) {
		options.unshift({
			value: String(sourceId),
			label: sourceName || `#${sourceId}`,
		});
	}

	return (
		<VStack spacing={4}>
			<ComboboxControl
				__next40pxDefaultSize
				label={builder.label}
				help={__(
					'Search for the source post. Both published and draft items appear.',
					'prc-email-builder'
				)}
				value={sourceId > 0 ? String(sourceId) : null}
				options={options}
				onFilterValueChange={setSearch}
				onChange={(value) => {
					const nextId = value ? Number(value) : 0;
					const selected = options.find(
						(option) => option.value === String(nextId)
					);
					onChange({
						sourceId: nextId,
						sourceName: selected?.label || '',
						verification,
						label,
					});
				}}
				__nextHasNoMarginBottom
			/>
			<SelectControl
				__next40pxDefaultSize
				label={__('Email verification', 'prc-email-builder')}
				value={verification}
				options={[
					{
						label: __('Verified only', 'prc-email-builder'),
						value: 'verified',
					},
					{
						label: __('Unverified only', 'prc-email-builder'),
						value: 'unverified',
					},
					{
						label: __('All users', 'prc-email-builder'),
						value: 'all',
					},
				]}
				onChange={(nextVerification) => {
					if (
						nextVerification === 'verified' ||
						nextVerification === 'unverified' ||
						nextVerification === 'all'
					) {
						onChange({
							sourceId,
							sourceName,
							verification: nextVerification,
							label,
						});
					}
				}}
				__nextHasNoMarginBottom
			/>
			<TextControl
				__next40pxDefaultSize
				label={__('Audience name (optional)', 'prc-email-builder')}
				help={__(
					'Leave blank to use the source title. This name appears in the recipient list picker.',
					'prc-email-builder'
				)}
				value={label}
				onChange={(nextLabel) =>
					onChange({
						sourceId,
						sourceName,
						verification,
						label: nextLabel,
					})
				}
				__nextHasNoMarginBottom
			/>
		</VStack>
	);
}

export function canStartSourceEntity(sourceId: number): boolean {
	return sourceId > 0;
}
