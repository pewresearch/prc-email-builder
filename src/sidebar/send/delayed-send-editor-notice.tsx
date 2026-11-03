import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';

import { isCampaignPostType, useNewsletterMeta } from '../use-newsletter-data';
import { formatCountdown } from './delayed-send-countdown';
import { useDelayedSendCountdown } from './delayed-send-status';
import { useDelayedMailchimpSend } from './use-delayed-mailchimp-send';

const DELAYED_SEND_NOTICE_ID = 'prc-email-builder-delayed-send';

export function DelayedSendEditorNotice() {
	const selectedPostId = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);
	const postId = Number(selectedPostId) || 0;
	const { postType, pendingSendAt } = useNewsletterMeta();
	const secondsLeft = useDelayedSendCountdown(pendingSendAt);
	const { handleCancelDelayedSend, handleSendImmediately } =
		useDelayedMailchimpSend(postId);
	const { createWarningNotice, removeNotice } = useDispatch(noticesStore);
	const announcedPendingSendAt = useRef(0);
	const visible =
		isCampaignPostType(postType) && pendingSendAt > 0 && secondsLeft > 0;

	// The notices store stringifies content, so each tick re-dispatches the same
	// id. Removing in this effect's cleanup would flicker the banner every second.
	useEffect(() => {
		if (!visible) {
			removeNotice(DELAYED_SEND_NOTICE_ID);
			return;
		}

		const announce = announcedPendingSendAt.current !== pendingSendAt;
		if (announce) {
			announcedPendingSendAt.current = pendingSendAt;
		}

		createWarningNotice(
			sprintf(
				/* translators: %s: remaining time as m:ss */
				__(
					'This campaign is pending a Mailchimp send. Sending in %s unless you cancel.',
					'prc-email-builder'
				),
				formatCountdown(secondsLeft)
			),
			{
				id: DELAYED_SEND_NOTICE_ID,
				isDismissible: false,
				speak: announce,
				actions: [
					{
						label: __('Cancel send', 'prc-email-builder'),
						onClick: handleCancelDelayedSend,
					},
					{
						label: __('Send now', 'prc-email-builder'),
						onClick: handleSendImmediately,
					},
				],
			}
		);
	}, [
		visible,
		secondsLeft,
		pendingSendAt,
		createWarningNotice,
		removeNotice,
		handleCancelDelayedSend,
		handleSendImmediately,
	]);

	useEffect(() => {
		return () => {
			removeNotice(DELAYED_SEND_NOTICE_ID);
		};
	}, [removeNotice]);

	return null;
}
