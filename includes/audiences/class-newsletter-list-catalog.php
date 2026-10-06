<?php
/**
 * Catalog of Mailchimp newsletter lists (prc_newsletter_list terms) for the Audiences list.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Shapes newsletter list terms as Audiences DataViews rows.
 *
 * Library requests never call Mailchimp. Names come from the Mailchimp
 * transients and subscriber counts from term meta. A stale or missing count
 * schedules one background sync that refreshes every list.
 */
final class Newsletter_List_Catalog {
	public const SYNC_HOOK          = 'prc_email_builder_sync_list_counts';
	public const COUNT_META_KEY     = 'prc_newsletter_list_subscriber_count';
	public const SYNCED_AT_META_KEY = 'prc_newsletter_list_subscriber_count_synced_at';
	public const ROW_ID_PREFIX      = 'list:';
	public const BUILDER            = 'mailchimp';
	public const STALE_SECONDS      = 6 * HOUR_IN_SECONDS;

	/**
	 * Register the background count sync.
	 */
	public static function init(): void {
		add_action( self::SYNC_HOOK, array( self::class, 'sync_counts' ) );
		add_action( 'created_' . Post_Type::TAXONOMY, array( self::class, 'invalidate_count' ) );
		add_action( 'edited_' . Post_Type::TAXONOMY, array( self::class, 'invalidate_count' ) );
	}

	/**
	 * Mark a list's cached count stale after its targeting may have changed.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function invalidate_count( int $term_id ): void {
		delete_term_meta( $term_id, self::SYNCED_AT_META_KEY );
	}

	/**
	 * Every newsletter list term.
	 *
	 * @return \WP_Term[]
	 */
	public static function terms(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Post_Type::TAXONOMY,
				'hide_empty' => false,
			)
		);

		return is_array( $terms ) ? array_values( $terms ) : array();
	}

	/**
	 * Rows for every newsletter list. Schedules a count sync when any count is stale.
	 *
	 * @param array<int, array<string, mixed>> $analytics Term ID => analytics block.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( array $analytics = array() ): array {
		$terms      = self::terms();
		$audiences  = get_transient( Mailchimp::AUDIENCES_TRANSIENT );
		$audiences  = is_array( $audiences ) ? $audiences : array();
		$can_edit   = current_user_can( 'manage_categories' );
		$needs_sync = false;
		$rows       = array();

		foreach ( $terms as $term ) {
			$targeting = self::targeting( (int) $term->term_id );
			$synced_at = (string) get_term_meta( $term->term_id, self::SYNCED_AT_META_KEY, true );
			if ( '' !== $targeting['audience_id'] && self::is_stale( $synced_at ) ) {
				$needs_sync = true;
			}

			$rows[] = self::to_list_row(
				$term,
				$targeting,
				array(
					'audienceName' => self::audience_name( $audiences, $targeting['audience_id'] ),
					'segmentName'  => self::segment_name( $targeting['audience_id'], $targeting['segment_id'] ),
					'count'        => self::cached_count( (int) $term->term_id ),
					'syncedAt'     => '' !== $synced_at ? $synced_at : null,
					'editUrl'      => $can_edit ? self::edit_url( (int) $term->term_id ) : null,
					'viewUrl'      => self::archive_url( $term ),
					'analytics'    => $analytics[ (int) $term->term_id ] ?? Audience_Analytics::empty_block(),
				)
			);
		}

		if ( $needs_sync ) {
			self::schedule_sync();
		}

		return $rows;
	}

	/**
	 * Shape one term as a list row.
	 *
	 * @param \WP_Term                                                        $term      Newsletter list term.
	 * @param array{audience_id: string, segment_id: string, pattern: string} $targeting Term targeting meta.
	 * @param array<string, mixed>                                            $resolved  Cached names, count, and URLs.
	 * @return array<string, mixed>
	 */
	public static function to_list_row( \WP_Term $term, array $targeting, array $resolved ): array {
		return array(
			'recordType'            => 'newsletter-list',
			'id'                    => self::ROW_ID_PREFIX . (int) $term->term_id,
			'termId'                => (int) $term->term_id,
			'title'                 => html_entity_decode( (string) $term->name, ENT_QUOTES ),
			'count'                 => $resolved['count'],
			'countSyncedAt'         => $resolved['syncedAt'],
			'builder'               => self::BUILDER,
			'builderLabel'          => __( 'Mailchimp', 'prc-email-builder' ),
			'mailchimpAudienceId'   => '' !== $targeting['audience_id'] ? $targeting['audience_id'] : null,
			'mailchimpAudienceName' => $resolved['audienceName'],
			'segmentName'           => $resolved['segmentName'],
			'campaignPattern'       => $targeting['pattern'],
			'editUrl'               => $resolved['editUrl'],
			'viewUrl'               => $resolved['viewUrl'],
			'verification'          => null,
			'sourceId'              => null,
			'sourceTitle'           => null,
			'builtAt'               => null,
			'datasetId'             => null,
			'status'                => 'ready',
			'requestedAt'           => null,
			'jobId'                 => null,
			'scannedUsers'          => null,
			'matchedUsers'          => null,
			'referenceCount'        => 0,
			'analytics'             => $resolved['analytics'],
		);
	}

	/**
	 * Refresh cached subscriber counts for every list with a Mailchimp audience.
	 *
	 * Failed lookups keep the previous count but still record the attempt, so a
	 * disconnected account is retried after the stale window instead of on every refresh.
	 */
	public static function sync_counts(): void {
		$mailchimp = new Mailchimp();
		if ( ! $mailchimp->is_connected() ) {
			return;
		}

		$mailchimp->get_audiences();
		foreach ( self::terms() as $term ) {
			$targeting = self::targeting( (int) $term->term_id );
			if ( '' === $targeting['audience_id'] ) {
				continue;
			}

			if ( '' !== $targeting['segment_id'] ) {
				$mailchimp->get_segments( $targeting['audience_id'] );
			}
			$count = $mailchimp->get_subscriber_count( $targeting['audience_id'], $targeting['segment_id'] );
			if ( ! is_wp_error( $count ) ) {
				update_term_meta( $term->term_id, self::COUNT_META_KEY, (string) (int) $count );
			}
			update_term_meta( $term->term_id, self::SYNCED_AT_META_KEY, gmdate( 'c' ) );
		}
	}

	/**
	 * Whether a stored sync time is missing or older than the stale window.
	 *
	 * @param string   $synced_at ISO 8601 sync time or ''.
	 * @param int|null $now       Current Unix time (tests).
	 */
	public static function is_stale( string $synced_at, ?int $now = null ): bool {
		$timestamp = '' === $synced_at ? false : strtotime( $synced_at );
		if ( false === $timestamp ) {
			return true;
		}

		return ( $now ?? time() ) - $timestamp >= self::STALE_SECONDS;
	}

	/**
	 * Mailchimp targeting and pattern meta for a term.
	 *
	 * @param int $term_id Term ID.
	 * @return array{audience_id: string, segment_id: string, pattern: string}
	 */
	private static function targeting( int $term_id ): array {
		return array(
			'audience_id' => (string) get_term_meta( $term_id, 'prc_newsletter_list_audience_id', true ),
			'segment_id'  => (string) get_term_meta( $term_id, 'prc_newsletter_list_segment_id', true ),
			'pattern'     => Newsletter_List::sanitize_campaign_pattern(
				(string) get_term_meta( $term_id, Newsletter_List::CAMPAIGN_PATTERN_META_KEY, true )
			),
		);
	}

	/**
	 * Cached count, or null before the first successful sync.
	 *
	 * @param int $term_id Term ID.
	 */
	private static function cached_count( int $term_id ): ?int {
		$count = get_term_meta( $term_id, self::COUNT_META_KEY, true );

		return is_numeric( $count ) ? (int) $count : null;
	}

	/**
	 * Audience name from the cached audiences map.
	 *
	 * @param array<string, string> $audiences   Audience ID => name.
	 * @param string                $audience_id Audience ID.
	 */
	private static function audience_name( array $audiences, string $audience_id ): ?string {
		$name = '' === $audience_id ? null : ( $audiences[ $audience_id ] ?? null );

		return is_string( $name ) && '' !== $name ? $name : null;
	}

	/**
	 * Segment name from the cached segments list.
	 *
	 * @param string $audience_id Audience ID.
	 * @param string $segment_id  Segment ID.
	 */
	private static function segment_name( string $audience_id, string $segment_id ): ?string {
		if ( '' === $audience_id || '' === $segment_id ) {
			return null;
		}

		$segments = get_transient( Mailchimp::SEGMENTS_TRANSIENT_PREFIX . md5( $audience_id ) );
		foreach ( is_array( $segments ) ? $segments : array() as $segment ) {
			if ( (string) ( $segment['id'] ?? '' ) === $segment_id && is_string( $segment['name'] ?? null ) ) {
				return $segment['name'];
			}
		}

		return null;
	}

	/**
	 * Term edit screen URL.
	 *
	 * @param int $term_id Term ID.
	 */
	private static function edit_url( int $term_id ): ?string {
		$url = get_edit_term_link( $term_id, Post_Type::TAXONOMY, Post_Type::CAMPAIGN_POST_TYPE );

		return is_string( $url ) && '' !== $url ? html_entity_decode( $url, ENT_QUOTES ) : null;
	}

	/**
	 * Public term archive URL.
	 *
	 * @param \WP_Term $term Newsletter list term.
	 */
	private static function archive_url( \WP_Term $term ): ?string {
		$url = get_term_link( $term );

		return is_string( $url ) && '' !== $url ? html_entity_decode( $url, ENT_QUOTES ) : null;
	}

	/**
	 * Queue one async count sync unless one is already pending.
	 */
	private static function schedule_sync(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! ( new Mailchimp() )->is_connected() ) {
			return;
		}
		if (
			function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::SYNC_HOOK, array(), 'prc-email-builder' )
		) {
			return;
		}

		as_enqueue_async_action( self::SYNC_HOOK, array(), 'prc-email-builder' );
	}
}
