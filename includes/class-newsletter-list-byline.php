<?php
/**
 * Newsletter list enforced byline: attribute every campaign on a list to one staff byline.
 *
 * @package    PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use PRC\Platform\Staff_Bylines\Byline_Sync;

/**
 * A newsletter list can name one `bylines` term. Campaigns on that list are
 * attributed to it as an acknowledgement, so they join that staff page without
 * printing a byline in the email, and are cleared from `hidden-on-index` so the
 * staff page publication listing includes them.
 */
class Newsletter_List_Byline {

	/** Term meta: `bylines` term ID attributed to every campaign on the list. Empty = none. */
	const META_KEY = 'prc_newsletter_list_enforced_byline_term_id';

	/** Staff bylines taxonomy. The staff plugin owns it; this plugin does not load that plugin. */
	const TAXONOMY = 'bylines';

	/** `_post_visibility` taxonomy and the term that keeps a post off publication listings. */
	const VISIBILITY_TAXONOMY  = '_post_visibility';
	const HIDDEN_ON_INDEX_TERM = 'hidden-on-index';

	/** Outcomes of apply(). */
	const RESULT_ENFORCED    = 'enforced';
	const RESULT_CURRENT     = 'current';
	const RESULT_NO_LIST     = 'no-list';
	const RESULT_NO_BYLINE   = 'no-byline';
	const RESULT_UNAVAILABLE = 'unavailable';

	/** Action Scheduler action that attributes a list's existing campaigns after its byline changes. */
	const APPLY_TO_LIST_HOOK = 'prc_email_builder_apply_list_byline';

	/** Campaigns per scheduled batch. Kept small so each batch bumps few cache versions. */
	const BATCH_SIZE = 25;

	/**
	 * Enforced byline each list had before a term meta update, keyed by list term ID.
	 *
	 * @var array<int, string>
	 */
	private array $previous_bylines = array();

	/**
	 * Wire campaign save and list byline change hooks.
	 *
	 * @param Loader $loader Plugin loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'rest_after_insert_' . Post_Type::CAMPAIGN_POST_TYPE, $this, 'enforce_on_rest_save', 10, 1 );
		$loader->add_action( 'wp_after_insert_post', $this, 'enforce_on_save', 20, 2 );
		$loader->add_action( 'update_term_meta', $this, 'remember_previous_byline', 10, 4 );
		$loader->add_action( 'added_term_meta', $this, 'schedule_apply_to_list', 10, 4 );
		$loader->add_action( 'updated_term_meta', $this, 'schedule_apply_to_list', 10, 4 );
		$loader->add_action( self::APPLY_TO_LIST_HOOK, $this, 'apply_list_batch', 10, 3 );
	}

	/**
	 * Remember a list's byline just before it is updated, so the change can be detected afterwards.
	 *
	 * @hook update_term_meta
	 *
	 * @param mixed  $meta_id    Meta row ID.
	 * @param int    $term_id    Term ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New meta value.
	 */
	public function remember_previous_byline( mixed $meta_id, int $term_id, string $meta_key, mixed $meta_value ): void {
		unset( $meta_id, $meta_value );
		if ( self::META_KEY !== $meta_key ) {
			return;
		}

		$this->previous_bylines[ $term_id ] = self::sanitize_term_id( get_term_meta( $term_id, self::META_KEY, true ) );
	}

	/**
	 * Queue the list's existing campaigns for attribution when its byline is set or changed.
	 *
	 * Campaign saves only cover campaigns edited after the byline is set, so
	 * campaigns already on the list would never reach the staff page. The work
	 * runs later in small Action Scheduler batches so a large list neither slows
	 * the term save nor bursts cache invalidation. Clearing the byline, or
	 * writing the value it already has, schedules nothing.
	 *
	 * @hook added_term_meta
	 * @hook updated_term_meta
	 *
	 * @param mixed  $meta_id    Meta row ID.
	 * @param int    $term_id    Term ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New meta value.
	 */
	public function schedule_apply_to_list( mixed $meta_id, int $term_id, string $meta_key, mixed $meta_value ): void {
		if ( self::META_KEY !== $meta_key ) {
			return;
		}

		$previous = $this->previous_bylines[ $term_id ] ?? '';
		unset( $this->previous_bylines[ $term_id ] );

		$current = self::sanitize_term_id( $meta_value );
		if ( '' === $current || $current === $previous ) {
			return;
		}

		Newsletter_List_Byline_Backfill::schedule( $term_id );
	}

	/**
	 * Attribute one batch of a list's campaigns, then queue the next batch.
	 *
	 * @hook prc_email_builder_apply_list_byline
	 *
	 * @param int                $list_term_id Newsletter list term ID.
	 * @param int                $after_id     Highest campaign ID already handled. 0 starts the list.
	 * @param array<string, int> $totals       Running counts from earlier batches.
	 */
	public function apply_list_batch( int $list_term_id, int $after_id = 0, array $totals = array() ): void {
		Newsletter_List_Byline_Backfill::handle_batch( $list_term_id, $after_id, $totals );
	}

	/**
	 * One batch of campaign IDs on a list, in ID order, after a cursor.
	 *
	 * Enforcement never changes list membership, and the cursor is an ID rather
	 * than a page number, so campaigns trashed or moved between batches cannot
	 * shift later batches and skip a campaign.
	 *
	 * @param int $list_term_id Newsletter list term ID.
	 * @param int $after_id     Only campaigns with an ID above this one.
	 * @param int $limit        Maximum campaigns.
	 * @return int[]
	 */
	public static function get_list_campaign_ids( int $list_term_id, int $after_id = 0, int $limit = self::BATCH_SIZE ): array {
		add_filter( 'posts_where', array( __CLASS__, 'filter_after_id' ), 10, 2 );
		try {
			$query = new \WP_Query(
				array(
					'post_type'              => Post_Type::CAMPAIGN_POST_TYPE,
					'post_status'            => array( 'publish', 'future', 'pending', 'draft', 'private' ),
					'posts_per_page'         => $limit,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'cache_results'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'prc_after_id'           => $after_id,
					'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => Post_Type::TAXONOMY,
							'field'    => 'term_id',
							'terms'    => array( $list_term_id ),
						),
					),
				)
			);
		} finally {
			remove_filter( 'posts_where', array( __CLASS__, 'filter_after_id' ), 10 );
		}

		return array_values(
			array_filter(
				array_map( 'intval', $query->posts ),
				static fn( int $post_id ): bool => $post_id > $after_id
			)
		);
	}

	/**
	 * Limit a campaign query to IDs above its `prc_after_id` query var.
	 *
	 * @hook posts_where
	 *
	 * @param string    $where SQL WHERE clause.
	 * @param \WP_Query $query Query being built.
	 */
	public static function filter_after_id( string $where, \WP_Query $query ): string {
		global $wpdb;

		$after_id = absint( $query->get( 'prc_after_id' ) );
		if ( $after_id <= 0 ) {
			return $where;
		}

		return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
	}

	/**
	 * Sanitize a bylines term ID; empty or non-positive becomes ''.
	 *
	 * @param mixed $raw Raw meta value.
	 */
	public static function sanitize_term_id( mixed $raw ): string {
		$id = absint( $raw );
		return $id > 0 ? (string) $id : '';
	}

	/**
	 * Enforce the list byline after the block editor saves a campaign.
	 *
	 * Runs after the list audience override (priority 9).
	 *
	 * @hook rest_after_insert_prc_email_campaign (priority 10)
	 *
	 * @param \WP_Post $post Saved campaign post.
	 */
	public function enforce_on_rest_save( \WP_Post $post ): void {
		self::apply( $post->ID );
	}

	/**
	 * Enforce the list byline after classic and CLI saves.
	 *
	 * Runs after publication-listing applies its default `hidden-on-index`
	 * visibility to new posts, so the list byline can clear it.
	 *
	 * @hook wp_after_insert_post (priority 20)
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Saved post.
	 */
	public function enforce_on_save( int $post_id, \WP_Post $post ): void {
		if ( ! Post_Type::is_campaign_post( $post ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		self::apply( $post_id );
	}

	/**
	 * Enforced `bylines` term for a list, or 0 when unset or no longer a byline term.
	 *
	 * @param int $list_term_id Newsletter list term ID.
	 */
	public static function resolve_term_id( int $list_term_id ): int {
		if ( $list_term_id <= 0 ) {
			return 0;
		}

		$byline_term_id = absint( self::sanitize_term_id( get_term_meta( $list_term_id, self::META_KEY, true ) ) );
		if ( $byline_term_id <= 0 ) {
			return 0;
		}

		return get_term( $byline_term_id, self::TAXONOMY ) instanceof \WP_Term ? $byline_term_id : 0;
	}

	/**
	 * Attribute a campaign to its list's enforced byline.
	 *
	 * Stores the byline as an acknowledgement, drops acknowledgements left by a
	 * previously enforced byline, and clears `hidden-on-index`.
	 * Safe to run repeatedly: a campaign that is already attributed and visible
	 * is left untouched.
	 *
	 * @param int  $post_id Campaign post ID.
	 * @param bool $dry_run Report the outcome without writing.
	 * @return string One of the RESULT_* constants.
	 */
	public static function apply( int $post_id, bool $dry_run = false ): string {
		$list_term_id = Newsletter_List::resolve_mailchimp_targeting( $post_id )['term_id'];
		if ( $list_term_id <= 0 ) {
			return self::RESULT_NO_LIST;
		}

		$byline_term_id = self::resolve_term_id( $list_term_id );
		if ( $byline_term_id <= 0 ) {
			return self::RESULT_NO_BYLINE;
		}

		if ( ! class_exists( Byline_Sync::class ) ) {
			return self::RESULT_UNAVAILABLE;
		}

		$is_attributed = Byline_Sync::is_attributed( $post_id, $byline_term_id );
		$is_hidden     = has_term( self::HIDDEN_ON_INDEX_TERM, self::VISIBILITY_TAXONOMY, $post_id );
		$has_stale     = Byline_Sync::has_stale_enforced_rows( $post_id, $byline_term_id );
		if ( $is_attributed && ! $is_hidden && ! $has_stale ) {
			return self::RESULT_CURRENT;
		}

		if ( ! $dry_run ) {
			if ( $has_stale ) {
				Byline_Sync::remove_stale_enforced_rows( $post_id, $byline_term_id );
			}
			if ( ! $is_attributed ) {
				Byline_Sync::add_term_row( $post_id, $byline_term_id );
			}
			if ( $is_hidden ) {
				wp_remove_object_terms( $post_id, self::HIDDEN_ON_INDEX_TERM, self::VISIBILITY_TAXONOMY );
			}
		}

		return self::RESULT_ENFORCED;
	}
}
