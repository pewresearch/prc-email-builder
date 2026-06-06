/**
 * Shared AI suggest button + modal for newsletter inbox metadata fields.
 */

import { __ } from '@wordpress/i18n';
import { useState, useCallback, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { useAISuggest, AISuggestButton, AISuggestModal } from '@prc/components';

declare const prcEmailBuilderAI: {
	enabled: boolean;
	subjectAbilityName: string;
	previewAbilityName: string;
};

interface InboxAISuggestProps {
	postId: number;
	abilityName: string;
	buttonLabel: string;
	modalTitle: string;
	loadingMessage: string;
	buildFetchInput: () => Record<string, unknown>;
	transformResult: (raw: {
		options: Array<Record<string, string>>;
	}) => string[];
	onApply: (value: string) => void;
}

export function InboxAISuggest({
	postId,
	abilityName,
	buttonLabel,
	modalTitle,
	loadingMessage,
	buildFetchInput,
	transformResult,
	onApply,
}: InboxAISuggestProps) {
	const aiConfig =
		typeof prcEmailBuilderAI !== 'undefined' ? prcEmailBuilderAI : null;

	const [isModalOpen, setIsModalOpen] = useState(false);
	const [selectedIndex, setSelectedIndex] = useState(0);

	const { isLoading, error, result, fetch, reset, dismissError } =
		useAISuggest<string[]>({
			abilityName,
			transformResult,
		});

	useEffect(() => {
		if (result?.length) {
			setSelectedIndex(0);
		}
	}, [result]);

	const handleOpen = useCallback(() => {
		setIsModalOpen(true);
		setSelectedIndex(0);
		fetch(buildFetchInput());
	}, [fetch, buildFetchInput]);

	const handleClose = useCallback(() => {
		setIsModalOpen(false);
		reset();
	}, [reset]);

	const handleApply = useCallback(() => {
		if (!result?.[selectedIndex]) {
			return;
		}
		onApply(result[selectedIndex]);
		handleClose();
	}, [result, selectedIndex, onApply, handleClose]);

	const handleRegenerate = useCallback(() => {
		setSelectedIndex(0);
		fetch(buildFetchInput());
	}, [fetch, buildFetchInput]);

	if (!aiConfig?.enabled) {
		return null;
	}

	return (
		<>
			<AISuggestButton
				text={null}
				label={buttonLabel}
				onClick={handleOpen}
				isLoading={isLoading && isModalOpen}
				disabled={postId === 0}
				fullWidth={false}
				size="compact"
				variant="tertiary"
			/>

			<AISuggestModal
				title={modalTitle}
				isOpen={isModalOpen}
				onClose={handleClose}
				isLoading={isLoading}
				loadingMessage={loadingMessage}
				error={error}
				onDismissError={dismissError}
				footer={
					result && result.length > 0 ? (
						<>
							<Button
								variant="primary"
								onClick={handleApply}
								disabled={!result[selectedIndex]}
							>
								{__('Apply', 'prc-email-builder')}
							</Button>
							<Button
								variant="tertiary"
								onClick={handleRegenerate}
							>
								{__('Regenerate', 'prc-email-builder')}
							</Button>
						</>
					) : null
				}
			>
				{result && result.length > 0 && (
					<div className="prc-email-inbox-ai-options">
						{result.map((option, index) => (
							<div
								key={index}
								role="button"
								tabIndex={0}
								onClick={() => setSelectedIndex(index)}
								onKeyDown={(event) => {
									if (
										event.key === 'Enter' ||
										event.key === ' '
									) {
										setSelectedIndex(index);
									}
								}}
								style={{
									padding: '8px',
									marginBottom: '6px',
									borderRadius: '4px',
									border: `2px solid ${
										selectedIndex === index
											? 'var(--wp-admin-theme-color, #3858e9)'
											: '#e0e0e0'
									}`,
									cursor: 'pointer',
									fontSize: '13px',
									lineHeight: '1.5',
									background:
										selectedIndex === index
											? 'rgba(56, 88, 233, 0.04)'
											: 'transparent',
								}}
							>
								<span
									style={{
										display: 'block',
										fontWeight: 600,
										fontSize: '11px',
										color: '#757575',
										marginBottom: '4px',
										textTransform: 'uppercase',
										letterSpacing: '0.05em',
									}}
								>
									{__('Option', 'prc-email-builder')}{' '}
									{index + 1}
								</span>
								{option}
							</div>
						))}
					</div>
				)}
			</AISuggestModal>
		</>
	);
}
