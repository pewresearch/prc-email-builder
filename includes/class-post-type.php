<?php
declare(strict_types=1);
/**
 * Post type and taxonomy registration.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Registers email campaign and transactional CPTs, prc_newsletter_list taxonomy,
 * and associated post meta exposed to the REST API / block editor.
 */
class Post_Type {
	const CAMPAIGN_POST_TYPE      = 'prc_email_campaign';
	const TRANSACTIONAL_POST_TYPE = 'prc_email_txn';

	const POST_TYPES = [
		self::CAMPAIGN_POST_TYPE,
		self::TRANSACTIONAL_POST_TYPE,
	];

	const TAXONOMY = 'prc_newsletter_list';

	/**
	 * Block types allowed in email post editors.
	 *
	 * Restricted to blocks the email pipeline can convert cleanly to email-safe
	 * table HTML. Extend via `prc_email_builder_allowed_blocks`.
	 */
	const NEWSLETTER_ALLOWED_BLOCKS = [
		'core/paragraph',
		'core/heading',
		'core/post-date',
		'core/list',
		'core/list-item',
		'core/image',
		'core/table',
		'core/group',
		'core/columns',
		'core/column',
		'core/quote',
		'core/pullquote',
		'core/separator',
		'core/spacer',
		'core/buttons',
		'core/button',
		'core/embed',
		'core/html',
		'prc-chart-builder/synced-chart',
		'prc-block/story-item',
	];

	/** Meta shared by campaign and transactional posts. */
	const SHARED_META_KEYS = [
		'prc_email_subject'      => 'Email subject line.',
		'prc_email_preview_text' => 'Email preview text (preheader).',
		'prc_email_template_slug' => 'Slug of the PHP body template file in templates/; empty = auto-match by audience + segment, then default.',
	];

	/** Meta registered only on campaign (Mailchimp) posts. */
	const CAMPAIGN_META_KEYS = [
		'prc_email_mailchimp_audience_id'        => 'Mailchimp audience (list) ID.',
		'prc_email_mailchimp_segment_id'         => 'Mailchimp saved-segment ID; restricts recipients within the audience.',
		'prc_email_mailchimp_campaign_id'        => 'Mailchimp campaign ID created on publish.',
		'prc_email_mailchimp_campaign_admin_url' => 'Mailchimp admin URL to edit the campaign draft.',
		'prc_email_mailchimp_campaign_status'    => 'Cached Mailchimp campaign status: "save" (draft), "sent", "schedule", "sending", "paused", or "" (no campaign).',
	];

	/** Meta registered only on transactional (Mandrill) posts. */
	const TRANSACTIONAL_META_KEYS = [
		'prc_email_delivery_mode'         => 'Transactional sub-mode: "mandrill" (fixed recipient list) or "dynamic" (per-recipient template).',
		'prc_email_audience_option_key'   => 'wp_options key holding the resolved [email,...] recipient list (mandrill sub-mode).',
		'prc_email_mandrill_template'     => 'Mandrill template slug (optional).',
		'prc_email_mandrill_send_status'  => 'Last Mandrill send result: "sent", "queued", "failed", "active" (dynamic templates after first send), or "" (not sent / waiting).',
		'prc_email_mandrill_send_summary' => 'JSON summary of last Mandrill send (batches, sent, queued, rejected, invalid, failed_batches, sent_at).',
	];

	public function __construct( Loader $loader ) {
		// Taxonomy must register before the campaign CPT so pagination rules
		// precede the two-segment single rule in the rewrite stack.
		$loader->add_action( 'init', $this, 'register_taxonomy' );
		$loader->add_action( 'init', $this, 'register_post_types', 20 );
		// Must run after register_post_types (priority 20): register_post_meta()
		// with 'revisions_enabled' => true is rejected by WordPress unless the
		// object subtype already exists and supports revisions.
		$loader->add_action( 'init', $this, 'register_meta', 30 );
		$loader->add_action( 'admin_menu', $this, 'reorder_transactional_submenu', 999 );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_list_table_assets' );
		$loader->add_filter( 'display_post_states', $this, 'add_email_post_states', 10, 2 );
		$loader->add_filter( 'allowed_block_types_all', $this, 'restrict_newsletter_blocks', 10, 2 );
		$loader->add_filter( 'prc_platform_post_publish_pipeline_post_types', $this, 'add_email_post_types_to_publish_pipeline' );
		$loader->add_filter( 'prc_taxonomy_formats_post_types', $this, 'opt_campaign_into_formats_taxonomy' );
		$loader->add_filter( 'prc_platform_pub_listing_default_visibility', $this, 'default_campaign_visibility' );
		$loader->add_action( 'prc_platform_on_incremental_save', $this, 'enforce_campaign_newsletter_format', 10, 1 );
	}

	/**
	 * Opt the email CPTs into the post-publish pipeline.
	 *
	 * prc-post-publish-pipeline gates on its own allowlist (not the
	 * `prc-post-publish-pipeline` post-type-support flag), so the email CPTs
	 * must opt in via this filter.
	 *
	 * @param mixed $post_types Allowed post types (string[]).
	 * @return mixed
	 */
	public function add_email_post_types_to_publish_pipeline( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return $post_types;
		}

		return array_values( array_unique( array_merge( $post_types, self::POST_TYPES ) ) );
	}

	/**
	 * Opt campaign emails into the formats taxonomy.
	 *
	 * Transactional emails are excluded — they are not public web content.
	 *
	 * @hook prc_taxonomy_formats_post_types
	 *
	 * @param mixed $post_types Post types registered for the formats taxonomy.
	 * @return mixed
	 */
	public function opt_campaign_into_formats_taxonomy( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return $post_types;
		}

		if ( ! in_array( self::CAMPAIGN_POST_TYPE, $post_types, true ) ) {
			$post_types[] = self::CAMPAIGN_POST_TYPE;
		}

		return $post_types;
	}

	/**
	 * Hide campaign emails from the publications archive by default.
	 *
	 * Editors can uncheck "Hide on Publications Archive" to opt a campaign
	 * into /publications; publication-listing only applies defaults when
	 * `_post_visibility` is empty on first post init.
	 *
	 * @hook prc_platform_pub_listing_default_visibility
	 *
	 * @param mixed $defaults Map of post_type => term slug[].
	 * @return mixed
	 */
	public function default_campaign_visibility( $defaults ) {
		if ( ! is_array( $defaults ) ) {
			return $defaults;
		}

		$defaults[ self::CAMPAIGN_POST_TYPE ] = [ 'hidden-on-index' ];
		return $defaults;
	}

	/**
	 * Append the newsletter format term to campaign emails on publish/save.
	 *
	 * @hook prc_platform_on_incremental_save
	 *
	 * @param object $post Post object with extra pipeline fields.
	 */
	public function enforce_campaign_newsletter_format( $post ): void {
		if ( ! is_object( $post ) || ! isset( $post->post_type, $post->ID ) ) {
			return;
		}

		if ( self::CAMPAIGN_POST_TYPE !== $post->post_type ) {
			return;
		}

		$format_term_slug = 'newsletter';
		$format           = wp_get_object_terms( (int) $post->ID, 'formats' );
		if ( is_wp_error( $format ) ) {
			return;
		}

		$has_enforced_term = array_filter(
			$format,
			static function ( $term ) use ( $format_term_slug ) {
				return $term->slug === $format_term_slug;
			}
		);

		if ( empty( $has_enforced_term ) ) {
			wp_set_object_terms( (int) $post->ID, $format_term_slug, 'formats', true );
		}
	}

	/**
	 * Whether the given post type is a newsletter-builder email CPT.
	 */
	public static function is_email_post_type( string $post_type ): bool {
		return in_array( $post_type, self::POST_TYPES, true );
	}

	/**
	 * Whether the post is a transactional email CPT.
	 *
	 * @param \WP_Post|int $post Post object or ID.
	 */
	public static function is_transactional_post( \WP_Post|int $post ): bool {
		$post = $post instanceof \WP_Post ? $post : get_post( $post );
		return $post instanceof \WP_Post && self::TRANSACTIONAL_POST_TYPE === $post->post_type;
	}

	/**
	 * Whether the post is a campaign email CPT.
	 *
	 * @param \WP_Post|int $post Post object or ID.
	 */
	public static function is_campaign_post( \WP_Post|int $post ): bool {
		$post = $post instanceof \WP_Post ? $post : get_post( $post );
		return $post instanceof \WP_Post && self::CAMPAIGN_POST_TYPE === $post->post_type;
	}

	/**
	 * Effective transactional sub-mode for a prc_email_txn post.
	 *
	 * Empty meta means dynamic per-recipient send — matches the editor sidebar default.
	 *
	 * @param \WP_Post|int $post Post object or ID.
	 * @return string "mandrill" or "dynamic".
	 */
	public static function transactional_delivery_mode( \WP_Post|int $post ): string {
		$post = $post instanceof \WP_Post ? $post : get_post( $post );
		if ( ! $post instanceof \WP_Post || ! self::is_transactional_post( $post ) ) {
			return '';
		}

		$mode = (string) get_post_meta( $post->ID, 'prc_email_delivery_mode', true );
		return 'mandrill' === $mode ? 'mandrill' : 'dynamic';
	}

	/**
	 * Meta query matching transactional posts whose effective delivery mode is dynamic.
	 *
	 * Aligns WP_Query with {@see self::transactional_delivery_mode()}: empty or
	 * missing meta defaults to dynamic; only explicit "mandrill" is excluded.
	 *
	 * @return array<string, mixed>
	 */
	public static function dynamic_delivery_mode_meta_query(): array {
		return [
			'relation' => 'OR',
			[
				'key'     => 'prc_email_delivery_mode',
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => 'prc_email_delivery_mode',
				'value'   => '',
				'compare' => '=',
			],
			[
				'key'     => 'prc_email_delivery_mode',
				'value'   => 'dynamic',
				'compare' => '=',
			],
		];
	}

	/**
	 * Resolve target CPT from a legacy or transactional delivery mode value.
	 *
	 * @param string $delivery_mode mailchimp, mandrill, dynamic, or empty (defaults to campaign).
	 */
	public static function post_type_for_delivery_mode( string $delivery_mode ): string {
		$delivery_mode = sanitize_text_field( $delivery_mode );
		if ( in_array( $delivery_mode, [ 'mandrill', 'dynamic' ], true ) ) {
			return self::TRANSACTIONAL_POST_TYPE;
		}
		return self::CAMPAIGN_POST_TYPE;
	}

	/**
	 * Restrict the block inserter to email-friendly blocks when editing email posts.
	 *
	 * @filter allowed_block_types_all
	 *
	 * @param bool|string[]            $allowed Existing allowlist or true (all blocks).
	 * @param \WP_Block_Editor_Context $context Current editor context.
	 * @return bool|string[]
	 */
	public function restrict_newsletter_blocks( $allowed, \WP_Block_Editor_Context $context ) {
		if ( ! isset( $context->post ) || ! self::is_email_post_type( $context->post->post_type ) ) {
			return $allowed;
		}

		/**
		 * Filter the allowed block list for email posts.
		 *
		 * @param string[] $blocks Default allowed block names.
		 */
		return apply_filters( 'prc_email_builder_allowed_blocks', self::NEWSLETTER_ALLOWED_BLOCKS );
	}

	public function register_post_types(): void {
		$campaign_supports = [
			'title',
			'editor' => [ 'notes' => true ],
			'excerpt',
			'thumbnail',
			'custom-fields',
			'revisions',
			'prc-markdown-for-agents',
			'prc-publication-listing',
			'prc-post-publish-pipeline',
			'prc-schema-seo',
			'prc-art-direction',
			'prc-publish-workflows',
		];

		// Transactional emails are not public web content; exclude pub-listing and SEO.
		$transactional_supports = array_filter(
			$campaign_supports,
			static fn( $support ) => ! in_array( $support, [ 'prc-publication-listing', 'prc-schema-seo' ], true )
		);

		register_post_type(
			self::CAMPAIGN_POST_TYPE,
			[
				'labels'            => [
					'name'               => 'Email Campaigns',
					'singular_name'      => 'Email Campaign',
					'add_new_item'       => 'Add New Campaign',
					'edit_item'          => 'Edit Campaign',
					'new_item'           => 'New Campaign',
					'view_item'          => 'View Campaign',
					'search_items'       => 'Search Campaigns',
					'not_found'          => 'No campaigns found',
					'not_found_in_trash' => 'No campaigns found in Trash',
					'menu_name'          => 'Emails',
					'all_items'          => 'Campaigns',
				],
				'public'            => true,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_rest'      => true,
				'menu_icon'         => 'dashicons-email-alt',
				'menu_position'     => 25,
				'supports'          => $campaign_supports,
				'has_archive'       => false,
				'rewrite'           => [
					'slug'       => Rewrites::NEWSLETTER_URL_PREFIX . '/%' . self::TAXONOMY . '%',
					'with_front' => false,
				],
				'capability_type'   => 'post',
				// `_post_visibility` must be listed here: the campaign CPT registers
				// at init/20, after prc-publication-listing binds that taxonomy to
				// get_post_types_by_support( 'prc-publication-listing' ) at init/10.
				// Without this, Hide on Publications Archive toggles look saved in
				// the editor but are dropped by REST on reload.
				'taxonomies'        => [ self::TAXONOMY, 'category', '_post_visibility' ],
			]
		);

		register_post_type(
			self::TRANSACTIONAL_POST_TYPE,
			[
				'labels'            => [
					'name'               => 'Transactional Emails',
					'singular_name'      => 'Transactional Email',
					'add_new_item'       => 'Add New Transactional Email',
					'edit_item'          => 'Edit Transactional Email',
					'new_item'           => 'New Transactional Email',
					'view_item'          => 'View Transactional Email',
					'search_items'       => 'Search Transactional Emails',
					'not_found'          => 'No transactional emails found',
					'not_found_in_trash' => 'No transactional emails found in Trash',
					'menu_name'          => 'Transactional',
				],
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => 'edit.php?post_type=' . self::CAMPAIGN_POST_TYPE,
				'show_in_rest'      => true,
				'supports'          => $transactional_supports,
				'has_archive'       => false,
				'rewrite'           => false,
				'capability_type'   => 'post',
				'taxonomies'        => [ 'category' ],
			]
		);
	}

	/**
	 * Reorder the "Emails" admin submenu so the transactional entries sit
	 * directly beneath "Add New Campaign", and add the "Add New Transactional"
	 * link that core omits for post types nested via show_in_menu.
	 *
	 * @hook admin_menu (priority 999)
	 */
	public function reorder_transactional_submenu(): void {
		global $submenu;

		$parent = 'edit.php?post_type=' . self::CAMPAIGN_POST_TYPE;
		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}

		// Core's _add_post_type_submenus() only adds the listing link for a
		// post type nested via a string show_in_menu, never the "Add New" link.
		add_submenu_page(
			$parent,
			__( 'Add New Transactional Email', 'prc-email-builder' ),
			__( 'Add New Transactional', 'prc-email-builder' ),
			'edit_posts',
			'post-new.php?post_type=' . self::TRANSACTIONAL_POST_TYPE
		);

		$txn_list     = 'edit.php?post_type=' . self::TRANSACTIONAL_POST_TYPE;
		$txn_new      = 'post-new.php?post_type=' . self::TRANSACTIONAL_POST_TYPE;
		$campaign_new = 'post-new.php?post_type=' . self::CAMPAIGN_POST_TYPE;

		// Pull the transactional entries out of their current positions.
		$extracted = [];
		foreach ( $submenu[ $parent ] as $key => $item ) {
			if ( in_array( $item[2], [ $txn_list, $txn_new ], true ) ) {
				$extracted[ $item[2] ] = $item;
				unset( $submenu[ $parent ][ $key ] );
			}
		}

		// Reinsert them immediately after "Add New Campaign".
		$reordered = [];
		foreach ( $submenu[ $parent ] as $item ) {
			$reordered[] = $item;
			if ( $campaign_new === $item[2] ) {
				if ( isset( $extracted[ $txn_list ] ) ) {
					$reordered[] = $extracted[ $txn_list ];
				}
				if ( isset( $extracted[ $txn_new ] ) ) {
					$reordered[] = $extracted[ $txn_new ];
				}
			}
		}

		$submenu[ $parent ] = array_values( $reordered );
	}

	public function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			self::CAMPAIGN_POST_TYPE,
			[
				'labels'            => [
					'name'          => 'Newsletter Lists',
					'singular_name' => 'Newsletter List',
					'add_new_item'  => 'Add New Newsletter List',
					'edit_item'     => 'Edit Newsletter List',
					'search_items'  => 'Search Newsletter Lists',
					'not_found'     => 'No newsletter lists found',
					'menu_name'     => 'Newsletter Lists',
				],
				'hierarchical'      => false,
				'public'            => false,
				'publicly_queryable' => true,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_in_nav_menus' => false,
				'show_admin_column' => true,
				'rewrite'           => [
					'slug'       => Rewrites::NEWSLETTER_URL_PREFIX,
					'with_front' => false,
				],
				'meta_box_cb'       => false,
			]
		);
	}

	/**
	 * Resolve a published dynamic-recipient transactional post ID by post slug.
	 *
	 * @param string $key Post slug (post_name) used by external code/forms to look up this email.
	 * @return int|null Post ID, or null if no published match exists.
	 */
	public static function get_by_system_email_key( string $key ): ?int {
		$key = sanitize_title( $key );
		if ( '' === $key ) {
			return null;
		}

		$query = new \WP_Query(
			[
				'post_type'              => self::TRANSACTIONAL_POST_TYPE,
				'post_status'            => 'publish',
				'name'                   => $key,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					self::dynamic_delivery_mode_meta_query(),
				],
			]
		);

		$ids = $query->posts;
		if ( ! empty( $ids ) ) {
			return (int) $ids[0];
		}
		// @TODO: once the migration has been run, we can remove this legacy query
		$legacy_query = new \WP_Query(
			[
				'post_type'              => self::TRANSACTIONAL_POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					[
						'key'   => 'prc_email_system_email_key',
						'value' => $key,
					],
					self::dynamic_delivery_mode_meta_query(),
				],
			]
		);

		$legacy_ids = $legacy_query->posts;
		return ! empty( $legacy_ids ) ? (int) $legacy_ids[0] : null;
	}

	/** Term meta on prc_newsletter_list (Mailchimp list + segment + default From). */
	const TERM_META_KEYS = [
		'prc_newsletter_list_audience_id'      => 'Mailchimp audience (list) ID for this newsletter list.',
		'prc_newsletter_list_segment_id'       => 'Mailchimp saved-segment ID; empty = entire audience.',
		'prc_newsletter_list_from_name'        => 'Default From name for campaigns using this list.',
		'prc_newsletter_list_from_email'       => 'Default From email (reply-to on Mailchimp sends) for campaigns using this list.',
		'prc_newsletter_list_campaign_pattern' => 'Default campaign pattern name used when creating a draft from this list.',
	];

	public function register_meta(): void {
		$this->register_meta_for_post_type( self::CAMPAIGN_POST_TYPE, array_merge( self::SHARED_META_KEYS, self::CAMPAIGN_META_KEYS ) );
		$this->register_report_meta();
		$this->register_meta_for_post_type(
			self::TRANSACTIONAL_POST_TYPE,
			array_merge( self::SHARED_META_KEYS, self::TRANSACTIONAL_META_KEYS ),
			[ 'prc_email_delivery_mode' => 'dynamic' ]
		);
		$this->register_term_meta();
	}

	/**
	 * Register term meta for prc_newsletter_list (exposed to REST for the editor sidebar).
	 */
	private function register_term_meta(): void {
		$sanitizers = [
			'prc_newsletter_list_from_email'       => 'sanitize_email',
			'prc_newsletter_list_campaign_pattern' => [
				Newsletter_List::class,
				'sanitize_campaign_pattern',
			],
		];

		foreach ( self::TERM_META_KEYS as $key => $description ) {
			register_term_meta(
				self::TAXONOMY,
				$key,
				[
					'type'              => 'string',
					'description'       => $description,
					'single'            => true,
					'show_in_rest'      => true,
					'default'           => '',
					'sanitize_callback' => $sanitizers[ $key ] ?? 'sanitize_text_field',
					'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
				]
			);
		}
	}

	/**
	 * @param array<string, string> $meta_keys
	 * @param array<string, string> $defaults   Per-key REST defaults.
	 */
	private function register_meta_for_post_type( string $post_type, array $meta_keys, array $defaults = [] ): void {
		foreach ( $meta_keys as $key => $description ) {
			register_post_meta(
				$post_type,
				$key,
				[
					'single'            => true,
					'type'              => 'string',
					'description'       => $description,
					'show_in_rest'      => true,
					'revisions_enabled' => true,
					'default'           => $defaults[ $key ] ?? '',
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
				]
			);
		}
	}

	/**
	 * Engagement report meta (campaign posts only). Per-post auth; not exposed on generic post REST.
	 */
	private function register_report_meta(): void {
		$post_type = self::CAMPAIGN_POST_TYPE;
		$can_edit  = static function ( bool $allowed, string $meta_key, int $post_id ): bool {
			return current_user_can( 'edit_post', $post_id );
		};

		register_post_meta(
			$post_type,
			\PRC\Platform\Email_Builder\Reports\Report_Store::META_REPORT,
			[
				'type'              => 'string',
				'description'       => 'Normalized JSON engagement report.',
				'single'            => true,
				'show_in_rest'      => false,
				'default'           => '',
				'sanitize_callback' => static function ( $value ): string {
					return is_string( $value ) ? $value : '';
				},
				'auth_callback'     => $can_edit,
			]
		);

		register_post_meta(
			$post_type,
			\PRC\Platform\Email_Builder\Reports\Report_Store::META_OPEN_RATE,
			[
				'type'              => 'number',
				'description'       => 'Denormalized open rate for library sorting.',
				'single'            => true,
				'show_in_rest'      => false,
				'default'           => 0,
				'sanitize_callback' => static fn( $value ): float => (float) $value,
				'auth_callback'     => $can_edit,
			]
		);

		register_post_meta(
			$post_type,
			\PRC\Platform\Email_Builder\Reports\Report_Store::META_CLICK_RATE,
			[
				'type'              => 'number',
				'description'       => 'Denormalized click rate for library sorting.',
				'single'            => true,
				'show_in_rest'      => false,
				'default'           => 0,
				'sanitize_callback' => static fn( $value ): float => (float) $value,
				'auth_callback'     => $can_edit,
			]
		);

		register_post_meta(
			$post_type,
			\PRC\Platform\Email_Builder\Reports\Report_Store::META_SEND_TIME,
			[
				'type'              => 'string',
				'description'       => 'Mailchimp send time anchor for refresh window.',
				'single'            => true,
				'show_in_rest'      => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $can_edit,
			]
		);

		register_post_meta(
			$post_type,
			\PRC\Platform\Email_Builder\Reports\Report_Store::META_LAST_SYNCED,
			[
				'type'              => 'string',
				'description'       => 'UTC timestamp of last successful report sync.',
				'single'            => true,
				'show_in_rest'      => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $can_edit,
			]
		);

		register_post_meta(
			$post_type,
			\PRC\Platform\Email_Builder\Reports\Report_Store::META_SYNC_STATE,
			[
				'type'              => 'string',
				'description'       => 'Report sync state: ok, unavailable, error, pending.',
				'single'            => true,
				'show_in_rest'      => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $can_edit,
			]
		);
	}

	/**
	 * Add status labels to email list tables.
	 *
	 * @hook display_post_states
	 *
	 * @param array    $post_states Existing post display states.
	 * @param \WP_Post $post        The post object.
	 * @return array
	 */
	public function add_email_post_states( array $post_states, \WP_Post $post ): array {
		if ( ! self::is_email_post_type( $post->post_type ) ) {
			return $post_states;
		}

		if ( Migration::is_migrated( $post->ID ) ) {
			$post_states['prc_email_migrated'] = __( 'Migrated', 'prc-email-builder' );
			return $post_states;
		}

		if ( self::is_transactional_post( $post ) ) {
			$mode = self::transactional_delivery_mode( $post );
			if ( 'dynamic' === $mode ) {
				$post_states['prc_email_dynamic'] = __( 'Dynamic', 'prc-email-builder' );
			} else {
				$post_states['prc_email_bulk'] = __( 'Bulk List', 'prc-email-builder' );
			}

			$send_status = (string) get_post_meta( $post->ID, 'prc_email_mandrill_send_status', true );
			$state       = Send_Status::mandrill_post_state( $send_status );
			if ( null !== $state ) {
				$post_states[ $state[0] ] = $state[1];
			}

			return $post_states;
		}

		$status = (string) get_post_meta( $post->ID, 'prc_email_mailchimp_campaign_status', true );
		$state  = Send_Status::mailchimp_post_state( $status );
		if ( null !== $state ) {
			$post_states[ $state[0] ] = $state[1];
		}

		return $post_states;
	}

	/**
	 * Enqueue traffic-light styling for classic email list tables.
	 *
	 * @hook admin_enqueue_scripts
	 */
	public function enqueue_list_table_assets( string $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! self::is_email_post_type( $screen->post_type ) ) {
			return;
		}

		wp_enqueue_style(
			'prc-email-builder-post-states',
			plugins_url( 'assets/admin-post-states.css', PRC_EMAIL_BUILDER_FILE ),
			[],
			PRC_EMAIL_BUILDER_VERSION
		);
	}
}
