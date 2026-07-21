/**
 * Automations settings for the Transactional Setup sidebar.
 *
 * Rendered for dynamic (`prc_email_delivery_mode === 'dynamic'`) transactional
 * emails. Lets authors configure an automation-level send window plus an ordered
 * list of follow-up steps, each with a delay (calendar days), a follow-up
 * template, and an optional per-step send window override.
 */

import { __, sprintf } from '@wordpress/i18n';
import { store as editorStore } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import {
	Button,
	SelectControl,
	Notice,
	Spinner,
	Card,
	CardBody,
	__experimentalNumberControl as NumberControl,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { chevronUp, chevronDown, trash } from '@wordpress/icons';

import { SendWindowControl } from './send-window-control';
import {
	useAutomationConfig,
	useFollowUpTemplates,
} from './use-automation-config';
import type { FollowUpTemplate, AutomationStep } from './types';

const TEXT_DOMAIN = 'prc-email-builder';

function StepCard({
	index,
	total,
	templates,
	templatesLoading,
	step,
	updateStep,
	removeStep,
	moveStep,
}: {
	index: number;
	total: number;
	templates: FollowUpTemplate[];
	templatesLoading: boolean;
	step: AutomationStep;
	updateStep: (index: number, patch: Partial<AutomationStep>) => void;
	removeStep: (index: number) => void;
	moveStep: (index: number, direction: -1 | 1) => void;
}) {
	const templateOptions = [
		{ value: '0', label: __('— Select follow-up —', TEXT_DOMAIN) },
		...templates.map((t) => ({
			value: String(t.id),
			label: t.key ? `${t.title} (${t.key})` : t.title,
		})),
	];

	return (
		<Card size="small">
			<CardBody>
				<VStack spacing={3}>
					<HStack alignment="left" spacing={2}>
						<Text weight={600}>
							{sprintf(
								/* translators: %d: step number */
								__('Step %d', TEXT_DOMAIN),
								index + 1
							)}
						</Text>
						<HStack justify="right" spacing={1}>
							<Button
								size="small"
								icon={chevronUp}
								label={__('Move up', TEXT_DOMAIN)}
								disabled={index === 0}
								onClick={() => moveStep(index, -1)}
							/>
							<Button
								size="small"
								icon={chevronDown}
								label={__('Move down', TEXT_DOMAIN)}
								disabled={index === total - 1}
								onClick={() => moveStep(index, 1)}
							/>
							<Button
								size="small"
								isDestructive
								icon={trash}
								label={__('Remove step', TEXT_DOMAIN)}
								onClick={() => removeStep(index)}
							/>
						</HStack>
					</HStack>

					{templatesLoading ? (
						<Spinner />
					) : (
						<SelectControl
							__nextHasNoMarginBottom
							label={__('Follow-up email', TEXT_DOMAIN)}
							value={String(step.follow_up_post_id || 0)}
							options={templateOptions}
							onChange={(value) =>
								updateStep(index, {
									follow_up_post_id: parseInt(value, 10) || 0,
								})
							}
						/>
					)}

					<NumberControl
						label={__('Delay (calendar days)', TEXT_DOMAIN)}
						min={0}
						max={365}
						value={step.delay_days}
						help={
							index === 0
								? __(
										'Days after the initial email is sent.',
										TEXT_DOMAIN
									)
								: __(
										'Days after the previous step is sent.',
										TEXT_DOMAIN
									)
						}
						onChange={(value) =>
							updateStep(index, {
								delay_days: Math.max(
									0,
									Math.min(365, Number(value) || 0)
								),
							})
						}
					/>

					<SendWindowControl
						value={step.send_window ?? null}
						onChange={(sendWindow) =>
							updateStep(index, { send_window: sendWindow })
						}
						inheritLabel={__(
							'Use automation default window',
							TEXT_DOMAIN
						)}
						inheritHelp={__(
							'When off, this step sends at its own time of day.',
							TEXT_DOMAIN
						)}
					/>
				</VStack>
			</CardBody>
		</Card>
	);
}

export function AutomationsSettings() {
	const postId = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);
	const {
		automation,
		setAutomationWindow,
		addStep,
		updateStep,
		removeStep,
		moveStep,
	} = useAutomationConfig();
	const { templates, loading: templatesLoading } = useFollowUpTemplates(
		postId ?? 0
	);

	return (
		<VStack spacing={4}>
			<Notice status="info" isDismissible={false}>
				{__(
					'Schedule follow-up emails after this email is sent. Each recipient enters the sequence when they receive this email.',
					TEXT_DOMAIN
				)}
			</Notice>

			<SendWindowControl
				value={automation.send_window}
				onChange={setAutomationWindow}
				inheritLabel={__('Use site default send window', TEXT_DOMAIN)}
				inheritHelp={__(
					'The default time of day follow-ups send, unless a step overrides it.',
					TEXT_DOMAIN
				)}
			/>

			{automation.steps.map((step, index) => (
				<StepCard
					// eslint-disable-next-line react/no-array-index-key
					key={index}
					index={index}
					total={automation.steps.length}
					templates={templates}
					templatesLoading={templatesLoading}
					step={step}
					updateStep={updateStep}
					removeStep={removeStep}
					moveStep={moveStep}
				/>
			))}

			<Button
				variant="secondary"
				style={{ width: '100%', justifyContent: 'center' }}
				onClick={addStep}
			>
				{__('Add follow-up step', TEXT_DOMAIN)}
			</Button>
		</VStack>
	);
}
