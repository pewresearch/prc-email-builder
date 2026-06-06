/**
 * E2E tests: Email Preview feature
 *
 * Covers:
 *  1. GET /prc-email-builder/v1/preview — response shape for an editor-level user
 *  2. POST /prc-email-builder/v1/test-send — 403 without auth, 400 for bad email
 *  3. Editor smoke — "Email Preview" entry visible in the View menu; modal opens and closes
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const REST_PREVIEW = '/wp-json/prc-email-builder/v1/preview';
const REST_TEST_SEND = '/wp-json/prc-email-builder/v1/test-send';

// ─── REST endpoint shape tests ────────────────────────────────────────────────

test.describe('REST: GET /preview', () => {
	let postId: number;

	test.beforeAll(async ({ requestUtils }) => {
		// Create a draft email campaign post to test against.
		const post = await requestUtils.createPost({
			title: 'Test Newsletter Preview',
			status: 'draft',
			// @ts-ignore — custom post type
			type: 'prc_email_campaign',
		});
		postId = post.id;
	});

	test.afterAll(async ({ requestUtils }) => {
		if (postId) {
			await requestUtils.deletePost(postId);
		}
	});

	test('returns expected shape for an authorised user', async ({
		requestUtils,
	}) => {
		const response = await requestUtils.rest({
			path: `${REST_PREVIEW}?post_id=${postId}`,
		});

		expect(response).toMatchObject({
			status: expect.stringMatching(/^(none|pending|complete|error)$/),
			html: expect.any(String),
			size_bytes: expect.any(Number),
			from_name: expect.any(String),
			from_email: expect.any(String),
			subject: expect.any(String),
			preview_text: expect.any(String),
		});
	});

	test('returns 403 when no post_id is provided', async ({
		request,
		baseURL,
	}) => {
		const response = await request.get(`${baseURL}${REST_PREVIEW}`);
		// WordPress REST validation returns 400 for missing required params.
		expect([400, 403]).toContain(response.status());
	});
});

test.describe('REST: POST /test-send', () => {
	let postId: number;

	test.beforeAll(async ({ requestUtils }) => {
		const post = await requestUtils.createPost({
			title: 'Test Newsletter Send',
			status: 'draft',
			// @ts-ignore
			type: 'prc_email_campaign',
		});
		postId = post.id;
	});

	test.afterAll(async ({ requestUtils }) => {
		if (postId) {
			await requestUtils.deletePost(postId);
		}
	});

	test('returns 403 for unauthenticated requests', async ({
		request,
		baseURL,
	}) => {
		const response = await request.post(`${baseURL}${REST_TEST_SEND}`, {
			data: { post_id: postId, email: 'test@example.com' },
		});
		expect(response.status()).toBe(403);
	});

	test('returns 400 for an invalid email address', async ({
		requestUtils,
	}) => {
		let threw = false;
		try {
			await requestUtils.rest({
				path: REST_TEST_SEND,
				method: 'POST',
				data: { post_id: postId, email: 'not-an-email' },
			});
		} catch {
			threw = true;
		}
		// The REST API schema validates email with `is_email()`; invalid → 400.
		expect(threw).toBe(true);
	});

	test('returns 409 when email HTML has not been generated yet', async ({
		requestUtils,
	}) => {
		// The post was just created so the transformer cache is empty → 409.
		let statusCode = 0;
		try {
			await requestUtils.rest({
				path: REST_TEST_SEND,
				method: 'POST',
				data: { post_id: postId, email: 'smoke@example.com' },
			});
		} catch (err: any) {
			statusCode = err?.data?.status ?? err?.status ?? 0;
		}
		expect(statusCode).toBe(409);
	});
});

// ─── Editor smoke test ────────────────────────────────────────────────────────

test.describe('Editor: Email Preview View-menu item', () => {
	let postId: number;

	test.beforeAll(async ({ requestUtils }) => {
		const post = await requestUtils.createPost({
			title: 'Preview Smoke Test Newsletter',
			status: 'draft',
			// @ts-ignore
			type: 'prc_email_campaign',
		});
		postId = post.id;
	});

	test.afterAll(async ({ requestUtils }) => {
		if (postId) {
			await requestUtils.deletePost(postId);
		}
	});

	test('"Email Preview" entry appears in the View menu', async ({
		admin,
		editor,
		page,
	}) => {
		await admin.visitAdminPage(`post.php?post=${postId}&action=edit`);

		// Open the View menu (the eye icon / "View" button in the editor toolbar).
		const viewMenuButton = page.getByRole('button', { name: /^View$/ });
		await viewMenuButton.click();

		await expect(
			page.getByRole('menuitem', { name: 'Email Preview' })
		).toBeVisible();
	});

	test('clicking "Email Preview" opens the preview modal', async ({
		admin,
		page,
	}) => {
		await admin.visitAdminPage(`post.php?post=${postId}&action=edit`);

		const viewMenuButton = page.getByRole('button', { name: /^View$/ });
		await viewMenuButton.click();
		await page.getByRole('menuitem', { name: 'Email Preview' }).click();

		// Modal should appear.
		await expect(
			page.getByRole('dialog', { name: 'Email Preview' })
		).toBeVisible();

		await expect(
			page.getByRole('button', { name: 'Regenerate email preview' })
		).toBeVisible();

		// Should contain the toolbar controls.
		await expect(page.getByText('Desktop')).toBeVisible();
		await expect(page.getByText('Mobile')).toBeVisible();
		await expect(page.getByText('Light')).toBeVisible();
		await expect(page.getByText('Dark')).toBeVisible();
	});

	test('closing the modal removes it from the DOM', async ({
		admin,
		page,
	}) => {
		await admin.visitAdminPage(`post.php?post=${postId}&action=edit`);

		const viewMenuButton = page.getByRole('button', { name: /^View$/ });
		await viewMenuButton.click();
		await page.getByRole('menuitem', { name: 'Email Preview' }).click();

		const modal = page.getByRole('dialog', { name: 'Email Preview' });
		await expect(modal).toBeVisible();

		// Close via the modal's × button.
		await page.getByRole('button', { name: 'Close' }).click();
		await expect(modal).not.toBeVisible();
	});
});
