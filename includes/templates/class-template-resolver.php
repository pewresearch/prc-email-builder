<?php
/**
 * Template resolver — finds the right PHP body template for a newsletter.
 *
 * @package    PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Resolves which PHP template file (from templates/) should wrap a given
 * newsletter when email HTML is generated.
 *
 * Resolution order (campaign — prc_email_campaign):
 *  1. Manual override — prc_email_template_slug meta on the newsletter post.
 *  2. Auto-match     — find a template whose Audience + Segment headers match
 *                      the newsletter's Mailchimp audience + segment.
 *  3. Default        — the template registered with the slug "default", or the
 *                      first registered template if none is named "default".
 *
 * Resolution order (transactional — prc_email_txn):
 *  1. Transactional  — slug "transactional" when registered.
 *  2. Default        — fallback only when the transactional template is missing.
 */
class Template_Resolver {

	/**
	 * Resolve the template slug for a given newsletter post.
	 *
	 * @param int $newsletter_post_id  ID of the email campaign or transactional post.
	 * @return string|null             Template slug, or null if none registered.
	 */
	public static function resolve( int $newsletter_post_id ): ?string {
		$post = get_post( $newsletter_post_id );
		if ( $post instanceof \WP_Post && Post_Type::is_transactional_post( $post ) ) {
			$transactional = Template_Registry::transactional();
			if ( null !== $transactional ) {
				return $transactional['slug'];
			}

			return Template_Registry::default()['slug'] ?? null;
		}

		// 1. Manual override.
		$override = (string) get_post_meta( $newsletter_post_id, 'prc_email_template_slug', true );
		if ( '' !== $override && null !== Template_Registry::get( $override ) ) {
			return $override;
		}

		// 2. Auto-match by audience + segment.
		$audience_id = (string) get_post_meta( $newsletter_post_id, 'prc_email_mailchimp_audience_id', true );
		$segment_id  = (string) get_post_meta( $newsletter_post_id, 'prc_email_mailchimp_segment_id', true );

		if ( '' !== $audience_id ) {
			$match = self::find_matching_template( $audience_id, $segment_id );
			if ( null !== $match ) {
				return $match;
			}
		}

		// 3. Default.
		return Template_Registry::default()['slug'] ?? null;
	}

	/**
	 * Find the first template whose Audience (and optionally Segment) header
	 * matches the given Mailchimp audience + segment pair.
	 *
	 * Segment matching: if the newsletter has a segment, prefer an exact
	 * template match; fall back to a template with only the audience set.
	 *
	 * @param string $audience_id  Mailchimp audience (list) ID.
	 * @param string $segment_id   Mailchimp segment ID, or '' for whole-audience.
	 * @return string|null         Matching template slug, or null if none found.
	 */
	public static function find_matching_template( string $audience_id, string $segment_id ): ?string {
		$exact         = null;
		$audience_only = null;

		foreach ( Template_Registry::all() as $slug => $tpl ) {
			if ( $tpl['audience'] !== $audience_id ) {
				continue;
			}

			if ( $tpl['segment'] === $segment_id ) {
				$exact = $slug;
			} elseif ( '' === $tpl['segment'] ) {
				$audience_only = $slug;
			}
		}

		// Prefer exact (audience + segment) match; fall back to audience-only.
		return $exact ?? $audience_only;
	}
}
