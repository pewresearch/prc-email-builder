/**
 * Inbox metadata text field with label/help above and input + optional AI control on one row.
 */

import type { ReactNode } from 'react';
import {
	BaseControl,
	TextControl,
	Flex,
	FlexBlock,
	FlexItem,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { CharacterCounter } from '@prc/components';

interface InboxMetadataFieldProps {
	label: string;
	value: string;
	onChange: (value: string) => void;
	placeholder: string;
	help?: string;
	limit?: number;
	aiControl?: ReactNode;
}

export function InboxMetadataField({
	label,
	value,
	onChange,
	placeholder,
	help,
	limit,
	aiControl,
}: InboxMetadataFieldProps) {
	const fieldId = useInstanceId(
		InboxMetadataField,
		'prc-email-inbox-metadata'
	);

	function handleChange(next: string) {
		if (typeof limit === 'number') {
			onChange(next.slice(0, limit));
			return;
		}

		onChange(next);
	}

	let composedHelp: ReactNode = help;
	if (typeof limit === 'number') {
		composedHelp = (
			<>
				<CharacterCounter current={value.length} limit={limit} />
				{help ? (
					<>
						<br />
						{help}
					</>
				) : null}
			</>
		);
	}

	return (
		<BaseControl
			__nextHasNoMarginBottom
			id={fieldId}
			label={label}
			help={composedHelp}
		>
			<Flex align="center" gap={2}>
				<FlexBlock>
					<TextControl
						__nextHasNoMarginBottom
						id={fieldId}
						value={value}
						onChange={handleChange}
						placeholder={placeholder}
						aria-describedby={
							composedHelp ? `${fieldId}__help` : undefined
						}
					/>
				</FlexBlock>
				{aiControl && <FlexItem>{aiControl}</FlexItem>}
			</Flex>
		</BaseControl>
	);
}
