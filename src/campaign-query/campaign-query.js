/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockVariation } from '@wordpress/blocks';
import { postList } from '@wordpress/icons';

const NAMESPACE = 'prc-email-builder/campaign-query';

/**
 * Register the Newsletter Campaigns core/query variation.
 *
 * Authors pick a newsletter list and how many recent campaigns to show.
 * The variation locks post type and order so listings stay on published
 * email campaigns, newest first.
 */
export default function registerCampaignQueryVariation() {
	registerBlockVariation('core/query', {
		name: NAMESPACE,
		title: __('Newsletter Campaigns', 'prc-email-builder'),
		description: __(
			'List recent published email campaigns. Filter by newsletter list and choose how many to show.',
			'prc-email-builder'
		),
		icon: postList,
		attributes: {
			namespace: NAMESPACE,
			query: {
				perPage: 5,
				pages: 0,
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
		allowedControls: ['taxQuery', 'postCount'],
		isActive: ['namespace'],
		scope: ['inserter'],
		innerBlocks: [
			[
				'core/post-template',
				{},
				[
					['core/post-title', { isLink: true }],
					['core/post-excerpt', {}],
				],
			],
		],
	});
}
