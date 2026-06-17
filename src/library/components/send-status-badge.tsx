import {
	__experimentalHStack as HStack,
	__experimentalText as Text,
} from '@wordpress/components';
import type { SendStatusTone } from '../utils/send-status';

interface SendStatusBadgeProps {
	label: string;
	tone: SendStatusTone;
}

const TONE_COLORS: Record<SendStatusTone, string> = {
	success: '#1a8a1a',
	warning: '#c07800',
	error: '#cc1818',
	neutral: '#757575',
};

export default function SendStatusBadge({ label, tone }: SendStatusBadgeProps) {
	const color = TONE_COLORS[tone] ?? TONE_COLORS.neutral;

	return (
		<HStack
			spacing={1}
			className={`prc-email-send-status prc-email-send-status--${tone}`}
			style={{ display: 'inline-flex', width: 'auto' }}
		>
			<span
				className="prc-email-send-status__dot"
				style={{
					display: 'inline-block',
					width: 8,
					height: 8,
					borderRadius: '50%',
					background: color,
					flexShrink: 0,
				}}
			/>
			<Text size={12} style={{ color }}>
				{label}
			</Text>
		</HStack>
	);
}
