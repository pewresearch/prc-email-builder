import { __ } from '@wordpress/i18n';
import {
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexBlock,
} from '@wordpress/components';
import DataViews from './components/dataviews';
import type { EmailListScope } from './types';
import './style.scss';

declare const prcEmailLibrary: {
	postTypeScope?: EmailListScope;
	pageTitle?: string;
	pageDescription?: string;
};

function getScope(): EmailListScope {
	return prcEmailLibrary?.postTypeScope === 'txn' ? 'txn' : 'campaign';
}

export default function EmailLibrary() {
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
				</Flex>
			</CardHeader>
			<CardBody>
				<DataViews scope={scope} />
			</CardBody>
		</Card>
	);
}
