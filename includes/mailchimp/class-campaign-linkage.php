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

	public const META_CAMPAIGN_ID        = 'prc_email_mailchimp_campaign_id';
	public const META_CAMPAIGN_ADMIN_URL = 'prc_email_mailchimp_campaign_admin_url';
	public const META_CAMPAIGN_STATUS    = 'prc_email_mailchimp_campaign_status';

	/**
	 * When true, delete_post_meta of linkage keys is allowed (unlink path).
	 *
	 * @var bool
	 */
	private static bool $clearing = false;

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
	}

	/**
	 * Skip writes that would blank a stored linkage value.
	 *
	 * Gutenberg includes these keys on every REST save. A second PUT after
	 * publish often still has empty campaign_id. Applying that empty value
	 * unlinks the post and lets auto-send create a second Mailchimp campaign.
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
			delete_post_meta( $post_id, self::META_CAMPAIGN_STATUS );
			delete_post_meta( $post_id, Campaign_Status_Sync::SENT_SLACK_NOTIFIED_META );
			delete_post_meta( $post_id, First_Day_Campaign_Stats::NOTIFIED_META );
			Report_Store::clear( $post_id );
		} finally {
			self::$clearing = false;
		}

		return array_merge( $prior, array( 'had_report' => $had_report ) );
	}
}
