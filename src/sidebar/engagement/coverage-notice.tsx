import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

import type { Coverage } from './use-report';

export interface CoverageNoticeProps {
	coverage?: Coverage | null;
}

export function CoverageNotice({ coverage }: CoverageNoticeProps) {
	if (!coverage) {
		return null;
	}

	const { engagement, audience, engagement_since: since } = coverage;

	let message = '';
	if (audience === 'crm_contacts') {
		message = __(
			'Opens and clicks count CRM contacts only, not the full audience.',
			'prc-email-builder'
		);
	} else if (engagement === 'none') {
		message = __(
			'Volume is known. Opens and clicks were not tracked for this send.',
			'prc-email-builder'
		);
	} else if (engagement !== 'forward_only') {
		return null;
	} else if (since) {
		message = __(
			'Opens and clicks are counted from when tracking started, not the full send history.',
			'prc-email-builder'
		);
	} else {
		message = __(
			'Opens and clicks are counted from when tracking started.',
			'prc-email-builder'
		);
	}

	return (
		<Notice status="info" isDismissible={false}>
			{message}
		</Notice>
	);
}

export default CoverageNotice;
