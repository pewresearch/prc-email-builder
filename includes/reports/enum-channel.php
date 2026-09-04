<?php
/**
 * Send path for an email post.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder\Reports;

use PRC\Platform\Email_Builder\Post_Type;

/**
 * Which send path produced this post's mail.
 *
 * The only reporting reader of overloaded `prc_email_mandrill_send_status`
 * (bulk: sent|partial|failed|sending; system: active).
 */
enum Channel: string {
	case Mailchimp    = 'mailchimp';
	case MandrillBulk = 'mandrill';
	case System       = 'system';

	/**
	 * Channel for an email post. Campaigns are Mailchimp; txn mode selects the rest.
	 *
	 * @param \WP_Post|int|null $post Post object or ID.
	 */
	public static function for_post( mixed $post ): self {
		if ( ! $post instanceof \WP_Post ) {
			$post = is_numeric( $post ) ? get_post( (int) $post ) : null;
		}
		if ( ! $post instanceof \WP_Post ) {
			return self::Mailchimp;
		}
		if ( Post_Type::is_campaign_post( $post ) ) {
			return self::Mailchimp;
		}
		if ( Post_Type::is_transactional_post( $post ) && 'mandrill' === Post_Type::transactional_delivery_mode( $post ) ) {
			return self::MandrillBulk;
		}
		if ( Post_Type::is_transactional_post( $post ) ) {
			return self::System;
		}
		return self::Mailchimp;
	}

	/**
	 * Whether the inspector and library stats button may offer a report.
	 *
	 * Campaign: Mailchimp status sent. Bulk: Mandrill sent|partial. System: active.
	 *
	 * @param \WP_Post|int|null $post Post object or ID.
	 */
	public static function stats_available( mixed $post ): bool {
		if ( ! $post instanceof \WP_Post ) {
			$post = is_numeric( $post ) ? get_post( (int) $post ) : null;
		}
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return match ( self::for_post( $post ) ) {
			self::Mailchimp    => 'sent' === (string) get_post_meta( $post->ID, 'prc_email_mailchimp_campaign_status', true ),
			self::MandrillBulk => in_array(
				(string) get_post_meta( $post->ID, 'prc_email_mandrill_send_status', true ),
				array( 'sent', 'partial' ),
				true
			),
			self::System       => 'active' === (string) get_post_meta( $post->ID, 'prc_email_mandrill_send_status', true ),
		};
	}

	/**
	 * Mandrill send-status meta for txn channels; empty for Mailchimp.
	 *
	 * @param \WP_Post|int|null $post Post object or ID.
	 */
	public static function delivery_status( mixed $post ): string {
		if ( ! $post instanceof \WP_Post ) {
			$post = is_numeric( $post ) ? get_post( (int) $post ) : null;
		}
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		if ( self::Mailchimp === self::for_post( $post ) ) {
			return '';
		}
		return (string) get_post_meta( $post->ID, 'prc_email_mandrill_send_status', true );
	}
}
