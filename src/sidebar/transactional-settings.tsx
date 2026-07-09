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
} from '@wordpress/components';

import { useNewsletterMeta, useSystemAudiences } from './use-newsletter-data';

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
		error: systemAudiencesError,
	} = useSystemAudiences();

	const systemAudienceOptions = [
		{
			value: '',
			label: __('— Select audience —', 'prc-email-builder'),
		},
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
							__nextHasNoMarginBottom
							label={__('Recipient list', 'prc-email-builder')}
							value={audienceOptionKey}
							options={systemAudienceOptions}
							onChange={setAudienceOptionKey}
							help={__(
								'Built via `wp prc datasets build-audience`. Use a fresh list before sending.',
								'prc-email-builder'
							)}
						/>
					)}
				</>
			)}

			{deliveryMode === 'dynamic' && (
				<>
					<TextControl
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
