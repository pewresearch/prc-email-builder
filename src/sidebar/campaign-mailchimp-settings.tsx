/**
 * Campaign sidebar: newsletter list picker, Mailchimp audience/segment, template.
 */

import { __ } from '@wordpress/i18n';
import { SelectControl, Notice, Spinner } from '@wordpress/components';

import {
	useNewsletterLists,
	useAudiences,
	useSegments,
} from './use-newsletter-data';

interface RegisteredTemplate {
	slug: string;
	label: string;
}

interface TemplateSelectorProps {
	templateSlug: string;
	setTemplateSlug: (value: string) => void;
}

function TemplateSelector({
	templateSlug,
	setTemplateSlug,
}: TemplateSelectorProps) {
	const registeredTemplates: RegisteredTemplate[] =
		(
			window as {
				prcEmailBuilderConfig?: { templates?: RegisteredTemplate[] };
			}
		).prcEmailBuilderConfig?.templates ?? [];

	const options = [
		{
			value: '',
			label: __(
				'Auto (matched by audience + segment)',
				'prc-email-builder'
			),
		},
		...registeredTemplates.map((t) => ({
			value: t.slug,
			label: t.label,
		})),
	];

	return (
		<SelectControl
			__nextHasNoMarginBottom
			label={__('Email template', 'prc-email-builder')}
			value={templateSlug}
			options={options}
			onChange={setTemplateSlug}
			help={__(
				'Wraps the newsletter with a header and footer. "Auto" uses the template whose audience/segment matches.',
				'prc-email-builder'
			)}
		/>
	);
}

interface SegmentPickerProps {
	audienceId: string;
	segmentId: string;
	setSegmentId: (value: string) => void;
	disabled?: boolean;
}

function SegmentPicker({
	audienceId,
	segmentId,
	setSegmentId,
	disabled = false,
}: SegmentPickerProps) {
	const { segments, loading, error } = useSegments(audienceId);

	if (error) {
		return (
			<Notice status="warning" isDismissible={false}>
				{error}
			</Notice>
		);
	}

	if (loading) {
		return <Spinner />;
	}

	const options = [
		{
			value: '',
			label: __('Entire audience', 'prc-email-builder'),
		},
		...segments.map((s) => ({
			value: String(s.id),
			label: `${s.name} (${s.member_count.toLocaleString()})`,
		})),
	];

	return (
		<SelectControl
			__nextHasNoMarginBottom
			label={__('Segment (optional)', 'prc-email-builder')}
			value={segmentId}
			options={options}
			onChange={setSegmentId}
			disabled={disabled}
			help={
				disabled
					? __('Locked by newsletter list.', 'prc-email-builder')
					: __(
							'Restrict delivery to a saved segment of the audience.',
							'prc-email-builder'
						)
			}
		/>
	);
}

export interface CampaignMailchimpSettingsProps {
	audienceId: string;
	segmentId: string;
	templateSlug: string;
	selectedListTermId: number;
	hasListTerm: boolean;
	setAudienceId: (value: string) => void;
	setSegmentId: (value: string) => void;
	setTemplateSlug: (value: string) => void;
	selectNewsletterList: (value: string) => void;
}

export function CampaignMailchimpSettings({
	audienceId,
	segmentId,
	templateSlug,
	selectedListTermId,
	hasListTerm,
	setAudienceId,
	setSegmentId,
	setTemplateSlug,
	selectNewsletterList,
}: CampaignMailchimpSettingsProps) {
	const { lists: newsletterLists, loading: newsletterListsLoading } =
		useNewsletterLists();
	const {
		audiences,
		loading: audiencesLoading,
		error: audiencesError,
	} = useAudiences();

	const audienceOptions = [
		{
			value: '',
			label: __('— Select audience —', 'prc-email-builder'),
		},
		...audiences.map((a) => ({ value: a.id, label: a.name })),
	];

	const newsletterListOptions = [
		{
			value: '',
			label: __('— None —', 'prc-email-builder'),
		},
		...newsletterLists.map((list) => ({
			value: String(list.id),
			label: list.name,
		})),
	];

	return (
		<>
			{newsletterListsLoading ? (
				<Spinner />
			) : (
				<SelectControl
					__nextHasNoMarginBottom
					label={__('Newsletter list', 'prc-email-builder')}
					value={selectedListTermId ? String(selectedListTermId) : ''}
					options={newsletterListOptions}
					onChange={selectNewsletterList}
					help={__(
						'Optional. When set, audience and segment are locked to the list configuration. Manage lists under Emails → Newsletter Lists.',
						'prc-email-builder'
					)}
				/>
			)}
			{audiencesError && (
				<Notice status="error" isDismissible={false}>
					{audiencesError}
				</Notice>
			)}
			{audiencesLoading ? (
				<Spinner />
			) : (
				<SelectControl
					__nextHasNoMarginBottom
					label={__('Mailchimp audience', 'prc-email-builder')}
					value={audienceId}
					options={audienceOptions}
					onChange={setAudienceId}
					disabled={hasListTerm}
					help={
						hasListTerm
							? __(
									'Locked by newsletter list.',
									'prc-email-builder'
								)
							: undefined
					}
				/>
			)}
			{audienceId && (
				<SegmentPicker
					audienceId={audienceId}
					segmentId={segmentId}
					setSegmentId={setSegmentId}
					disabled={hasListTerm}
				/>
			)}
			<TemplateSelector
				templateSlug={templateSlug}
				setTemplateSlug={setTemplateSlug}
			/>
		</>
	);
}
