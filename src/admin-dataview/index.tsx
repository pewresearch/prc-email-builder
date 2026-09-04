/**
 * WordPress Dependencies
 */
import { Button, createSlotFill, Flex } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import { composeEmailActions } from './actions';
import { AudienceHubModal } from './audience-hub-modal';
import CampaignStatsModal from '../library/components/campaign-stats-modal';
import CreateCampaignDropdown from '../library/components/create-campaign-dropdown';
import GenerateLinksNewsletterModal from '../library/components/generate-links-newsletter-modal';
import { getDefaultVisibleFields, getFieldsForScope } from '../library/fields';
import {
	getEmailConfig,
	type EmailLibraryRow,
	type EmailListScope,
} from '../library/types';
import '../library/style.scss';

declare const prcEmailBuilderLibraryAI:
	| {
			enabled: boolean;
			linksNewsletterAbilityName: string;
	  }
	| undefined;

const { Fill: PageExtrasFill } = createSlotFill(
	'prcWpAdminDataview.PageExtras'
);
const PAGE_EXTRA_EVENT = 'prcWpAdminDataview.pageExtra';

function getScope(): EmailListScope | null {
	return (
		getEmailConfig()?.postTypeScope ??
		window.prcWpAdminDataview?.config?.postTypeScope ??
		null
	);
}

function emitPageExtra(type: string, payload?: EmailLibraryRow) {
	window.dispatchEvent(
		new CustomEvent(PAGE_EXTRA_EVENT, {
			detail: { type, payload },
		})
	);
}

function EmailPageExtras() {
	const [campaign, setCampaign] = useState<EmailLibraryRow | null>(null);
	const [isGenerateOpen, setIsGenerateOpen] = useState(false);
	const [isAudienceOpen, setIsAudienceOpen] = useState(false);

	useEffect(() => {
		const handlePageExtra = (event: Event) => {
			if (!(event instanceof CustomEvent)) {
				return;
			}
			if (event.detail?.type === 'campaign-stats') {
				setCampaign(event.detail.payload);
			}
			if (event.detail?.type === 'generate-links-newsletter') {
				setIsGenerateOpen(true);
			}
			if (event.detail?.type === 'build-auth-domain-audience') {
				setIsAudienceOpen(true);
			}
		};
		window.addEventListener(PAGE_EXTRA_EVENT, handlePageExtra);
		return () =>
			window.removeEventListener(PAGE_EXTRA_EVENT, handlePageExtra);
	}, []);

	return (
		<>
			<CampaignStatsModal
				campaign={campaign}
				onClose={() => setCampaign(null)}
			/>
			<GenerateLinksNewsletterModal
				isOpen={isGenerateOpen}
				onClose={() => setIsGenerateOpen(false)}
				onDraftCreated={() => window.location.reload()}
			/>
			<AudienceHubModal
				isOpen={isAudienceOpen}
				onClose={() => setIsAudienceOpen(false)}
			/>
		</>
	);
}

function EmailHeaderActions() {
	const scope = getScope();
	if (!scope) {
		return null;
	}

	const config = getEmailConfig();
	const isCampaign = scope === 'campaign';
	const isAIEnabled =
		isCampaign &&
		typeof prcEmailBuilderLibraryAI !== 'undefined' &&
		prcEmailBuilderLibraryAI.enabled;
	const transactionalNewUrl =
		config?.transactionalNewUrl ??
		`post-new.php?post_type=${config?.transactionalPostType ?? 'prc_email_txn'}`;

	return (
		<Flex gap={2} align="center" justify="flex-end">
			{isCampaign ? (
				<CreateCampaignDropdown />
			) : (
				<>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={() =>
							emitPageExtra('build-auth-domain-audience')
						}
					>
						{__('Build audience', 'prc-email-builder')}
					</Button>
					<Button
						__next40pxDefaultSize
						variant="primary"
						href={transactionalNewUrl}
					>
						{__('Create new', 'prc-email-builder')}
					</Button>
				</>
			)}
			{isAIEnabled ? (
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={() => emitPageExtra('generate-links-newsletter')}
				>
					{__('Generate Links Newsletter', 'prc-email-builder')}
				</Button>
			) : null}
		</Flex>
	);
}

function EmailFills() {
	const scope = getScope();
	if (!scope) {
		return null;
	}

	const isCampaign = scope === 'campaign';

	return (
		<>
			<span>
				{isCampaign
					? __(
							'Browse and manage email campaigns.',
							'prc-email-builder'
						)
					: __(
							'Browse and manage transactional emails.',
							'prc-email-builder'
						)}
			</span>
			<PageExtrasFill>
				<EmailPageExtras />
			</PageExtrasFill>
		</>
	);
}

addFilter('prcWpAdminDataview.fields', 'prc-email-builder/fields', (fields) => {
	const scope = getScope();
	if (!scope) {
		return fields;
	}
	return [
		...fields,
		...getFieldsForScope(scope, {
			onOpenStats: (item) => emitPageExtra('campaign-stats', item),
		}),
	];
});

addFilter(
	'prcWpAdminDataview.actions',
	'prc-email-builder/actions',
	(actions) => composeEmailActions(actions, getScope())
);

addFilter(
	'prcWpAdminDataview.defaultVisibleFields',
	'prc-email-builder/default-fields',
	(fields) => {
		const scope = getScope();
		return scope ? getDefaultVisibleFields(scope) : fields;
	}
);

addFilter(
	'prcWpAdminDataview.headerActions',
	'prc-email-builder/header-actions',
	(actions) => (getScope() ? <EmailHeaderActions /> : actions)
);

addFilter(
	'prcWpAdminDataview.pageDescription',
	'prc-email-builder/page-description',
	(description) => (getScope() ? <EmailFills /> : description)
);

addFilter(
	'prcWpAdminDataview.restQuery',
	'prc-email-builder/rest-query',
	(args, { view }) => {
		const scope = getScope();
		if (!scope) {
			return args;
		}

		const mappedArgs = {
			...args,
			post_type: scope,
		};
		if (mappedArgs.newsletterLists) {
			mappedArgs.newsletter_list = mappedArgs.newsletterLists;
			delete mappedArgs.newsletterLists;
		}

		if (mappedArgs.sendStatus) {
			const mailchimpStatuses: string[] = [];
			const mandrillStatuses: string[] = [];
			String(mappedArgs.sendStatus)
				.split(',')
				.forEach((value) => {
					const [prefix, status] = value.split(':', 2);
					if (prefix === 'campaign' && status) {
						mailchimpStatuses.push(status);
					}
					if (prefix === 'txn' && status) {
						mandrillStatuses.push(status);
					}
				});
			if (mailchimpStatuses.length) {
				mappedArgs.mailchimp_status = mailchimpStatuses.join(',');
			}
			if (mandrillStatuses.length) {
				mappedArgs.mandrill_status = mandrillStatuses.join(',');
			}
			delete mappedArgs.sendStatus;
		}

		const orderbyMap: Record<string, string> = {
			openRate: 'open_rate',
			clickRate: 'click_rate',
		};
		const sortField = view.sort?.field;
		if (sortField && orderbyMap[sortField]) {
			mappedArgs.orderby = orderbyMap[sortField];
		}

		return mappedArgs;
	}
);
