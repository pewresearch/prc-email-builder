<?php
/**
 * Generic Firebase enqueue + ledger + artifact audience job runner.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Generic Firebase enqueue + ledger + artifact audience job runner.
 */
final class Audience_Job {
	public const COLLECT_HOOK           = 'prc_email_builder_collect_audience_job';
	private const JOB_OPTION_PREFIX     = 'prc_email_audience_job_';
	private const JOB_TIMEOUT_SECONDS   = 900;
	private const COLLECT_DELAY_SECONDS = 20;
	private const WAIT_POLL_SECONDS     = 5;

	/**
	 * Bind Action Scheduler collect hooks.
	 */
	public static function init(): void {
		add_action( self::COLLECT_HOOK, array( self::class, 'collect' ) );
		add_action( Auth_Domain_Audience_Build::COLLECT_HOOK, array( self::class, 'collect' ) );
	}

	/**
	 * Start an audience job.
	 *
	 * @param string               $builder_slug Builder slug.
	 * @param array<string, mixed> $input        Parsed REST / CLI input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function start( string $builder_slug, array $input ): array|WP_Error {
		$builder = Audience_Builder_Registry::get( $builder_slug );
		if ( null === $builder ) {
			return new WP_Error( 'invalid_builder', 'Unknown audience builder.', array( 'status' => 400 ) );
		}

		$parsed = call_user_func( $builder['parse_input'], $input );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		if ( ! is_array( $parsed ) || ! isset( $parsed['query'] ) || ! is_array( $parsed['query'] ) ) {
			return new WP_Error( 'invalid_query', 'The audience builder input is invalid.', array( 'status' => 400 ) );
		}

		$label = isset( $parsed['label'] ) && is_string( $parsed['label'] )
			? trim( $parsed['label'] )
			: '';
		if ( '' === $label ) {
			return new WP_Error( 'invalid_query', 'Audience name is required.', array( 'status' => 400 ) );
		}

		if ( 'csv-upload' === $builder['form'] ) {
			return self::persist_local( $builder, $parsed, $label );
		}

		$job_id = self::mint_job_id( $builder['job_id_prefix'] );
		$job    = array(
			'jobId'        => $job_id,
			'builder'      => $builder_slug,
			'query'        => $parsed['query'],
			'label'        => $label,
			'phase'        => 'queued',
			'requestedAt'  => gmdate( 'c' ),
			'dryRun'       => ! empty( $parsed['dryRun'] ),
			'sourcePostId' => isset( $parsed['sourcePostId'] ) ? (int) $parsed['sourcePostId'] : 0,
			'draft'        => array( 'status' => 'not_requested' ),
		);

		if ( ! add_option( self::job_option_key( $job_id ), $job, '', false ) ) {
			return new WP_Error( 'enqueue_failed', 'Could not create the audience job.', array( 'status' => 500 ) );
		}

		$enqueue_error = self::enqueue( $builder, $job );
		if ( is_wp_error( $enqueue_error ) && 'enqueue_failed' === $enqueue_error->get_error_code() ) {
			return self::to_rest(
				self::fail_job( $job, 'enqueue_failed', $enqueue_error->get_error_message() )
			);
		}

		// Timeouts and 5xx stay queued: the function may have written the ledger
		// after the HTTP client gave up. status() re-POSTs while the row is missing.
		self::schedule_collection( $job_id );

		return self::to_rest( $job );
	}

	/**
	 * Poll a job, import the artifact when ready, and return the REST view.
	 *
	 * @param string $job_id Job ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function status( string $job_id ): array|WP_Error {
		if ( null === Audience_Builder_Registry::match_job_id( $job_id ) ) {
			return new WP_Error( 'invalid_query', 'Invalid audience job ID.', array( 'status' => 400 ) );
		}

		$job = get_option( self::job_option_key( $job_id ), null );
		if ( ! is_array( $job ) ) {
			return new WP_Error( 'audience_job_not_found', 'Audience job not found.', array( 'status' => 404 ) );
		}

		if ( 'ready' === $job['phase'] || 'failed' === $job['phase'] ) {
			return self::to_rest( $job );
		}

		$ledger = self::read_ledger( $job_id );
		if ( is_wp_error( $ledger ) ) {
			return self::timeout_or_wait( $job );
		}

		if ( null === $ledger || 'queued' === ( $ledger['phase'] ?? null ) ) {
			if ( self::has_timed_out( $job ) ) {
				return self::timeout_or_wait( $job );
			}

			if ( null === $ledger && self::should_retry_enqueue( $job ) ) {
				$job['lastEnqueueAt'] = gmdate( 'c' );
				self::save_job( $job );
				$enqueue_error = self::retry_enqueue( $job );
				if ( is_wp_error( $enqueue_error ) && 'enqueue_failed' === $enqueue_error->get_error_code() ) {
					return self::to_rest(
						self::fail_job( $job, 'enqueue_failed', $enqueue_error->get_error_message() )
					);
				}
			}

			return self::timeout_or_wait( $job );
		}

		if ( 'scanning' === ( $ledger['phase'] ?? null ) ) {
			if ( self::has_timed_out( $job ) ) {
				return self::timeout_or_wait( $job );
			}

			$job['phase']         = 'scanning';
			$job['scannedUsers']  = isset( $ledger['scannedUsers'] ) ? (int) $ledger['scannedUsers'] : null;
			$job['matchedUsers']  = isset( $ledger['matchedUsers'] ) ? (int) $ledger['matchedUsers'] : null;
			$job['scannedGroups'] = isset( $ledger['scannedGroups'] ) ? (int) $ledger['scannedGroups'] : null;
			$job['v2Groups']      = isset( $ledger['v2Groups'] ) ? (int) $ledger['v2Groups'] : null;
			self::save_job( $job );
			self::schedule_collection( $job_id );

			return self::to_rest( $job );
		}

		if ( 'failed' === ( $ledger['phase'] ?? null ) ) {
			$message = is_array( $ledger['error'] ?? null ) && is_string( $ledger['error']['message'] ?? null )
				? $ledger['error']['message']
				: 'Firebase could not build the audience.';

			return self::to_rest( self::fail_job( $job, 'scan_failed', $message ) );
		}

		if ( 'artifact_ready' !== ( $ledger['phase'] ?? null ) ) {
			return self::to_rest(
				self::fail_job( $job, 'scan_failed', 'Firebase returned an unknown audience job phase.' )
			);
		}

		$artifact = self::download_artifact( $ledger );
		if ( is_wp_error( $artifact ) ) {
			if ( 'artifact_retry' === $artifact->get_error_code() ) {
				return self::timeout_or_wait( $job );
			}

			return self::to_rest(
				self::fail_job( $job, 'artifact_invalid', $artifact->get_error_message() )
			);
		}

		if ( ! empty( $job['dryRun'] ) ) {
			$job['phase']         = 'ready';
			$job['count']         = isset( $artifact['count'] ) ? (int) $artifact['count'] : 0;
			$job['scannedUsers']  = isset( $artifact['scannedUsers'] ) ? (int) $artifact['scannedUsers'] : ( $job['scannedUsers'] ?? null );
			$job['matchedUsers']  = isset( $artifact['matchedUsers'] ) ? (int) $artifact['matchedUsers'] : ( $job['matchedUsers'] ?? null );
			$job['scannedGroups'] = isset( $artifact['scannedGroups'] ) ? (int) $artifact['scannedGroups'] : ( $job['scannedGroups'] ?? null );
			$job['v2Groups']      = isset( $artifact['v2Groups'] ) ? (int) $artifact['v2Groups'] : ( $job['v2Groups'] ?? null );
			self::save_job( $job );

			return self::to_rest( $job );
		}

		$builder_slug = is_string( $job['builder'] ?? null ) ? $job['builder'] : 'auth-domain';
		$builder      = Audience_Builder_Registry::get( $builder_slug );
		if ( null === $builder ) {
			return self::to_rest(
				self::fail_job( $job, 'scan_failed', 'The audience builder is no longer registered.' )
			);
		}

		$audience = call_user_func( $builder['import'], $job, $artifact );
		if ( is_wp_error( $audience ) ) {
			return self::to_rest(
				self::fail_job( $job, $audience->get_error_code(), $audience->get_error_message() )
			);
		}

		$job['phase']    = 'ready';
		$job['audience'] = $audience;
		self::save_job( $job );

		return self::to_rest( $job );
	}

	/**
	 * Insert a transactional draft for a ready audience job.
	 *
	 * @param string $job_id Job ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_draft( string $job_id ): array|WP_Error {
		$view = self::status( $job_id );
		if ( is_wp_error( $view ) ) {
			return $view;
		}
		if ( 'ready' !== $view['phase'] || empty( $view['audience']['key'] ) ) {
			return new WP_Error( 'audience_not_ready', 'The audience is not ready.', array( 'status' => 409 ) );
		}

		$job = get_option( self::job_option_key( $job_id ), null );
		if ( ! is_array( $job ) ) {
			return new WP_Error( 'audience_job_not_found', 'Audience job not found.', array( 'status' => 404 ) );
		}

		$builder_slug = is_string( $job['builder'] ?? null ) ? $job['builder'] : 'auth-domain';
		$builder      = Audience_Builder_Registry::get( $builder_slug );
		if ( null === $builder || empty( $builder['supports_create_draft'] ) ) {
			return new WP_Error( 'audience_not_ready', 'This audience builder does not create drafts.', array( 'status' => 409 ) );
		}

		if ( 'created' === ( $job['draft']['status'] ?? null ) ) {
			return self::to_rest( $job );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => Post_Type::TRANSACTIONAL_POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => $job['label'],
				'meta_input'  => array(
					'prc_email_delivery_mode'       => 'mandrill',
					'prc_email_audience_option_key' => $job['audience']['key'],
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			$job['draft'] = array(
				'status'  => 'failed',
				'message' => $post_id->get_error_message(),
			);
			self::save_job( $job );

			return self::to_rest( $job );
		}

		$edit_url     = get_edit_post_link( $post_id, 'raw' );
		$job['draft'] = array(
			'status'  => 'created',
			'postId'  => (int) $post_id,
			'editUrl' => is_string( $edit_url )
				? $edit_url
				: admin_url( "post.php?post={$post_id}&action=edit" ),
		);
		self::save_job( $job );

		return self::to_rest( $job );
	}

	/**
	 * Action Scheduler callback that polls a job and reschedules while in flight.
	 *
	 * @param string $job_id Job ID.
	 */
	public static function collect( string $job_id ): void {
		$view = self::status( $job_id );
		if (
			! is_wp_error( $view )
			&& in_array( $view['phase'], array( 'queued', 'scanning' ), true )
		) {
			self::schedule_collection( $job_id, true );
		}
	}

	/**
	 * Block until the job is ready, failed, or times out. CLI use only.
	 *
	 * @param string $job_id          Job ID.
	 * @param int    $timeout_seconds Max wait in seconds.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function wait( string $job_id, int $timeout_seconds = self::JOB_TIMEOUT_SECONDS ): array|WP_Error {
		$deadline = time() + $timeout_seconds;
		do {
			$view = self::status( $job_id );
			if ( is_wp_error( $view ) ) {
				return $view;
			}
			if ( in_array( $view['phase'], array( 'ready', 'failed' ), true ) ) {
				return $view;
			}
			if ( time() >= $deadline ) {
				break;
			}
			sleep( self::WAIT_POLL_SECONDS );
		} while ( time() < $deadline );

		return new WP_Error(
			'timed_out',
			'The audience build did not finish within 15 minutes.',
			array( 'status' => 504 )
		);
	}

	/**
	 * Persist a local (non-Firebase) audience and return a ready job view.
	 *
	 * @param array<string, mixed> $builder Validated builder record.
	 * @param array<string, mixed> $parsed  parse_input result.
	 * @param string               $label   Audience label.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function persist_local( array $builder, array $parsed, string $label ): array|WP_Error {
		$emails = $parsed['emails'] ?? null;
		if ( ! is_array( $emails ) || ! array_is_list( $emails ) || array() === $emails ) {
			return new WP_Error(
				'csv_no_emails',
				'The CSV file does not contain any valid email addresses.',
				array( 'status' => 400 )
			);
		}

		$job_id = self::mint_job_id( $builder['job_id_prefix'] );
		$job    = array(
			'jobId'        => $job_id,
			'builder'      => $builder['slug'],
			'query'        => $parsed['query'],
			'label'        => $label,
			'phase'        => 'queued',
			'requestedAt'  => gmdate( 'c' ),
			'dryRun'       => false,
			'sourcePostId' => 0,
			'draft'        => array( 'status' => 'not_requested' ),
		);

		$audience = call_user_func(
			$builder['import'],
			$job,
			array(
				'emails'  => $emails,
				'skipped' => isset( $parsed['query']['skipped'] ) ? (int) $parsed['query']['skipped'] : 0,
				'builtAt' => $job['requestedAt'],
			)
		);
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}

		$job['phase']    = 'ready';
		$job['audience'] = $audience;

		if ( ! add_option( self::job_option_key( $job_id ), $job, '', false ) ) {
			return new WP_Error( 'enqueue_failed', 'Could not create the audience job.', array( 'status' => 500 ) );
		}

		return self::to_rest( $job );
	}

	/**
	 * Option key for a job record.
	 *
	 * @param string $job_id Job ID.
	 */
	public static function job_option_key( string $job_id ): string {
		return self::JOB_OPTION_PREFIX . $job_id;
	}

	/**
	 * Shape an internal job record for REST / CLI.
	 *
	 * @param array<string, mixed> $job Internal job record.
	 * @return array<string, mixed>
	 */
	public static function to_rest( array $job ): array {
		$view = array(
			'jobId'   => $job['jobId'],
			'builder' => $job['builder'] ?? 'auth-domain',
			'phase'   => $job['phase'],
			'label'   => $job['label'],
			'query'   => $job['query'],
			'dryRun'  => ! empty( $job['dryRun'] ),
		);

		switch ( $job['phase'] ) {
			case 'queued':
				return $view;
			case 'scanning':
				$view['scannedUsers']  = $job['scannedUsers'] ?? null;
				$view['matchedUsers']  = $job['matchedUsers'] ?? null;
				$view['scannedGroups'] = $job['scannedGroups'] ?? null;
				$view['v2Groups']      = $job['v2Groups'] ?? null;
				return $view;
			case 'ready':
				if ( ! empty( $job['dryRun'] ) ) {
					$view['count']         = $job['count'] ?? 0;
					$view['scannedUsers']  = $job['scannedUsers'] ?? null;
					$view['matchedUsers']  = $job['matchedUsers'] ?? null;
					$view['scannedGroups'] = $job['scannedGroups'] ?? null;
					$view['v2Groups']      = $job['v2Groups'] ?? null;
					return $view;
				}
				$view['audience'] = $job['audience'];
				$view['draft']    = $job['draft'];
				return $view;
			case 'failed':
				$view['error'] = $job['error'];
				return $view;
			default:
				return array_merge(
					$view,
					array(
						'phase' => 'failed',
						'error' => array(
							'code'    => 'scan_failed',
							'message' => 'The audience job has an invalid phase.',
						),
					)
				);
		}
	}

	/**
	 * Whether the current user may view or continue this job.
	 *
	 * @param array<string, mixed> $job Internal job record.
	 */
	public static function current_user_can_access( array $job ): bool {
		$builder_slug = is_string( $job['builder'] ?? null ) ? $job['builder'] : 'auth-domain';
		$builder      = Audience_Builder_Registry::get( $builder_slug );
		if ( null === $builder ) {
			return current_user_can( 'edit_posts' );
		}

		if ( 'source-entity' === $builder['form'] ) {
			$source_id = isset( $job['sourcePostId'] ) ? (int) $job['sourcePostId'] : 0;
			return $source_id > 0 && current_user_can( 'edit_post', $source_id );
		}

		return current_user_can( 'edit_posts' );
	}

	/**
	 * POST the enqueue HTTP function for this builder.
	 *
	 * @param array<string, mixed> $builder Builder record.
	 * @param array<string, mixed> $job     Internal job record.
	 */
	private static function enqueue( array $builder, array $job ): true|WP_Error {
		$endpoints = apply_filters( 'prc_platform_firebase_audiences_endpoints', array() );
		$endpoint  = $endpoints[ $builder['firebase_endpoint_key'] ] ?? '';
		if ( ! is_string( $endpoint ) || '' === $endpoint ) {
			return new WP_Error( 'enqueue_failed', 'The Firebase audience endpoint is not configured.' );
		}

		$firebase = new \PRC\Platform\Firebase();
		$id_token = $firebase->get_id_token( $endpoint );
		if ( is_wp_error( $id_token ) ) {
			return new WP_Error( 'enqueue_failed', $id_token->get_error_message() );
		}

		$body = call_user_func( $builder['enqueue_body'], $job );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'enqueue_failed', 'The audience builder enqueue payload is invalid.' );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Enqueue only writes the RTDB job.
				'headers' => array(
					'Authorization' => 'Bearer ' . $id_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'enqueue_retry', $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 202 === $status_code ) {
			return true;
		}

		if ( $status_code >= 400 && $status_code < 500 ) {
			return new WP_Error( 'enqueue_failed', 'Firebase did not accept the audience job.' );
		}

		return new WP_Error( 'enqueue_retry', 'Firebase did not acknowledge the audience job.' );
	}

	/**
	 * Re-POST the enqueue function when the RTDB ledger is still missing.
	 *
	 * @param array<string, mixed> $job Internal job record.
	 */
	private static function retry_enqueue( array $job ): true|WP_Error {
		$builder_slug = is_string( $job['builder'] ?? null ) ? $job['builder'] : 'auth-domain';
		$builder      = Audience_Builder_Registry::get( $builder_slug );
		if ( null === $builder ) {
			return new WP_Error( 'enqueue_failed', 'The audience builder is no longer registered.' );
		}

		return self::enqueue( $builder, $job );
	}

	/**
	 * Whether enough time has passed to re-POST a missing-ledger enqueue.
	 *
	 * @param array<string, mixed> $job Internal job record.
	 */
	private static function should_retry_enqueue( array $job ): bool {
		$last = strtotime( (string) ( $job['lastEnqueueAt'] ?? $job['requestedAt'] ?? '' ) );

		return false === $last || ( time() - $last ) >= self::COLLECT_DELAY_SECONDS;
	}

	/**
	 * Read the Firebase Realtime Database ledger for a job.
	 *
	 * @param string $job_id Job ID.
	 * @return array<string, mixed>|null|WP_Error
	 */
	private static function read_ledger( string $job_id ): array|null|WP_Error {
		try {
			$firebase = new \PRC\Platform\Firebase();
			if ( null === $firebase->db ) {
				return new WP_Error( 'scan_failed', 'Firebase Realtime Database is unavailable.' );
			}
			$ledger = $firebase->db
				->getReference( 'audienceBuildJobs/' . $job_id )
				->getValue();
		} catch ( \Throwable $error ) {
			return new WP_Error( 'scan_failed', $error->getMessage() );
		}

		if ( null === $ledger ) {
			return null;
		}
		if ( ! is_array( $ledger ) || ( $ledger['jobId'] ?? null ) !== $job_id ) {
			return new WP_Error( 'scan_failed', 'Firebase returned an invalid audience ledger.' );
		}

		return $ledger;
	}

	/**
	 * Download and decode the Cloud Storage artifact JSON.
	 *
	 * @param array<string, mixed> $ledger Firebase job ledger.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function download_artifact( array $ledger ): array|WP_Error {
		$signed_url = $ledger['signedUrl'] ?? null;
		if ( ! is_string( $signed_url ) || '' === $signed_url ) {
			return new WP_Error( 'artifact_retry', 'Firebase did not provide an artifact download URL.' );
		}

		$response = wp_remote_get( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Signed GCS artifact URL is not a VIP origin.
			$signed_url,
			array(
				'timeout' => 60, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Artifact JSON can exceed the VIP default.
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'artifact_retry', $response->get_error_message() );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'artifact_retry', 'Could not download the audience artifact.' );
		}

		$artifact = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $artifact ) ) {
			return new WP_Error( 'artifact_invalid', 'The audience artifact is not valid JSON.' );
		}

		return $artifact;
	}

	/**
	 * Mark a job failed and persist it.
	 *
	 * @param array<string, mixed> $job     Internal job record.
	 * @param string               $code    Error code.
	 * @param string               $message Error message.
	 * @return array<string, mixed>
	 */
	private static function fail_job( array $job, string $code, string $message ): array {
		$allowed_codes = array(
			'invalid_query',
			'enqueue_failed',
			'scan_failed',
			'artifact_invalid',
			'verification_mismatch',
			'timed_out',
		);
		$job['phase']  = 'failed';
		$job['error']  = array(
			'code'    => in_array( $code, $allowed_codes, true ) ? $code : 'scan_failed',
			'message' => $message,
		);
		self::save_job( $job );

		return $job;
	}

	/**
	 * Persist a job option.
	 *
	 * @param array<string, mixed> $job Internal job record.
	 */
	private static function save_job( array $job ): void {
		update_option( self::job_option_key( $job['jobId'] ), $job, false );
	}

	/**
	 * Reschedule collection and return the current REST view.
	 *
	 * @param array<string, mixed> $job Internal job record.
	 * @return array<string, mixed>
	 */
	private static function keep_waiting( array $job ): array {
		self::schedule_collection( $job['jobId'] );

		return self::to_rest( $job );
	}

	/**
	 * Fail the job on timeout, otherwise keep waiting.
	 *
	 * @param array<string, mixed> $job Internal job record.
	 * @return array<string, mixed>
	 */
	private static function timeout_or_wait( array $job ): array {
		if ( self::has_timed_out( $job ) ) {
			return self::to_rest(
				self::fail_job( $job, 'timed_out', 'The audience build did not finish within 15 minutes.' )
			);
		}

		return self::keep_waiting( $job );
	}

	/**
	 * Schedule the next Action Scheduler collect tick.
	 *
	 * @param string $job_id         Job ID.
	 * @param bool   $from_collector Whether this call is from the collect hook.
	 */
	private static function schedule_collection( string $job_id, bool $from_collector = false ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$args = array( $job_id );
		if (
			! $from_collector
			&& function_exists( 'as_next_scheduled_action' )
			&& false !== as_next_scheduled_action( self::COLLECT_HOOK, $args, 'prc-email-builder' )
		) {
			return;
		}

		as_schedule_single_action(
			time() + self::COLLECT_DELAY_SECONDS,
			self::COLLECT_HOOK,
			$args,
			'prc-email-builder'
		);
	}

	/**
	 * Whether the job has exceeded the 15-minute timeout.
	 *
	 * @param array<string, mixed> $job Internal job record.
	 */
	private static function has_timed_out( array $job ): bool {
		$requested_at = strtotime( (string) ( $job['requestedAt'] ?? '' ) );

		return false !== $requested_at && time() - $requested_at >= self::JOB_TIMEOUT_SECONDS;
	}

	/**
	 * Mint a unique job ID with the builder prefix.
	 *
	 * @param string $prefix Two-letter prefix plus underscore.
	 */
	private static function mint_job_id( string $prefix ): string {
		return uniqid( $prefix, false );
	}
}
