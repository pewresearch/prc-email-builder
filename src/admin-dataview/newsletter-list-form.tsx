import apiFetch from '@wordpress/api-fetch';
import { Button, Flex, Notice } from '@wordpress/components';
import type { Field } from '@wordpress/dataviews';
import { DataForm } from '@wordpress/dataviews/wp';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

import { getAudienceErrorMessage } from './audience-builder-views';
import {
	isValidOptionalEmail,
	type NewsletterListInput,
} from './newsletter-list-input';
import { useEmailPatterns } from '../sidebar/pattern-selector/use-email-patterns';
import { getEmailConfig } from '../library/types';

interface MailchimpAudience {
	readonly id: string;
	readonly name: string;
}

interface MailchimpSegment {
	readonly id: number;
	readonly name: string;
	readonly member_count: number;
}

const REST_NAMESPACE = '/prc-email-builder/v1';

function useMailchimpAudiences() {
	const [audiences, setAudiences] = useState<MailchimpAudience[]>([]);
	const [isLoading, setIsLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);

	useEffect(() => {
		let cancelled = false;
		apiFetch<MailchimpAudience[]>({ path: `${REST_NAMESPACE}/audiences` })
			.then((data) => {
				if (!cancelled) {
					setAudiences(data);
				}
			})
			.catch((reason: unknown) => {
				if (!cancelled) {
					setError(getAudienceErrorMessage(reason));
				}
			})
			.finally(() => {
				if (!cancelled) {
					setIsLoading(false);
				}
			});
		return () => {
			cancelled = true;
		};
	}, []);

	return { audiences, isLoading, error };
}

function useMailchimpSegments(audienceId: string) {
	const [segments, setSegments] = useState<MailchimpSegment[]>([]);
	const [isLoading, setIsLoading] = useState(false);

	useEffect(() => {
		setSegments([]);
		if (!audienceId) {
			return undefined;
		}
		let cancelled = false;
		setIsLoading(true);
		apiFetch<MailchimpSegment[]>({
			path: `${REST_NAMESPACE}/audiences/${encodeURIComponent(audienceId)}/segments`,
		})
			.then((data) => {
				if (!cancelled) {
					setSegments(data);
				}
			})
			.catch(() => {
				if (!cancelled) {
					setSegments([]);
				}
			})
			.finally(() => {
				if (!cancelled) {
					setIsLoading(false);
				}
			});
		return () => {
			cancelled = true;
		};
	}, [audienceId]);

	return { segments, isLoading };
}

export function NewsletterListCreated({ name }: { readonly name: string }) {
	return (
		<div>
			<p>
				<strong>{name}</strong>
			</p>
			<p>
				{__(
					'The newsletter list was added. Its subscriber count appears on this page after the next Mailchimp sync.',
					'prc-email-builder'
				)}
			</p>
		</div>
	);
}

export function NewsletterListFooter({
	screen,
	canCreate,
	isBusy,
	onBack,
	onCreate,
	onCreateCampaign,
	onClose,
}: {
	readonly screen: 'newsletter-list' | 'newsletter-list-done';
	readonly canCreate: boolean;
	readonly isBusy: boolean;
	readonly onBack: () => void;
	readonly onCreate: () => void;
	readonly onCreateCampaign: () => void;
	readonly onClose: () => void;
}) {
	return (
		<Flex
			className="prc-email-audience-builder__footer"
			justify="flex-end"
			gap={2}
		>
			{screen === 'newsletter-list' ? (
				<>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={onBack}
						disabled={isBusy}
					>
						{__('Back', 'prc-email-builder')}
					</Button>
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={onCreate}
						isBusy={isBusy}
						disabled={isBusy || !canCreate}
					>
						{__('Add list', 'prc-email-builder')}
					</Button>
				</>
			) : (
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={onCreateCampaign}
					isBusy={isBusy}
					disabled={isBusy}
				>
					{__('Create campaign', 'prc-email-builder')}
				</Button>
			)}
			<Button
				__next40pxDefaultSize
				variant="secondary"
				onClick={onClose}
				disabled={isBusy}
			>
				{screen === 'newsletter-list-done'
					? __('Close', 'prc-email-builder')
					: __('Cancel', 'prc-email-builder')}
			</Button>
		</Flex>
	);
}

type SelectOption = { value: string; label: string };

/**
 * Select options with a leading placeholder. A saved value that is no longer
 * offered (deleted audience, segment, or pattern) stays selectable by its ID.
 *
 * @param placeholder Empty-value option.
 * @param options     Loaded options.
 * @param current     Saved value.
 */
function selectOptions(
	placeholder: SelectOption,
	options: SelectOption[],
	current: string
): SelectOption[] {
	const isKnown =
		current === '' || options.some((option) => option.value === current);
	return [
		placeholder,
		...options,
		...(isKnown ? [] : [{ value: current, label: current }]),
	];
}

function getNewsletterListFields({
	input,
	disabled,
	audiences,
	isLoadingAudiences,
	segments,
	isLoadingSegments,
	patterns,
	isLoadingPatterns,
}: {
	readonly input: NewsletterListInput;
	readonly disabled: boolean;
	readonly audiences: MailchimpAudience[];
	readonly isLoadingAudiences: boolean;
	readonly segments: MailchimpSegment[];
	readonly isLoadingSegments: boolean;
	readonly patterns: ReturnType<typeof useEmailPatterns>['patterns'];
	readonly isLoadingPatterns: boolean;
}): Field<NewsletterListInput>[] {
	return [
		{
			id: 'name',
			type: 'text',
			label: __('List name', 'prc-email-builder'),
			isValid: { required: true },
			isDisabled: disabled,
		},
		{
			id: 'description',
			type: 'text',
			label: __('Description (optional)', 'prc-email-builder'),
			// A config object would remount the textarea on every keystroke.
			Edit: 'textarea',
			isDisabled: disabled,
		},
		{
			id: 'audienceId',
			type: 'text',
			label: __('Mailchimp audience', 'prc-email-builder'),
			Edit: 'select',
			isValid: { required: true },
			isDisabled: disabled || isLoadingAudiences,
			elements: selectOptions(
				{
					value: '',
					label: isLoadingAudiences
						? __('Loading audiences…', 'prc-email-builder')
						: __('— Select audience —', 'prc-email-builder'),
				},
				audiences.map((audience) => ({
					value: audience.id,
					label: decodeEntities(audience.name),
				})),
				input.audienceId
			),
			setValue: ({ value }) => ({
				audienceId: String(value ?? ''),
				segmentId: '',
			}),
		},
		{
			id: 'segmentId',
			type: 'text',
			label: __('Segment (optional)', 'prc-email-builder'),
			description: __(
				'Campaigns that use this list are locked to this audience and segment.',
				'prc-email-builder'
			),
			Edit: 'select',
			isDisabled: ({ item }) =>
				disabled || item.audienceId === '' || isLoadingSegments,
			elements: selectOptions(
				{
					value: '',
					label: input.audienceId
						? __('Entire audience', 'prc-email-builder')
						: __('Select an audience first', 'prc-email-builder'),
				},
				segments.map((segment) => ({
					value: String(segment.id),
					label: `${decodeEntities(segment.name)} (${segment.member_count.toLocaleString()})`,
				})),
				isLoadingSegments ? '' : input.segmentId
			),
		},
		{
			id: 'fromName',
			type: 'text',
			label: __('Default From name (optional)', 'prc-email-builder'),
			isDisabled: disabled,
		},
		{
			id: 'fromEmail',
			type: 'email',
			label: __('Default From email (optional)', 'prc-email-builder'),
			description: isValidOptionalEmail(input.fromEmail)
				? undefined
				: __('Enter a valid email address.', 'prc-email-builder'),
			isDisabled: disabled,
		},
		{
			id: 'campaignPattern',
			type: 'text',
			label: __(
				'Default campaign pattern (optional)',
				'prc-email-builder'
			),
			description: __(
				'Create campaign starts drafts from this pattern. Without one, the editor asks for a pattern.',
				'prc-email-builder'
			),
			Edit: 'select',
			isDisabled: disabled || isLoadingPatterns,
			elements: selectOptions(
				{
					value: '',
					label: isLoadingPatterns
						? __('Loading patterns…', 'prc-email-builder')
						: __('No default pattern', 'prc-email-builder'),
				},
				patterns.map((pattern) => ({
					value: pattern.name,
					label: decodeEntities(pattern.title),
				})),
				isLoadingPatterns ? '' : input.campaignPattern
			),
		},
	];
}

const NEWSLETTER_LIST_FORM = {
	layout: { type: 'regular' as const },
	fields: [
		'name',
		'description',
		'audienceId',
		'segmentId',
		'fromName',
		'fromEmail',
		'campaignPattern',
	],
};

/**
 * Newsletter list fields shared by the Add new and Edit modals.
 *
 * @param props          Component props.
 * @param props.input    Current form input.
 * @param props.onChange Receives the next input.
 * @param props.disabled Lock every field while saving.
 */
export function NewsletterListForm({
	input,
	onChange,
	disabled,
}: {
	readonly input: NewsletterListInput;
	readonly onChange: (input: NewsletterListInput) => void;
	readonly disabled: boolean;
}) {
	const config = getEmailConfig();
	const {
		audiences,
		isLoading: isLoadingAudiences,
		error,
	} = useMailchimpAudiences();
	const { segments, isLoading: isLoadingSegments } = useMailchimpSegments(
		input.audienceId
	);
	const { patterns, isLoading: isLoadingPatterns } = useEmailPatterns(
		config?.campaignPatternCategorySlug ?? 'email-campaign',
		true
	);
	const fields = useMemo(
		() =>
			getNewsletterListFields({
				input,
				disabled,
				audiences,
				isLoadingAudiences,
				segments,
				isLoadingSegments,
				patterns,
				isLoadingPatterns,
			}),
		[
			input,
			disabled,
			audiences,
			isLoadingAudiences,
			segments,
			isLoadingSegments,
			patterns,
			isLoadingPatterns,
		]
	);

	if (config?.mailchimpConnected === false) {
		return (
			<Notice status="warning" isDismissible={false}>
				{__(
					'Mailchimp is not connected. Configure the API key in Email Builder Settings before you add or edit a newsletter list.',
					'prc-email-builder'
				)}
			</Notice>
		);
	}

	return (
		<Flex direction="column" gap={4} align="stretch">
			{error ? (
				<Notice status="error" isDismissible={false}>
					{error}
				</Notice>
			) : null}
			<DataForm<NewsletterListInput>
				data={input}
				fields={fields}
				form={NEWSLETTER_LIST_FORM}
				onChange={(edits) => onChange({ ...input, ...edits })}
			/>
		</Flex>
	);
}
