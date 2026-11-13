<?php
/**
 * Read/clear Mailchimp campaign linkage meta on campaign posts.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use PRC\Platform\Email_Builder\Reports\Report_Store;

/**
 * Owns the three campaign-link meta keys plus Slack sent/first-day marker cleanup.
 *
 * Does not clear audience, segment, template, subject, or preview text.
 */
final class Campaign_Linkage {

	public const META_CAMPAIGN_ID            = 'prc_email_mailchimp_campaign_id';
	public const META_CAMPAIGN_ADMIN_URL     = 'prc_email_mailchimp_campaign_admin_url';
	public const META_CAMPAIGN_STATUS        = 'prc_email_mailchimp_campaign_status';
	public const META_PENDING_SEND_AT        = 'prc_email_mailchimp_pending_send_at';
	public const META_DELAYED_SEND_CANCELLED = 'prc_email_mailchimp_delayed_send_cancelled';

	/**
	 * '1' while the linked campaign has no HTML content from this site yet.
	 * Server-only: not registered for REST.
	 */
	public const META_CONTENT_PENDING = 'prc_email_mailchimp_content_pending';

	/**
	 * Environment name that queued a manual delayed send.
	 * Server-only: not registered for REST. A copied production database carries
	 * the production name, so a non-production run does not trust it.
	 */
	public const META_MANUAL_SEND_ENV = 'prc_email_mailchimp_manual_send_env';

	/**
	 * When true, delete_post_meta of linkage keys is allowed (unlink path).
	 *
	 * @var bool
	 */
	private static bool $clearing = false;

	/**
	 * When true, mark_delayed_send_cancelled() is writing the cancelled-send marker.
	 *
	 * @var bool
	 */
	private static bool $marking_cancelled = false;

	/**
	 * When true, set_pending_send_at() is writing the queued-send time.
	 *
	 * @var bool
	 */
	private static bool $writing_pending_send = false;

	/**
	 * Linkage meta keys. Server-owned. The editor may read them over REST
	 * but must not blank them on a later save.
	 *
	 * @return array<int, string>
	 */
	public static function keys(): array {
		return array(
			self::META_CAMPAIGN_ID,
			self::META_CAMPAIGN_ADMIN_URL,
			self::META_CAMPAIGN_STATUS,
			self::META_PENDING_SEND_AT,
			self::META_DELAYED_SEND_CANCELLED,
		);
	}

	/**
	 * Whether a meta key is Mailchimp campaign linkage.
	 *
	 * @param string $meta_key Post meta key.
	 */
	public static function is_key( string $meta_key ): bool {
		return in_array( $meta_key, self::keys(), true );
	}

	/**
	 * Hook empty-overwrite and REST-null delete protection.
	 */
	public static function init(): void {
		add_filter( 'update_post_metadata', array( self::class, 'filter_update_post_metadata' ), 10, 4 );
		add_filter( 'delete_post_metadata', array( self::class, 'filter_delete_post_metadata' ), 10, 3 );
		add_filter( 'rest_pre_insert_' . Post_Type::CAMPAIGN_POST_TYPE, array( self::class, 'drop_status_from_rest_write' ), 10, 2 );
	}

	/**
	 * Ignore the campaign status in a REST save of a newsletter.
	 *
	 * Campaign_Status_Sync::advance() is the only writer of the status. The
	 * Meta_State guard refuses any other write, and REST would turn that refusal
	 * into a 500 for the whole save when an older editor tab sends a different
	 * status. The key stays readable over REST. This drops it from the write
	 * before WordPress validates the meta, so the rest of the save goes through.
	 *
	 * @param \stdClass|\WP_Error $prepared_post Post data WordPress will insert.
	 * @param \WP_REST_Request    $request       REST request.
	 * @return \stdClass|\WP_Error
	 */
	public static function drop_status_from_rest_write( $prepared_post, $request ) {
		$meta = $request->get_param( 'meta' );
		if ( is_array( $meta ) && array_key_exists( self::META_CAMPAIGN_STATUS, $meta ) ) {
			unset( $meta[ self::META_CAMPAIGN_STATUS ] );
			$request->set_param( 'meta', $meta );
		}
		return $prepared_post;
	}

	/**
	 * Skip writes that would blank a stored linkage value.
	 *
	 * Gutenberg includes these keys on every REST save. A second PUT after
	 * publish often still has empty campaign_id. Applying that empty value
	 * unlinks the post and lets auto-send create a second Mailchimp campaign.
	 *
	 * The delayed send stops when the cancelled-send marker is set, so only
	 * mark_delayed_send_cancelled() writes it. A save from an editor tab that
	 * still holds an old Cancel would otherwise stop a send queued later.
	 *
	 * Returning a non-null value short-circuits the write. True means the
	 * caller treats the update as successful while the stored value stays.
	 *
	 * @param null|bool $check      Prior filter result. Null means proceed.
	 * @param int       $object_id  Post ID.
	 * @param string    $meta_key   Meta key.
	 * @param mixed     $meta_value Incoming value.
	 * @return null|bool
	 */
	public static function filter_update_post_metadata( $check, $object_id, $meta_key, $meta_value ) {
		if ( null !== $check ) {
			return $check;
		}
		if ( ! is_string( $meta_key ) || ! self::is_key( $meta_key ) ) {
			return $check;
		}
		if ( self::META_DELAYED_SEND_CANCELLED === $meta_key && ! self::$marking_cancelled ) {
			return true;
		}
		if ( self::META_PENDING_SEND_AT === $meta_key && ! self::$writing_pending_send ) {
			return true;
		}
		if ( null !== $meta_value && ! is_scalar( $meta_value ) ) {
			return $check;
		}
		$incoming = null === $meta_value ? '' : trim( (string) $meta_value );
		if ( '' !== $incoming ) {
			return $check;
		}
		if ( ! self::has_stored_value( (int) $object_id, $meta_key ) ) {
			return $check;
		}
		return true;
	}

	/**
	 * Skip deletes of stored linkage keys unless Campaign_Linkage::clear() is running.
	 *
	 * WP REST treats a null meta value as delete. That would unlink the post
	 * the same way an empty-string overwrite does. Post deletion still uses
	 * delete_metadata_by_mid and is not affected.
	 *
	 * @param null|bool $check     Prior filter result. Null means proceed.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 * @return null|bool
	 */
	public static function filter_delete_post_metadata( $check, $object_id, $meta_key ) {
		if ( self::$clearing ) {
			return $check;
		}
		if ( null !== $check ) {
			return $check;
		}
		if ( ! is_string( $meta_key ) || ! self::is_key( $meta_key ) ) {
			return $check;
		}
		if ( ! self::has_stored_value( (int) $object_id, $meta_key ) ) {
			return $check;
		}
		return true;
	}

	/**
	 * Whether a linkage key currently has a non-empty stored value.
	 *
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	private static function has_stored_value( int $object_id, string $meta_key ): bool {
		return '' !== (string) get_post_meta( $object_id, $meta_key, true );
	}

	/**
	 * Current Mailchimp linkage for a campaign post.
	 *
	 * @param int $post_id Campaign post ID.
	 * @return array{campaign_id: string, admin_url: string, status: string}
	 */
	public static function read( int $post_id ): array {
		return array(
			'campaign_id' => (string) get_post_meta( $post_id, self::META_CAMPAIGN_ID, true ),
			'admin_url'   => (string) get_post_meta( $post_id, self::META_CAMPAIGN_ADMIN_URL, true ),
			'status'      => (string) get_post_meta( $post_id, self::META_CAMPAIGN_STATUS, true ),
		);
	}

	/**
	 * Whether the post has a Mailchimp campaign ID.
	 *
	 * @param int $post_id Campaign post ID.
	 */
	public static function is_linked( int $post_id ): bool {
		return '' !== self::read( $post_id )['campaign_id'];
	}

	/**
	 * Unix timestamp when the queued Mailchimp send should run, or 0.
	 *
	 * @param int $post_id Campaign post ID.
	 */
	public static function pending_send_at( int $post_id ): int {
		return (int) get_post_meta( $post_id, self::META_PENDING_SEND_AT, true );
	}

	/**
	 * Store the queued send timestamp.
	 *
	 * Only the server writes this key. A REST or editor meta save is skipped
	 * by filter_update_post_metadata(), so an older tab cannot bring back a
	 * stale send time.
	 *
	 * @param int $post_id   Campaign post ID.
	 * @param int $timestamp Unix timestamp.
	 */
	public static function set_pending_send_at( int $post_id, int $timestamp ): void {
		self::$writing_pending_send = true;
		try {
			update_post_meta( $post_id, self::META_PENDING_SEND_AT, (string) $timestamp );
		} finally {
			self::$writing_pending_send = false;
		}
		self::clear_delayed_send_cancelled( $post_id );
	}

	/**
	 * Clear the queued send timestamp.
	 *
	 * @param int $post_id Campaign post ID.
	 */
	public static function clear_pending_send( int $post_id ): void {
		self::$clearing = true;
		try {
			delete_post_meta( $post_id, self::META_PENDING_SEND_AT );
			delete_post_meta( $post_id, self::META_MANUAL_SEND_ENV );
		} finally {
			self::$clearing = false;
		}
	}

	/**
	 * Record that a person queued the delayed send from this environment.
	 *
	 * @param int    $post_id Campaign post ID.
	 * @param string $env     Environment name from Send_Guard::environment_name().
	 */
	public static function mark_manual_send_env( int $post_id, string $env ): void {
		update_post_meta( $post_id, self::META_MANUAL_SEND_ENV, $env );
	}

	/**
	 * Whether a person queued the pending send from the given environment.
	 *
	 * @param int    $post_id Campaign post ID.
	 * @param string $env     Environment name from Send_Guard::environment_name().
	 */
	public static function is_manual_send_from_env( int $post_id, string $env ): bool {
		$stored = (string) get_post_meta( $post_id, self::META_MANUAL_SEND_ENV, true );
		return '' !== $stored && $stored === $env;
	}

	/**
	 * Mark a queued send as cancelled.
	 *
	 * @param int $post_id Campaign post ID.
	 */
	public static function mark_delayed_send_cancelled( int $post_id ): void {
		self::$marking_cancelled = true;
		try {
			update_post_meta( $post_id, self::META_DELAYED_SEND_CANCELLED, '1' );
		} finally {
			self::$marking_cancelled = false;
		}
	}

	/**
	 * Whether the cancelled-send marker is set.
	 *
	 * @param int $post_id Campaign post ID.
	 */
	public static function delayed_send_cancelled( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, self::META_DELAYED_SEND_CANCELLED, true );
	}

	/**
	 * Clear the cancelled-send marker.
	 *
	 * @param int $post_id Campaign post ID.
	 */
	public static function clear_delayed_send_cancelled( int $post_id ): void {
		self::$clearing = true;
		try {
			delete_post_meta( $post_id, self::META_DELAYED_SEND_CANCELLED );
		} finally {
			self::$clearing = false;
		}
	}

	/**
	 * Mark the linked campaign as still needing its HTML content.
	 *
	 * @param int $post_id Campaign post ID.
	 */
	public static function mark_content_pending( int $post_id ): void {
		update_post_meta( $post_id, self::META_CONTENT_PENDING, '1' );
	}

	/**
	 * Whether the linked campaign still needs its HTML content.
	 *
	 * @param int $post_id Campaign post ID.
	 */
	public static function content_pending( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, self::META_CONTENT_PENDING, true );
	}

	/**
	 * Clear the content-pending marker after Mailchimp accepted the HTML.
	 *
	 * @param int $post_id Campaign post ID.
	 */
	public static function clear_content_pending( int $post_id ): void {
		delete_post_meta( $post_id, self::META_CONTENT_PENDING );
	}

	/**
	 * Clears linkage + Slack markers and report meta. Idempotent.
	 *
	 * @param int $post_id Campaign post ID.
	 * @return array{
	 *   campaign_id: string,
	 *   admin_url: string,
	 *   status: string,
	 *   had_report: bool
	 * }
	 */
	public static function clear( int $post_id ): array {
		$prior      = self::read( $post_id );
		$had_report = null !== Report_Store::get_report( $post_id )
			|| '' !== Report_Store::get_sync_state( $post_id );

		self::$clearing = true;
		try {
			delete_post_meta( $post_id, self::META_CAMPAIGN_ID );
			delete_post_meta( $post_id, self::META_CAMPAIGN_ADMIN_URL );
			Campaign_Status_Sync::reset( $post_id );
			delete_post_meta( $post_id, self::META_PENDING_SEND_AT );
			delete_post_meta( $post_id, self::META_MANUAL_SEND_ENV );
			delete_post_meta( $post_id, self::META_DELAYED_SEND_CANCELLED );
			delete_post_meta( $post_id, self::META_CONTENT_PENDING );
			delete_post_meta( $post_id, Campaign_Status_Sync::SENT_SLACK_NOTIFIED_META );
			delete_post_meta( $post_id, First_Day_Campaign_Stats::NOTIFIED_META );
			Report_Store::clear( $post_id );
		} finally {
			self::$clearing = false;
		}

		return array_merge( $prior, array( 'had_report' => $had_report ) );
	}
}
