import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import {
	isDomainNeedleValid,
	type StartAudienceInput,
} from './auth-domain-audience-types';

interface DomainQueryFormProps {
	readonly step: number;
	readonly input: StartAudienceInput;
	readonly onChange: (input: StartAudienceInput) => void;
}

export function DomainQueryForm({
	step,
	input,
	onChange,
}: DomainQueryFormProps) {
	if (step === 0) {
		return (
			<TextControl
				__next40pxDefaultSize
				label={__('Domain contains', 'prc-email-builder')}
				help={__(
					'Enter part of the email domain, such as k12. Do not include @.',
					'prc-email-builder'
				)}
				value={input.domainContains}
				onChange={(domainContains) =>
					onChange({ ...input, domainContains })
				}
				__nextHasNoMarginBottom
			/>
		);
	}

	if (step === 1) {
		return (
			<SelectControl
				__next40pxDefaultSize
				label={__('Email verification', 'prc-email-builder')}
				value={input.verification}
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
				onChange={(verification) => {
					if (
						verification === 'verified' ||
						verification === 'unverified' ||
						verification === 'all'
					) {
						onChange({ ...input, verification });
					}
				}}
				__nextHasNoMarginBottom
			/>
		);
	}

	return (
		<TextControl
			__next40pxDefaultSize
			label={__('Audience name', 'prc-email-builder')}
			help={__(
				'This name appears in the recipient list picker.',
				'prc-email-builder'
			)}
			value={input.label}
			onChange={(label) => onChange({ ...input, label })}
			__nextHasNoMarginBottom
		/>
	);
}

export function canAdvanceDomainStep(
	step: number,
	input: StartAudienceInput
): boolean {
	if (step === 0) {
		return isDomainNeedleValid(input.domainContains);
	}
	return true;
}
