<?php
declare(strict_types=1);
/**
 * Mailchimp API v3 integration via the official mailchimp/marketing PHP SDK.
 *
 * The SDK is loaded through the Jetpack Autoloader (Shape B). At runtime the
 * highest registered version of mailchimp/marketing wins across all plugins
 * that ship it (prc-mailchimp mu-plugin + this plugin).
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use MailchimpMarketing\ApiClient;
use MailchimpMarketing\ApiException;
use WP_Error;

/**
 * Handles all communication with the Mailchimp API v3 via the official SDK.
 *
 * Credentials are read from the PRC_PLATFORM_MAILCHIMP_KEY constant
 * (set in wp-config.php) with a fallback to the prc_email_builder_settings
 * option. The REST API never exposes credentials.
 */
class Mailchimp {
	const SETTINGS_KEY                = 'prc_email_builder_settings';
	const API_KEY_CONSTANT            = 'PRC_PLATFORM_MAILCHIMP_KEY';
	const AUDIENCES_TRANSIENT         = 'prc_email_mailchimp_audiences';
	const SEGMENTS_TRANSIENT_PREFIX   = 'prc_email_mailchimp_segments_saved_';
	const LIST_TOTAL_TRANSIENT_PREFIX = 'prc_email_mailchimp_list_total_';
	/** Per audience+segment orphan count fallback (when segment missing from saved list). */
	const SEGMENT_COUNT_TRANSIENT_PREFIX = 'prc_email_mailchimp_segment_count_';
	/** Durable list of audience IDs that have been cached (invalidation aid). */
	const CACHED_AUDIENCE_IDS_OPTION = 'prc_email_mailchimp_cached_audience_ids';
	/** Registry of orphan segment-count transient keys (invalidation aid). */
	const SEGMENT_COUNT_KEYS_OPTION = 'prc_email_mailchimp_segment_count_keys';
	/**
	 * Options-table lock serializing durable registry read-modify-writes.
	 *
	 * Without this, concurrent get_option/update_option merges can drop
	 * audience IDs or segment-count keys; on VIP, invalidate then misses
	 * object-cache transients the SQL prefix wipe cannot see.
	 */
	const REGISTRY_LOCK_OPTION_PREFIX = 'prc_email_mailchimp_registry_lock_';
	const REGISTRY_LOCK_TTL           = 30;
	const REGISTRY_LOCK_RESOURCE_ID   = 1;
	/**
	 * Monotonic fence for Mailchimp metadata cache writes.
	 *
	 * Bumped under the registry lock at the start of invalidation. Cache-miss
	 * writers snapshot this before the API call and refuse to set_transient /
	 * update registries if the generation moved (credentials/settings changed).
	 */
	const CACHE_GENERATION_OPTION = 'prc_email_mailchimp_cache_generation';

	/**
	 * Options-table lock serializing create+send for one campaign post.
	 *
	 * Without this, overlapping auto-dispatch and recovery can each mint a
	 * Mailchimp campaign and/or call send, producing duplicate audience sends.
	 */
	const SEND_LOCK_OPTION_PREFIX = 'prc_email_mailchimp_send_lock_';
	const SEND_LOCK_TTL           = 180;

	/**
	 * Campaign post IDs that transitioned to `publish` during the current REST
	 * request. Send is deferred to `rest_after_insert` so taxonomy/targeting
	 * from the same request is applied first. Also gates auto-send so ordinary
	 * updates of an already-published (or unlinked) campaign do not dispatch.
	 *
	 * @var array<int, true>
	 */
	private array $pending_publish_dispatch = [];

	public function __construct( ?Loader $loader = null ) {
		if ( null === $loader ) {
			return;
		}
		$loader->add_action( 'rest_after_insert_' . Post_Type::CAMPAIGN_POST_TYPE, $this, 'on_rest_publish', 10, 1 );
		$loader->add_action( 'prc_email_builder_campaign_ready', $this, 'on_campaign_ready', 10, 2 );
		$loader->add_action( 'transition_post_status', $this, 'on_status_transition', 10, 3 );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Returns true if a Mailchimp API key is configured (does not make a network call).
	 */
	public function is_connected(): bool {
		return '' !== $this->get_api_key();
	}

	/**
	 * Returns all Mailchimp audiences as [ list_id => name ], sorted by name.
	 * Result is cached in a transient for 1 hour.
	 *
	 * @return array|WP_Error
	 */
	public function get_audiences(): array|WP_Error {
		$cached = get_transient( self::AUDIENCES_TRANSIENT );
		if ( false !== $cached ) {
			return $cached;
		}

		$generation = self::cache_generation();
		$client     = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$response = $client->lists->getAllLists( 'lists.id,lists.name', null, 1000 );
		} catch ( \Exception $e ) {
			return $this->to_wp_error( $e, 'mailchimp_audiences_error' );
		}

		$audiences = [];
		foreach ( $response->lists ?? [] as $list ) {
			$audiences[ $list->id ] = $list->name;
		}
		asort( $audiences );

		// Merge into the registry; do not replace — IDs registered via
		// remember_cached_audience_id() (segments / list-total) must survive.
		self::commit_mailchimp_cache_write(
			$generation,
			static function ( array &$rollback_keys ) use ( $audiences ): void {
				set_transient( self::AUDIENCES_TRANSIENT, $audiences, HOUR_IN_SECONDS );
				$rollback_keys[] = self::AUDIENCES_TRANSIENT;
				self::merge_cached_audience_ids_under_lock( array_keys( $audiences ) );
			}
		);

		return $audiences;
	}

	/**
	 * Returns saved segments for an audience, sorted by name.
	 *
	 * Only type "saved" is included. Static segments (tags) and fuzzy segments
	 * (ad-hoc campaign conditions) are excluded.
	 * Result is cached per audience for 1 hour.
	 *
	 * @param string $audience_id Mailchimp list (audience) ID.
	 * @return array|WP_Error Array of [ id, name, type, member_count ] maps.
	 */
	public function get_segments( string $audience_id ): array|WP_Error {
		if ( '' === $audience_id ) {
			return [];
		}

		$cache_key = self::SEGMENTS_TRANSIENT_PREFIX . md5( $audience_id );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return self::filter_saved_segments( $cached );
		}

		$generation = self::cache_generation();
		$client     = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$response = $client->lists->listSegments(
				$audience_id,
				'segments.id,segments.name,segments.type,segments.member_count',
				null,   // $exclude_fields
				1000,   // $count
				null,   // $offset
				'saved' // $type — exclude static (tags) and fuzzy segments
			);
		} catch ( \Exception $e ) {
			return $this->to_wp_error( $e, 'mailchimp_segments_error' );
		}

		$segments = [];
		foreach ( $response->segments ?? [] as $s ) {
			$type = (string) ( $s->type ?? '' );
			if ( 'saved' !== $type ) {
				continue;
			}
			$segments[] = [
				'id'           => (int) ( $s->id ?? 0 ),
				'name'         => (string) ( $s->name ?? '' ),
				'type'         => $type,
				'member_count' => (int) ( $s->member_count ?? 0 ),
			];
		}
		usort( $segments, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

		self::commit_mailchimp_cache_write(
			$generation,
			static function ( array &$rollback_keys ) use ( $cache_key, $segments, $audience_id ): void {
				set_transient( $cache_key, $segments, HOUR_IN_SECONDS );
				$rollback_keys[] = $cache_key;
				self::merge_cached_audience_ids_under_lock( [ $audience_id ] );
			}
		);

		return self::filter_saved_segments( $segments );
	}

	/**
	 * Keep only Mailchimp saved segments (excludes static/tag and fuzzy entries).
	 *
	 * @param array<int, array<string, mixed>> $segments Segment rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_saved_segments( array $segments ): array {
		return array_values(
			array_filter(
				$segments,
				static fn( array $segment ): bool => 'saved' === (string) ( $segment['type'] ?? '' )
			)
		);
	}

	/**
	 * Returns the total subscribed member count for a Mailchimp audience (list).
	 * Result is cached per audience for 1 hour.
	 *
	 * @param string $audience_id Mailchimp list (audience) ID.
	 * @return int|WP_Error
	 */
	public function get_list_total_count( string $audience_id ): int|WP_Error {
		if ( '' === $audience_id ) {
			return 0;
		}

		$cache_key = self::LIST_TOTAL_TRANSIENT_PREFIX . md5( $audience_id );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_numeric( $cached ) ) {
			return (int) $cached;
		}

		$generation = self::cache_generation();
		$client     = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$response = $client->lists->getList( $audience_id, 'stats.member_count' );
		} catch ( \Exception $e ) {
			return $this->to_wp_error( $e, 'mailchimp_list_total_error' );
		}

		$count = (int) ( $response->stats->member_count ?? 0 );
		self::commit_mailchimp_cache_write(
			$generation,
			static function ( array &$rollback_keys ) use ( $cache_key, $count, $audience_id ): void {
				set_transient( $cache_key, $count, HOUR_IN_SECONDS );
				$rollback_keys[] = $cache_key;
				self::merge_cached_audience_ids_under_lock( [ $audience_id ] );
			}
		);

		return $count;
	}

	/**
	 * Returns subscriber count for an audience, optionally scoped to a saved segment.
	 *
	 * @param string $audience_id Mailchimp list (audience) ID.
	 * @param string $segment_id  Mailchimp saved-segment ID; empty = entire audience.
	 * @return int|WP_Error
	 */
	public function get_subscriber_count( string $audience_id, string $segment_id = '' ): int|WP_Error {
		if ( '' === $audience_id ) {
			return 0;
		}

		if ( '' !== $segment_id ) {
			$segments = $this->get_segments( $audience_id );
			if ( is_wp_error( $segments ) ) {
				return $segments;
			}

			$segment_id_int = (int) $segment_id;
			foreach ( $segments as $segment ) {
				if ( $segment_id_int === (int) ( $segment['id'] ?? 0 ) ) {
					return (int) ( $segment['member_count'] ?? 0 );
				}
			}

			// Orphan / non-saved / stale-list miss: cache the live getSegment()
			// count so repeated admin reads do not re-hit Mailchimp every time.
			$count_key = self::SEGMENT_COUNT_TRANSIENT_PREFIX . md5( $audience_id . ':' . $segment_id );
			$cached    = get_transient( $count_key );
			if ( false !== $cached && is_numeric( $cached ) ) {
				return (int) $cached;
			}

			$generation = self::cache_generation();
			$client     = $this->get_client();
			if ( is_wp_error( $client ) ) {
				return $client;
			}

			try {
				$response = $client->lists->getSegment(
					$audience_id,
					$segment_id,
					'member_count'
				);
			} catch ( \Exception $e ) {
				return $this->to_wp_error( $e, 'mailchimp_segment_count_error' );
			}

			$count = (int) ( $response->member_count ?? 0 );
			self::commit_mailchimp_cache_write(
				$generation,
				static function ( array &$rollback_keys ) use ( $count_key, $count ): void {
					set_transient( $count_key, $count, HOUR_IN_SECONDS );
					$rollback_keys[] = $count_key;
					self::remember_segment_count_key_under_lock( $count_key );
				}
			);
			return $count;
		}

		return $this->get_list_total_count( $audience_id );
	}

	/**
	 * Creates a Mailchimp campaign draft for a newsletter post and sets its HTML
	 * content. When prc_email_mailchimp_segment_id is set, restricts the
	 * recipients to that saved segment. Returns the Mailchimp campaign ID on success.
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $html    Email-safe HTML from the content transformer.
	 * @return array{ campaign_id: string, admin_url: string }|WP_Error
	 */
	public function create_campaign_draft( int $post_id, string $html ): array|WP_Error {
		$targeting    = Newsletter_List::sync_mailchimp_targeting_meta( $post_id );
		$audience_id  = $targeting['audience_id'];
		$segment_id   = (int) $targeting['segment_id'];
		$ready        = Email_Subject::require_for_send( $post_id );
		$preview_text = get_post_meta( $post_id, 'prc_email_preview_text', true );
		$from         = self::resolve_from_for_post( $post_id );

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( empty( $audience_id ) ) {
			return new WP_Error( 'missing_audience', 'No Mailchimp audience selected for this newsletter.' );
		}

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$recipients = self::build_recipients( $audience_id, $segment_id );

		try {
			$campaign = $client->campaigns->create( [
				'type'       => 'regular',
				'recipients' => $recipients,
				'settings'   => [
					'title'        => get_the_title( $post_id ),
					'subject_line' => $ready->line(),
					'preview_text' => $preview_text,
					'from_name'    => $from['from_name'],
					'reply_to'     => $from['from_email'],
					'auto_footer'  => false,
				],
			] );
		} catch ( \Exception $e ) {
			return $this->to_wp_error( $e, 'mailchimp_campaign_create_error' );
		}

		$campaign_id = $campaign->id ?? '';
		if ( '' === $campaign_id ) {
			return new WP_Error( 'campaign_create_failed', 'Mailchimp did not return a campaign ID.' );
		}

		try {
			$client->campaigns->setContent( $campaign_id, [ 'html' => $html ] );
		} catch ( \Exception $e ) {
			return $this->to_wp_error( $e, 'mailchimp_campaign_content_error' );
		}

		$web_id = (int) ( $campaign->web_id ?? 0 );

		return [
			'campaign_id' => $campaign_id,
			'admin_url'   => self::build_campaign_admin_url( $web_id ),
		];
	}

	/**
	 * Overwrites HTML content and settings on an existing Mailchimp draft campaign.
	 *
	 * Only campaigns in "save" (draft) status can be updated. Sent, scheduled,
	 * and in-flight campaigns are rejected.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return array{ campaign_id: string, admin_url: string, status: string }|WP_Error
	 */
	public function update_campaign_draft( int $post_id ): array|WP_Error {
		$campaign_id = (string) get_post_meta( $post_id, 'prc_email_mailchimp_campaign_id', true );
		if ( '' === $campaign_id ) {
			return new WP_Error(
				'no_campaign',
				'No Mailchimp campaign exists for this newsletter.',
				[ 'status' => 400 ]
			);
		}

		$campaign = $this->get_campaign( $campaign_id );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		$status = (string) ( $campaign['status'] ?? '' );
		if ( 'save' !== $status ) {
			return new WP_Error(
				'campaign_not_editable',
				sprintf(
					'Mailchimp campaign cannot be edited while status is "%s".',
					$status ?: 'unknown'
				),
				[ 'status' => 409 ]
			);
		}

		$targeting   = Newsletter_List::sync_mailchimp_targeting_meta( $post_id );
		$audience_id = $targeting['audience_id'];
		$segment_id  = (int) $targeting['segment_id'];
		if ( '' === $audience_id ) {
			return new WP_Error(
				'missing_audience',
				'No Mailchimp audience selected for this newsletter.',
				[ 'status' => 400 ]
			);
		}

		$html = Cached_Email_Html::resolve( $post_id );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		if ( '' === $html ) {
			return new WP_Error(
				'empty_content',
				'Newsletter has no renderable email content.',
				[ 'status' => 400 ]
			);
		}

		$ready        = Email_Subject::require_for_send( $post_id );
		$preview_text = get_post_meta( $post_id, 'prc_email_preview_text', true );
		$from         = self::resolve_from_for_post( $post_id );

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$client->campaigns->update(
				$campaign_id,
				[
					'recipients' => self::build_recipients( $audience_id, $segment_id, true ),
					'settings'   => [
						'title'        => get_the_title( $post_id ),
						'subject_line' => $ready->line(),
						'preview_text' => $preview_text,
						'from_name'    => $from['from_name'],
						'reply_to'     => $from['from_email'],
					],
				]
			);
		} catch ( \Exception $e ) {
			return $this->to_wp_error( $e, 'mailchimp_campaign_update_error' );
		}

		try {
			$client->campaigns->setContent( $campaign_id, [ 'html' => $html ] );
		} catch ( \Exception $e ) {
			return $this->to_wp_error( $e, 'mailchimp_campaign_content_error' );
		}

		update_post_meta( $post_id, 'prc_email_mailchimp_campaign_status', 'save' );

		$admin_url = (string) get_post_meta( $post_id, 'prc_email_mailchimp_campaign_admin_url', true );

		return [
			'campaign_id' => $campaign_id,
			'admin_url'   => $admin_url ?: 'https://admin.mailchimp.com/campaigns/',
			'status'      => 'save',
		];
	}

	/**
	 * Mailchimp admin URL to edit a campaign draft.
	 *
	 * @param int $web_id Mailchimp campaign web_id from the create response.
	 * @return string
	 */
	public static function build_campaign_admin_url( int $web_id ): string {
		$fallback = 'https://admin.mailchimp.com/campaigns/';
		if ( $web_id <= 0 ) {
			return $fallback;
		}

		$dc = self::get_mailchimp_data_center();
		if ( '' === $dc ) {
			return $fallback;
		}

		return sprintf(
			'https://%s.admin.mailchimp.com/campaigns/edit?id=%d',
			$dc,
			$web_id
		);
	}

	/**
	 * Data-center suffix from the Mailchimp API key (e.g. "us21").
	 *
	 * @return string
	 */
	public static function get_mailchimp_data_center(): string {
		$key = '';
		if ( defined( self::API_KEY_CONSTANT ) ) {
			$key = (string) constant( self::API_KEY_CONSTANT );
		}
		if ( '' === $key ) {
			$settings = self::get_settings();
			$key      = (string) ( $settings['mailchimp_api_key'] ?? '' );
		}
		if ( '' === $key ) {
			return '';
		}

		if ( preg_match( '/-([a-z0-9]+)$/i', $key, $matches ) ) {
			return strtolower( $matches[1] );
		}

		return '';
	}

	/**
	 * Persists Mailchimp campaign ID, admin URL, and cached status on a newsletter post.
	 *
	 * @param int    $post_id     Newsletter post ID.
	 * @param string $campaign_id Mailchimp campaign ID.
	 * @param string $admin_url   Mailchimp admin edit URL.
	 * @param string $status      Cached Mailchimp status (`save`, `sending`, …).
	 */
	public static function persist_campaign_meta(
		int $post_id,
		string $campaign_id,
		string $admin_url,
		string $status = 'save'
	): void {
		update_post_meta( $post_id, 'prc_email_mailchimp_campaign_id', $campaign_id );
		update_post_meta( $post_id, 'prc_email_mailchimp_campaign_status', sanitize_text_field( $status ) );
		update_post_meta( $post_id, 'prc_email_mailchimp_campaign_admin_url', esc_url_raw( $admin_url ) );
	}

	/**
	 * Create a Mailchimp campaign (when unlinked) and send it to the configured segment.
	 *
	 * Idempotent pipeline:
	 * 1. Guards (campaign CPT, published, not migrated, connected).
	 * 2. Acquire a per-post send lock; re-read meta under the lock.
	 * 3. Ensure a Mailchimp campaign exists (create when unlinked).
	 * 4. Send when status is empty/`save`. Never re-send `sending`/`sent`/`schedule`.
	 * 5. Persist meta with status `sending` on success.
	 *
	 * Auto hooks pass `$retry_existing_draft = false` so a failed send is not
	 * retried on every subsequent REST update (autosave). Recovery REST passes
	 * true to send an existing draft. Concurrent callers receive `send_locked`.
	 *
	 * @param int  $post_id              Campaign post ID.
	 * @param bool $retry_existing_draft When true, send an already-linked draft (`save`).
	 * @return array{ campaign_id: string, admin_url: string, status: string }|WP_Error
	 */
	public function create_and_send_campaign( int $post_id, bool $retry_existing_draft = false ): array|WP_Error {
		if ( ! Post_Type::is_campaign_post( $post_id ) ) {
			return new WP_Error(
				'invalid_post_type',
				'Mailchimp send applies only to campaign newsletters.',
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'not_published',
				'Publish the campaign before sending to Mailchimp.',
				[ 'status' => 400 ]
			);
		}

		if ( Migration::is_migrated( $post_id ) ) {
			return new WP_Error(
				'migrated_campaign',
				'Migrated campaigns cannot be sent to Mailchimp from this panel.',
				[ 'status' => 400 ]
			);
		}

		if ( ! $this->is_connected() ) {
			return new WP_Error(
				'mailchimp_not_connected',
				'Mailchimp is not connected.',
				[ 'status' => 400 ]
			);
		}

		$lock  = new Option_Lock( self::SEND_LOCK_OPTION_PREFIX, self::SEND_LOCK_TTL, 'mc_send_' );
		$token = $lock->acquire( $post_id );
		if ( '' === $token ) {
			return new WP_Error(
				'send_locked',
				'A Mailchimp send is already in progress for this campaign.',
				[ 'status' => 409 ]
			);
		}

		try {
			return $this->create_and_send_campaign_locked( $post_id, $retry_existing_draft );
		} finally {
			$lock->release( $post_id, $token );
		}
	}

	/**
	 * Create/send pipeline. Caller must hold the per-post send lock.
	 *
	 * Meta is read here so a loser of the race cannot create or send from a
	 * stale empty/`save` snapshot taken before the lock was acquired.
	 *
	 * @param int  $post_id              Campaign post ID.
	 * @param bool $retry_existing_draft When true, send an already-linked draft (`save`).
	 * @return array{ campaign_id: string, admin_url: string, status: string }|WP_Error
	 */
	private function create_and_send_campaign_locked( int $post_id, bool $retry_existing_draft ): array|WP_Error {
		$campaign_id = (string) get_post_meta( $post_id, 'prc_email_mailchimp_campaign_id', true );
		$admin_url   = (string) get_post_meta( $post_id, 'prc_email_mailchimp_campaign_admin_url', true );
		$status      = (string) get_post_meta( $post_id, 'prc_email_mailchimp_campaign_status', true );

		if ( '' !== $campaign_id && ! in_array( $status, [ '', 'save' ], true ) ) {
			return [
				'campaign_id' => $campaign_id,
				'admin_url'   => $admin_url ?: 'https://admin.mailchimp.com/campaigns/',
				'status'      => $status,
			];
		}

		if ( '' !== $campaign_id && ! $retry_existing_draft ) {
			return [
				'campaign_id' => $campaign_id,
				'admin_url'   => $admin_url ?: 'https://admin.mailchimp.com/campaigns/',
				'status'      => $status ?: 'save',
			];
		}

		if ( '' === $campaign_id ) {
			$html = Cached_Email_Html::resolve( $post_id );
			if ( is_wp_error( $html ) ) {
				return $html;
			}
			if ( '' === $html ) {
				return new WP_Error(
					'missing_email_html',
					'Email HTML is not ready. Generate email content before sending to Mailchimp.',
					[ 'status' => 400 ]
				);
			}

			$result = $this->create_campaign_draft( $post_id, $html );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$campaign_id = $result['campaign_id'];
			$admin_url   = $result['admin_url'];
			self::persist_campaign_meta( $post_id, $campaign_id, $admin_url, 'save' );
		}

		$send = $this->send_campaign( $campaign_id );
		if ( is_wp_error( $send ) ) {
			return $send;
		}

		$admin_url = $admin_url ?: 'https://admin.mailchimp.com/campaigns/';
		self::persist_campaign_meta( $post_id, $campaign_id, $admin_url, 'sending' );

		return [
			'campaign_id' => $campaign_id,
			'admin_url'   => $admin_url,
			'status'      => 'sending',
		];
	}

	/**
	 * Fetches the current status of a Mailchimp campaign.
	 *
	 * @param string $campaign_id Mailchimp campaign ID.
	 * @return array|WP_Error
	 */
	public function get_campaign( string $campaign_id ): array|WP_Error {
		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$response = $client->campaigns->get( $campaign_id );
		} catch ( \Exception $e ) {
			return $this->to_wp_error( $e, 'mailchimp_campaign_get_error' );
		}

		return (array) $response;
	}

	/**
	 * Fetches aggregate campaign report metrics (no member sub-resources).
	 *
	 * @param string $campaign_id Mailchimp campaign ID.
	 * @return array|WP_Error
	 */
	public function get_campaign_report( string $campaign_id ): array|WP_Error {
		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$fields = 'emails_sent,send_time,opens,clicks,bounces,unsubscribed,abuse_reports';

		try {
			$response = $client->reports->getCampaignReport( $campaign_id, $fields );
		} catch ( \Exception $e ) {
			return $this->to_wp_error( $e, 'mailchimp_report_error' );
		}

		return (array) $response;
	}

	/**
	 * Fetches aggregate click-by-URL details (no member sub-resources).
	 *
	 * @param string $campaign_id Mailchimp campaign ID.
	 * @param int    $count       Max URLs to request from Mailchimp.
	 * @return array|WP_Error
	 */
	public function get_campaign_click_details( string $campaign_id, int $count = 100 ): array|WP_Error {
		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$count = max( 1, min( 1000, $count ) );
		$fields = 'urls_clicked.url,urls_clicked.total_clicks';

		try {
			$response = $client->reports->getCampaignClickDetails( $campaign_id, $fields, null, $count );
		} catch ( \Exception $e ) {
			return $this->to_wp_error( $e, 'mailchimp_click_details_error' );
		}

		return (array) $response;
	}

	/**
	 * Subscribes an email address to a Mailchimp audience.
	 * Uses the members upsert endpoint so re-subscribing is safe.
	 *
	 * @param string $email       Email address.
	 * @param string $audience_id Mailchimp list (audience) ID.
	 * @return true|WP_Error
	 */
	public function subscribe_email( string $email, string $audience_id ): true|WP_Error {
		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$hash = md5( strtolower( trim( $email ) ) );

		try {
			$client->lists->setListMember(
				$audience_id,
				$hash,
				[
					'email_address' => $email,
					'status_if_new' => 'subscribed',
					'status'        => 'subscribed',
				]
			);
		} catch ( \Exception $e ) {
			return $this->to_wp_error( $e, 'mailchimp_subscribe_error' );
		}

		return true;
	}

	/**
	 * Sends a Mailchimp campaign. Use with caution — this cannot be undone.
	 *
	 * @param string $campaign_id Mailchimp campaign ID.
	 * @return true|WP_Error
	 */
	public function send_campaign( string $campaign_id ): true|WP_Error {
		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$client->campaigns->send( $campaign_id );
		} catch ( \Exception $e ) {
			return $this->to_wp_error( $e, 'mailchimp_campaign_send_error' );
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Settings helpers (no REST exposure)
	// -------------------------------------------------------------------------

	/**
	 * Resolve From name/email for a campaign, preferring the assigned newsletter list term meta.
	 *
	 * @param int $post_id Campaign post ID.
	 * @return array{ from_name: string, from_email: string }
	 */
	public static function resolve_from_for_post( int $post_id ): array {
		$settings   = self::get_settings();
		$from_name  = (string) ( $settings['from_name'] ?? '' );
		$from_email = (string) ( $settings['from_email'] ?? '' );

		$term_ids = wp_get_object_terms(
			$post_id,
			Post_Type::TAXONOMY,
			[
				'fields' => 'ids',
			]
		);

		if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
			return [
				'from_name'  => $from_name,
				'from_email' => $from_email,
			];
		}

		$term_id         = (int) $term_ids[0];
		$list_from_name  = (string) get_term_meta( $term_id, 'prc_newsletter_list_from_name', true );
		$list_from_email = (string) get_term_meta( $term_id, 'prc_newsletter_list_from_email', true );

		if ( '' !== $list_from_name ) {
			$from_name = $list_from_name;
		}
		if ( '' !== $list_from_email && is_email( $list_from_email ) ) {
			$from_email = $list_from_email;
		}

		return [
			'from_name'  => $from_name,
			'from_email' => $from_email,
		];
	}

	/**
	 * Returns saved settings merged with defaults.
	 */
	public static function get_settings(): array {
		$defaults = [
			'mailchimp_api_key'    => '',
			'from_name'            => '',
			'from_email'           => '',
			'track_opens'          => true,
			'track_clicks'         => true,
			'reply_to'             => '',
			'mandrill_subaccount'  => '',
			'mandrill_tags'        => [ 'prc-newsletter' ],
			'auto_send_on_publish' => true,
		];
		$saved = get_option( self::SETTINGS_KEY, [] );
		return wp_parse_args( $saved, $defaults );
	}

	public static function is_auto_send_on_publish_enabled(): bool {
		return (bool) self::get_settings()['auto_send_on_publish'];
	}

	/**
	 * Persists settings. Strips the API key from the array before saving if
	 * the constant is defined — the constant is always authoritative.
	 */
	public static function save_settings( array $settings ): void {
		$tags = $settings['mandrill_tags'] ?? [];
		if ( is_string( $tags ) ) {
			$tags = preg_split( '/[\s,]+/', $tags, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		}
		$tags = is_array( $tags ) ? $tags : [];
		$tags = array_values(
			array_filter(
				array_map( 'sanitize_key', $tags ),
				static fn( string $tag ): bool => '' !== $tag
			)
		);

		$sanitized = [
			'mailchimp_api_key'    => defined( self::API_KEY_CONSTANT ) ? '' : sanitize_text_field( $settings['mailchimp_api_key'] ?? '' ),
			'from_name'            => sanitize_text_field( $settings['from_name'] ?? '' ),
			'from_email'           => sanitize_email( $settings['from_email'] ?? '' ),
			'track_opens'          => filter_var( $settings['track_opens'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			'track_clicks'         => filter_var( $settings['track_clicks'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			'reply_to'             => sanitize_email( $settings['reply_to'] ?? '' ),
			'mandrill_subaccount'  => sanitize_text_field( $settings['mandrill_subaccount'] ?? '' ),
			'mandrill_tags'        => $tags ?: [ 'prc-newsletter' ],
			'auto_send_on_publish' => filter_var(
				array_key_exists( 'auto_send_on_publish', $settings )
					? $settings['auto_send_on_publish']
					: self::get_settings()['auto_send_on_publish'],
				FILTER_VALIDATE_BOOLEAN
			),
		];
		update_option( self::SETTINGS_KEY, $sanitized, false );
		self::invalidate_mailchimp_caches();
	}

	/**
	 * Drop Mailchimp metadata transients after credentials/settings change.
	 *
	 * Uses delete_transient() so object-cache-backed installs (VIP) clear
	 * correctly. Audience IDs and orphan segment-count keys are remembered
	 * when those caches are written so invalidation stays targeted.
	 *
	 * Holds the registry lock for the full clear and bumps the cache
	 * generation fence first so in-flight API fetches cannot re-seed object
	 * cache after this returns. Never force-clears a fresh lock — that would
	 * steal from an in-progress commit that already passed the generation
	 * check and let it reseed after invalidation.
	 */
	public static function invalidate_mailchimp_caches(): void {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$result = self::with_registry_lock(
				static function (): true {
					self::clear_mailchimp_caches_under_lock();
					return true;
				}
			);
			if ( true === $result ) {
				return;
			}
		}

		// Could not obtain the registry lock (live holder kept winning the
		// wait window). Bump the fence so in-flight commits re-check and roll
		// back, then try one final clear. Do not Option_Lock::clear() a fresh
		// lock — that breaks mutual exclusion with the holder.
		update_option( self::CACHE_GENERATION_OPTION, self::cache_generation() + 1, false );
		self::with_registry_lock(
			static function (): true {
				self::clear_mailchimp_caches_under_lock();
				return true;
			}
		);
	}

	/**
	 * Bump the generation fence and delete Mailchimp metadata caches.
	 *
	 * Caller must hold the registry lock.
	 */
	private static function clear_mailchimp_caches_under_lock(): void {
		self::bump_cache_generation_under_lock();

		$audiences = get_transient( self::AUDIENCES_TRANSIENT );
		delete_transient( self::AUDIENCES_TRANSIENT );

		// Always union audiences keys with the durable registry. The registry
		// is a superset (segment/list-total IDs may be absent from audiences),
		// so preferring a warm audiences transient alone would skip those IDs.
		$audience_ids = [];
		if ( is_array( $audiences ) ) {
			$audience_ids = array_keys( $audiences );
		}
		$stored = get_option( self::CACHED_AUDIENCE_IDS_OPTION, [] );
		if ( is_array( $stored ) ) {
			$audience_ids = array_unique( array_merge( $audience_ids, $stored ) );
		}

		foreach ( $audience_ids as $audience_id ) {
			$audience_id = (string) $audience_id;
			if ( '' === $audience_id ) {
				continue;
			}
			delete_transient( self::SEGMENTS_TRANSIENT_PREFIX . md5( $audience_id ) );
			delete_transient( self::LIST_TOTAL_TRANSIENT_PREFIX . md5( $audience_id ) );
		}
		delete_option( self::CACHED_AUDIENCE_IDS_OPTION );

		$segment_count_keys = get_option( self::SEGMENT_COUNT_KEYS_OPTION, [] );
		if ( is_array( $segment_count_keys ) ) {
			foreach ( array_keys( $segment_count_keys ) as $key ) {
				delete_transient( (string) $key );
			}
		}
		delete_option( self::SEGMENT_COUNT_KEYS_OPTION );

		// Belt-and-suspenders for installs that persist transients in options.
		self::delete_mailchimp_transients_by_prefix(
			[
				self::SEGMENTS_TRANSIENT_PREFIX,
				self::LIST_TOTAL_TRANSIENT_PREFIX,
				self::SEGMENT_COUNT_TRANSIENT_PREFIX,
			]
		);
	}

	/**
	 * Current Mailchimp metadata cache generation (invalidation fence).
	 */
	private static function cache_generation(): int {
		return (int) get_option( self::CACHE_GENERATION_OPTION, 0 );
	}

	/**
	 * Bump the cache generation. Caller must hold the registry lock.
	 */
	private static function bump_cache_generation_under_lock(): void {
		update_option( self::CACHE_GENERATION_OPTION, self::cache_generation() + 1, false );
	}

	/**
	 * Persist a Mailchimp metadata cache write if still on the same generation.
	 *
	 * Acquires the registry lock, compares $generation to the durable fence,
	 * runs set_transient + registry updates, then re-checks the fence. If the
	 * generation moved (invalidation won, or a last-resort fence bump), any
	 * transients the writer recorded in $rollback_keys are deleted before
	 * returning false. Callers still return fresh API data either way.
	 *
	 * @param int      $generation Generation snapshot from before the API call.
	 * @param callable $writer     `function ( array &$rollback_keys ): void`.
	 *                             Must push each set_transient key onto
	 *                             $rollback_keys before updating registries.
	 */
	private static function commit_mailchimp_cache_write( int $generation, callable $writer ): bool {
		$result = self::with_registry_lock(
			static function () use ( $generation, $writer ) {
				if ( self::cache_generation() !== $generation ) {
					return false;
				}
				$rollback_keys = [];
				$writer( $rollback_keys );
				if ( self::cache_generation() !== $generation ) {
					foreach ( $rollback_keys as $key ) {
						delete_transient( (string) $key );
					}
					return false;
				}
				return true;
			}
		);
		return true === $result;
	}

	/**
	 * Remember an audience ID that has per-audience Mailchimp caches.
	 *
	 * @param string $audience_id Mailchimp list (audience) ID.
	 */
	private static function remember_cached_audience_id( string $audience_id ): void {
		if ( '' === $audience_id ) {
			return;
		}
		self::with_registry_lock(
			static function () use ( $audience_id ): void {
				self::merge_cached_audience_ids_under_lock( [ $audience_id ] );
			}
		);
	}

	/**
	 * Union audience IDs into the durable invalidation registry.
	 *
	 * @param array<int|string, mixed> $audience_ids Audience IDs to remember.
	 */
	private static function merge_cached_audience_ids( array $audience_ids ): void {
		self::with_registry_lock(
			static function () use ( $audience_ids ): void {
				self::merge_cached_audience_ids_under_lock( $audience_ids );
			}
		);
	}

	/**
	 * Union audience IDs into the registry. Caller must hold the registry lock.
	 *
	 * @param array<int|string, mixed> $audience_ids Audience IDs to remember.
	 */
	private static function merge_cached_audience_ids_under_lock( array $audience_ids ): void {
		$incoming = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $audience_ids ),
					static fn( string $id ): bool => '' !== $id
				)
			)
		);
		if ( [] === $incoming ) {
			return;
		}

		$stored = get_option( self::CACHED_AUDIENCE_IDS_OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$merged = array_values(
			array_unique(
				array_filter(
					array_merge(
						array_map( 'strval', $stored ),
						$incoming
					),
					static fn( string $id ): bool => '' !== $id
				)
			)
		);
		update_option( self::CACHED_AUDIENCE_IDS_OPTION, $merged, false );
	}

	/**
	 * Remember an orphan segment-count transient key for later invalidation.
	 *
	 * @param string $key Transient key.
	 */
	private static function remember_segment_count_key( string $key ): void {
		if ( '' === $key ) {
			return;
		}
		self::with_registry_lock(
			static function () use ( $key ): void {
				self::remember_segment_count_key_under_lock( $key );
			}
		);
	}

	/**
	 * Remember an orphan segment-count key. Caller must hold the registry lock.
	 *
	 * @param string $key Transient key.
	 */
	private static function remember_segment_count_key_under_lock( string $key ): void {
		if ( '' === $key ) {
			return;
		}
		$keys = get_option( self::SEGMENT_COUNT_KEYS_OPTION, [] );
		if ( ! is_array( $keys ) ) {
			$keys = [];
		}
		if ( isset( $keys[ $key ] ) ) {
			return;
		}
		$keys[ $key ] = 1;
		update_option( self::SEGMENT_COUNT_KEYS_OPTION, $keys, false );
	}

	/**
	 * Serialize durable registry + cache-write mutations via Option_Lock.
	 *
	 * Spins until the lock is acquired or the wait exceeds the lock TTL so a
	 * crashed holder can be reclaimed. Never runs $callback unlocked — that
	 * reintroduces the lost-registry race this lock exists to prevent.
	 *
	 * @template T
	 * @param callable(): T $callback Critical section.
	 * @return T|null Callback result, or null when the lock could not be acquired.
	 */
	private static function with_registry_lock( callable $callback ) {
		$lock  = new Option_Lock( self::REGISTRY_LOCK_OPTION_PREFIX, self::REGISTRY_LOCK_TTL, 'mc_reg_' );
		$token = '';
		// Wait longer than REGISTRY_LOCK_TTL so an abandoned lock can expire
		// and be reclaimed (50ms * 700 ≈ 35s > 30s TTL).
		for ( $attempt = 0; $attempt < 700; ++$attempt ) {
			$token = $lock->acquire( self::REGISTRY_LOCK_RESOURCE_ID );
			if ( '' !== $token ) {
				break;
			}
			usleep( 50000 );
		}

		if ( '' === $token ) {
			return null;
		}

		try {
			return $callback();
		} finally {
			$lock->release( self::REGISTRY_LOCK_RESOURCE_ID, $token );
		}
	}

	/**
	 * Delete options-table transient rows matching known Mailchimp cache prefixes.
	 *
	 * Admin/settings path only — not used on steady-state reads. Complements
	 * delete_transient() when transients are stored in the options table.
	 *
	 * @param array<int, string> $prefixes Transient key prefixes (without _transient_).
	 */
	private static function delete_mailchimp_transients_by_prefix( array $prefixes ): void {
		global $wpdb;

		foreach ( $prefixes as $prefix ) {
			if ( '' === $prefix || ! str_starts_with( $prefix, 'prc_email_mailchimp_' ) ) {
				continue;
			}
			$like         = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
			$timeout_like = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$like,
					$timeout_like
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// WordPress hooks
	// -------------------------------------------------------------------------

	/**
	 * When a campaign transitions to publish via REST, create and send after
	 * `rest_after_insert` (taxonomies + newsletter-list targeting sync first).
	 *
	 * Only runs when `on_status_transition` marked this post for the current
	 * request — not on ordinary updates of an already-published campaign.
	 *
	 * @hook rest_after_insert_{post_type}
	 */
	public function on_rest_publish( \WP_Post $post ): void {
		if ( ! isset( $this->pending_publish_dispatch[ $post->ID ] ) ) {
			return;
		}
		unset( $this->pending_publish_dispatch[ $post->ID ] );

		if ( ! Post_Type::is_campaign_post( $post ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$this->dispatch_auto_send( $post->ID, 'post' );
	}

	/**
	 * Create and send a Mailchimp campaign when email HTML is supplied externally.
	 *
	 * @action prc_email_builder_campaign_ready
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $html    Full email HTML document (unused; resolved from the post).
	 */
	public function on_campaign_ready( int $post_id, string $html ): void {
		unset( $html );

		$this->dispatch_auto_send( $post_id, 'post' );
	}

	/**
	 * Track or dispatch Mailchimp send when a campaign becomes published.
	 *
	 * REST: mark for `on_rest_publish` so targeting from the same request is
	 * applied before create/send (covers draft→publish and future→publish now).
	 * Non-REST: only `future` → `publish` (cron) sends immediately — terms were
	 * already stored when the post was scheduled.
	 *
	 * @hook transition_post_status
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       Post object.
	 */
	public function on_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! Post_Type::is_campaign_post( $post ) ) {
			return;
		}

		// REST applies taxonomies after transition_post_status. Defer send until
		// rest_after_insert (priority 10; list targeting sync runs at 9).
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			if ( self::is_auto_send_on_publish_enabled() ) {
				$this->pending_publish_dispatch[ $post->ID ] = true;
			}
			return;
		}

		// Non-REST auto-dispatch is limited to scheduled publish (wp_cron).
		if ( 'future' !== $old_status ) {
			return;
		}

		$this->dispatch_auto_send( $post->ID, 'scheduled post' );
	}

	private function dispatch_auto_send( int $post_id, string $log_context ): void {
		if ( ! self::is_auto_send_on_publish_enabled() ) {
			return;
		}

		$result = $this->create_and_send_campaign( $post_id );
		if ( is_wp_error( $result ) ) {
			error_log(
				sprintf(
					'[prc-email-builder] Mailchimp create-and-send failed for %s %d: %s',
					$log_context,
					$post_id,
					$result->get_error_message()
				)
			);
		}
	}

	/**
	 * Build Mailchimp recipients payload for an audience, optionally scoped to a segment.
	 *
	 * On create, omit segment_opts when targeting the entire audience. On update,
	 * include an empty segment_opts object so PATCH clears a previously saved
	 * segment — omitting segment_opts leaves the prior saved_segment_id in place.
	 *
	 * @param string $audience_id         Mailchimp audience (list) ID.
	 * @param int    $segment_id          Saved segment ID; 0 = entire audience.
	 * @param bool   $clear_empty_segment When true and $segment_id is 0, send
	 *                                    empty segment_opts to clear a prior segment on PATCH.
	 * @return array{list_id: string, segment_opts?: array{saved_segment_id: int}|\stdClass}
	 */
	private static function build_recipients( string $audience_id, int $segment_id, bool $clear_empty_segment = false ): array {
		$recipients = [ 'list_id' => $audience_id ];
		if ( $segment_id > 0 ) {
			$recipients['segment_opts'] = [ 'saved_segment_id' => $segment_id ];
		} elseif ( $clear_empty_segment ) {
			// Empty JSON object (not [] / omission) so PATCH clears saved segments.
			$recipients['segment_opts'] = (object) [];
		}

		return $recipients;
	}

	private function get_api_key(): string {
		if ( defined( self::API_KEY_CONSTANT ) ) {
			return (string) constant( self::API_KEY_CONSTANT );
		}
		$settings = self::get_settings();
		return $settings['mailchimp_api_key'] ?? '';
	}

	/**
	 * Builds and configures the Mailchimp Marketing SDK client.
	 *
	 * @return ApiClient|WP_Error
	 */
	private function get_client(): ApiClient|WP_Error {
		if ( ! class_exists( ApiClient::class ) ) {
			return new WP_Error(
				'mailchimp_sdk_missing',
				'Mailchimp Marketing SDK not found. Run composer install in prc-email-builder.'
			);
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'mailchimp_not_configured', 'Mailchimp API key is not set.' );
		}
		if ( ! str_contains( $api_key, '-' ) ) {
			return new WP_Error( 'mailchimp_bad_key', 'Mailchimp API key is malformed (missing data-center suffix).' );
		}

		$client = new ApiClient();
		$client->setConfig( [
			'apiKey' => $api_key,
			'server' => substr( $api_key, strrpos( $api_key, '-' ) + 1 ),
		] );

		return $client;
	}

	/**
	 * Converts a Throwable into a WP_Error.
	 *
	 * The official mailchimp/marketing SDK rethrows bare Guzzle
	 * RequestException/ClientException on many 4xx/5xx responses instead of
	 * wrapping them in ApiException. Call sites must catch \Exception (not
	 * only ApiException) so those failures become WP_Error instead of
	 * crashing Action Scheduler / REST handlers.
	 *
	 * @param \Throwable $e    The exception.
	 * @param string     $code WP_Error code slug.
	 * @return WP_Error
	 */
	private function to_wp_error( \Throwable $e, string $code ): WP_Error {
		$detail = $e->getMessage();
		$status = (int) $e->getCode();
		if ( $status < 100 || $status > 599 ) {
			$status = 500;
		}

		if ( $e instanceof ApiException ) {
			$body   = json_decode( (string) $e->getResponseBody(), true );
			$detail = is_array( $body ) ? ( $body['detail'] ?? $body['title'] ?? $detail ) : $detail;
			$status = (int) $e->getCode();
			if ( $status < 100 || $status > 599 ) {
				$status = 500;
			}
		} elseif (
			class_exists( \GuzzleHttp\Exception\RequestException::class )
			&& $e instanceof \GuzzleHttp\Exception\RequestException
			&& $e->hasResponse()
		) {
			$response = $e->getResponse();
			$status   = $response->getStatusCode();
			$body     = json_decode( (string) $response->getBody(), true );
			if ( is_array( $body ) ) {
				$detail = $body['detail'] ?? $body['title'] ?? $detail;
			}
		}

		return new WP_Error( $code, $detail, [ 'status' => $status ] );
	}
}
