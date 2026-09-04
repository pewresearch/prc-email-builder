<?php
/**
 * REST API endpoints for the block editor sidebar.
 *
 * @package    PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use PRC\Platform\Email_Builder\Reports\Email_Reports;
use PRC\Platform\Email_Builder\Reports\Report_Store;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use wpdb;
use function PRC\Platform\Wp_Admin_Dataview\plain_text;

/**
 * Provides read-only endpoints consumed by the sidebar panel:
 *  GET /prc-email-builder/v1/audiences                       — Mailchimp audience list
 *  GET /prc-email-builder/v1/connection                      — connection status + sender info
 *  GET /prc-email-builder/v1/audiences/{id}/segments         — Mailchimp saved segments for an audience
 *  GET /prc-email-builder/v1/audiences-system                — System-email audiences from wp_options
 *  POST /prc-email-builder/v1/audience-jobs                  — Start a registry-backed audience job
 *  GET /prc-email-builder/v1/audience-jobs/{jobId}           — Poll an audience job
 *  POST /prc-email-builder/v1/audience-jobs/{jobId}/draft    — Draft a transactional email from a ready job
 *  POST /prc-email-builder/v1/send                           — Mandrill bulk send (explicit, edit_post scoped)
 *  POST /prc-email-builder/v1/campaigns/update-draft         — Push post HTML/settings to existing Mailchimp draft
 *  POST /prc-email-builder/v1/campaigns/unlink               — Clear Mailchimp campaign linkage meta
 *  POST /prc-email-builder/v1/campaigns/create-draft         — Create and send a Mailchimp campaign (recovery)
 *  POST /prc-email-builder/v1/transactional/create-from-audience — Draft prc_email_txn targeting an audience option
 */
class REST_API {
	const NAMESPACE = 'prc-email-builder/v1';

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Plugin loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * Register REST routes for the email builder sidebar.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/audiences',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_audiences' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/connection',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_connection' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audiences-system',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_system_audiences' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/auth-domain-audiences',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_auth_domain_audience' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/auth-domain-audiences/(?P<job_id>ad_[a-z0-9]{13,32})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_auth_domain_audience' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'job_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/auth-domain-audiences/(?P<job_id>ad_[a-z0-9]{13,32})/draft',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_auth_domain_audience_draft' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'job_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audience-jobs',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_audience_job' ),
				'permission_callback' => array( $this, 'audience_job_create_permission' ),
				'args'                => array(
					'builder' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audience-jobs/(?P<job_id>(?:ad|ds|qz|cs)_[a-z0-9]{13,32})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_audience_job' ),
				'permission_callback' => array( $this, 'audience_job_access_permission' ),
				'args'                => array(
					'job_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audience-jobs/(?P<job_id>(?:ad|ds|qz|cs)_[a-z0-9]{13,32})/draft',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_audience_job_draft' ),
				'permission_callback' => array( $this, 'audience_job_access_permission' ),
				'args'                => array(
					'job_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/transactional/create-from-audience',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_transactional_from_audience' ),
				'permission_callback' => array( $this, 'create_transactional_from_audience_permission' ),
				'args'                => array(
					'audience_key' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'title'        => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'subject'      => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'quiz_id'      => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/send-system-email',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_system_email' ),
				// Capability-gated: this endpoint can email arbitrary addresses,
				// so it is for editors / programmatic callers. Anonymous public
				// sends go through the nonce + captcha gated form action at
				// /prc-api/v3/form/send-system-email instead. Requires edit rights
				// on the *specific* transactional post (not just the generic
				// edit_posts cap) — see send_system_email_permission_check().
				'permission_callback' => array( $this, 'send_system_email_permission_check' ),
				'args'                => array(
					'post_id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'to_email' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => fn( $v ) => is_email( $v ),
					),
					'context'  => array(
						'required' => false,
						'type'     => 'object',
						'default'  => array(),
					),
					'dry_run'  => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/send',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_newsletter' ),
				'permission_callback' => array( $this, 'send_newsletter_permission_check' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'reset'   => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaigns/update-draft',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_mailchimp_draft' ),
				'permission_callback' => array( $this, 'send_newsletter_permission_check' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaigns/unlink',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'unlink_mailchimp_campaign' ),
				'permission_callback' => array( $this, 'send_newsletter_permission_check' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaigns/create-draft',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_mailchimp_draft' ),
				'permission_callback' => array( $this, 'send_newsletter_permission_check' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaigns/(?P<post_id>\d+)/report',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_campaign_report' ),
				'permission_callback' => array( $this, 'campaign_report_permission_check' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaigns/(?P<post_id>\d+)/report/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'refresh_campaign_report' ),
				'permission_callback' => array( $this, 'campaign_report_permission_check' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/mandrill-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mandrill_webhook' ),
				'permission_callback' => array( $this, 'mandrill_webhook_permission' ),
				'args'                => array(
					'key'             => array(
						'required' => false,
						'type'     => 'string',
					),
					'mandrill_events' => array(
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audiences/(?P<audience_id>[a-f0-9]+)/segments',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_segments' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'audience_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audiences/(?P<audience_id>[a-f0-9]+)/count',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_audience_subscriber_count' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'audience_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'segment_id'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/library',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_library' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'post_type'        => array(
						'type'              => 'string',
						'default'           => 'all',
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => array( 'all', 'campaign', 'txn' ),
					),
					'newsletter_list'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'mailchimp_status' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'mandrill_status'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'search'           => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'orderby'          => array(
						'type'              => 'string',
						'default'           => 'date',
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => array( 'date', 'modified', 'title', 'open_rate', 'click_rate' ),
					),
					'order'            => array(
						'type'              => 'string',
						'default'           => 'desc',
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => array( 'asc', 'desc' ),
					),
					'status'           => array(
						'type'              => 'string',
						'default'           => 'publish,draft,private',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'per_page'         => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'page'             => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'watchingOnly'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'activeEditors'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Start a generic audience build job.
	 *
	 * @param WP_REST_Request $request Request with builder plus builder-specific input.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function start_audience_job( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$builder = sanitize_key( (string) $request->get_param( 'builder' ) );
		$input   = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			$input = $request->get_params();
		}
		$view = Audience_Job::start( $builder, $input );
		if ( is_wp_error( $view ) ) {
			return $view;
		}

		return new WP_REST_Response( $view, 202 );
	}

	/**
	 * Poll a generic audience build job.
	 *
	 * @param WP_REST_Request $request Request with job_id.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function get_audience_job( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$view = Audience_Job::status( (string) $request['job_id'] );
		if ( is_wp_error( $view ) ) {
			return $view;
		}

		return rest_ensure_response( $view );
	}

	/**
	 * Create a transactional draft from a finished audience job.
	 *
	 * @param WP_REST_Request $request Request with job_id.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function create_audience_job_draft( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$view = Audience_Job::create_draft( (string) $request['job_id'] );
		if ( is_wp_error( $view ) ) {
			return $view;
		}

		return rest_ensure_response( $view );
	}

	/**
	 * Permission check for starting an audience job.
	 *
	 * @param WP_REST_Request $request Request with builder and source id.
	 */
	public function audience_job_create_permission( WP_REST_Request $request ): bool {
		$slug    = sanitize_key( (string) $request->get_param( 'builder' ) );
		$builder = Audience_Builder_Registry::get( $slug );
		if ( null === $builder ) {
			return current_user_can( 'edit_posts' );
		}
		if ( 'source-entity' === $builder['form'] ) {
			$id = (int) $request->get_param( $builder['source_id_param'] );
			return $id > 0 && current_user_can( 'edit_post', $id );
		}

		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission check for polling or drafting an audience job.
	 *
	 * @param WP_REST_Request $request Request with job_id.
	 */
	public function audience_job_access_permission( WP_REST_Request $request ): bool {
		$job_id = (string) $request['job_id'];
		$job    = get_option( Audience_Job::job_option_key( $job_id ), null );
		if ( ! is_array( $job ) ) {
			return current_user_can( 'edit_posts' );
		}

		return Audience_Job::current_user_can_access( $job );
	}

	/**
	 * Start an auth-domain audience build job.
	 *
	 * @param WP_REST_Request $request Request with domainContains, verification, and label.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function start_auth_domain_audience( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$verification = (string) $request->get_param( 'verification' );
		$input        = array(
			'domainContains' => sanitize_text_field( (string) $request->get_param( 'domainContains' ) ),
			'verification'   => sanitize_key( '' !== $verification ? $verification : 'verified' ),
			'label'          => sanitize_text_field( (string) $request->get_param( 'label' ) ),
		);
		$view         = Auth_Domain_Audience_Build::start( $input );
		if ( is_wp_error( $view ) ) {
			return $view;
		}

		return new WP_REST_Response( $view, 202 );
	}

	/**
	 * Poll an auth-domain audience build job.
	 *
	 * @param WP_REST_Request $request Request with job_id.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function get_auth_domain_audience( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$view = Auth_Domain_Audience_Build::status( (string) $request['job_id'] );
		if ( is_wp_error( $view ) ) {
			return $view;
		}

		return rest_ensure_response( $view );
	}

	/**
	 * Create a transactional draft from a finished auth-domain audience job.
	 *
	 * @param WP_REST_Request $request Request with job_id.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function create_auth_domain_audience_draft( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$view = Auth_Domain_Audience_Build::create_draft( (string) $request['job_id'] );
		if ( is_wp_error( $view ) ) {
			return $view;
		}

		return rest_ensure_response( $view );
	}

	/**
	 * Permission check for drafting a transactional email from an audience option.
	 *
	 * @param WP_REST_Request $request Request with optional quiz_id.
	 * @return bool
	 */
	public function create_transactional_from_audience_permission( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$quiz_id = (int) $request->get_param( 'quiz_id' );
		if ( $quiz_id > 0 && ! current_user_can( 'edit_post', $quiz_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Draft a transactional email targeting an audience option.
	 *
	 * @param WP_REST_Request $request Request with audience_key and optional title, subject, quiz_id.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function create_transactional_from_audience( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$args  = array();
		$title = $request->get_param( 'title' );
		if ( is_string( $title ) && '' !== $title ) {
			$args['title'] = $title;
		}
		$subject = $request->get_param( 'subject' );
		if ( is_string( $subject ) && '' !== $subject ) {
			$args['subject'] = $subject;
		}
		$quiz_id = (int) $request->get_param( 'quiz_id' );
		if ( $quiz_id > 0 ) {
			$args['quiz_id'] = $quiz_id;
		}

		$result = Transactional_Draft::create_from_audience(
			(string) $request->get_param( 'audience_key' ),
			$args
		);
		if ( is_wp_error( $result ) ) {
			return $this->normalize_rest_error( $result );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /audiences — Mailchimp audience list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_audiences( WP_REST_Request $request ): WP_REST_Response {
		$mailchimp = new Mailchimp();
		$audiences = $mailchimp->get_audiences();

		if ( is_wp_error( $audiences ) ) {
			return new WP_REST_Response(
				array( 'error' => $audiences->get_error_message() ),
				503
			);
		}

		$formatted = array_map(
			fn( $id, $name ) => array(
				'id'   => $id,
				'name' => $name,
			),
			array_keys( $audiences ),
			array_values( $audiences )
		);

		return rest_ensure_response( $formatted );
	}

	/**
	 * GET /connection — Mailchimp connection status and sender info.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_connection( WP_REST_Request $request ): WP_REST_Response {
		$mailchimp = new Mailchimp();
		$settings  = Mailchimp::get_settings();

		return rest_ensure_response(
			array(
				'connected'  => $mailchimp->is_connected(),
				'from_name'  => $settings['from_name'],
				'from_email' => $settings['from_email'],
			)
		);
	}

	/**
	 * GET /audiences-system — system-email audiences stored in options.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_system_audiences( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		// Fetch all _meta option names whose base key starts with prc_email_audience_
		// or legacy prc_newsletter_audience_. We query only the meta siblings to avoid
		// loading the (potentially large) email arrays.
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name ASC",
				$wpdb->esc_like( 'prc_email_audience_' ) . '%' . $wpdb->esc_like( '_meta' ),
				$wpdb->esc_like( 'prc_newsletter_audience_' ) . '%' . $wpdb->esc_like( '_meta' )
			)
		);

		$audiences = array();
		foreach ( $results as $meta_option_name ) {
			$meta = get_option( $meta_option_name, array() );
			// Mirror CLI_Audience::is_audience_meta_array(): skip empty / list-shaped
			// options (e.g. a raw email list whose key happens to end in "_meta") so
			// the picker never surfaces non-audience options the CLI would ignore.
			if (
				empty( $meta ) || ! is_array( $meta )
				|| array_is_list( $meta )
				|| ! ( isset( $meta['label'] ) || isset( $meta['built_at'] ) || isset( $meta['source'] ) )
			) {
				continue;
			}
			// The base audience key is the meta key without the _meta suffix.
			$audience_key = substr( $meta_option_name, 0, -5 );
			$audiences[]  = Audience_Builder_Registry::describe_audience( $audience_key, $meta );
		}

		return rest_ensure_response( $audiences );
	}

	/**
	 * Permission check for POST /send — caller must be able to edit the target newsletter.
	 *
	 * @param WP_REST_Request $request Request with post_id.
	 */
	public function send_newsletter_permission_check( WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );

		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Permission check for campaign engagement report endpoints.
	 *
	 * @param WP_REST_Request $request Request with post_id.
	 * @return bool
	 */
	public function campaign_report_permission_check( WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );

		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * GET /campaigns/{post_id}/report — stored or live engagement envelope.
	 *
	 * @param WP_REST_Request $request Request with post_id.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function get_campaign_report( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$post = $this->engagement_report_post( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return rest_ensure_response( Email_Reports::envelope( (int) $post->ID ) );
	}

	/**
	 * POST /campaigns/{post_id}/report/refresh — Mailchimp pull or txn ledger recompute.
	 *
	 * @param WP_REST_Request $request Request with post_id.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function refresh_campaign_report( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$post = $this->engagement_report_post( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$result = Email_Reports::refresh( (int) $post->ID );
		if ( is_wp_error( $result ) ) {
			return $this->normalize_rest_error( $result );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Whether the Mandrill webhook key matches this plugin or, if loaded, CRM.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function mandrill_webhook_permission( WP_REST_Request $request ): bool {
		$provided = (string) $request->get_param( 'key' );
		if ( Mandrill_Event_Ledger::webhook_key_is_valid( $provided ) ) {
			return true;
		}
		if ( class_exists( '\\PRC\\Platform\\CRM\\Email_Activity' ) ) {
			return \PRC\Platform\CRM\Email_Activity::webhook_key_is_valid( $provided );
		}
		return false;
	}

	/**
	 * POST /mandrill-webhook — ingest Mandrill events into the full-audience ledger.
	 *
	 * @param WP_REST_Request $request Request with mandrill_events.
	 * @return array<string, int>
	 */
	public function mandrill_webhook( WP_REST_Request $request ): array {
		$raw = $request->get_param( 'mandrill_events' );
		if ( empty( $raw ) ) {
			$body = $request->get_json_params();
			if ( is_array( $body ) && isset( $body['mandrill_events'] ) ) {
				$raw = $body['mandrill_events'];
			}
		}

		$events = Mandrill_Event_Ledger::parse_mandrill_events( $raw );
		$stored = Mandrill_Event_Ledger::ingest_events( $events );

		if ( class_exists( '\\PRC\\Platform\\CRM\\Email_Activity' ) ) {
			foreach ( $events as $event ) {
				\PRC\Platform\CRM\Email_Activity::ingest_event( $event );
			}
		}

		return array(
			'stored' => $stored,
		);
	}

	/**
	 * Campaign or transactional post for engagement report routes.
	 *
	 * @param WP_REST_Request $request Request with post_id.
	 * @return \WP_Post|\WP_Error
	 */
	private function engagement_report_post( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post || ( ! Post_Type::is_campaign_post( $post ) && ! Post_Type::is_transactional_post( $post ) ) ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Invalid email post.', 'prc-email-builder' ),
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * POST /send — deliver a published mandrill newsletter to its bulk audience.
	 *
	 * @param WP_REST_Request $request Request with post_id and optional reset flag.
	 */
	public function send_newsletter( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );
		$reset   = (bool) $request->get_param( 'reset' );

		$post = get_post( $post_id );
		if ( ! $post || ! Post_Type::is_transactional_post( $post ) ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Invalid transactional email post.', 'prc-email-builder' ),
				array( 'status' => 404 )
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return new \WP_Error(
				'not_published',
				__( 'Newsletter must be published before sending.', 'prc-email-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( Migration::is_migrated( $post_id ) ) {
			return new \WP_Error(
				'migrated',
				__( 'This newsletter has been migrated and cannot be sent from the builder.', 'prc-email-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( 'mandrill' !== Post_Type::transactional_delivery_mode( $post ) ) {
			return new \WP_Error(
				'wrong_delivery_mode',
				__( 'Only bulk-list transactional emails can be sent from this endpoint.', 'prc-email-builder' ),
				array( 'status' => 400 )
			);
		}

		$current_status = (string) get_post_meta( $post_id, Mandrill_Sender::STATUS_META, true );
		if ( 'sent' === $current_status && ! $reset ) {
			return new \WP_Error(
				'already_sent',
				__( 'This newsletter was already sent. Pass reset=true to send again.', 'prc-email-builder' ),
				array( 'status' => 409 )
			);
		}

		if ( $reset && 'sent' === $current_status ) {
			$sender = new Mandrill_Sender( null );
			$sender->reset_progress( $post_id );
		}

		$html = Cached_Email_Html::resolve( $post_id );
		if ( is_wp_error( $html ) ) {
			$html->add_data( array( 'status' => 409 ) );
			return $html;
		}

		$sender = new Mandrill_Sender( null );
		if ( $sender->is_locked( $post_id ) ) {
			return new \WP_Error(
				'send_locked',
				__( 'A send is already in progress for this newsletter.', 'prc-email-builder' ),
				array( 'status' => 409 )
			);
		}

		$scheduled = $sender->schedule_send( $post_id );
		if ( is_wp_error( $scheduled ) ) {
			$code = $scheduled->get_error_code();
			if ( in_array( $code, array( 'send_locked', 'send_already_scheduled' ), true ) ) {
				$scheduled->add_data( array( 'status' => 409 ) );
			} elseif ( Email_Subject::ERROR_CODE === $code ) {
				$scheduled->add_data( array( 'status' => 400 ) );
			} else {
				$scheduled->add_data( array( 'status' => 500 ) );
			}
			return $scheduled;
		}

		return rest_ensure_response(
			array(
				'status'  => 'sending',
				'summary' => array(),
			)
		);
	}

	/**
	 * Permission check for POST /send-system-email — caller must be able to edit
	 * the specific transactional post being sent, not merely hold the generic
	 * edit_posts capability (which would otherwise let an editor send branded
	 * mail from a transactional template they cannot edit).
	 *
	 * @param WP_REST_Request $request Request with post_id.
	 */
	public function send_system_email_permission_check( WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );

		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * POST /transactional/{post_id}/send — send one transactional email.
	 *
	 * @param WP_REST_Request $request Request with post_id, to_email, context, and optional dry_run.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function send_system_email( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$post_id  = (int) $request->get_param( 'post_id' );
		$to_email = (string) $request->get_param( 'to_email' );
		$context  = (array) $request->get_param( 'context' );

		// Dry run: render and return the email without sending it.
		if ( (bool) $request->get_param( 'dry_run' ) ) {
			$preview = System_Email_Sender::preview( $post_id, $context );
			if ( is_wp_error( $preview ) ) {
				return $preview;
			}
			return rest_ensure_response(
				array_merge(
					array(
						'success' => true,
						'dry_run' => true,
					),
					$preview
				)
			);
		}

		$result = System_Email_Sender::send( $post_id, $to_email, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * GET /audiences/{id}/segments — Mailchimp saved segments for an audience.
	 *
	 * @param WP_REST_Request $request Request with audience_id.
	 * @return WP_REST_Response
	 */
	public function get_segments( WP_REST_Request $request ): WP_REST_Response {
		$audience_id = (string) $request['audience_id'];
		$segments    = ( new Mailchimp() )->get_segments( $audience_id );

		if ( is_wp_error( $segments ) ) {
			return new WP_REST_Response(
				array( 'error' => $segments->get_error_message() ),
				503
			);
		}

		return rest_ensure_response( $segments );
	}

	/**
	 * Returns subscriber count for an audience, optionally scoped to a saved segment.
	 *
	 * @param WP_REST_Request $request Request with audience_id and optional segment_id.
	 */
	public function get_audience_subscriber_count( WP_REST_Request $request ): WP_REST_Response {
		$audience_id = (string) $request['audience_id'];
		$segment_id  = (string) ( $request['segment_id'] ?? '' );
		$count       = ( new Mailchimp() )->get_subscriber_count( $audience_id, $segment_id );

		if ( is_wp_error( $count ) ) {
			return new WP_REST_Response(
				array( 'error' => $count->get_error_message() ),
				503
			);
		}

		return rest_ensure_response(
			array(
				'count' => (int) $count,
				'scope' => '' !== $segment_id ? 'segment' : 'audience',
			)
		);
	}

	/**
	 * Push current post HTML and settings to an existing Mailchimp draft campaign.
	 *
	 * @param WP_REST_Request $request Request with post_id.
	 */
	public function update_mailchimp_draft( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! Post_Type::is_campaign_post( $post_id ) ) {
			return new \WP_Error(
				'invalid_post_type',
				'Mailchimp draft updates apply only to campaign newsletters.',
				array( 'status' => 400 )
			);
		}

		$result = ( new Mailchimp() )->update_campaign_draft( $post_id );
		if ( is_wp_error( $result ) ) {
			return $this->normalize_rest_error( $result );
		}

		return rest_ensure_response(
			array_merge( array( 'success' => true ), $result )
		);
	}

	/**
	 * Clear Mailchimp campaign linkage meta (and related report/Slack markers).
	 *
	 * Idempotent: already-unlinked posts still return 200.
	 *
	 * @param WP_REST_Request $request Request with post_id.
	 */
	public function unlink_mailchimp_campaign( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! Post_Type::is_campaign_post( $post_id ) ) {
			return new \WP_Error(
				'invalid_post_type',
				'Mailchimp campaign unlink applies only to campaign newsletters.',
				array( 'status' => 400 )
			);
		}

		$cleared = Campaign_Linkage::clear( $post_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'post_id' => $post_id,
				'cleared' => array(
					'campaign_id' => $cleared['campaign_id'],
					'had_report'  => $cleared['had_report'],
				),
				'linkage' => array(
					'campaign_id' => '',
					'admin_url'   => '',
					'status'      => '',
				),
			)
		);
	}

	/**
	 * Create and send a Mailchimp campaign for a published campaign newsletter.
	 *
	 * Recovery path when auto-dispatch failed, or to retry send for a linked
	 * draft (`save` status). Route path kept for backward compatibility.
	 *
	 * @param WP_REST_Request $request Request with post_id.
	 */
	public function create_mailchimp_draft( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		$linkage = Campaign_Linkage::read( $post_id );
		if (
			'' !== $linkage['campaign_id']
			&& ! in_array( $linkage['status'], array( '', 'save' ), true )
		) {
			return new \WP_Error(
				'already_sent',
				'This campaign was already sent or is no longer a Mailchimp draft. Unlink it first to create a new send.',
				array( 'status' => 409 )
			);
		}

		$result = ( new Mailchimp() )->create_and_send_campaign( $post_id, true );
		if ( is_wp_error( $result ) ) {
			return $this->normalize_rest_error( $result );
		}

		return rest_ensure_response(
			array_merge( array( 'success' => true ), $result )
		);
	}

	/**
	 * GET /library — paginated email listing for the Email Library DataViews UI.
	 *
	 * @param WP_REST_Request $request Request with filter/sort/pagination args.
	 */
	public function get_library( WP_REST_Request $request ): WP_REST_Response {
		$post_type_param = (string) $request->get_param( 'post_type' );
		$post_types      = $this->resolve_library_post_types( $post_type_param );

		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$status_param = (string) $request->get_param( 'status' );
		$statuses     = array_filter(
			array_map( 'sanitize_key', explode( ',', $status_param ) )
		);
		if ( empty( $statuses ) ) {
			$statuses = array( 'publish', 'draft', 'private' );
		}

		$mailchimp_values = $this->parse_library_status_values(
			(string) $request->get_param( 'mailchimp_status' )
		);
		$mandrill_values  = $this->parse_library_status_values(
			(string) $request->get_param( 'mandrill_status' )
		);
		$has_mailchimp    = ! empty( $mailchimp_values );
		$has_mandrill     = ! empty( $mandrill_values );
		$querying_both    = count( $post_types ) > 1;

		// Provider meta keys only exist on their respective post types.
		if ( $querying_both && ( $has_mailchimp || $has_mandrill ) ) {
			if ( $has_mailchimp && ! $has_mandrill ) {
				$post_types = array_values(
					array_intersect( $post_types, array( Post_Type::CAMPAIGN_POST_TYPE ) )
				);
			} elseif ( $has_mandrill && ! $has_mailchimp ) {
				$post_types = array_values(
					array_intersect( $post_types, array( Post_Type::TRANSACTIONAL_POST_TYPE ) )
				);
			}
		}

		if ( empty( $post_types ) ) {
			$response = rest_ensure_response( array() );
			$response->header( 'X-WP-Total', '0' );
			$response->header( 'X-WP-TotalPages', '0' );

			return $response;
		}

		$query_args = array(
			'post_type'              => $post_types,
			'post_status'            => $statuses,
			'perm'                   => 'editable',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => (string) $request->get_param( 'orderby' ),
			'order'                  => strtoupper( (string) $request->get_param( 'order' ) ),
			'no_found_rows'          => false,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
		);

		$orderby_param       = (string) $request->get_param( 'orderby' );
		$engagement_sort     = in_array( $orderby_param, array( 'open_rate', 'click_rate' ), true );
		$engagement_meta_key = 'click_rate' === $orderby_param
			? Report_Store::META_CLICK_RATE
			: Report_Store::META_OPEN_RATE;

		if ( $engagement_sort ) {
			$query_args['posts_per_page'] = -1; // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page -- engagement sort ranks the full editable set in PHP, then slices to the request page.
			$query_args['paged']          = 1;
			$query_args['orderby']        = 'date';
		}

		$tax_query = $this->build_library_tax_query( (string) $request->get_param( 'newsletter_list' ) );
		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$dataview_post_type = $post_types[0];

		if ( $querying_both && $has_mailchimp && $has_mandrill ) {
			$query_args = apply_filters(
				'prc_wp_admin_dataview_query_args',
				$query_args,
				$request,
				$dataview_post_type
			);
			$query_args = $this->apply_library_search( $query_args, $request, $statuses );
			$query      = $this->query_library_with_dual_status_filter(
				$query_args,
				$mailchimp_values,
				$mandrill_values
			);
		} else {
			$meta_query = $this->build_library_meta_query_from_values(
				$mailchimp_values,
				$mandrill_values
			);
			if ( ! empty( $meta_query ) ) {
				$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			}

			$query_args = apply_filters(
				'prc_wp_admin_dataview_query_args',
				$query_args,
				$request,
				$dataview_post_type
			);
			$query_args = $this->apply_library_search( $query_args, $request, $statuses );
			$query      = new \WP_Query( $query_args ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$posts = $query->posts;

		if ( $engagement_sort ) {
			$order       = strtoupper( (string) $request->get_param( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';
			$posts       = $this->sort_posts_by_engagement_rate( $posts, $engagement_meta_key, $order );
			$total       = count( $posts );
			$total_pages = max( 1, (int) ceil( $total / $per_page ) );
			$offset      = ( $page - 1 ) * $per_page;
			$posts       = array_slice( $posts, $offset, $per_page );
		} else {
			$total       = (int) $query->found_posts;
			$total_pages = (int) $query->max_num_pages;
		}

		$rows = array_map(
			fn( \WP_Post $post ) => apply_filters(
				'prc_wp_admin_dataview_shape_row',
				$this->shape_library_row( $post ),
				$post,
				$post->post_type
			),
			$posts
		);

		$response = rest_ensure_response( $rows );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * Route library search through ElasticPress when the DataViews shell is present.
	 *
	 * @param array<string, mixed> $query_args Query args.
	 * @param WP_REST_Request      $request    Request.
	 * @param string[]             $statuses   Post statuses.
	 * @return array<string, mixed>
	 */
	private function apply_library_search( array $query_args, WP_REST_Request $request, array $statuses ): array {
		$search = (string) $request->get_param( 'search' );
		if ( class_exists( \PRC\Platform\Wp_Admin_Dataview\Search_Query::class ) ) {
			return \PRC\Platform\Wp_Admin_Dataview\Search_Query::apply( $query_args, $search, $statuses );
		}
		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}
		return $query_args;
	}

	/**
	 * Sort library posts by denormalized engagement rate with nulls last.
	 *
	 * @param \WP_Post[] $posts     Posts to sort.
	 * @param string     $meta_key  Open or click rate meta key.
	 * @param string     $order     ASC or DESC.
	 * @return \WP_Post[]
	 */
	private function sort_posts_by_engagement_rate( array $posts, string $meta_key, string $order ): array {
		usort(
			$posts,
			function ( \WP_Post $a, \WP_Post $b ) use ( $meta_key, $order ): int {
				$va = $this->engagement_rate_for_sort( $a->ID, $meta_key );
				$vb = $this->engagement_rate_for_sort( $b->ID, $meta_key );

				if ( null === $va && null === $vb ) {
					return 0;
				}
				if ( null === $va ) {
					return 1;
				}
				if ( null === $vb ) {
					return -1;
				}

				if ( 'ASC' === $order ) {
					return $va <=> $vb;
				}

				return $vb <=> $va;
			}
		);

		return $posts;
	}

	/**
	 * Stored engagement rate for library sort, or null when the row has none.
	 *
	 * @param int    $post_id  Email post ID.
	 * @param string $meta_key Open-rate or click-rate meta key.
	 * @return float|null
	 */
	private function engagement_rate_for_sort( int $post_id, string $meta_key ): ?float {
		if ( Post_Type::CAMPAIGN_POST_TYPE !== get_post_type( $post_id ) ) {
			return null;
		}

		if ( Report_Store::STATE_OK !== Report_Store::get_sync_state( $post_id )
			&& null === Report_Store::get_report( $post_id ) ) {
			return null;
		}

		$value = get_post_meta( $post_id, $meta_key, true );
		if ( '' === $value && null === Report_Store::get_report( $post_id ) ) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * Resolve post types for the library query from the post_type param.
	 *
	 * @param string $post_type_param One of all, campaign, txn.
	 * @return string[]
	 */
	private function resolve_library_post_types( string $post_type_param ): array {
		return match ( $post_type_param ) {
			'campaign' => array( Post_Type::CAMPAIGN_POST_TYPE ),
			'txn'      => array( Post_Type::TRANSACTIONAL_POST_TYPE ),
			default    => Post_Type::POST_TYPES,
		};
	}

	/**
	 * Build tax_query for prc_newsletter_list slugs (comma-separated).
	 *
	 * @param string $newsletter_list Comma-separated term slugs.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_library_tax_query( string $newsletter_list ): array {
		$slugs = array_filter(
			array_map(
				static fn( string $slug ) => sanitize_title( $slug ),
				explode( ',', $newsletter_list )
			)
		);

		if ( empty( $slugs ) ) {
			return array();
		}

		return array(
			array(
				'taxonomy' => Post_Type::TAXONOMY,
				'field'    => 'slug',
				'terms'    => $slugs,
				'operator' => 'IN',
			),
		);
	}

	/**
	 * Build meta_query for Mailchimp and/or Mandrill status filters.
	 *
	 * When both are provided, matches posts satisfying either filter (OR).
	 *
	 * @param string $mailchimp_status Comma-separated Mailchimp status values.
	 * @param string $mandrill_status  Comma-separated Mandrill status values.
	 * @return array<int|string, mixed>
	 */
	private function build_library_meta_query( string $mailchimp_status, string $mandrill_status ): array {
		return $this->build_library_meta_query_from_values(
			$this->parse_library_status_values( $mailchimp_status ),
			$this->parse_library_status_values( $mandrill_status )
		);
	}

	/**
	 * Build meta_query for Mailchimp and/or Mandrill status filters.
	 *
	 * When both are provided, matches posts satisfying either filter (OR).
	 * Callers querying multiple post types must scope filters by post type first.
	 *
	 * @param string[] $mailchimp_values Parsed Mailchimp status values.
	 * @param string[] $mandrill_values  Parsed Mandrill status values.
	 * @return array<int|string, mixed>
	 */
	private function build_library_meta_query_from_values( array $mailchimp_values, array $mandrill_values ): array {
		$clauses = array();

		if ( ! empty( $mailchimp_values ) ) {
			$clauses[] = $this->build_status_meta_clause(
				'prc_email_mailchimp_campaign_status',
				$mailchimp_values
			);
		}

		if ( ! empty( $mandrill_values ) ) {
			$clauses[] = $this->build_status_meta_clause(
				'prc_email_mandrill_send_status',
				$mandrill_values
			);
		}

		if ( empty( $clauses ) ) {
			return array();
		}

		if ( count( $clauses ) === 1 ) {
			return $clauses;
		}

		return array_merge(
			array( 'relation' => 'OR' ),
			$clauses
		);
	}

	/**
	 * Query the library when both provider status filters are active across post types.
	 *
	 * Runs separate ID queries per post type so empty-provider filters do not leak rows.
	 *
	 * @param array<string, mixed> $query_args       Base library query args.
	 * @param string[]             $mailchimp_values Parsed Mailchimp status values.
	 * @param string[]             $mandrill_values  Parsed Mandrill status values.
	 */
	private function query_library_with_dual_status_filter(
		array $query_args,
		array $mailchimp_values,
		array $mandrill_values
	): \WP_Query {
		$id_args                   = $query_args;
		$id_args['posts_per_page'] = -1; // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page -- ID union across post types before the paged library query.
		$id_args['fields']         = 'ids';
		unset( $id_args['paged'] );

		$existing_meta = isset( $id_args['meta_query'] ) && is_array( $id_args['meta_query'] )
			? $id_args['meta_query']
			: array();

		$campaign_args = array_merge(
			$id_args,
			array(
				'post_type'  => array( Post_Type::CAMPAIGN_POST_TYPE ),
				'meta_query' => array_merge(
					$existing_meta,
					array(
						$this->build_status_meta_clause(
							'prc_email_mailchimp_campaign_status',
							$mailchimp_values
						),
					)
				),
			)
		);

		$txn_args = array_merge(
			$id_args,
			array(
				'post_type'  => array( Post_Type::TRANSACTIONAL_POST_TYPE ),
				'meta_query' => array_merge(
					$existing_meta,
					array(
						$this->build_status_meta_clause(
							'prc_email_mandrill_send_status',
							$mandrill_values
						),
					)
				),
			)
		);

		$campaign_ids = ( new \WP_Query( $campaign_args ) )->posts; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$txn_ids      = ( new \WP_Query( $txn_args ) )->posts; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$ids          = array_values( array_unique( array_merge( $campaign_ids, $txn_ids ) ) );

		if ( empty( $ids ) ) {
			return new \WP_Query(
				array(
					'post__in'  => array( 0 ),
					'post_type' => Post_Type::POST_TYPES,
				)
			);
		}

		$paged_args             = $query_args;
		$paged_args['post__in'] = $ids;

		return new \WP_Query( $paged_args );
	}

	/**
	 * Parse comma-separated status filter values, preserving empty string sentinel.
	 *
	 * @param string $raw Raw comma-separated values.
	 * @return string[]
	 */
	private function parse_library_status_values( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static fn( string $value ) => sanitize_text_field( $value ),
					explode( ',', $raw )
				),
				static fn( string $value ) => '__empty__' === $value || '' !== $value
			)
		);
	}

	/**
	 * Build a meta_query clause for a status meta key and allowed values.
	 *
	 * Supports the __empty__ sentinel for posts with no status meta value.
	 *
	 * @param string   $meta_key Meta key.
	 * @param string[] $values   Allowed values.
	 * @return array<string, mixed>
	 */
	private function build_status_meta_clause( string $meta_key, array $values ): array {
		$includes_empty = in_array( '__empty__', $values, true );
		$non_empty      = array_values(
			array_filter(
				$values,
				static fn( string $value ) => '__empty__' !== $value
			)
		);

		if ( $includes_empty && ! empty( $non_empty ) ) {
			return array(
				'relation' => 'OR',
				array(
					'key'     => $meta_key,
					'value'   => $non_empty,
					'compare' => 'IN',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => $meta_key,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $meta_key,
						'value'   => '',
						'compare' => '=',
					),
				),
			);
		}

		if ( $includes_empty ) {
			return array(
				'relation' => 'OR',
				array(
					'key'     => $meta_key,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => $meta_key,
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		return array(
			'key'     => $meta_key,
			'value'   => $non_empty,
			'compare' => 'IN',
		);
	}

	/**
	 * Shape a WP_Post into a library row for the DataViews UI.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	private function shape_library_row( \WP_Post $post ): array {
		$terms = wp_get_post_terms(
			$post->ID,
			Post_Type::TAXONOMY,
			array( 'fields' => 'all' )
		);

		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$newsletter_lists = array_map(
			static fn( \WP_Term $term ) => array(
				'slug'  => $term->slug,
				'label' => plain_text( (string) $term->name ),
			),
			$terms
		);

		$type  = Post_Type::CAMPAIGN_POST_TYPE === $post->post_type ? 'campaign' : 'txn';
		$stats = Email_Reports::row_stats( $post->ID );

		return array(
			'id'                => $post->ID,
			'type'              => $type,
			'title'             => plain_text( (string) get_the_title( $post ) ),
			'status'            => $post->post_status,
			'previousStatus'    => (string) get_post_meta( $post->ID, '_wp_trash_meta_status', true ),
			'date'              => mysql2date( 'c', $post->post_date, false ),
			'modified'          => mysql2date( 'c', $post->post_modified, false ),
			'edit_url'          => get_edit_post_link( $post->ID, 'raw' ),
			'newsletter_lists'  => $newsletter_lists,
			'subject'           => (string) get_post_meta( $post->ID, 'prc_email_subject', true ),
			'mailchimp_status'  => (string) get_post_meta( $post->ID, 'prc_email_mailchimp_campaign_status', true ),
			'mandrill_status'   => (string) get_post_meta( $post->ID, 'prc_email_mandrill_send_status', true ),
			'delivery_mode'     => Post_Type::is_transactional_post( $post )
				? Post_Type::transactional_delivery_mode( $post )
				: '',
			'open_rate'         => $stats['open_rate'],
			'click_rate'        => $stats['click_rate'],
			'stats_available'   => $stats['stats_available'],
			'channel'           => $stats['channel'],
			'report_sync_state' => Report_Store::get_sync_state( $post->ID ),
		);
	}

	/**
	 * Ensure a WP_Error carries an HTTP status for REST serialization.
	 *
	 * @param \WP_Error $error Original error.
	 * @return \WP_Error
	 */
	private function normalize_rest_error( \WP_Error $error ): \WP_Error {
		$data   = $error->get_error_data();
		$status = 500;
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
		}

		return new \WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array( 'status' => $status > 0 ? $status : 500 )
		);
	}
}
