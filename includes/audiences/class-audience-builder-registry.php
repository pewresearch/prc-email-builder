<?php
/**
 * Registry of Mandrill audience builders.
 *
 * Other plugins register on `prc_email_builder_register_audience_builders`,
 * which fires at init priority 5.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Static registry of audience builder metadata and job callbacks.
 */
final class Audience_Builder_Registry {
	public const FORMS = array( 'domain-query', 'source-entity', 'csv-upload' );

	/**
	 * Registered slug → builder map.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $builders = array();

	/**
	 * Validate and register an audience builder.
	 *
	 * @param array<string, mixed> $builder Builder record.
	 * @return true|WP_Error
	 */
	public static function register( array $builder ): true|WP_Error {
		$validated = self::validate( $builder );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		self::$builders[ $validated['slug'] ] = $validated;

		return true;
	}

	/**
	 * Validate and normalize a builder record.
	 *
	 * @param array<string, mixed> $builder Builder record.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function validate( array $builder ): array|WP_Error {
		$slug = isset( $builder['slug'] ) && is_string( $builder['slug'] )
			? sanitize_key( $builder['slug'] )
			: '';
		if ( '' === $slug || ( $builder['slug'] ?? null ) !== $slug ) {
			return new WP_Error( 'invalid_builder', 'Audience builder slug is invalid.' );
		}

		$label                 = isset( $builder['label'] ) && is_string( $builder['label'] )
			? trim( $builder['label'] )
			: '';
		$description           = isset( $builder['description'] ) && is_string( $builder['description'] )
			? trim( $builder['description'] )
			: '';
		$option_prefix         = isset( $builder['option_prefix'] ) && is_string( $builder['option_prefix'] )
			? $builder['option_prefix']
			: '';
		$form                  = isset( $builder['form'] ) && is_string( $builder['form'] )
			? $builder['form']
			: '';
		$job_id_prefix         = isset( $builder['job_id_prefix'] ) && is_string( $builder['job_id_prefix'] )
			? $builder['job_id_prefix']
			: '';
		$firebase_endpoint_key = isset( $builder['firebase_endpoint_key'] ) && is_string( $builder['firebase_endpoint_key'] )
			? $builder['firebase_endpoint_key']
			: '';

		if ( '' === $label || '' === $description ) {
			return new WP_Error( 'invalid_builder', 'Audience builder label and description are required.' );
		}
		if ( ! str_starts_with( $option_prefix, 'prc_email_audience_' ) ) {
			return new WP_Error( 'invalid_builder', 'Audience builder option prefix is invalid.' );
		}
		if ( ! in_array( $form, self::FORMS, true ) ) {
			return new WP_Error( 'invalid_builder', 'Audience builder form is invalid.' );
		}
		if ( 1 !== preg_match( '/^[a-z]{2}_$/', $job_id_prefix ) ) {
			return new WP_Error( 'invalid_builder', 'Audience builder job ID prefix is invalid.' );
		}
		if ( 'csv-upload' !== $form && '' === $firebase_endpoint_key ) {
			return new WP_Error( 'invalid_builder', 'Audience builder Firebase endpoint key is required.' );
		}
		if ( ! is_callable( $builder['parse_input'] ?? null ) ) {
			return new WP_Error( 'invalid_builder', 'Audience builder parse_input callback is required.' );
		}
		if ( 'csv-upload' !== $form && ! is_callable( $builder['enqueue_body'] ?? null ) ) {
			return new WP_Error( 'invalid_builder', 'Audience builder enqueue_body callback is required.' );
		}
		if ( ! is_callable( $builder['import'] ?? null ) ) {
			return new WP_Error( 'invalid_builder', 'Audience builder import callback is required.' );
		}

		$record = array(
			'slug'                  => $slug,
			'label'                 => $label,
			'description'           => $description,
			'option_prefix'         => $option_prefix,
			'form'                  => $form,
			'job_id_prefix'         => $job_id_prefix,
			'firebase_endpoint_key' => $firebase_endpoint_key,
			'supports_create_draft' => ! empty( $builder['supports_create_draft'] ),
			'parse_input'           => $builder['parse_input'],
			'enqueue_body'          => $builder['enqueue_body'] ?? null,
			'import'                => $builder['import'],
			'source_post_type'      => null,
			'source_id_param'       => null,
			'source_id_meta'        => null,
			'source_title_meta'     => null,
		);

		if ( 'source-entity' === $form ) {
			$post_type = isset( $builder['source_post_type'] ) && is_string( $builder['source_post_type'] )
				? sanitize_key( $builder['source_post_type'] )
				: '';
			$id_param  = isset( $builder['source_id_param'] ) && is_string( $builder['source_id_param'] )
				? sanitize_key( $builder['source_id_param'] )
				: '';
			$id_meta   = isset( $builder['source_id_meta'] ) && is_string( $builder['source_id_meta'] )
				? sanitize_key( $builder['source_id_meta'] )
				: '';
			if ( '' === $post_type || '' === $id_param || '' === $id_meta ) {
				return new WP_Error(
					'invalid_builder',
					'Source-entity builders require source_post_type, source_id_param, and source_id_meta.'
				);
			}
			$record['source_post_type']  = $post_type;
			$record['source_id_param']   = $id_param;
			$record['source_id_meta']    = $id_meta;
			$record['source_title_meta'] = isset( $builder['source_title_meta'] ) && is_string( $builder['source_title_meta'] )
				? sanitize_key( $builder['source_title_meta'] )
				: null;
		}

		return $record;
	}

	/**
	 * All registered builders keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return self::$builders;
	}

	/**
	 * Get one registered builder.
	 *
	 * @param string $slug Builder slug.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		return self::$builders[ $slug ] ?? null;
	}

	/**
	 * Longest option-prefix match for a stored audience key.
	 *
	 * @param string $option_key Audience option key.
	 * @return array<string, mixed>|null
	 */
	public static function match_option_key( string $option_key ): ?array {
		$best     = null;
		$best_len = -1;
		foreach ( self::$builders as $builder ) {
			$prefix = $builder['option_prefix'];
			if ( str_starts_with( $option_key, $prefix ) && strlen( $prefix ) > $best_len ) {
				$best     = $builder;
				$best_len = strlen( $prefix );
			}
		}

		return $best;
	}

	/**
	 * Longest job-id-prefix match that also satisfies the id pattern.
	 *
	 * @param string $job_id Job ID.
	 * @return array<string, mixed>|null
	 */
	public static function match_job_id( string $job_id ): ?array {
		$best     = null;
		$best_len = -1;
		foreach ( self::$builders as $builder ) {
			$prefix = $builder['job_id_prefix'];
			if ( str_starts_with( $job_id, $prefix ) && strlen( $prefix ) > $best_len ) {
				$best     = $builder;
				$best_len = strlen( $prefix );
			}
		}
		if ( null === $best ) {
			return null;
		}

		$pattern = '/^' . preg_quote( $best['job_id_prefix'], '/' ) . '[a-z0-9]{13,32}$/';

		return 1 === preg_match( $pattern, $job_id ) ? $best : null;
	}

	/**
	 * Builder metadata safe to localize to admin JavaScript.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function to_js(): array {
		$rows = array();
		foreach ( self::$builders as $builder ) {
			$rows[] = array(
				'slug'                => $builder['slug'],
				'label'               => $builder['label'],
				'description'         => $builder['description'],
				'form'                => $builder['form'],
				'jobIdPrefix'         => $builder['job_id_prefix'],
				'supportsCreateDraft' => $builder['supports_create_draft'],
				'sourcePostType'      => $builder['source_post_type'],
				'sourceIdParam'       => $builder['source_id_param'],
			);
		}

		return $rows;
	}

	/**
	 * Catalog fields derived from an audience option key and its meta.
	 *
	 * @param string               $audience_key Audience option key.
	 * @param array<string, mixed> $meta         Audience `_meta` option.
	 * @return array<string, mixed>
	 */
	public static function describe_audience( string $audience_key, array $meta ): array {
		$builder = self::match_option_key( $audience_key );
		$row     = array(
			'key'          => $audience_key,
			'label'        => $meta['label'] ?? $audience_key,
			'count'        => (int) ( $meta['count'] ?? 0 ),
			'dataset_id'   => $meta['dataset_id'] ?? null,
			'built_at'     => $meta['built_at'] ?? null,
			'builder'      => $builder['slug'] ?? null,
			'verification' => $meta['verification'] ?? null,
			'source_id'    => null,
			'source_title' => null,
		);

		if ( null === $builder || 'source-entity' !== $builder['form'] ) {
			return $row;
		}

		$id_meta = $builder['source_id_meta'];
		if ( isset( $meta[ $id_meta ] ) ) {
			$row['source_id'] = (int) $meta[ $id_meta ];
		}

		$title_meta = $builder['source_title_meta'];
		if ( is_string( $title_meta ) && isset( $meta[ $title_meta ] ) && is_string( $meta[ $title_meta ] ) ) {
			$row['source_title'] = $meta[ $title_meta ];
		}

		return $row;
	}

	/**
	 * Reset the registry (test use only).
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$builders = array();
	}
}
