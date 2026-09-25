<?php
/**
 * Catalog of stored Mandrill audiences (recipient lists in wp_options).
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Discovers `prc_email_audience_*` meta options and pages them for the Audiences list.
 */
final class Audience_Catalog {
	public const OPTION_PREFIX        = 'prc_email_audience_';
	public const LEGACY_OPTION_PREFIX = 'prc_newsletter_audience_';
	public const OTHER_BUILDER        = 'other';
	public const MAX_PER_PAGE         = 100;

	private const ORDERBY = array( 'title', 'count', 'builtAt' );

	/**
	 * Every stored audience, described from its `_meta` companion option.
	 *
	 * Only meta siblings are read so the (potentially large) email arrays stay unloaded.
	 *
	 * @return array<int, array<string, mixed>> Rows from Audience_Builder_Registry::describe_audience().
	 */
	public static function all(): array {
		global $wpdb;

		$meta_option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name ASC",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%' . $wpdb->esc_like( '_meta' ),
				$wpdb->esc_like( self::LEGACY_OPTION_PREFIX ) . '%' . $wpdb->esc_like( '_meta' )
			)
		);

		$audiences = array();
		foreach ( $meta_option_names as $meta_option_name ) {
			$meta = get_option( $meta_option_name, array() );
			if ( ! self::is_meta_array( $meta ) ) {
				continue;
			}
			$audiences[] = Audience_Builder_Registry::describe_audience( substr( $meta_option_name, 0, -5 ), $meta );
		}

		return $audiences;
	}

	/**
	 * Whether a key can identify an audience option.
	 *
	 * @param string $key Option key.
	 */
	public static function is_valid_key( string $key ): bool {
		return ! str_ends_with( $key, '_meta' )
			&& ( str_starts_with( $key, self::OPTION_PREFIX )
				|| str_starts_with( $key, self::LEGACY_OPTION_PREFIX ) );
	}

	/**
	 * Fetch one stored audience description.
	 *
	 * @param string $key Audience option key.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get( string $key ): array|\WP_Error {
		$key = trim( $key );
		if ( ! self::is_valid_key( $key ) || ! is_array( get_option( $key, null ) ) ) {
			return new \WP_Error(
				'audience_not_found',
				__( 'Audience not found.', 'prc-email-builder' ),
				array( 'status' => 404 )
			);
		}

		$meta = get_option( $key . '_meta', array() );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		return Audience_Builder_Registry::describe_audience( $key, $meta );
	}

	/**
	 * Rename an audience without changing its stable option key.
	 *
	 * @param string $key   Audience option key.
	 * @param string $label New display label.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function rename( string $key, string $label ): array|\WP_Error {
		$audience = self::get( $key );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}

		$label = sanitize_text_field( trim( $label ) );
		if ( '' === $label ) {
			return new \WP_Error(
				'invalid_audience_label',
				__( 'Audience name is required.', 'prc-email-builder' ),
				array( 'status' => 400 )
			);
		}

		$meta          = get_option( $key . '_meta', array() );
		$meta          = is_array( $meta ) ? $meta : array();
		$previous_name = is_string( $meta['label'] ?? null ) ? $meta['label'] : (string) $audience['label'];
		$meta['label'] = $label;
		update_option( $key . '_meta', $meta, false );

		/**
		 * Fires after an audience display label changes.
		 *
		 * @param string $key           Stable audience option key.
		 * @param string $label         New label.
		 * @param string $previous_name Previous label.
		 */
		do_action( 'prc_email_builder_audience_renamed', $key, $label, $previous_name );

		return Audience_Builder_Registry::describe_audience( $key, $meta );
	}

	/**
	 * Count transactional posts that reference each audience key.
	 *
	 * Trash is included. An audience must have no references before deletion.
	 *
	 * @param string[] $keys Audience option keys.
	 * @return array<string, int>
	 */
	public static function reference_counts( array $keys ): array {
		global $wpdb;

		$keys = array_values( array_filter( array_unique( $keys ), array( self::class, 'is_valid_key' ) ) );
		if ( empty( $keys ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated %s placeholders are interpolated.
		$sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The placeholder list is generated from the validated key count.
			"SELECT pm.meta_value AS audience_key, COUNT(DISTINCT p.ID) AS reference_count
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
				AND p.post_type = %s
				AND pm.meta_value IN ({$placeholders})
			GROUP BY pm.meta_value",
			'prc_email_audience_option_key',
			Post_Type::TRANSACTIONAL_POST_TYPE,
			...$keys
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; one grouped query avoids loading every post.
		$counts = array_fill_keys( $keys, 0 );

		foreach ( $rows as $row ) {
			$key = is_string( $row['audience_key'] ?? null ) ? $row['audience_key'] : '';
			if ( isset( $counts[ $key ] ) ) {
				$counts[ $key ] = (int) $row['reference_count'];
			}
		}

		return $counts;
	}

	/**
	 * Permanently delete an unreferenced audience and its metadata.
	 *
	 * @param string $key Audience option key.
	 * @return true|\WP_Error
	 */
	public static function delete( string $key ): true|\WP_Error {
		$audience = self::get( $key );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}

		$reference_count = self::reference_counts( array( $key ) )[ $key ] ?? 0;
		if ( $reference_count > 0 ) {
			return new \WP_Error(
				'audience_in_use',
				__( 'This audience is used by transactional emails and cannot be deleted.', 'prc-email-builder' ),
				array(
					'status'         => 409,
					'referenceCount' => $reference_count,
				)
			);
		}

		delete_option( $key );
		delete_option( $key . '_meta' );

		/**
		 * Fires after an audience and its metadata are permanently deleted.
		 *
		 * @param string               $key      Deleted audience option key.
		 * @param array<string, mixed> $audience Last audience description.
		 */
		do_action( 'prc_email_builder_audience_deleted', $key, $audience );

		return true;
	}

	/**
	 * Distinguish a `_meta` companion from an email list whose slug happens to end in "_meta".
	 *
	 * @param mixed $meta Option value.
	 */
	public static function is_meta_array( mixed $meta ): bool {
		if ( empty( $meta ) || ! is_array( $meta ) || array_is_list( $meta ) ) {
			return false;
		}

		return isset( $meta['label'] ) || isset( $meta['built_at'] ) || isset( $meta['source'] );
	}

	/**
	 * Shape a described audience as an Audiences DataViews row.
	 *
	 * @param array<string, mixed> $audience Row from Audience_Builder_Registry::describe_audience().
	 * @param int                  $reference_count Number of transactional posts using this audience.
	 * @param array<string, mixed> $analytics       Block from Audience_Analytics; empty when omitted.
	 * @return array<string, mixed>
	 */
	public static function to_list_row( array $audience, int $reference_count = 0, array $analytics = array() ): array {
		$builder_slug = is_string( $audience['builder'] ?? null ) ? $audience['builder'] : null;
		$builder      = null === $builder_slug ? null : Audience_Builder_Registry::get( $builder_slug );

		return array(
			'recordType'     => 'audience',
			'id'             => (string) $audience['key'],
			'title'          => (string) $audience['label'],
			'count'          => (int) $audience['count'],
			'builder'        => $builder_slug,
			'builderLabel'   => null === $builder ? __( 'Other', 'prc-email-builder' ) : (string) $builder['label'],
			'verification'   => is_string( $audience['verification'] ?? null ) ? $audience['verification'] : null,
			'sourceId'       => isset( $audience['source_id'] ) ? (int) $audience['source_id'] : null,
			'sourceTitle'    => is_string( $audience['source_title'] ?? null ) ? $audience['source_title'] : null,
			'builtAt'        => self::normalize_built_at( $audience['built_at'] ?? null ),
			'datasetId'      => isset( $audience['dataset_id'] ) ? (int) $audience['dataset_id'] : null,
			'status'         => 'ready',
			'requestedAt'    => null,
			'jobId'          => null,
			'scannedUsers'   => null,
			'matchedUsers'   => null,
			'referenceCount' => max( 0, $reference_count ),
			'analytics'      => empty( $analytics ) ? Audience_Analytics::empty_block() : $analytics,
		);
	}

	/**
	 * Filter, sort, and page list rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from to_list_row().
	 * @param array<string, mixed>             $args search, type, builder, orderby, order, page, per_page.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( array $rows, array $args ): array {
		$types = self::csv_list( $args['type'] ?? '' );
		if ( ! empty( $types ) ) {
			$rows = array_filter(
				$rows,
				static fn( array $row ): bool => in_array( $row['recordType'] ?? '', $types, true )
			);
		}

		$search = strtolower( trim( (string) ( $args['search'] ?? '' ) ) );
		if ( '' !== $search ) {
			$rows = array_filter(
				$rows,
				static fn( array $row ): bool => str_contains( strtolower( $row['title'] ), $search )
					|| str_contains( strtolower( $row['id'] ), $search )
			);
		}

		$builders = self::csv_list( $args['builder'] ?? '' );
		if ( ! empty( $builders ) ) {
			$rows = array_filter(
				$rows,
				static fn( array $row ): bool => in_array( $row['builder'] ?? self::OTHER_BUILDER, $builders, true )
			);
		}

		$orderby   = in_array( $args['orderby'] ?? '', self::ORDERBY, true ) ? $args['orderby'] : 'builtAt';
		$direction = 'asc' === ( $args['order'] ?? '' ) ? 1 : -1;
		usort(
			$rows,
			static function ( array $a, array $b ) use ( $orderby, $direction ): int {
				$cmp = match ( $orderby ) {
					'title'   => strcasecmp( $a['title'], $b['title'] ),
					'count'   => ( $a['count'] ?? -1 ) <=> ( $b['count'] ?? -1 ),
					'builtAt' => strcmp( (string) $a['builtAt'], (string) $b['builtAt'] ),
				};
				return 0 !== $cmp ? $cmp * $direction : strcmp( $a['id'], $b['id'] );
			}
		);

		$total    = count( $rows );
		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		return array(
			'rows'  => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
			'total' => $total,
		);
	}

	/**
	 * ISO 8601 UTC for a stored `built_at` (MySQL GMT or ISO), or null.
	 *
	 * @param mixed $built_at Stored value.
	 */
	private static function normalize_built_at( mixed $built_at ): ?string {
		if ( ! is_string( $built_at ) || '' === $built_at ) {
			return null;
		}
		$timestamp = strtotime( $built_at );

		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Comma-separated or array input as a list of keys.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	private static function csv_list( mixed $value ): array {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );

		return array_values( array_filter( array_map( 'sanitize_key', $items ) ) );
	}
}
