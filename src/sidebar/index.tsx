/**
 * Newsletter Builder block editor sidebar panel.
 * Registered on email campaign and transactional post types.
 */

import { registerPlugin } from '@wordpress/plugins';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

import NewsletterPanel from './newsletter-panel';
import { EmailPreviewMenuItem } from './preview/index';
import { SendNewsletterSidebar } from './send/index';
import EmailPatternSelector from './pattern-selector';
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
			<NewsletterPanel />
			<SendNewsletterSidebar />
			<EmailPreviewMenuItem />
		</>
	);
}

registerPlugin('prc-email-builder', {
	render: NewsletterBuilderSidebar,
});
