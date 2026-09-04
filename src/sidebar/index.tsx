import { registerPlugin } from '@wordpress/plugins';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

import EmailSettingsPanel from './email-settings-panel';
import { EmailPreviewMenuItem } from './preview/index';
import { SendSidebar } from './send/index';
import EmailPatternSelector from './pattern-selector';
import { EngagementPanel } from './engagement';
import { EmailSubjectPrePublishPanel } from './pre-publish-panel';
import { isEmailPostType } from './use-newsletter-data';

function NewsletterBuilderSidebar() {
	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);

	if (!isEmailPostType(postType)) {
		return null;
	}

	return (
		<>
			<EmailPatternSelector />
			<EmailSettingsPanel />
			<EngagementPanel />
			<EmailSubjectPrePublishPanel />
			<SendSidebar />
			<EmailPreviewMenuItem />
		</>
	);
}

registerPlugin('prc-email-builder', {
	render: NewsletterBuilderSidebar,
});
