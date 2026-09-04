<?php
/**
 * Transactional Firebase Auth domain audience builds.
 *
 * Thin adapter over {@see Audience_Job} so existing REST aliases and tests keep
 * working. Registers the auth-domain builder on the audience registry.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Thin adapter that registers the email-domain builder and delegates to Audience_Job.
 */
final class Auth_Domain_Audience_Build {
	public const COLLECT_HOOK            = 'prc_email_builder_collect_auth_domain_audience';
	private const AUDIENCE_OPTION_PREFIX = 'prc_email_audience_auth_domain_';

	/**
	 * Bind the generic job collect hook.
	 */
	public static function init(): void {
		Audience_Job::init();
	}

	/**
	 * Register the auth-domain builder on the audience registry.
	 */
	public static function register_builder(): void {
		Audience_Builder_Registry::register(
			array(
				'slug'                  => 'auth-domain',
				'label'                 => 'Email domain',
				'description'           => 'Firebase Auth users whose email domain contains a substring.',
				'option_prefix'         => self::AUDIENCE_OPTION_PREFIX,
				'form'                  => 'domain-query',
				'job_id_prefix'         => 'ad_',
				'firebase_endpoint_key' => 'auth_domain',
				'supports_create_draft' => true,
				'parse_input'           => array( self::class, 'parse_input' ),
				'enqueue_body'          => array( self::class, 'enqueue_body' ),
				'import'                => array( Auth_Domain_Audience_Importer::class, 'import' ),
			)
		);
	}

	/**
	 * Start an auth-domain audience job.
	 *
	 * @param array<string, mixed> $input Parsed REST input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function start( array $input ): array|WP_Error {
		self::ensure_registered();

		return Audience_Job::start( 'auth-domain', $input );
	}

	/**
	 * Poll an auth-domain audience job.
	 *
	 * @param string $job_id Job ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function status( string $job_id ): array|WP_Error {
		self::ensure_registered();

		return Audience_Job::status( $job_id );
	}

	/**
	 * Create a transactional draft for a ready auth-domain job.
	 *
	 * @param string $job_id Job ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_draft( string $job_id ): array|WP_Error {
		self::ensure_registered();

		return Audience_Job::create_draft( $job_id );
	}

	/**
	 * Action Scheduler collect callback for auth-domain jobs.
	 *
	 * @param string $job_id Job ID.
	 */
	public static function collect( string $job_id ): void {
		self::ensure_registered();
		Audience_Job::collect( $job_id );
	}

	/**
	 * Option key for an imported auth-domain audience.
	 *
	 * @param string $job_id Job ID.
	 */
	public static function audience_key( string $job_id ): string {
		return self::AUDIENCE_OPTION_PREFIX . $job_id;
	}

	/**
	 * Shape an internal job record for REST.
	 *
	 * @param array<string, mixed> $job Internal job record.
	 * @return array<string, mixed>
	 */
	public static function to_rest( array $job ): array {
		return Audience_Job::to_rest( $job );
	}

	/**
	 * Parse REST / CLI input for the auth-domain builder.
	 *
	 * @param array<string, mixed> $input REST / CLI input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function parse_input( array $input ): array|WP_Error {
		$query = Domain_Contains_Query::parse( $input );
		if ( is_wp_error( $query ) ) {
			return $query;
		}

		$label = isset( $input['label'] ) && is_string( $input['label'] )
			? trim( $input['label'] )
			: '';
		if ( '' === $label ) {
			return new WP_Error( 'invalid_query', 'Audience name is required.', array( 'status' => 400 ) );
		}

		return array(
			'query' => $query,
			'label' => $label,
		);
	}

	/**
	 * Firebase enqueue HTTP body for an auth-domain job.
	 *
	 * @param array<string, mixed> $job Internal job record.
	 * @return array<string, mixed>
	 */
	public static function enqueue_body( array $job ): array {
		return array(
			'jobId'          => $job['jobId'],
			'domainContains' => $job['query']['domainContains'],
			'verification'   => $job['query']['verification'],
		);
	}

	/**
	 * Register the builder if tests or aliases invoke this class first.
	 */
	private static function ensure_registered(): void {
		if ( null === Audience_Builder_Registry::get( 'auth-domain' ) ) {
			self::register_builder();
		}
	}
}
