import type { ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Notice,
	Spinner,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';

import { EngagementReport } from './engagement-report';
import { CoverageNotice } from './coverage-notice';
import type { ReportEnvelope } from './use-report';

function formatLastSynced(utcIso: string): string {
	if (!utcIso) {
		return '';
	}
	const date = new Date(utcIso);
	if (Number.isNaN(date.getTime())) {
		return utcIso;
	}
	return sprintf(
		/* translators: %s: site-local datetime */
		__('Last updated %s', 'prc-email-builder'),
		date.toLocaleString()
	);
}

export interface EngagementContentProps {
	data: ReportEnvelope | null;
	isLoading: boolean;
	isRefreshing: boolean;
	error: string | null;
	onRefresh: () => void;
	className?: string;
}

// Shared engagement report body used by the editor panel and library modal.
export function EngagementContent({
	data,
	isLoading,
	isRefreshing,
	error,
	onRefresh,
	className = 'prc-email-engagement',
}: EngagementContentProps) {
	const mailchimpStatus = data?.mailchimp_status ?? '';
	const deliveryStatus = data?.delivery_status ?? '';
	const syncState = data?.sync_state ?? '';
	const report = data?.report;
	const channel = data?.channel ?? report?.channel ?? '';
	const coverage = data?.coverage;
	const isSent =
		typeof data?.stats_available === 'boolean'
			? data.stats_available
			: mailchimpStatus === 'sent' ||
				deliveryStatus === 'sent' ||
				deliveryStatus === 'partial' ||
				deliveryStatus === 'active';

	let notSentCopy = __(
		'Engagement reporting is available after this campaign is sent in Mailchimp.',
		'prc-email-builder'
	);
	if (channel === 'mandrill' || channel === 'crm') {
		notSentCopy = __(
			'Engagement reporting is available after this email is sent via Mandrill.',
			'prc-email-builder'
		);
	} else if (channel === 'system') {
		notSentCopy = __(
			'Engagement reporting is available after this system email has sent at least once.',
			'prc-email-builder'
		);
	}

	let body: ReactNode = null;

	if (isLoading && !data) {
		body = <Spinner />;
	} else if (!isSent) {
		body = <Text>{notSentCopy}</Text>;
	} else if (syncState === 'unavailable') {
		body = (
			<Text>
				{__(
					'Report unavailable in Mailchimp. The linked campaign may have been deleted.',
					'prc-email-builder'
				)}
			</Text>
		);
	} else if (!report) {
		body = (
			<VStack spacing={2}>
				<CoverageNotice coverage={coverage} />
				<Text>
					{__(
						'Report pending — no engagement data synced yet.',
						'prc-email-builder'
					)}
				</Text>
				<Button
					__next40pxDefaultSize
					style={{ width: '100%', justifyContent: 'center' }}
					variant="secondary"
					onClick={onRefresh}
					disabled={isRefreshing}
					isBusy={isRefreshing}
				>
					{__('Refresh now', 'prc-email-builder')}
				</Button>
			</VStack>
		);
	} else {
		body = (
			<VStack spacing={3}>
				<CoverageNotice coverage={coverage} />
				<EngagementReport
					summary={report.summary ?? {}}
					clicks={report.clicks_by_url ?? []}
				/>

				{data?.last_synced ? (
					<p className="prc-email-engagement-last-updated">
						{formatLastSynced(data.last_synced)}
					</p>
				) : null}

				<Button
					__next40pxDefaultSize
					style={{ width: '100%', justifyContent: 'center' }}
					variant="secondary"
					onClick={onRefresh}
					disabled={isRefreshing}
					isBusy={isRefreshing}
				>
					{__('Refresh now', 'prc-email-builder')}
				</Button>
			</VStack>
		);
	}

	return (
		<div className={className}>
			<VStack spacing={2}>
				{error ? (
					<Notice status="warning" isDismissible={false}>
						{error}
					</Notice>
				) : null}
				{body}
			</VStack>
		</div>
	);
}

export default EngagementContent;
