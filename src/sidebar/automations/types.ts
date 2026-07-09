/**
 * Types for the scheduled-automation editor UI.
 *
 * Mirrors the PHP `prc_email_automation_config` object meta shape.
 */

export interface SendWindow {
	timezone: string;
	hour: number;
	minute: number;
}

export interface AutomationStep {
	follow_up_post_id: number;
	delay_days: number;
	send_window?: SendWindow | null;
}

export interface AutomationConfig {
	send_window: SendWindow | null;
	steps: AutomationStep[];
}

export interface FollowUpTemplate {
	id: number;
	title: string;
	key: string;
}

export const EMPTY_CONFIG: AutomationConfig = {
	send_window: null,
	steps: [],
};
