/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';

type OriginUrlConfigProps = {
	config: Record<string, string>;
	setConfig: (key: string, value: string) => void;
};

/**
 * Config UI for the Send System Email form action.
 *
 * @param props
 * @param props.config    Current action config values.
 * @param props.setConfig Updates a single config key.
 */
export default function OriginUrlConfigComponent({
	config,
	setConfig,
}: OriginUrlConfigProps) {
	const originUrl = config?.originUrl ?? '';

	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={__('Origin URL', 'prc-email-builder')}
			help={__(
				'Optional URL recorded as the submission origin for Mailchimp merge fields. Falls back to the page referer when empty.',
				'prc-email-builder'
			)}
			value={originUrl}
			onChange={(value) => setConfig('originUrl', value)}
		/>
	);
}
