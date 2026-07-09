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
	const SETTINGS_KEY            = 'prc_email_builder_settings';
	const API_KEY_CONSTANT        = 'PRC_PLATFORM_MAILCHIMP_KEY';
	const AUDIENCES_TRANSIENT       = 'prc_email_mailchimp_audiences';
	const SEGMENTS_TRANSIENT_PREFIX = 'prc_email_mailchimp_segments_saved_';
	const LIST_TOTAL_TRANSIENT_PREFIX = 'prc_email_mailchimp_list_total_';

	public function __construct( ?Loader $loader = null ) {
		if ( null === $loader ) {
			return;
		}
		$loader->add_action( 'rest_after_insert_' . Post_Type::CAMPAIGN_POST_TYPE, $this, 'on_rest_publish', 10, 1 );
		$loader->add_action( 'prc_email_builder_campaign_ready', $this, 'on_campaign_ready', 10, 2 );
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

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$response = $client->lists->getAllLists( 'lists.id,lists.name', null, 1000 );
		} catch ( ApiException $e ) {
			return $this->to_wp_error( $e, 'mailchimp_audiences_error' );
		}

		$audiences = [];
		foreach ( $response->lists ?? [] as $list ) {
			$audiences[ $list->id ] = $list->name;
		}
		asort( $audiences );

		set_transient( self::AUDIENCES_TRANSIENT, $audiences, HOUR_IN_SECONDS );

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

		$client = $this->get_client();
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
		} catch ( ApiException $e ) {
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

		set_transient( $cache_key, $segments, HOUR_IN_SECONDS );

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

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$response = $client->lists->getList( $audience_id, 'stats.member_count' );
		} catch ( ApiException $e ) {
			return $this->to_wp_error( $e, 'mailchimp_list_total_error' );
		}

		$count = (int) ( $response->stats->member_count ?? 0 );
		set_transient( $cache_key, $count, HOUR_IN_SECONDS );

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

			$client = $this->get_client();
			if ( is_wp_error( $client ) ) {
				return $client;
			}

			try {
				$response = $client->lists->getSegment(
					$audience_id,
					$segment_id,
					'member_count'
				);
			} catch ( ApiException $e ) {
				return $this->to_wp_error( $e, 'mailchimp_segment_count_error' );
			}

			return (int) ( $response->member_count ?? 0 );
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
		$audience_id  = get_post_meta( $post_id, 'prc_email_mailchimp_audience_id', true );
		$subject      = get_post_meta( $post_id, 'prc_email_subject', true ) ?: get_the_title( $post_id );
		$preview_text = get_post_meta( $post_id, 'prc_email_preview_text', true );
		$segment_id   = (int) get_post_meta( $post_id, 'prc_email_mailchimp_segment_id', true );
		$from         = self::resolve_from_for_post( $post_id );

		if ( empty( $audience_id ) ) {
			return new WP_Error( 'missing_audience', 'No Mailchimp audience selected for this newsletter.' );
		}

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$recipients = [ 'list_id' => $audience_id ];
		if ( $segment_id > 0 ) {
			$recipients['segment_opts'] = [ 'saved_segment_id' => $segment_id ];
		}

		try {
			$campaign = $client->campaigns->create( [
				'type'       => 'regular',
				'recipients' => $recipients,
				'settings'   => [
					'title'        => get_the_title( $post_id ),
					'subject_line' => $subject,
					'preview_text' => $preview_text,
					'from_name'    => $from['from_name'],
					'reply_to'     => $from['from_email'],
					'auto_footer'  => false,
				],
			] );
		} catch ( ApiException $e ) {
			return $this->to_wp_error( $e, 'mailchimp_campaign_create_error' );
		}

		$campaign_id = $campaign->id ?? '';
		if ( '' === $campaign_id ) {
			return new WP_Error( 'campaign_create_failed', 'Mailchimp did not return a campaign ID.' );
		}

		try {
			$client->campaigns->setContent( $campaign_id, [ 'html' => $html ] );
		} catch ( ApiException $e ) {
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

		$audience_id = (string) get_post_meta( $post_id, 'prc_email_mailchimp_audience_id', true );
		if ( '' === $audience_id || $audience_id !== self::campaign_list_id( $campaign ) ) {
			return new WP_Error(
				'campaign_mismatch',
				'Stored Mailchimp campaign does not match this newsletter audience.',
				[ 'status' => 409 ]
			);
		}

		$segment_id = (int) get_post_meta( $post_id, 'prc_email_mailchimp_segment_id', true );
		if ( $segment_id !== self::campaign_saved_segment_id( $campaign ) ) {
			return new WP_Error(
				'campaign_mismatch',
				'Stored Mailchimp campaign does not match this newsletter segment.',
				[ 'status' => 409 ]
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

		$subject      = get_post_meta( $post_id, 'prc_email_subject', true ) ?: get_the_title( $post_id );
		$preview_text = get_post_meta( $post_id, 'prc_email_preview_text', true );
		$from         = self::resolve_from_for_post( $post_id );

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$client->campaigns->update(
				$campaign_id,
				[
					'settings' => [
						'title'        => get_the_title( $post_id ),
						'subject_line' => $subject,
						'preview_text' => $preview_text,
						'from_name'    => $from['from_name'],
						'reply_to'     => $from['from_email'],
					],
				]
			);
		} catch ( ApiException $e ) {
			return $this->to_wp_error( $e, 'mailchimp_campaign_update_error' );
		}

		try {
			$client->campaigns->setContent( $campaign_id, [ 'html' => $html ] );
		} catch ( ApiException $e ) {
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
	 * Persists Mailchimp campaign ID and admin URL on a newsletter post.
	 *
	 * @param int    $post_id     Newsletter post ID.
	 * @param string $campaign_id Mailchimp campaign ID.
	 * @param string $admin_url   Mailchimp admin edit URL.
	 */
	public static function persist_campaign_meta( int $post_id, string $campaign_id, string $admin_url ): void {
		update_post_meta( $post_id, 'prc_email_mailchimp_campaign_id', $campaign_id );
		update_post_meta( $post_id, 'prc_email_mailchimp_campaign_status', 'save' );
		update_post_meta( $post_id, 'prc_email_mailchimp_campaign_admin_url', esc_url_raw( $admin_url ) );
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
		} catch ( ApiException $e ) {
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
		} catch ( ApiException $e ) {
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
		} catch ( ApiException $e ) {
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
		} catch ( ApiException $e ) {
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
		} catch ( ApiException $e ) {
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
			'mailchimp_api_key'   => '',
			'from_name'           => '',
			'from_email'          => '',
			'track_opens'         => true,
			'track_clicks'        => true,
			'reply_to'            => '',
			'mandrill_subaccount' => '',
			'mandrill_tags'       => [ 'prc-newsletter' ],
		];
		$saved = get_option( self::SETTINGS_KEY, [] );
		return wp_parse_args( $saved, $defaults );
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
			'mailchimp_api_key'   => defined( self::API_KEY_CONSTANT ) ? '' : sanitize_text_field( $settings['mailchimp_api_key'] ?? '' ),
			'from_name'           => sanitize_text_field( $settings['from_name'] ?? '' ),
			'from_email'          => sanitize_email( $settings['from_email'] ?? '' ),
			'track_opens'         => filter_var( $settings['track_opens'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			'track_clicks'        => filter_var( $settings['track_clicks'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			'reply_to'            => sanitize_email( $settings['reply_to'] ?? '' ),
			'mandrill_subaccount' => sanitize_text_field( $settings['mandrill_subaccount'] ?? '' ),
			'mandrill_tags'       => $tags ?: [ 'prc-newsletter' ],
		];
		update_option( self::SETTINGS_KEY, $sanitized, false );
	}

	// -------------------------------------------------------------------------
	// WordPress hooks
	// -------------------------------------------------------------------------

	/**
	 * When a newsletter is published via REST, create a Mailchimp campaign draft.
	 *
	 * @hook rest_after_insert_{post_type}
	 */
	public function on_rest_publish( \WP_Post $post ): void {
		if ( ! Post_Type::is_campaign_post( $post ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		if ( Migration::is_migrated( $post->ID ) ) {
			return;
		}

		// Skip if a campaign draft already exists for this post.
		$existing = get_post_meta( $post->ID, 'prc_email_mailchimp_campaign_id', true );
		if ( ! empty( $existing ) ) {
			return;
		}

		$html = Cached_Email_Html::resolve( $post->ID );

		if ( is_wp_error( $html ) || '' === $html || ! $this->is_connected() ) {
			return;
		}

		$result = $this->create_campaign_draft( $post->ID, $html );
		if ( is_wp_error( $result ) ) {
			error_log( sprintf( '[prc-email-builder] Mailchimp draft creation failed for post %d: %s', $post->ID, $result->get_error_message() ) );
			return;
		}

		self::persist_campaign_meta(
			$post->ID,
			$result['campaign_id'],
			$result['admin_url']
		);
	}

	/**
	 * Creates a Mailchimp campaign draft when email HTML is supplied externally.
	 *
	 * @action prc_email_builder_campaign_ready
	 *
	 * @param int    $post_id Newsletter post ID.
	 * @param string $html    Full email HTML document.
	 */
	public function on_campaign_ready( int $post_id, string $html ): void {
		if ( ! Post_Type::is_campaign_post( $post_id ) ) {
			return;
		}
		if ( Migration::is_migrated( $post_id ) ) {
			return;
		}
		if ( empty( $html ) || ! $this->is_connected() ) {
			return;
		}

		$existing = get_post_meta( $post_id, 'prc_email_mailchimp_campaign_id', true );
		if ( ! empty( $existing ) ) {
			return;
		}

		$result = $this->create_campaign_draft( $post_id, $html );
		if ( is_wp_error( $result ) ) {
			error_log( sprintf( '[prc-email-builder] Mailchimp draft creation failed for post %d: %s', $post_id, $result->get_error_message() ) );
			return;
		}

		self::persist_campaign_meta(
			$post_id,
			$result['campaign_id'],
			$result['admin_url']
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Mailchimp list ID from a campaign payload.
	 *
	 * @param array<string, mixed> $campaign Campaign object from the API.
	 */
	private static function campaign_list_id( array $campaign ): string {
		$recipients = $campaign['recipients'] ?? null;
		if ( is_array( $recipients ) ) {
			return (string) ( $recipients['list_id'] ?? '' );
		}
		if ( is_object( $recipients ) && isset( $recipients->list_id ) ) {
			return (string) $recipients->list_id;
		}

		return '';
	}

	/**
	 * Saved segment ID from a campaign payload (0 when whole-audience).
	 *
	 * @param array<string, mixed> $campaign Campaign object from the API.
	 */
	private static function campaign_saved_segment_id( array $campaign ): int {
		$recipients = $campaign['recipients'] ?? null;
		if ( is_array( $recipients ) ) {
			$segment_opts = $recipients['segment_opts'] ?? null;
		} elseif ( is_object( $recipients ) && isset( $recipients->segment_opts ) ) {
			$segment_opts = $recipients->segment_opts;
		} else {
			return 0;
		}

		if ( is_array( $segment_opts ) ) {
			return (int) ( $segment_opts['saved_segment_id'] ?? 0 );
		}
		if ( is_object( $segment_opts ) && isset( $segment_opts->saved_segment_id ) ) {
			return (int) $segment_opts->saved_segment_id;
		}

		return 0;
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
	 * Converts a Throwable (typically ApiException) into a WP_Error.
	 *
	 * @param \Throwable $e    The exception.
	 * @param string     $code WP_Error code slug.
	 * @return WP_Error
	 */
	private function to_wp_error( \Throwable $e, string $code ): WP_Error {
		$detail = $e->getMessage();
		$status = 500;
		if ( $e instanceof ApiException ) {
			$body   = json_decode( (string) $e->getResponseBody(), true );
			$detail = $body['detail'] ?? $body['title'] ?? $detail;
			$status = (int) $e->getCode();
			if ( $status < 100 || $status > 599 ) {
				$status = 500;
			}
		}
		return new WP_Error( $code, $detail, [ 'status' => $status ] );
	}
}
