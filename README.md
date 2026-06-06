# PRC Email Builder

Native WordPress email authoring and Mailchimp/Mandrill delivery for PRC Platform. Replaces Newsletter Glue Pro.

## What it does

- Registers two custom post types — `prc_email_campaign` (Mailchimp campaigns) and `prc_email_txn` (Mandrill bulk + dynamic system emails) — plus a `prc_newsletter_list` taxonomy for organizing email products (The Briefing, Notifications, etc.)
- Provides an editor sidebar panel for setting the email subject line, preview text, and Mailchimp audience
- Converts block content to email-safe HTML via the deterministic `Email_Block_Converter` pipeline
- Creates a Mailchimp campaign draft automatically on publish
- Registers the "The Briefing" block pattern as a starting-point template
- Supports a `dynamic` delivery channel: a newsletter is published as a reusable, per-recipient template sent on demand (e.g. from a `prc-block/form` via the `sendSystemEmail` action), with block-bits resolving merge fields per send
- Settings page under **Newsletters → Settings** for Mailchimp API key, From Name, and From Email

## Publish flow

1. Author creates a `prc_email_campaign` post and sets subject, preview text, and audience in the sidebar
2. _(Optional)_ Open **Email Preview** or click **Refresh preview** in the sidebar to verify rendered HTML
3. Author publishes — email HTML is rendered synchronously and a Mailchimp campaign draft is created immediately
4. Author reviews and sends the campaign from the Mailchimp dashboard

## Architecture

| File                             | Purpose                                                                                                  |
| -------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `includes/class-post-type.php`   | Registers `prc_email_campaign` + `prc_email_txn` CPTs and `prc_newsletter_list` taxonomy; registers post meta |
| `includes/class-mailchimp.php`   | Mailchimp API v3 wrapper; `on_rest_publish` creates campaign drafts                                      |
| `includes/class-cached-email-html.php` | Shared helper that renders wrapped email HTML via `Email_Block_Converter`                          |
| `includes/class-preview.php`     | REST endpoints for the Email Preview modal (`/preview`, `/test-send`)                                    |
| `includes/email/class-email-block-converter.php` | Deterministic block-to-email-HTML conversion pipeline                                    |
| `includes/class-settings.php`    | Admin settings page + REST endpoint (`/prc-email-builder/v1/settings`)                              |
| `includes/class-patterns.php`    | Auto-registers block patterns from `patterns/*.php`                                                      |
| `includes/class-quiz-email.php`  | REST endpoint for the quiz results email block                                                           |
| `includes/class-assets.php`      | Enqueues editor sidebar JS (injects `from_name`/`from_email` defaults into `prcEmailBuilderConfig`) |
| `src/sidebar/`                   | Editor sidebar panels (Newsletter Settings, Email Content)                                               |
| `src/sidebar/preview/`           | Email Preview View-menu item + modal (see below)                                                         |
| `src/settings/`                  | React settings page (Mailchimp connection, From Name/Email)                                              |
| `src/form-action/`               | Registers the `sendSystemEmail` prc-block/form action in the editor                                      |
| `patterns/the-briefing.php`      | "The Briefing" starter template pattern                                                                  |

### Email Preview

The editor gains an **Email Preview** entry in the View menu (the eye icon in the toolbar). Clicking it opens a full-screen modal with:

- **Inbox header** — From name, From email, subject line, and preview text as the subscriber will see them
- **Viewport toggle** — Desktop (600 px iframe width) / Mobile (375 px)
- **Color-scheme toggle** — Light / Dark (a dark-mode style override is injected into the iframe `srcDoc`)
- **View toggle** — Rendered Preview vs. Raw HTML (with one-click copy)
- **HTML size calculator** — displays bytes and KB with thresholds:
    - green < 100 KB
    - amber 100–102 KB
    - red ≥ 102 KB (Gmail clips emails at ~102 KB)
- **Test-send form** — enter any email address to receive a live `[TEST]` send via `wp_mail()`

The preview renders email HTML synchronously via `Email_Block_Converter` — the same path used for Mailchimp drafts, Mandrill sends, and test sends.

## REST endpoints

| Method     | Path                                            | Description                                              |
| ---------- | ----------------------------------------------- | -------------------------------------------------------- |
| `GET`      | `/prc-email-builder/v1/connection`         | Mailchimp connection status                              |
| `GET`      | `/prc-email-builder/v1/audiences`          | Available Mailchimp audiences                            |
| `GET/POST` | `/prc-email-builder/v1/settings`           | Read/write plugin settings                               |
| `POST`     | `/prc-email-builder/v1/send-system-email`  | Render + send a dynamic-recipient newsletter (editors)   |
| `POST`     | `/prc-api/v3/form/send-system-email`            | prc-block/form action: send a system email to submitter  |
| `GET`      | `/prc-email-builder/v1/preview`            | Render email HTML + metadata for the preview modal       |
| `POST`     | `/prc-email-builder/v1/test-send`          | Send a test email via `wp_mail()`                        |

### GET `/preview`

Query param: `post_id` (required, integer).

Returns:

```json
{
	"status": "complete | error",
	"html": "<full email HTML string or empty string>",
	"size_bytes": 12345,
	"from_name": "Pew Research Center",
	"from_email": "newsletters@pewresearch.org",
	"subject": "Email subject line",
	"preview_text": "Short preheader text"
}
```

Rendering is synchronous; `status` is always `complete` when the post has renderable block content.

### POST `/test-send`

Body: `{ "post_id": 123, "email": "you@example.com" }`.

Renders and sends email HTML to the given address via `wp_mail()` with `Content-Type: text/html` and the subject prefixed with `[TEST]`. Returns `{ "success": true }` on success or a `WP_Error` (`409` if the post has no renderable content, `500` if `wp_mail()` returned `false`).

## Configuration

Mailchimp credentials are read from the `PRC_PLATFORM_MAILCHIMP_KEY` constant (set via `keys-and-tokens.php`). For local development, add a `putenv()` call in `wp-config.php` before the `require(wp-config-defaults.php)` line:

```php
if ( ! getenv( 'PRC_PLATFORM_MAILCHIMP_KEY' ) ) {
    putenv( 'PRC_PLATFORM_MAILCHIMP_KEY=your-key-here' );
}
```

## Development

```bash
# Build editor sidebar + settings page
npx turbo build --filter=@prc/email-builder

# Watch mode (sidebar)
npm run start -w @prc/email-builder

# Watch mode (settings page)
npm run start:settings -w @prc/email-builder

# Run E2E tests (requires dev env running)
WP_BASE_URL=http://prc-platform.vipdev.lndo.site/pewresearch-org \
WP_USERNAME=vipgo \
WP_PASSWORD=password \
npm run test -w @prc/email-builder
```

## Tests

Playwright E2E specs live in `tests/`:

| File                              | Coverage                                                                          |
| --------------------------------- | --------------------------------------------------------------------------------- |
| `newsletter-registration.spec.ts` | CPT, taxonomy, meta registration, REST endpoint shapes                            |
| `newsletter-editor.spec.ts`       | Sidebar panels, subject/preview persistence                                       |
| `system-email.spec.ts`            | Dynamic delivery key meta, `/send-system-email` + form action validation          |
| `newsletter-preview.spec.ts`      | `/preview` response shape, `/test-send` auth + validation, editor View-menu smoke |
