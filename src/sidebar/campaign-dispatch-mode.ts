export const CAMPAIGN_DISPATCH_MODE = {
	unpublished: 'unpublished',
	htmlNotReady: 'htmlNotReady',
	preparing: 'preparing',
	pendingDelayedSend: 'pendingDelayedSend',
	createDraft: 'createDraft',
	sendNowRecovery: 'sendNowRecovery',
	linkedSaveAutoOn: 'linkedSaveAutoOn',
	linkedSaveAutoOff: 'linkedSaveAutoOff',
	linkedNonDraft: 'linkedNonDraft',
} as const;

export type CampaignDispatchMode =
	(typeof CAMPAIGN_DISPATCH_MODE)[keyof typeof CAMPAIGN_DISPATCH_MODE];

export interface CampaignDispatchInput {
	isPublished: boolean;
	htmlReady: boolean;
	campaignId: string;
	campaignStatus: string;
	autoSendOnPublish: boolean;
	unlinkedThisSession: boolean;
	syncTimedOut: boolean;
	pendingSendAt?: number;
	cancelledDelayedSendThisSession?: boolean;
}

/**
 * Which Campaign Setup Mailchimp actions to show.
 *
 * Auto-send off never offers send until a draft exists. Auto-send on keeps
 * Send now as recovery when publish did not finish the send. A queued
 * delayed send takes over until it is cancelled or Mailchimp is sending.
 *
 * @param input Published state, HTML readiness, linkage, and auto-send flag.
 */
export function getCampaignDispatchMode(
	input: CampaignDispatchInput
): CampaignDispatchMode {
	const {
		isPublished,
		htmlReady,
		campaignId,
		campaignStatus,
		autoSendOnPublish,
		unlinkedThisSession,
		syncTimedOut,
		pendingSendAt = 0,
		cancelledDelayedSendThisSession = false,
	} = input;

	const draftReady = Boolean(campaignId);
	const draftEditable = !campaignStatus || campaignStatus === 'save';

	if (draftReady && !draftEditable) {
		return CAMPAIGN_DISPATCH_MODE.linkedNonDraft;
	}

	if (pendingSendAt > 0) {
		return CAMPAIGN_DISPATCH_MODE.pendingDelayedSend;
	}

	if (draftReady) {
		return autoSendOnPublish
			? CAMPAIGN_DISPATCH_MODE.linkedSaveAutoOn
			: CAMPAIGN_DISPATCH_MODE.linkedSaveAutoOff;
	}

	if (!isPublished) {
		return CAMPAIGN_DISPATCH_MODE.unpublished;
	}

	if (!htmlReady) {
		return CAMPAIGN_DISPATCH_MODE.htmlNotReady;
	}

	if (autoSendOnPublish) {
		if (
			unlinkedThisSession ||
			syncTimedOut ||
			cancelledDelayedSendThisSession
		) {
			return CAMPAIGN_DISPATCH_MODE.sendNowRecovery;
		}
		return CAMPAIGN_DISPATCH_MODE.preparing;
	}

	return CAMPAIGN_DISPATCH_MODE.createDraft;
}
