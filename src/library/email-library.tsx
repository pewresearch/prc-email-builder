import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexBlock,
	FlexItem,
} from '@wordpress/components';
import DataViews from './components/dataviews';
import GenerateLinksNewsletterModal from './components/generate-links-newsletter-modal';
import type { EmailListScope } from './types';
import './style.scss';

declare const prcEmailBuilderLibraryAI: {
	enabled: boolean;
};

declare const prcEmailLibrary: {
	postTypeScope?: EmailListScope;
	pageTitle?: string;
	pageDescription?: string;
};

function getScope(): EmailListScope {
	return prcEmailLibrary?.postTypeScope === 'txn' ? 'txn' : 'campaign';
}

export default function EmailLibrary() {
	const [isGenerateModalOpen, setIsGenerateModalOpen] = useState(false);
	const [refreshToken, setRefreshToken] = useState(0);
	const scope = getScope();
	const pageTitle =
		prcEmailLibrary?.pageTitle ||
		(scope === 'txn'
			? __('Transactional Emails', 'prc-email-builder')
			: __('Campaigns', 'prc-email-builder'));
	const pageDescription =
		prcEmailLibrary?.pageDescription ||
		(scope === 'txn'
			? __('Browse and manage transactional emails.', 'prc-email-builder')
			: __('Browse and manage email campaigns.', 'prc-email-builder'));
	const aiEnabled =
		scope === 'campaign' &&
		typeof prcEmailBuilderLibraryAI !== 'undefined' &&
		prcEmailBuilderLibraryAI.enabled;

	return (
		<Card>
			<CardHeader>
				<Flex align="center">
					<FlexBlock>
						<h1 style={{ margin: 0 }}>{pageTitle}</h1>
						<p style={{ margin: '4px 0 0', color: '#757575' }}>
							{pageDescription}
						</p>
					</FlexBlock>
					{aiEnabled ? (
						<FlexItem>
							<Button
								variant="primary"
								onClick={() => setIsGenerateModalOpen(true)}
							>
								{__(
									'Generate Links Newsletter',
									'prc-email-builder'
								)}
							</Button>
						</FlexItem>
					) : null}
				</Flex>
			</CardHeader>
			<CardBody>
				<DataViews scope={scope} refreshToken={refreshToken} />
			</CardBody>
			{scope === 'campaign' ? (
				<GenerateLinksNewsletterModal
					isOpen={isGenerateModalOpen}
					onClose={() => setIsGenerateModalOpen(false)}
					onDraftCreated={() => setRefreshToken((value) => value + 1)}
				/>
			) : null}
		</Card>
	);
}
