import { DataViews as DataViewsComponent } from '@wordpress/dataviews';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { Button, Flex, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import actions from '../actions';
import { getDefaultVisibleFields, getFieldsForScope } from '../fields';
import useEmails, { type EmailLibraryRow } from '../hooks/use-emails';
import type { EmailListScope } from '../types';
import CampaignStatsModal from './campaign-stats-modal';
import CreateCampaignDropdown from './create-campaign-dropdown';
import GenerateLinksNewsletterModal from './generate-links-newsletter-modal';

declare const prcEmailBuilderLibraryAI: {
	enabled: boolean;
};

declare const prcEmailLibrary: {
	postEditUrl: string;
	transactionalNewUrl?: string;
	transactionalPostType?: string;
};

const DEFAULT_LAYOUTS = {
	table: {
		layout: {
			primaryField: 'title',
		},
	},
};

function createDefaultView(scope: EmailListScope) {
	return {
		type: 'table',
		page: 1,
		perPage: 20,
		sort: {
			field: 'date',
			direction: 'desc',
		},
		search: '',
		filters: [],
		titleField: 'title',
		fields: getDefaultVisibleFields(scope),
		layout: {
			primaryField: 'title',
		},
	};
}

interface DataViewsProps {
	scope: EmailListScope;
}

export default function DataViews({ scope }: DataViewsProps) {
	const [view, setView] = useState(() => createDefaultView(scope));
	const [isGenerateModalOpen, setIsGenerateModalOpen] = useState(false);
	const [statsCampaign, setStatsCampaign] = useState<EmailLibraryRow | null>(
		null
	);
	const handleOpenStats = useCallback((item: EmailLibraryRow) => {
		setStatsCampaign(item);
	}, []);
	const fields = useMemo(
		() =>
			getFieldsForScope(scope, {
				onOpenStats: handleOpenStats,
			}),
		[handleOpenStats, scope]
	);
	const { emails, paginationInfo, isLoading, error, refresh } = useEmails(
		view,
		0,
		scope
	);

	const aiEnabled =
		scope === 'campaign' &&
		typeof prcEmailBuilderLibraryAI !== 'undefined' &&
		prcEmailBuilderLibraryAI.enabled;

	const actionsWithRefresh = useMemo(
		() =>
			actions.map((action) => {
				if (action.id !== 'trash-email') {
					return action;
				}
				return {
					...action,
					RenderModal: (props) => {
						const OriginalModal = action.RenderModal;
						return (
							<OriginalModal
								{...props}
								onActionPerformed={(items) => {
									props.onActionPerformed?.(items);
									refresh();
								}}
							/>
						);
					},
				};
			}),
		[refresh]
	);

	const handleChangeView = useCallback((newView) => {
		setView(newView);
	}, []);

	const handleCreateTransactional = useCallback(() => {
		const postType =
			prcEmailLibrary?.transactionalPostType ?? 'prc_email_txn';
		const newUrl =
			prcEmailLibrary?.transactionalNewUrl ??
			`post-new.php?post_type=${postType}`;
		window.location.href = newUrl;
	}, []);

	const headerActions =
		scope === 'campaign' ? (
			<Flex gap={2} align="center" justify="flex-end">
				<CreateCampaignDropdown />
				{aiEnabled ? (
					<Button
						variant="secondary"
						onClick={() => setIsGenerateModalOpen(true)}
					>
						{__('Generate Links Newsletter', 'prc-email-builder')}
					</Button>
				) : null}
			</Flex>
		) : (
			<Flex gap={2} align="center" justify="flex-end">
				<Button variant="primary" onClick={handleCreateTransactional}>
					{__('Create new', 'prc-email-builder')}
				</Button>
			</Flex>
		);

	if (error) {
		return (
			<Notice status="error" isDismissible={false}>
				{error}
			</Notice>
		);
	}

	return (
		<>
			<DataViewsComponent
				data={emails}
				fields={fields}
				view={view}
				onChangeView={handleChangeView}
				defaultLayouts={DEFAULT_LAYOUTS}
				actions={actionsWithRefresh}
				paginationInfo={paginationInfo}
				isLoading={isLoading}
				search={true}
				searchLabel={__('Search emails…', 'prc-email-builder')}
				getItemId={(item) => item.id.toString()}
				isItemClickable={() => true}
				onClickItem={(item) => {
					if (item.edit_url) {
						window.location.href = item.edit_url;
					}
				}}
				header={headerActions}
			/>
			{scope === 'campaign' ? (
				<>
					<GenerateLinksNewsletterModal
						isOpen={isGenerateModalOpen}
						onClose={() => setIsGenerateModalOpen(false)}
						onDraftCreated={() => refresh()}
					/>
					{statsCampaign ? (
						<CampaignStatsModal
							campaign={statsCampaign}
							onClose={() => setStatsCampaign(null)}
						/>
					) : null}
				</>
			) : null}
		</>
	);
}
