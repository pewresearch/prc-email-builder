import { __ } from '@wordpress/i18n';
import {
	__experimentalHStack as HStack,
	__experimentalText as Text,
} from '@wordpress/components';

interface ConnectionBadgeProps {
	connected: boolean;
	connectedLabel?: string;
	disconnectedLabel?: string;
}

/**
 * Status dot + label used to surface integration connection state in the
 * Newsletter Builder settings sections. Defaults to "Connected / Not connected"
 * but accepts custom labels (e.g. "Configured / Not configured" for Mandrill,
 * where we only know whether the API key constant is defined).
 */
export function ConnectionBadge({
	connected,
	connectedLabel,
	disconnectedLabel,
}: ConnectionBadgeProps) {
	return (
		<HStack spacing={1} style={{ display: 'inline-flex', width: 'auto' }}>
			<span
				style={{
					display: 'inline-block',
					width: 8,
					height: 8,
					borderRadius: '50%',
					background: connected ? '#00a32a' : '#d63638',
					flexShrink: 0,
					marginTop: 2,
				}}
			/>
			<Text size={12} color={connected ? '#00a32a' : '#d63638'}>
				{connected
					? (connectedLabel ?? __('Connected', 'prc-email-builder'))
					: (disconnectedLabel ??
						__('Not connected', 'prc-email-builder'))}
			</Text>
		</HStack>
	);
}
