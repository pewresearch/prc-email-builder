import { __ } from '@wordpress/i18n';
import { Modal } from '@wordpress/components';
import { EngagementContent } from '../../sidebar/engagement/engagement-content';
import { useCampaignReport } from '../../sidebar/engagement/use-report';
import '../../sidebar/engagement/style.scss';
import type { EmailLibraryRow } from '../hooks/use-emails';

interface CampaignStatsModalProps {
	campaign: EmailLibraryRow | null;
	onClose: () => void;
}

export default function CampaignStatsModal({
	campaign,
	onClose,
}: CampaignStatsModalProps) {
	const postId = campaign?.id;
	const { data, isLoading, isRefreshing, error, refresh } =
		useCampaignReport(postId);

	if (!campaign) {
		return null;
	}

	const title =
		campaign.title?.trim() || __('Untitled campaign', 'prc-email-builder');

	return (
		<Modal
			title={title}
			onRequestClose={onClose}
			className="prc-email-library-stats-modal"
			size="medium"
		>
			<EngagementContent
				data={data}
				isLoading={isLoading}
				isRefreshing={isRefreshing}
				error={error}
				onRefresh={refresh}
				className="prc-email-engagement"
			/>
		</Modal>
	);
}
