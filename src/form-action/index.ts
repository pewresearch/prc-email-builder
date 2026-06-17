/**
 * Registers the `sendSystemEmail` action with the prc-block/form provider so
 * it appears in the form block's Action dropdown in the editor.
 *
 * The action uses the `rest` method: the form view script slugifies
 * `sendSystemEmail` to `send-system-email` and POSTs to
 * `/prc-api/v3/form/send-system-email`, handled by Form_Send_System_Email.
 *
 * Enqueued on all editor screens (forms can live on any post type), so this
 * is intentionally tiny and side-effect-only.
 */

import { dispatch } from '@wordpress/data';
import domReady from '@wordpress/dom-ready';

// `namespace` is the form provider's dropdown-visibility key, not a PHP
// namespace. The form block only surfaces a `rest` action whose namespace
// matches the parent block's name or the literal `prc-block/form`, so this
// must stay `prc-block/form` for the action to be selectable on any form.
const SYSTEM_EMAIL_FORM = {
	label: 'Send System Email',
	description:
		'Email the submitter a dynamic-recipient newsletter template. Add a hidden "system_email_key" or "newsletter_post_id" field to pick the newsletter. Optionally add a Newsletter Signup checkbox (mailchimp_signup) to subscribe the submitter to a Mailchimp segment.',
	namespace: 'prc-block/form',
	action: 'sendSystemEmail',
	method: 'rest',
};

function registerSystemEmailForm(): void {
	const formsStore = dispatch('prc-block-library/forms') as
		| { registerForm?: (form: typeof SYSTEM_EMAIL_FORM) => void }
		| undefined;

	if (formsStore && typeof formsStore.registerForm === 'function') {
		formsStore.registerForm(SYSTEM_EMAIL_FORM);
	}
}

// Defer to DOM ready so the prc-block-library/forms store is registered first.
domReady(registerSystemEmailForm);
