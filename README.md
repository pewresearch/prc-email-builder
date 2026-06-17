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
- **Email Library** admin page (`Newsletters → Library`) — DataViews listing of campaigns and transactional emails with filters for type, newsletter list, and combined Mailchimp/Mandrill send status

## Publish flow

1. Author creates a `prc_email_campaign` post and sets subject, preview text, and audience in the sidebar
2. _(Optional)_ Assign a `prc_newsletter_list` term — when present, audience and segment post meta are overwritten from the term's Mailchimp settings on save (see [Newsletter lists](#newsletter-lists))
3. _(Optional)_ Open **Email Preview** or click **Refresh preview** in the sidebar to verify rendered HTML
4. Author publishes — email HTML is rendered synchronously and a Mailchimp campaign draft is created immediately
5. Author reviews and sends the campaign from the Mailchimp dashboard

### Updating an existing Mailchimp draft

After publish, authors can push revised content to the same Mailchimp draft without
republishing via **Update Mailchimp draft** in the Send sidebar panel. This calls
`POST /prc-email-builder/v1/campaigns/update-draft` with `{ "post_id": <id> }`.

Constraints (enforced server-side):

- A Mailchimp campaign must already exist (`prc_email_mailchimp_campaign_id` meta)
- Mailchimp campaign status must be `save` (draft) — sent/scheduled campaigns return `409`
- Stored audience and segment must still match the linked Mailchimp campaign
- Post must have renderable email HTML

## Email Library

`Newsletters → Library` renders a `@wordpress/dataviews` table backed by
`GET /prc-email-builder/v1/library`. Filters:

| Query param | Values | Notes |
| --- | --- | --- |
| `post_type` | `all`, `campaign`, `txn` | Campaign vs transactional emails |
| `newsletter_list` | comma-separated term slugs | `prc_newsletter_list` taxonomy |
| `mailchimp_status` | `campaign:<status>` values | e.g. `campaign:save`, `campaign:sent` |
| `mandrill_status` | `txn:<status>` values | Mandrill send status for transactional posts |
| `search`, `orderby`, `order`, `page`, `per_page` | standard | Pagination via `X-WP-Total` headers |

Send-status filter labels are merged from Mailchimp and Mandrill option sets in
`Send_Status::library_filter_options()`.

## Newsletter lists

`prc_newsletter_list` taxonomy terms can store default Mailchimp targeting:

| Term meta key | Purpose |
| --- | --- |
| `prc_newsletter_list_audience_id` | Default Mailchimp audience for campaigns on this list |
| `prc_newsletter_list_segment_id` | Default saved segment within that audience |

When a campaign is saved with one or more list terms assigned,
`Newsletter_List::override_campaign_audience_from_list()` (priority 9 on
`rest_after_insert_prc_email_campaign`):

- Keeps only the first assigned term if multiple are set
- Overwrites `prc_email_mailchimp_audience_id` and `prc_email_mailchimp_segment_id` post meta from the term

In the editor sidebar, audience/segment fields show "Locked by newsletter list" when
a list term drives the values. Term admin UI (`src/term-admin/`) loads segments
dynamically when the audience changes.

## Architecture

| File                             | Purpose                                                                                                  |
| -------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `includes/class-post-type.php`   | Registers `prc_email_campaign` + `prc_email_txn` CPTs and `prc_newsletter_list` taxonomy; registers post meta |
| `includes/class-mailchimp.php`   | Mailchimp API v3 wrapper; `on_rest_publish` creates campaign drafts                                      |
| `includes/class-cached-email-html.php` | Shared helper that renders wrapped email HTML via `Email_Block_Converter`                          |
| `includes/class-preview.php`     | REST endpoints for the Email Preview modal (`/preview`, `/test-send`)                                    |
| `includes/email/class-email-block-converter.php` | Deterministic block-to-email-HTML conversion pipeline                                    |
| `includes/class-settings.php`    | Admin settings page + REST endpoint (`/prc-email-builder/v1/settings`)                              |
| `includes/class-library.php`     | Email Library admin page (`Newsletters → Library`)                                                |
| `includes/class-newsletter-list.php` | Newsletter list term meta + campaign audience override on save                                |
| `src/library/`                   | DataViews Email Library UI                                                                        |
| `src/term-admin/`                | Term edit screen: Mailchimp audience/segment fields                                               |
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
| `GET`      | `/prc-email-builder/v1/library`            | Paginated email listing for the Email Library DataViews UI |
| `POST`     | `/prc-email-builder/v1/campaigns/update-draft` | Push current HTML/settings to an existing Mailchimp draft |
| `GET`      | `/prc-email-builder/v1/audiences/{id}/segments` | Saved segments for a Mailchimp audience (term admin + sidebar) |

### `sendSystemEmail` form action + Mailchimp opt-in

Forms using the **Send System Email** action (`POST /prc-api/v3/form/send-system-email`) can optionally include a `prc-block/form-input-checkbox` named `mailchimp_signup` (insert via the **Newsletter Signup** block variation). The checkbox `value` attribute holds the Mailchimp interest ID for the target segment.

When the checkbox is checked, the handler subscribes the submitter's email to that interest **after** a successful system email send. Mailchimp failures are logged and non-fatal — the form still returns `status: success` for the send. The response may include `newsletter_signup: subscribed | failed | skipped` when the field is present.

Override or suppress interests server-side with the `prc_email_builder_system_email_mailchimp_optin` filter (`$interests`, `$field_values`, `$post_id`).

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

# Run E2E tests (Playground on port 8889)
npm run playground:start -- --port=8889
WP_BASE_URL=http://127.0.0.1:8889 npx playwright test tests/prc-email-builder/
```

## Tests

Playwright E2E specs live in the repo-root `tests/prc-email-builder/` directory
(centralized Playwright config — no per-plugin `playwright.config.js`):

| File                              | Coverage                                                                          |
| --------------------------------- | --------------------------------------------------------------------------------- |
| `newsletter-registration.spec.ts` | CPT, taxonomy, meta registration, REST endpoint shapes                            |
| `newsletter-editor.spec.ts`       | Sidebar panels, subject/preview persistence                                       |
| `system-email.spec.ts`            | Dynamic delivery key meta, `/send-system-email` + form action validation          |
| `newsletter-preview.spec.ts`      | `/preview` response shape, `/test-send` auth + validation, editor View-menu smoke |
