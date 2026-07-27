import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

import { isCampaignPostType } from '../use-newsletter-data';
import { EngagementContent } from './engagement-content';
import { useCampaignReport } from './use-report';
import './style.scss';

export function EngagementPanel() {
	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);
	const postId = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);

	const { data, isLoading, isRefreshing, error, refresh } =
		useCampaignReport(postId);

	if (!isCampaignPostType(postType)) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="prc-email-engagement"
			title={__('Engagement', 'prc-email-builder')}
			className="prc-email-engagement-panel"
		>
			<EngagementContent
				data={data}
				isLoading={isLoading}
				isRefreshing={isRefreshing}
				error={error}
				onRefresh={refresh}
				className="prc-email-engagement"
			/>
		</PluginDocumentSettingPanel>
	);
}

export { EngagementContent } from './engagement-content';
export { useCampaignReport } from './use-report';
export default EngagementPanel;
