import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	__experimentalText as Text,
	VStack,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';
import { edit, trash } from '@wordpress/icons';
import { useState } from '@wordpress/element';
import type { EmailLibraryRow } from '../hooks/use-emails';

function getRestBase(item: EmailLibraryRow): string {
	return item.type === 'campaign' ? 'prc_email_campaign' : 'prc_email_txn';
}

const actions = [
	{
		id: 'edit-email',
		label: __('Edit Email', 'prc-email-builder'),
		icon: edit,
		isPrimary: true,
		isEligible: (item: EmailLibraryRow) => !!item?.id,
		callback: ([item]: EmailLibraryRow[]) => {
			if (item.edit_url) {
				window.location.href = item.edit_url;
			}
		},
	},
	{
		id: 'trash-email',
		label: __('Move to Trash', 'prc-email-builder'),
		icon: trash,
		supportsBulk: true,
		isEligible: (item: EmailLibraryRow) => item?.status !== 'trash',
		RenderModal: ({
			items,
			closeModal,
			onActionPerformed,
		}: {
			items: EmailLibraryRow[];
			closeModal?: () => void;
			onActionPerformed?: (items: EmailLibraryRow[]) => void;
		}) => {
			const { createErrorNotice } = useDispatch(noticesStore);
			const [isTrashing, setIsTrashing] = useState(false);
			const count = items.length;
			const message =
				count === 1
					? __(
							'Are you sure you want to move this email to the trash?',
							'prc-email-builder'
						)
					: __(
							'Are you sure you want to move these emails to the trash?',
							'prc-email-builder'
						);

			const handleConfirm = async () => {
				setIsTrashing(true);

				try {
					await Promise.all(
						items.map((item) =>
							apiFetch({
								path: `/wp/v2/${getRestBase(item)}/${item.id}`,
								method: 'DELETE',
							})
						)
					);
					onActionPerformed?.(items);
					closeModal?.();
				} catch {
					createErrorNotice(
						__(
							'Could not move the selected email(s) to the trash.',
							'prc-email-builder'
						),
						{ type: 'snackbar' }
					);
				} finally {
					setIsTrashing(false);
				}
			};

			return (
				<VStack spacing={3}>
					<Text>{message}</Text>
					<VStack spacing={2} direction="row" justify="flex-end">
						<Button
							variant="tertiary"
							onClick={closeModal}
							disabled={isTrashing}
						>
							{__('Cancel', 'prc-email-builder')}
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={handleConfirm}
							isBusy={isTrashing}
							disabled={isTrashing}
						>
							{__('Move to Trash', 'prc-email-builder')}
						</Button>
					</VStack>
				</VStack>
			);
		},
	},
];

export default actions;
