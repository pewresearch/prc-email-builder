import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Notice,
	PanelBody,
	Spinner,
	__experimentalVStack as VStack,
	__experimentalText as Text,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';

import { CampaignAdvancedSettings } from '../campaign-mailchimp-settings';
import {
	CAMPAIGN_DISPATCH_MODE,
	type CampaignDispatchMode,
} from '../campaign-dispatch-mode';
import { useMailchimpDispatch } from './use-mailchimp-dispatch';
import {
	DelayedSendStatus,
	delayMinutesFromSeconds,
} from './delayed-send-status';

const FULL_WIDTH_STYLE = {
	width: '100%',
	justifyContent: 'center',
} as const;

interface MailchimpDispatchPanelProps {
	postId: number;
}

interface DispatchActionsProps {
	dispatchMode: CampaignDispatchMode;
	htmlReady: boolean;
	autoSendOnPublish: boolean;
	campaignStatus: string;
	mailchimpUrl: string;
	syncTimedOut: boolean;
	unlinkedThisSession: boolean;
	isCreatingDraft: boolean;
	isUpdatingDraft: boolean;
	isUnlinking: boolean;
	isSendingMailchimp: boolean;
	onCreateDraft: () => void;
	onUpdateDraft: () => void;
	onOpenSendConfirm: () => void;
	onOpenUnlinkConfirm: () => void;
}

function getOpenInMailchimpLabel(campaignStatus: string): string {
	if (campaignStatus === 'sent') {
		return __('Sent to Mailchimp', 'prc-email-builder');
	}
	if (campaignStatus === 'sending') {
		return __('Sending in Mailchimp…', 'prc-email-builder');
	}
	if (campaignStatus === 'schedule') {
		return __('Scheduled in Mailchimp', 'prc-email-builder');
	}
	return __('Open in Mailchimp', 'prc-email-builder');
}

function getNonDraftNotice(campaignStatus: string): string {
	if (campaignStatus === 'sent') {
		return __(
			'Campaign already sent in Mailchimp; content can no longer be updated.',
			'prc-email-builder'
		);
	}
	if (campaignStatus === 'schedule') {
		return __(
			'Campaign is scheduled in Mailchimp. Unschedule in Mailchimp to edit content here.',
			'prc-email-builder'
		);
	}
	return __(
		'Campaign is no longer a draft in Mailchimp; content can no longer be updated.',
		'prc-email-builder'
	);
}

function getSendNowConfirmCopy(
	recipientCount: number,
	delaySeconds: number
): string {
	const minutes = delayMinutesFromSeconds(delaySeconds);
	if (recipientCount > 0) {
		return sprintf(
			/* translators: 1: recipient count, 2: delay in minutes */
			__(
				'Queue a send to %1$s recipients in %2$s minutes? You can cancel or send immediately until then.',
				'prc-email-builder'
			),
			recipientCount.toLocaleString(),
			minutes
		);
	}
	return sprintf(
		/* translators: %s: delay in minutes */
		__(
			'Queue a send to the Mailchimp audience in %s minutes? You can cancel or send immediately until then.',
			'prc-email-builder'
		),
		minutes
	);
}

function MailchimpDispatchActions({
	dispatchMode,
	htmlReady,
	autoSendOnPublish,
	campaignStatus,
	mailchimpUrl,
	syncTimedOut,
	unlinkedThisSession,
	isCreatingDraft,
	isUpdatingDraft,
	isUnlinking,
	isSendingMailchimp,
	onCreateDraft,
	onUpdateDraft,
	onOpenSendConfirm,
	onOpenUnlinkConfirm,
}: DispatchActionsProps) {
	const openInMailchimpButton = (
		<Button
			__next40pxDefaultSize
			variant="primary"
			href={mailchimpUrl}
			target="_blank"
			rel="noreferrer"
			style={FULL_WIDTH_STYLE}
		>
			{getOpenInMailchimpLabel(campaignStatus)}
		</Button>
	);
	const updateDraftButton = (
		<Button
			__next40pxDefaultSize
			variant="secondary"
			onClick={onUpdateDraft}
			disabled={!htmlReady}
			isBusy={isUpdatingDraft}
			style={FULL_WIDTH_STYLE}
		>
			{__('Update Mailchimp draft', 'prc-email-builder')}
		</Button>
	);
	const unlinkButton = (
		<Button
			__next40pxDefaultSize
			variant="tertiary"
			isDestructive
			onClick={onOpenUnlinkConfirm}
			disabled={isUnlinking}
			isBusy={isUnlinking}
			style={FULL_WIDTH_STYLE}
		>
			{__('Unlink from Mailchimp', 'prc-email-builder')}
		</Button>
	);
	const sendNowPrimary = (
		<Button
			__next40pxDefaultSize
			variant="primary"
			onClick={onOpenSendConfirm}
			disabled={isSendingMailchimp}
			isBusy={isSendingMailchimp}
			style={FULL_WIDTH_STYLE}
		>
			{__('Send now', 'prc-email-builder')}
		</Button>
	);
	const sendNowDestructive = (
		<Button
			__next40pxDefaultSize
			variant="secondary"
			isDestructive
			onClick={onOpenSendConfirm}
			disabled={isSendingMailchimp}
			isBusy={isSendingMailchimp}
			style={FULL_WIDTH_STYLE}
		>
			{__('Send now', 'prc-email-builder')}
		</Button>
	);
	const htmlNotReadyNotice = !htmlReady && (
		<Notice status="warning" isDismissible={false}>
			{__(
				'Generate email HTML in Email Content before updating the Mailchimp draft.',
				'prc-email-builder'
			)}
		</Notice>
	);

	switch (dispatchMode) {
		case CAMPAIGN_DISPATCH_MODE.preparing:
			return (
				<Button
					__next40pxDefaultSize
					variant="primary"
					disabled
					style={FULL_WIDTH_STYLE}
				>
					{__('Queuing Mailchimp send…', 'prc-email-builder')}
					<Spinner />
				</Button>
			);
		case CAMPAIGN_DISPATCH_MODE.createDraft:
			return (
				<>
					<Notice status="info" isDismissible={false}>
						{__(
							'Automatic Mailchimp send on publish is off. Create a Mailchimp draft, then schedule or send in Mailchimp.',
							'prc-email-builder'
						)}
					</Notice>
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={onCreateDraft}
						disabled={isCreatingDraft}
						isBusy={isCreatingDraft}
						style={FULL_WIDTH_STYLE}
					>
						{__('Create Mailchimp draft', 'prc-email-builder')}
					</Button>
				</>
			);
		case CAMPAIGN_DISPATCH_MODE.sendNowRecovery:
			return (
				<>
					{syncTimedOut && !unlinkedThisSession && (
						<Notice status="error" isDismissible={false}>
							{__(
								'Mailchimp send did not complete automatically. Send now queues a 10-minute send, or check that Mailchimp is connected.',
								'prc-email-builder'
							)}
						</Notice>
					)}
					{sendNowPrimary}
				</>
			);
		case CAMPAIGN_DISPATCH_MODE.linkedSaveAutoOn:
			return (
				<>
					{sendNowPrimary}
					{updateDraftButton}
					{unlinkButton}
					<Notice status="warning" isDismissible={false}>
						{__(
							'Mailchimp campaign exists as a draft. Send now queues a 10-minute send you can cancel.',
							'prc-email-builder'
						)}
					</Notice>
					{htmlNotReadyNotice}
				</>
			);
		case CAMPAIGN_DISPATCH_MODE.linkedSaveAutoOff:
			return (
				<>
					{openInMailchimpButton}
					{updateDraftButton}
					{sendNowDestructive}
					{unlinkButton}
					<Notice status="info" isDismissible={false}>
						{__(
							'Mailchimp campaign exists as a draft. Open it in Mailchimp to schedule or send, or use Send now to queue a 10-minute send from WordPress.',
							'prc-email-builder'
						)}
					</Notice>
					{htmlNotReadyNotice}
				</>
			);
		case CAMPAIGN_DISPATCH_MODE.linkedNonDraft:
			return (
				<>
					{openInMailchimpButton}
					{unlinkButton}
					<Notice status="warning" isDismissible={false}>
						{campaignStatus === 'unavailable'
							? __(
									'The linked Mailchimp campaign is missing. Unlink to create a new Mailchimp draft.',
									'prc-email-builder'
								)
							: getNonDraftNotice(campaignStatus)}
					</Notice>
				</>
			);
		case CAMPAIGN_DISPATCH_MODE.unpublished:
			return (
				<Notice status="warning" isDismissible={false}>
					{autoSendOnPublish
						? __(
								'Publish or schedule the campaign. WordPress waits 10 minutes before sending so you can cancel.',
								'prc-email-builder'
							)
						: __(
								'Publish the campaign, then create a Mailchimp draft from this panel.',
								'prc-email-builder'
							)}
				</Notice>
			);
		case CAMPAIGN_DISPATCH_MODE.htmlNotReady:
			return (
				<Notice status="warning" isDismissible={false}>
					{__(
						'Generate email HTML in Email Content before creating a Mailchimp draft.',
						'prc-email-builder'
					)}
				</Notice>
			);
		case CAMPAIGN_DISPATCH_MODE.pendingDelayedSend:
			return null;
		default: {
			const _exhaustive: never = dispatchMode;
			return _exhaustive;
		}
	}
}

export function MailchimpDispatchPanel({
	postId,
}: MailchimpDispatchPanelProps) {
	const dispatch = useMailchimpDispatch(postId);
	const showSendConfirm =
		dispatch.dispatchMode === CAMPAIGN_DISPATCH_MODE.sendNowRecovery ||
		dispatch.dispatchMode === CAMPAIGN_DISPATCH_MODE.linkedSaveAutoOn ||
		dispatch.dispatchMode === CAMPAIGN_DISPATCH_MODE.linkedSaveAutoOff;
	const showUnlinkConfirm =
		dispatch.dispatchMode === CAMPAIGN_DISPATCH_MODE.linkedSaveAutoOn ||
		dispatch.dispatchMode === CAMPAIGN_DISPATCH_MODE.linkedSaveAutoOff ||
		dispatch.dispatchMode === CAMPAIGN_DISPATCH_MODE.linkedNonDraft;
	const unlinkConfirmIsStrong = ['sent', 'schedule', 'sending'].includes(
		dispatch.campaignStatus
	);
	const delayMinutes = delayMinutesFromSeconds(dispatch.delayedSendSeconds);

	return (
		<PanelBody title={__('Dispatch Info', 'prc-email-builder')} initialOpen>
			<VStack spacing={3}>
				<CampaignAdvancedSettings />
				<Text>
					{dispatch.autoSendOnPublish
						? sprintf(
								/* translators: %s: delay in minutes */
								__(
									'Publishing or scheduling this campaign queues a Mailchimp send. WordPress waits %s minutes so you can cancel.',
									'prc-email-builder'
								),
								delayMinutes
							)
						: sprintf(
								/* translators: %s: delay in minutes */
								__(
									'Publishing or scheduling this campaign saves the WordPress post. Create a Mailchimp draft from this panel, then schedule or send in Mailchimp. Send now queues a %s-minute send you can cancel.',
									'prc-email-builder'
								),
								delayMinutes
							)}
				</Text>
				{dispatch.dispatchMode ===
				CAMPAIGN_DISPATCH_MODE.pendingDelayedSend ? (
					<DelayedSendStatus
						pendingSendAt={dispatch.pendingSendAt}
						isCancelling={dispatch.isCancellingDelayedSend}
						isSendingImmediately={dispatch.isSendingImmediately}
						onCancel={dispatch.handleCancelDelayedSend}
						onSendImmediately={dispatch.handleSendImmediately}
					/>
				) : (
					<MailchimpDispatchActions
						dispatchMode={dispatch.dispatchMode}
						htmlReady={dispatch.htmlReady}
						autoSendOnPublish={dispatch.autoSendOnPublish}
						campaignStatus={dispatch.campaignStatus}
						mailchimpUrl={dispatch.mailchimpUrl}
						syncTimedOut={dispatch.syncTimedOut}
						unlinkedThisSession={dispatch.unlinkedThisSession}
						isCreatingDraft={dispatch.isCreatingDraft}
						isUpdatingDraft={dispatch.isUpdatingDraft}
						isUnlinking={dispatch.isUnlinking}
						isSendingMailchimp={dispatch.isSendingMailchimp}
						onCreateDraft={dispatch.handleCreateMailchimpDraft}
						onUpdateDraft={dispatch.handleUpdateMailchimpDraft}
						onOpenSendConfirm={() =>
							dispatch.setIsSendConfirmOpen(true)
						}
						onOpenUnlinkConfirm={() =>
							dispatch.setIsUnlinkConfirmOpen(true)
						}
					/>
				)}
				{showSendConfirm && (
					<ConfirmDialog
						isOpen={dispatch.isSendConfirmOpen}
						onConfirm={dispatch.handleSendNow}
						onCancel={() => dispatch.setIsSendConfirmOpen(false)}
						confirmButtonText={__('Send now', 'prc-email-builder')}
					>
						{getSendNowConfirmCopy(
							dispatch.mailchimpRecipientCount,
							dispatch.delayedSendSeconds
						)}
					</ConfirmDialog>
				)}
				{showUnlinkConfirm && (
					<ConfirmDialog
						isOpen={dispatch.isUnlinkConfirmOpen}
						onConfirm={dispatch.handleUnlinkMailchimpCampaign}
						onCancel={() => dispatch.setIsUnlinkConfirmOpen(false)}
					>
						{unlinkConfirmIsStrong
							? __(
									'This campaign may already be sent or scheduled in Mailchimp. Unlinking clears the WordPress link only; it does not delete the Mailchimp campaign. Continue?',
									'prc-email-builder'
								)
							: __(
									'Unlink this newsletter from its Mailchimp campaign? Audience and content settings stay. The Mailchimp campaign is not deleted.',
									'prc-email-builder'
								)}
					</ConfirmDialog>
				)}
			</VStack>
		</PanelBody>
	);
}
