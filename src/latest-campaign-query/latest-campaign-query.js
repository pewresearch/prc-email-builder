/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockVariation } from '@wordpress/blocks';
import { mobile } from '@wordpress/icons';

const NAMESPACE = 'prc-email-builder/latest-campaign-preview';

/**
 * Register the Latest Newsletter Preview core/query variation.
 *
 * Authors pick a newsletter list via the native taxQuery control; the
 * variation locks post type, count, and order so the child preview block
 * always receives the most recent published campaign for that list.
 */
export default function registerLatestCampaignQueryVariation() {
	registerBlockVariation('core/query', {
		name: NAMESPACE,
		title: __('Latest Newsletter Preview', 'prc-email-builder'),
		description: __(
			'Show a live phone-width preview of the most recent email for a newsletter list.',
			'prc-email-builder'
		),
		icon: mobile,
		attributes: {
			namespace: NAMESPACE,
			query: {
				perPage: 1,
				pages: 1,
				offset: 0,
				postType: 'prc_email_campaign',
				order: 'desc',
				orderBy: 'date',
				author: '',
				search: '',
				exclude: [],
				sticky: '',
				inherit: false,
				taxQuery: {},
				parents: [],
			},
		},
		allowedControls: ['taxQuery'],
		isActive: ['namespace'],
		scope: ['inserter'],
		innerBlocks: [
			[
				'core/post-template',
				{},
				[['prc-email-builder/campaign-email-preview', {}]],
			],
		],
	});
}
