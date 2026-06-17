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
import './style.scss';

declare const prcEmailBuilderLibraryAI: {
	enabled: boolean;
};

export default function EmailLibrary() {
	const [isGenerateModalOpen, setIsGenerateModalOpen] = useState(false);
	const [refreshToken, setRefreshToken] = useState(0);
	const aiEnabled =
		typeof prcEmailBuilderLibraryAI !== 'undefined' &&
		prcEmailBuilderLibraryAI.enabled;

	return (
		<Card>
			<CardHeader>
				<Flex align="center">
					<FlexBlock>
						<h1 style={{ margin: 0 }}>
							{__('Email Library', 'prc-email-builder')}
						</h1>
						<p style={{ margin: '4px 0 0', color: '#757575' }}>
							{__(
								'Browse and manage campaign and transactional emails.',
								'prc-email-builder'
							)}
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
				<DataViews refreshToken={refreshToken} />
			</CardBody>
			<GenerateLinksNewsletterModal
				isOpen={isGenerateModalOpen}
				onClose={() => setIsGenerateModalOpen(false)}
				onDraftCreated={() => setRefreshToken((value) => value + 1)}
			/>
		</Card>
	);
}
