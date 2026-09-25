/**
 * Transactional email settings for the Transactional Setup plugin sidebar.
 *
 * Hosts transactional type, mandrill recipient list, and dynamic system email
 * slug — moved out of the primary document sidebar so producers configure
 * delivery in one place alongside send actions.
 */

import { __ } from '@wordpress/i18n';
import {
	RadioControl,
	SelectControl,
	TextControl,
	Notice,
	Spinner,
	Button,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { external, update } from '@wordpress/icons';

import {
	config,
	useNewsletterMeta,
	useSystemAudiences,
} from './use-newsletter-data';

export function TransactionalSettings() {
	const {
		deliveryMode,
		audienceOptionKey,
		slug,
		setDeliveryMode,
		setAudienceOptionKey,
		setSlug,
	} = useNewsletterMeta();

	const {
		audiences: systemAudiences,
		loading: systemAudiencesLoading,
		isRefreshing: systemAudiencesRefreshing,
		error: systemAudiencesError,
		refresh: refreshSystemAudiences,
	} = useSystemAudiences();

	const selectedAudienceIsMissing =
		Boolean(audienceOptionKey) &&
		!systemAudiences.some((audience) => audience.key === audienceOptionKey);
	const systemAudienceOptions = [
		{
			value: '',
			label: __('— Select audience —', 'prc-email-builder'),
		},
		...(selectedAudienceIsMissing
			? [
					{
						value: audienceOptionKey,
						label: `${__(
							'Unavailable audience',
							'prc-email-builder'
						)} — ${audienceOptionKey}`,
					},
				]
			: []),
		...systemAudiences.map((a) => ({
			value: a.key,
			label: `${a.label} — ${a.count.toLocaleString()} recipients${
				a.built_at ? ` (built ${a.built_at.substring(0, 10)})` : ''
			}`,
		})),
	];

	return (
		<>
			<RadioControl
				label={__('Transactional type', 'prc-email-builder')}
				selected={deliveryMode}
				options={[
					{
						value: 'mandrill',
						label: __(
							'Bulk (fixed recipient list)',
							'prc-email-builder'
						),
					},
					{
						value: 'dynamic',
						label: __(
							'Dynamic (per-recipient)',
							'prc-email-builder'
						),
					},
				]}
				onChange={setDeliveryMode}
			/>

			{deliveryMode === 'mandrill' && (
				<>
					{systemAudiencesError && (
						<Notice status="error" isDismissible={false}>
							{systemAudiencesError}
						</Notice>
					)}
					{systemAudiencesLoading ? (
						<Spinner />
					) : (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={__('Recipient list', 'prc-email-builder')}
							value={audienceOptionKey}
							options={systemAudienceOptions}
							onChange={setAudienceOptionKey}
							help={__(
								'Pick a list built from Emails → Audiences, or from a quiz or dataset inspector. Use a fresh list before sending.',
								'prc-email-builder'
							)}
						/>
					)}
					{!systemAudiencesLoading && selectedAudienceIsMissing ? (
						<Notice status="warning" isDismissible={false}>
							{__(
								'The selected audience no longer exists. Choose another audience before you send this email.',
								'prc-email-builder'
							)}
						</Notice>
					) : null}
					<VStack spacing={2} alignment="flex-start">
						{config.audiencesPageUrl ? (
							<Button
								__next40pxDefaultSize
								variant="secondary"
								icon={external}
								href={config.audiencesPageUrl}
								target="_blank"
								rel="noopener noreferrer"
							>
								{__('Manage audiences', 'prc-email-builder')}
							</Button>
						) : null}
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							icon={update}
							onClick={() => void refreshSystemAudiences()}
							isBusy={systemAudiencesRefreshing}
							disabled={systemAudiencesRefreshing}
						>
							{__('Refresh lists', 'prc-email-builder')}
						</Button>
					</VStack>
				</>
			)}

			{deliveryMode === 'dynamic' && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={__('System email slug', 'prc-email-builder')}
						value={slug}
						onChange={setSlug}
						help={__(
							'Unique slug other plugins/forms use to look up and send this newsletter (e.g. "typology-loyal-liberals").',
							'prc-email-builder'
						)}
					/>
					<Notice status="info" isDismissible={false}>
						{__(
							'Publishing makes this newsletter available as a reusable template. It is sent on demand to a single recipient — no recipient list or campaign is created. Use block bits for per-recipient merge fields.',
							'prc-email-builder'
						)}
					</Notice>
				</>
			)}
		</>
	);
}
