/**
 * Campaign sidebar controls, split across two surfaces:
 *  - CampaignListControl:     newsletter list picker (primary document sidebar).
 *  - CampaignAdvancedSettings: Mailchimp audience/segment + template overrides
 *                              (Campaign Setup plugin sidebar).
 */

import { __, sprintf } from '@wordpress/i18n';
import { SelectControl, Notice, Spinner } from '@wordpress/components';

import {
	useNewsletterMeta,
	useNewsletterLists,
	useAudiences,
	useSegments,
	config,
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

/**
 * Newsletter list picker for the primary document sidebar. Selecting a list
 * locks the Mailchimp audience/segment to the list configuration (enforced
 * server-side and reflected as read-only fields in Campaign Setup).
 */
export function CampaignListControl() {
	const { selectedListTermId, selectNewsletterList } = useNewsletterMeta();
	const { lists: newsletterLists, loading: newsletterListsLoading } =
		useNewsletterLists();

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

	const selectedList = newsletterLists.find(
		(list) => list.id === selectedListTermId
	);
	const globalFromName = config.defaults?.from_name ?? '';
	const globalFromEmail = config.defaults?.from_email ?? '';
	const effectiveFromName =
		selectedList?.meta?.prc_newsletter_list_from_name || globalFromName;
	const effectiveFromEmail =
		selectedList?.meta?.prc_newsletter_list_from_email || globalFromEmail;

	if (newsletterListsLoading) {
		return <Spinner />;
	}

	return (
		<>
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
			{(effectiveFromName || effectiveFromEmail) && (
				<p className="description">
					{sprintf(
						/* translators: 1: sender name, 2: sender email */
						__('Sending as: %1$s <%2$s>', 'prc-email-builder'),
						effectiveFromName || '—',
						effectiveFromEmail || '—'
					)}
				</p>
			)}
		</>
	);
}

/**
 * Advanced Mailchimp overrides (audience, segment, template) for the Campaign
 * Setup plugin sidebar. Audience/segment are read-only when a newsletter list
 * is assigned; producers are steered toward the list control instead.
 */
export function CampaignAdvancedSettings() {
	const {
		audienceId,
		segmentId,
		templateSlug,
		hasListTerm,
		setAudienceId,
		setSegmentId,
		setTemplateSlug,
	} = useNewsletterMeta();
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

	return (
		<>
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
