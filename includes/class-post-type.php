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
		'prc_email_mandrill_send_status'  => 'Last Mandrill send result: "sent", "queued", "failed", or "" (not sent).',
		'prc_email_mandrill_send_summary' => 'JSON summary of last Mandrill send (batches, sent, queued, rejected, invalid, failed_batches, sent_at).',
		'prc_email_system_email_key'      => 'Unique slug used by external code/forms to look up this dynamic-recipient email (dynamic sub-mode).',
	];

	public function __construct( Loader $loader ) {
		$loader->add_action( 'init', $this, 'register_post_types' );
		$loader->add_action( 'init', $this, 'register_taxonomy' );
		$loader->add_action( 'init', $this, 'register_meta' );
		$loader->add_filter( 'display_post_states', $this, 'add_email_post_states', 10, 2 );
		$loader->add_filter( 'allowed_block_types_all', $this, 'restrict_newsletter_blocks', 10, 2 );
		$loader->add_filter( 'prc_platform_post_publish_pipeline_post_types', $this, 'add_email_post_types_to_publish_pipeline' );
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
	 * Empty meta means bulk Mandrill send — matches the editor sidebar default.
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
		return 'dynamic' === $mode ? 'dynamic' : 'mandrill';
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
		$supports = [
			'title',
			'editor',
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
				'supports'          => $supports,
				'has_archive'       => true,
				'rewrite'           => false,
				'capability_type'   => 'post',
				'taxonomies'        => [ self::TAXONOMY, 'category' ],
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
				'supports'          => $supports,
				'has_archive'       => false,
				'rewrite'           => false,
				'capability_type'   => 'post',
				'taxonomies'        => [ self::TAXONOMY, 'category' ],
			]
		);
	}

	public function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPES,
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
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => false,
				'meta_box_cb'       => false,
			]
		);
	}

	/**
	 * Resolve a published dynamic-recipient transactional post ID by system email key.
	 *
	 * @param string $key System email key slug.
	 * @return int|null Post ID, or null if no published match exists.
	 */
	public static function get_by_system_email_key( string $key ): ?int {
		$key = sanitize_text_field( $key );
		if ( '' === $key ) {
			return null;
		}

		$query = new \WP_Query(
			[
				'post_type'              => self::TRANSACTIONAL_POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => [
					'relation' => 'AND',
					[
						'key'   => 'prc_email_system_email_key',
						'value' => $key,
					],
					[
						'key'   => 'prc_email_delivery_mode',
						'value' => 'dynamic',
					],
				],
			]
		);

		$ids = $query->posts;
		return ! empty( $ids ) ? (int) $ids[0] : null;
	}

	public function register_meta(): void {
		$this->register_meta_for_post_type( self::CAMPAIGN_POST_TYPE, array_merge( self::SHARED_META_KEYS, self::CAMPAIGN_META_KEYS ) );
		$this->register_meta_for_post_type(
			self::TRANSACTIONAL_POST_TYPE,
			array_merge( self::SHARED_META_KEYS, self::TRANSACTIONAL_META_KEYS ),
			[ 'prc_email_delivery_mode' => 'mandrill' ]
		);
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

			$send_status = get_post_meta( $post->ID, 'prc_email_mandrill_send_status', true );
			if ( 'sent' === $send_status ) {
				$post_states['prc_email_mandrill_sent'] = __( 'Sent', 'prc-email-builder' );
			} elseif ( 'queued' === $send_status ) {
				$post_states['prc_email_mandrill_queued'] = __( 'Queued', 'prc-email-builder' );
			}

			return $post_states;
		}

		$status = get_post_meta( $post->ID, 'prc_email_mailchimp_campaign_status', true );

		$labels = [
			'save'     => __( 'MC Draft', 'prc-email-builder' ),
			'sent'     => __( 'MC Sent', 'prc-email-builder' ),
			'sending'  => __( 'Sending', 'prc-email-builder' ),
			'schedule' => __( 'Scheduled', 'prc-email-builder' ),
		];

		if ( isset( $labels[ $status ] ) ) {
			$post_states['prc_email_mc_status'] = $labels[ $status ];
		}

		return $post_states;
	}
}
