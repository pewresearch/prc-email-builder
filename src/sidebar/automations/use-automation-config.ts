/**
 * Read/write the `prc_email_automation_config` object meta on the trigger email.
 */

import { useCallback, useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEntityProp } from '@wordpress/core-data';
import apiFetch from '@wordpress/api-fetch';

import { config } from '../use-newsletter-data';
import {
	type AutomationConfig,
	type AutomationStep,
	type FollowUpTemplate,
	EMPTY_CONFIG,
} from './types';

const META_KEY = 'prc_email_automation_config';

function normalize(raw: unknown): AutomationConfig {
	if (!raw || typeof raw !== 'object') {
		return { ...EMPTY_CONFIG };
	}
	const value = raw as Partial<AutomationConfig>;
	return {
		send_window: value.send_window ?? null,
		steps: Array.isArray(value.steps) ? value.steps : [],
	};
}

export function useAutomationConfig() {
	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);
	const [meta, setMeta] = useEntityProp('postType', postType, 'meta');
	const { editPost } = useDispatch(editorStore);

	const automation = normalize(meta?.[META_KEY]);

	const update = useCallback(
		(next: AutomationConfig) => {
			// Persist via setMeta so the object round-trips through REST.
			setMeta({ [META_KEY]: next });
			// Some object-meta setups need an explicit editPost nudge to mark dirty.
			editPost({ meta: { [META_KEY]: next } });
		},
		[setMeta, editPost]
	);

	const setAutomationWindow = useCallback(
		(sendWindow: AutomationConfig['send_window']) => {
			update({ ...automation, send_window: sendWindow });
		},
		[automation, update]
	);

	const addStep = useCallback(() => {
		const step: AutomationStep = { follow_up_post_id: 0, delay_days: 3 };
		update({ ...automation, steps: [...automation.steps, step] });
	}, [automation, update]);

	const updateStep = useCallback(
		(index: number, patch: Partial<AutomationStep>) => {
			const steps = automation.steps.map((s, i) =>
				i === index ? { ...s, ...patch } : s
			);
			update({ ...automation, steps });
		},
		[automation, update]
	);

	const removeStep = useCallback(
		(index: number) => {
			const steps = automation.steps.filter((_, i) => i !== index);
			update({ ...automation, steps });
		},
		[automation, update]
	);

	const moveStep = useCallback(
		(index: number, direction: -1 | 1) => {
			const target = index + direction;
			if (target < 0 || target >= automation.steps.length) {
				return;
			}
			const steps = [...automation.steps];
			[steps[index], steps[target]] = [steps[target], steps[index]];
			update({ ...automation, steps });
		},
		[automation, update]
	);

	return {
		automation,
		setAutomationWindow,
		addStep,
		updateStep,
		removeStep,
		moveStep,
	};
}

/**
 * Fetch published dynamic system emails usable as follow-up steps.
 *
 * @param excludePostId The current trigger post to exclude from the list.
 */
export function useFollowUpTemplates(excludePostId: number) {
	const [templates, setTemplates] = useState<FollowUpTemplate[]>([]);
	const [loading, setLoading] = useState(true);

	useEffect(() => {
		let cancelled = false;
		setLoading(true);
		apiFetch<FollowUpTemplate[]>({
			path: `/${config.restNamespace}/automation-templates?exclude=${excludePostId}`,
		})
			.then((data) => {
				if (!cancelled) {
					setTemplates(Array.isArray(data) ? data : []);
					setLoading(false);
				}
			})
			.catch(() => {
				if (!cancelled) {
					setTemplates([]);
					setLoading(false);
				}
			});
		return () => {
			cancelled = true;
		};
	}, [excludePostId]);

	return { templates, loading };
}
