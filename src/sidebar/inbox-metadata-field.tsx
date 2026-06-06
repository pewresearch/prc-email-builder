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

interface InboxMetadataFieldProps {
	label: string;
	value: string;
	onChange: (value: string) => void;
	placeholder: string;
	help?: string;
	aiControl?: ReactNode;
}

export function InboxMetadataField({
	label,
	value,
	onChange,
	placeholder,
	help,
	aiControl,
}: InboxMetadataFieldProps) {
	return (
		<BaseControl __nextHasNoMarginBottom label={label} help={help}>
			<Flex align="center" gap={2}>
				<FlexBlock>
					<TextControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={label}
						value={value}
						onChange={onChange}
						placeholder={placeholder}
					/>
				</FlexBlock>
				{aiControl && <FlexItem>{aiControl}</FlexItem>}
			</Flex>
		</BaseControl>
	);
}
