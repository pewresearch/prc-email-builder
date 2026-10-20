/**
 * Staff picker for a newsletter list's enforced byline. Stores a `bylines`
 * term ID and shows the staff member's name.
 */

import { WPEntitySearch } from '@prc/components';
import apiFetch from '@wordpress/api-fetch';
import { Button } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';

export const BYLINE_TAXONOMY = 'bylines';

interface EntitySearchItem {
	entityId: number;
	entityName: string;
}

interface BylineTerm {
	name?: string;
}

interface StaffBylinePickerProps {
	readonly value: string;
	readonly onChange: (termId: string) => void;
	readonly disabled?: boolean;
	readonly taxonomy?: string;
}

function useBylineName(termId: string, taxonomy: string): string {
	const [name, setName] = useState('');

	useEffect(() => {
		setName('');
		if (!termId) {
			return undefined;
		}
		let cancelled = false;
		apiFetch<BylineTerm>({
			path: `/wp/v2/${taxonomy}/${encodeURIComponent(termId)}?_fields=name`,
		})
			.then((term) => {
				if (!cancelled) {
					setName(decodeEntities(term.name ?? ''));
				}
			})
			.catch(() => {
				if (!cancelled) {
					setName('');
				}
			});
		return () => {
			cancelled = true;
		};
	}, [termId, taxonomy]);

	return name;
}

export function StaffBylinePicker({
	value,
	onChange,
	disabled = false,
	taxonomy = BYLINE_TAXONOMY,
}: StaffBylinePickerProps) {
	const name = useBylineName(value, taxonomy);

	const handleSelect = useCallback(
		(item: EntitySearchItem) => {
			const nextId = String(item.entityId ?? '');
			if (nextId && nextId !== '0') {
				onChange(nextId);
			}
		},
		[onChange]
	);

	const selectedLabel =
		name ||
		sprintf(
			/* translators: %s: bylines term ID */
			__('Byline #%s', 'prc-email-builder'),
			value
		);

	return (
		<div className="prc-newsletter-list-enforced-byline">
			{value ? (
				<p className="prc-newsletter-list-enforced-byline__selected">
					<strong>
						{__('Selected staff:', 'prc-email-builder')}
					</strong>{' '}
					{selectedLabel}
				</p>
			) : (
				<p className="description">
					{__('No staff byline', 'prc-email-builder')}
				</p>
			)}

			{disabled ? null : (
				<WPEntitySearch
					placeholder={__('Search staff…', 'prc-email-builder')}
					entityType="taxonomy"
					entitySubType={taxonomy}
					entityId={value ? Number(value) : undefined}
					onSelect={handleSelect}
					clearOnSelect
					showExcerpt={false}
					showType={false}
					showUrl={false}
					searchSize="large"
				/>
			)}

			{value ? (
				<div className="prc-newsletter-list-enforced-byline__actions">
					<Button
						variant="tertiary"
						isDestructive
						__next40pxDefaultSize
						disabled={disabled}
						onClick={() => onChange('')}
					>
						{__('Remove staff byline', 'prc-email-builder')}
					</Button>
				</div>
			) : null}
		</div>
	);
}
