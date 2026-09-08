# PRC Email Builder

> Canonical docs: [docs/plugins/prc-email-builder/](../../docs/plugins/prc-email-builder/)

Native WordPress email authoring and Mailchimp/Mandrill delivery for PRC Platform.

## What it does

- Registers two custom post types — `prc_email_campaign` (Mailchimp campaigns) and `prc_email_txn` (Mandrill bulk + dynamic system emails) — plus a `prc_newsletter_list` taxonomy for organizing email products (The Briefing, Notifications, etc.)
- Binds the `_post_visibility` taxonomy to `prc_email_campaign` so **Hide on Publications Archive** persists through the block editor REST API (the campaign CPT registers after `prc-publication-listing` wires visibility support to participating post types)
- Provides editor panels for subject line, preview text, and newsletter list assignment (see [Editor sidebars](#editor-sidebars))
- Converts block content to email-safe HTML via the deterministic `Email_Block_Converter` pipeline
- Creates a Mailchimp campaign automatically on publish (or when a scheduled campaign goes live) when **Automatically send on publish** is enabled, then waits 10 minutes before sending so authors can cancel
- Registers the "The Briefing" block pattern as a starting-point template
- Supports a `dynamic` delivery channel: a newsletter is published as a reusable, per-recipient template sent on demand (e.g. from a `prc-block/form` via the `sendSystemEmail` action), with block-bits resolving merge fields per send
- Settings page under **Newsletters → Settings** for Mailchimp API key, From Name, From Email, and **Automatically send on publish**
- **Email Library** admin page (`Newsletters → Library`) — DataViews listing of campaigns and transactional emails with filters for type, newsletter list, combined Mailchimp/Mandrill send status, and sortable open/click-rate columns for sent campaigns
- **Engagement reporting** for Mailchimp campaigns — daily Action Scheduler sync plus on-demand refresh; sidebar **Engagement** panel on campaign posts shows opens, clicks, bounces, unsubscribes, and click-by-URL breakdown
- **Slack first-day stats** — 24 hours after Mailchimp marks a campaign sent, a threaded Slack reply posts sent/open/click/bounce totals and top links (same publish announcement as the sent notice)
- **Scheduled automations** for dynamic system emails — configure follow-up (drip) steps that send X calendar days after the initial email in a fixed daily send window (see [Scheduled automations](#scheduled-automations))
- **System email audience tooling** — durable per-recipient send log plus WP-CLI builders for Mandrill activity exports and log-derived bulk audiences (see [System email audiences](#system-email-audiences))

## Newsletter Glue (retired)

The vendored **Newsletter Glue Pro** plugin and the automated NGL → `prc_email_*` migration engine (Action Scheduler jobs, WP-CLI importers, and editor hooks that assumed NGL was installed) were removed from the monorepo. New sites should author campaigns and transactional emails only through this plugin.

**Archival migrated posts** still carry `_migrated_from_ngl_id` post meta. `PRC\Platform\Email_Builder\Migration::is_migrated( $post_id )` is the single guard used across send, Mailchimp sync, engagement reporting, and editor sidebar enqueue — those posts are treated as read-only archives (no Mailchimp send, no Engagement panel). Historical release notes in `docs/release-notes/1.7/` describe the original migration; they are not a runbook for new environments.

## Editor sidebars

Email posts split producer controls across the **document panel** (always visible) and a pinned **plugin sidebar** (toolbar send icon):

| Post type            | Document panel ("Email Settings")             | Plugin sidebar                                                                                                               |
| -------------------- | --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `prc_email_campaign` | Subject, preview text, newsletter list picker | **Campaign Setup** — Mailchimp audience/segment/template overrides, draft recovery, and send status                          |
| `prc_email_txn`      | Subject and preview text only                 | **Transactional Setup** — delivery type, Mandrill recipient list or dynamic system email slug, automations, and send actions |

**Campaign Setup** (`src/sidebar/send/`) is available before and after publish. When a `prc_newsletter_list` term is assigned, audience/segment fields in Campaign Setup show "Locked by newsletter list" (values copied from term meta on save).

**Transactional Setup** hosts:

- **Dispatch Info** (collapsible) — transactional type (`mandrill` bulk vs `dynamic` per-recipient), Mandrill recipient list picker, or dynamic **system email slug**
- **Automations** (collapsible, dynamic only) — follow-up step configuration (moved from the document panel)
- Send / draft actions and delivery status notices

Dynamic system emails are identified by their **post slug** (`post_name`), not a separate meta field. The slug control in Transactional Setup is the lookup key forms and other plugins use when sending (e.g. `typology-loyal-liberals`). Legacy `prc_email_system_email_key` meta is migrated with `wp prc email migrate-system-keys`.

## Publish flow

1. Author creates a `prc_email_campaign` post and sets subject, preview text, and (optionally) newsletter list in the document panel
2. _(Optional)_ Assign a `prc_newsletter_list` term — when present, audience and segment post meta are overwritten from the term's Mailchimp settings on save (see [Newsletter lists](#newsletter-lists)). Advanced audience/segment/template overrides live in **Campaign Setup**.
3. _(Optional)_ Open **Preview** in the Email Content document panel to verify rendered HTML
4. Author publishes **or schedules** the campaign in WordPress — when **Automatically send on publish** is enabled (the default) and the post reaches `publish`, WordPress queues a Mailchimp send for 10 minutes later (Action Scheduler). Mailchimp does not get a new campaign until that job runs. Use **Cancel send** or **Send immediately** in Campaign Setup during the wait. When the setting is off, publish only saves the WordPress post; use **Create Mailchimp draft** in Campaign Setup, then schedule or send in Mailchimp. **Send now** queues the same 10-minute send.
5. When auto-send is on, WordPress scheduling (`future` → `publish`) starts the 10-minute send window. When auto-send is off, authors can finish in Mailchimp or queue a send from Campaign Setup.

### Recovery and draft updates

If auto-dispatch fails, **Send now** in Campaign Setup calls
`POST /prc-email-builder/v1/campaigns/send` (queues create-and-send for 10
minutes). When **Automatically send on publish** is off, published
unlinked campaigns use **Create Mailchimp draft**
(`POST /prc-email-builder/v1/campaigns/create-draft`) which mints a Mailchimp
draft and does not send. After a draft exists, **Send now** is a separate
confirmed action that queues the same 10-minute window. While a send is
queued, Campaign Setup offers **Cancel send** and **Send immediately**.

While status is still `save`, **Update Mailchimp draft** can push revised content
via `POST /prc-email-builder/v1/campaigns/update-draft` with `{ "post_id": <id> }`.

Constraints (enforced server-side):

- A Mailchimp campaign must already exist (`prc_email_mailchimp_campaign_id` meta)
- Mailchimp campaign status must be `save` (draft) — sent/scheduled campaigns return `409`
- Stored audience and segment must still match the linked Mailchimp campaign
- Post must have renderable email HTML

### Unlinking and recovering a deleted Mailchimp draft

When the linked Mailchimp campaign is missing (deleted in Mailchimp or never created after publish), **Campaign Setup** shows a warning and offers **Unlink from Mailchimp**. Unlinking clears WordPress linkage meta (`prc_email_mailchimp_campaign_id`, admin URL, status, and any stored engagement report) but does **not** delete the remote Mailchimp campaign.

After unlink, **Create Mailchimp draft** appears for published, non-migrated campaigns. This calls `POST /prc-email-builder/v1/campaigns/create-draft` with `{ "post_id": <id> }` and mints a fresh Mailchimp draft from the current email HTML and audience settings.

| Action       | REST route                                                 | Notes                                                                                                                      |
| ------------ | ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| Unlink       | `POST /prc-email-builder/v1/campaigns/unlink`              | Idempotent; strong confirm when Mailchimp status is `sent`, `schedule`, or `sending`                                       |
| Create draft | `POST /prc-email-builder/v1/campaigns/create-draft`        | Requires `publish` status; returns `409` when still linked or when `Migration::is_migrated()`                              |
| Send now     | `POST /prc-email-builder/v1/campaigns/send`                | Queue create-and-send for 10 minutes; `immediate: true` skips the wait; `409 already_sent` when status is not empty/`save` |
| Cancel send  | `POST /prc-email-builder/v1/campaigns/cancel-delayed-send` | Unschedules the Action Scheduler job; `409 no_pending_send` when nothing is queued                                         |

Migrated NGL archive posts (`Migration::is_migrated()`) cannot create a new Mailchimp draft from this panel.

## Email Library

`Newsletters → Library` renders a `@wordpress/dataviews` table backed by
`GET /prc-email-builder/v1/library`. Filters:

| Query param                                      | Values                     | Notes                                        |
| ------------------------------------------------ | -------------------------- | -------------------------------------------- |
| `post_type`                                      | `all`, `campaign`, `txn`   | Campaign vs transactional emails             |
| `newsletter_list`                                | comma-separated term slugs | `prc_newsletter_list` taxonomy               |
| `mailchimp_status`                               | `campaign:<status>` values | e.g. `campaign:save`, `campaign:sent`        |
| `mandrill_status`                                | `txn:<status>` values      | Mandrill send status for transactional posts |
| `search`, `orderby`, `order`, `page`, `per_page` | standard                   | Pagination via `X-WP-Total` headers          |

Send-status filter labels are merged from Mailchimp and Mandrill option sets in
`Send_Status::library_filter_options()`.

## Newsletter lists

`prc_newsletter_list` taxonomy terms can store default Mailchimp targeting:

| Term meta key                     | Purpose                                               |
| --------------------------------- | ----------------------------------------------------- |
| `prc_newsletter_list_audience_id` | Default Mailchimp audience for campaigns on this list |
| `prc_newsletter_list_segment_id`  | Default saved segment within that audience            |

When a campaign is saved with one or more list terms assigned,
`Newsletter_List::override_campaign_audience_from_list()` (priority 9 on
`rest_after_insert_prc_email_campaign`):

- Keeps only the first assigned term if multiple are set
- Overwrites `prc_email_mailchimp_audience_id` and `prc_email_mailchimp_segment_id` post meta from the term

In the editor, audience/segment fields in **Campaign Setup** show "Locked by newsletter list" when
a list term drives the values. Term admin UI (`src/term-admin/`) loads segments
dynamically when the audience changes.

## System email audiences

Bulk transactional sends (`prc_email_txn` + `prc_email_delivery_mode = mandrill`) target a **recipient list** stored in `wp_options` as `prc_email_audience_{key}` with companion `{key}_meta` (label, count, `built_at`, source). The Transactional Setup sidebar lists available audiences built via WP-CLI or the Emails → Transactional **Build audience** wizard.

On **Emails → Transactional**, **Build audience** opens a hub of stored lists plus a builder picker (email domain, CSV upload, quiz group creators, dataset downloaders). CSV upload writes the list immediately from the file. Domain, quiz, and dataset builds can keep running after the dialog closes. The list appears in the catalog and recipient picker when the scan finishes. WordPress polls the job without exposing recipient addresses through REST. Operators can still run `wp prc email audience build-from-auth-domain` for domain matching, and quiz/dataset CLIs for those builders.

The Firebase functions are an operational dependency. Operators must deploy the enqueue HTTP functions and the RTDB worker with `firebase/bin/deploy-audience-functions.sh`. Installing or deploying this plugin does not deploy Cloud Functions.

Every dynamic system email send is also logged durably in `{prefix}prc_email_system_email_recipients` (`system_email_key`, `post_id`, `email`, first/last sent timestamps, send count). The key is the newsletter post slug.

### WP-CLI

```bash
# List / create / delete test audiences
wp prc email audience list
wp prc email audience create --emails=you@example.com,qa@example.com --key=qa --label="QA test"
wp prc email audience delete --key=qa --yes

# Build from Mandrill activity export (API or local CSV)
wp prc email audience build-from-mandrill \
  --key=typology-2026-requesters \
  --date-from=2026-05-01 \
  --label="Typology system-email requesters" \
  --dry-run

# Build from the durable send log (exact key, comma-separated, or prefix wildcard)
wp prc email audience build-from-log \
  --system-email-key=typology-2026-* \
  --key=typology-2026-requesters \
  --dry-run

# Build from Firebase Auth email domains (substring of the domain only, not the local-part).
# Calls buildEmailDomainAudience. Default verification is verified. Does not create a
# draft post unless --create-post. Deploy the CF first:
#   cd firebase && ./bin/deploy-audience-functions.sh staging /path/to/sa.json
wp prc email audience build-from-auth-domain --domain-contains=k12 --dry-run
wp prc email audience build-from-auth-domain \
  --domain-contains=k12 \
  --label="K-12 school domains (verified)"
wp prc email audience build-from-auth-domain --domain-contains=k12 --create-post

# Migrate legacy prc_email_system_email_key meta to post slugs
wp prc email migrate-system-keys --dry-run
wp prc email migrate-system-keys --dry-run=false
```

Both `build-from-mandrill` and `build-from-log` accept `--create-post` to draft a `prc_email_txn` newsletter pre-targeted at the new audience. `build-from-auth-domain` defaults to **not** creating a post (operators usually already have a transactional email); pass `--create-post` when you want a draft.

## Scheduled automations

Dynamic system emails (`prc_email_txn` + `prc_email_delivery_mode = dynamic`) can carry
a follow-up **automation**: when the initial email is sent to a recipient, the platform
schedules one or more follow-up messages after a configurable delay (e.g. 3 days, 7 days),
delivered in a fixed daily **send window**.

**Authoring.** The **Automations** collapsible panel in **Transactional Setup** (dynamic system emails only)
edits the `prc_email_automation_config` object meta:

```json
{
	"send_window": { "timezone": "America/New_York", "hour": 9, "minute": 0 },
	"steps": [
		{ "follow_up_post_id": 123, "delay_days": 3 },
		{
			"follow_up_post_id": 124,
			"delay_days": 7,
			"send_window": {
				"timezone": "America/New_York",
				"hour": 14,
				"minute": 0
			}
		}
	]
}
```

Each step references a published dynamic system email. Delays are **calendar days** relative
to the previous step's send (or the initial send for step 1).

**Send-window cascade** (most specific wins): per-step `send_window` → automation
`send_window` → site default (`prc_email_automation_default_send_window` option, set under
**Newsletters → Settings → Automations**, defaulting to 9:00 AM ET).

**Runtime.** Enrollment hangs off the `prc_email_builder_system_email_sent` action, so every
send path (REST, form action, batch) enrolls automatically when the trigger defines steps.
Rather than one Action Scheduler job per enrollment, a single recurring dispatcher
(`prc_email_automation_process_due`, every 15 minutes) processes every enrollment whose
computed `due_at` has passed, grouping recipients by render key (`follow_up_post_id` +
`context_hash`) so identical-render recipients batch into one `System_Email_Sender::send_many()`
call. Enrollments live in the `{prefix}prc_email_automation_enrollments` table.

Merge context is frozen at enrollment; the `prc_email_builder_automation_context` filter
allows opt-in re-resolution at send time. Re-enrolling the same recipient supersedes (cancels)
prior active enrollments, and unpublishing a follow-up template cancels enrollments pointed at it.

**Ops (WP-CLI).**

```bash
wp prc email automations list [--status=active|completed|cancelled] [--limit=<n>]
wp prc email automations cancel <enrollment-id>
wp prc email automations run-due [--limit=<n>]   # manually trigger the dispatcher
wp prc email first-day-stats <post_id>           # post Mailchimp first-day stats in Slack now (production)
```

## Architecture

| File                                                            | Purpose                                                                                                       |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `includes/class-post-type.php`                                  | Registers `prc_email_campaign` + `prc_email_txn` CPTs and `prc_newsletter_list` taxonomy; registers post meta |
| `includes/mailchimp/class-mailchimp.php`                        | Mailchimp API v3 wrapper; `create_linked_campaign_draft` and `create_and_send_campaign`                       |
| `includes/email/class-cached-email-html.php`                    | Shared helper that renders wrapped email HTML via `Email_Block_Converter`                                     |
| `includes/class-preview.php`                                    | REST endpoints for the Email Preview modal (`/preview`, `/test-send`)                                         |
| `includes/email/class-email-block-converter.php`                | Deterministic block-to-email-HTML conversion pipeline                                                         |
| `includes/class-settings.php`                                   | Admin settings page + REST endpoint (`/prc-email-builder/v1/settings`)                                        |
| `includes/class-library.php`                                    | Email Library admin page (`Newsletters → Library`)                                                            |
| `includes/class-newsletter-list.php`                            | Newsletter list term meta, archive preview pin, campaign audience override on save                            |
| `includes/class-latest-campaign-query.php`                      | Latest Newsletter Preview Query Loop: published-only, list archive scope, optional pinned campaign            |
| `includes/system-email/class-system-email-recipients-table.php` | Durable send log + audience builders from log data                                                            |
| `includes/cli/class-cli-audience.php`                           | `wp prc email audience *` commands                                                                            |
| `includes/cli/class-cli-system-key-migrate.php`                 | `wp prc email migrate-system-keys` — legacy key → slug migration                                              |
| `includes/cli/class-cli-first-day-stats.php`                    | `wp prc email first-day-stats` — run Mailchimp first-day Slack follow-up now                                  |
| `includes/mailchimp/class-first-day-campaign-stats.php`         | Schedule/deliver Mailchimp first-day stats in the campaign Slack thread                                       |
| `src/library/`                                                  | DataViews Email Library UI                                                                                    |
| `src/term-admin/`                                               | Term edit screen: Mailchimp fields, campaign pattern, archive preview campaign                                |
| `src/sidebar/send/`                                             | Campaign Setup / Transactional Setup plugin sidebar                                                           |
| `src/sidebar/campaign-mailchimp-settings.tsx`                   | Campaign list picker + Mailchimp overrides                                                                    |
| `src/sidebar/transactional-settings.tsx`                        | Transactional type, Mandrill audience, dynamic system email slug                                              |
| `includes/class-patterns.php`                                   | Auto-registers block patterns from `patterns/*.php`                                                           |
| `includes/class-quiz-email.php`                                 | REST endpoint for the quiz results email block                                                                |
| `includes/class-assets.php`                                     | Enqueues editor sidebar JS (injects `from_name`/`from_email` defaults into `prcEmailBuilderConfig`)           |
| `src/sidebar/`                                                  | Editor document panels (Email Settings, Email Content)                                                        |
| `src/sidebar/preview/`                                          | Email Preview View-menu item + modal (see below)                                                              |
| `src/settings/`                                                 | React settings page (Mailchimp connection, From Name/Email)                                                   |
| `src/form-action/`                                              | Registers the `sendSystemEmail` prc-block/form action in the editor                                           |
| `patterns/the-briefing.php`                                     | "The Briefing" starter template pattern                                                                       |

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

The preview renders email HTML synchronously via `Email_Block_Converter` — the same path used for Mailchimp sends, Mandrill sends, and test sends.

## REST endpoints

| Method     | Path                                                        | Description                                                    |
| ---------- | ----------------------------------------------------------- | -------------------------------------------------------------- |
| `GET`      | `/prc-email-builder/v1/connection`                          | Mailchimp connection status                                    |
| `GET`      | `/prc-email-builder/v1/audiences`                           | Available Mailchimp audiences                                  |
| `GET/POST` | `/prc-email-builder/v1/settings`                            | Read/write plugin settings                                     |
| `POST`     | `/prc-email-builder/v1/send-system-email`                   | Render + send a dynamic-recipient newsletter (editors)         |
| `POST`     | `/prc-api/v3/form/send-system-email`                        | prc-block/form action: send a system email to submitter        |
| `GET`      | `/prc-email-builder/v1/preview`                             | Render email HTML + metadata for the preview modal             |
| `POST`     | `/prc-email-builder/v1/test-send`                           | Send a test email via `wp_mail()`                              |
| `GET`      | `/prc-email-builder/v1/library`                             | Paginated email listing for the Email Library DataViews UI     |
| `GET`      | `/prc-email-builder/v1/campaigns/{id}/report`               | Stored Mailchimp engagement report for a campaign              |
| `POST`     | `/prc-email-builder/v1/campaigns/{id}/report/refresh`       | On-demand Mailchimp report pull (throttled)                    |
| `POST`     | `/prc-email-builder/v1/campaigns/update-draft`              | Push current HTML/settings to an existing Mailchimp draft      |
| `POST`     | `/prc-email-builder/v1/campaigns/create-draft`              | Create a Mailchimp draft (does not send)                       |
| `POST`     | `/prc-email-builder/v1/campaigns/send`                      | Queue a 10-minute Mailchimp send (`immediate` skips the wait)  |
| `POST`     | `/prc-email-builder/v1/campaigns/cancel-delayed-send`       | Cancel a queued Mailchimp send                                 |
| `POST`     | `/prc-email-builder/v1/campaigns/unlink`                    | Clear Mailchimp campaign linkage meta (recover deleted drafts) |
| `GET`      | `/prc-email-builder/v1/audiences/{id}/segments`             | Saved segments for a Mailchimp audience (term admin + sidebar) |
| `POST`     | `/prc-email-builder/v1/auth-domain-audiences`               | Start a Firebase Auth domain audience build                    |
| `GET`      | `/prc-email-builder/v1/auth-domain-audiences/{jobId}`       | Read progress and import a finished audience artifact          |
| `POST`     | `/prc-email-builder/v1/auth-domain-audiences/{jobId}/draft` | Create a transactional draft for a ready audience              |

### `sendSystemEmail` form action + Mailchimp opt-in

Forms using the **Send System Email** action (`POST /prc-api/v3/form/send-system-email`) resolve the target newsletter by:

- `newsletter_post_id` hidden field (post ID), or
- `system_email_key` hidden field matching the published dynamic newsletter's **post slug** (legacy meta keys are still resolved during migration)

Optionally include a `prc-block/form-input-checkbox` named `mailchimp_signup` (insert via the **Newsletter Signup** block variation). The checkbox `value` attribute holds the Mailchimp interest ID for the target segment.

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

# Run E2E tests (VIP dev-env required)
npm run vip:start
npm test -- tests/prc-email-builder/e2e/
```

## Tests

Playwright E2E specs live in the repo-root `tests/prc-email-builder/e2e/` directory
(centralized Playwright config — no per-plugin `playwright.config.js`):

| File                              | Coverage                                                                          |
| --------------------------------- | --------------------------------------------------------------------------------- |
| `newsletter-registration.spec.ts` | CPT, taxonomy, meta registration, REST endpoint shapes                            |
| `newsletter-editor.spec.ts`       | Sidebar panels, subject/preview persistence                                       |
| `system-email.spec.ts`            | Dynamic delivery key meta, `/send-system-email` + form action validation          |
| `newsletter-preview.spec.ts`      | `/preview` response shape, `/test-send` auth + validation, editor View-menu smoke |
