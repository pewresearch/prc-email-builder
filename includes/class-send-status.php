<?php
declare( strict_types=1 );
/**
 * Shared send-status labels and traffic-light tone mapping.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Send_Status {
	public const TONE_SUCCESS = 'success';
	public const TONE_WARNING = 'warning';
	public const TONE_ERROR   = 'error';
	public const TONE_NEUTRAL = 'neutral';

	/**
	 * Resolve display label and tone for a campaign or transactional email.
	 *
	 * @param string $email_type `campaign` or `txn`.
	 * @param string $raw_status Meta value; empty string treated as not sent.
	 * @return array{label: string, tone: string, raw: string}
	 */
	public static function resolve( string $email_type, string $raw_status ): array {
		$raw = sanitize_text_field( $raw_status );

		if ( 'txn' === $email_type ) {
			$lookup = self::normalize_lookup_key( $raw );
			$label  = self::mandrill_labels()[ $lookup ] ?? ( '' === $raw ? self::mandrill_labels()['__empty__'] : $raw );

			return [
				'label' => $label,
				'tone'  => self::tone_for_mandrill( $raw ),
				'raw'   => $raw,
			];
		}

		$lookup = self::normalize_lookup_key( $raw );
		$label  = self::mailchimp_labels()[ $lookup ] ?? ( '' === $raw ? self::mailchimp_labels()['__empty__'] : $raw );

		return [
			'label' => $label,
			'tone'  => self::tone_for_mailchimp( $raw ),
			'raw'   => $raw,
		];
	}

	/**
	 * Traffic-light tone for Mailchimp campaign status meta.
	 */
	public static function tone_for_mailchimp( string $status ): string {
		return match ( sanitize_text_field( $status ) ) {
			'sent' => self::TONE_SUCCESS,
			'save', 'sending', 'schedule', 'paused', '' => self::TONE_WARNING,
			default => self::TONE_WARNING,
		};
	}

	/**
	 * Traffic-light tone for Mandrill send status meta.
	 */
	public static function tone_for_mandrill( string $status ): string {
		return match ( sanitize_text_field( $status ) ) {
			'sent' => self::TONE_SUCCESS,
			'sending', 'queued', '' => self::TONE_WARNING,
			'failed', 'partial' => self::TONE_ERROR,
			default => self::TONE_WARNING,
		};
	}

	/**
	 * Mailchimp status filter/display options.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function mailchimp_options(): array {
		return [
			[
				'value' => '__empty__',
				'label' => __( 'No Campaign', 'prc-email-builder' ),
			],
			[
				'value' => 'save',
				'label' => __( 'MC Draft', 'prc-email-builder' ),
			],
			[
				'value' => 'sent',
				'label' => __( 'MC Sent', 'prc-email-builder' ),
			],
			[
				'value' => 'sending',
				'label' => __( 'Sending', 'prc-email-builder' ),
			],
			[
				'value' => 'schedule',
				'label' => __( 'Scheduled', 'prc-email-builder' ),
			],
			[
				'value' => 'paused',
				'label' => __( 'Paused', 'prc-email-builder' ),
			],
		];
	}

	/**
	 * Mandrill status filter/display options.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function mandrill_options(): array {
		return [
			[
				'value' => '__empty__',
				'label' => __( 'Not Sent', 'prc-email-builder' ),
			],
			[
				'value' => 'sent',
				'label' => __( 'Sent', 'prc-email-builder' ),
			],
			[
				'value' => 'queued',
				'label' => __( 'Queued', 'prc-email-builder' ),
			],
			[
				'value' => 'sending',
				'label' => __( 'Sending', 'prc-email-builder' ),
			],
			[
				'value' => 'failed',
				'label' => __( 'Failed', 'prc-email-builder' ),
			],
			[
				'value' => 'partial',
				'label' => __( 'Partial', 'prc-email-builder' ),
			],
		];
	}

	/**
	 * Send-status filter options for the Email DataViews UI.
	 *
	 * Values are prefixed with email type: campaign:save, txn:sent, etc.
	 *
	 * @param string|null $scope `campaign`, `txn`, or null for both.
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function library_filter_options( ?string $scope = null ): array {
		$options = [];

		if ( null === $scope || 'campaign' === $scope ) {
			foreach ( self::mailchimp_options() as $option ) {
				$options[] = [
					'value' => 'campaign:' . $option['value'],
					'label' => $option['label'],
				];
			}
		}

		if ( null === $scope || 'txn' === $scope ) {
			foreach ( self::mandrill_options() as $option ) {
				$options[] = [
					'value' => 'txn:' . $option['value'],
					'label' => $option['label'],
				];
			}
		}

		return $options;
	}

	/**
	 * Post state key and label for Mailchimp campaign status (classic list table).
	 *
	 * @return array{0: string, 1: string}|null [ state_key, label ] or null when no state.
	 */
	public static function mailchimp_post_state( string $status ): ?array {
		$status = sanitize_text_field( $status );
		if ( '' === $status ) {
			return null;
		}

		$labels = self::mailchimp_labels();

		if ( ! isset( $labels[ $status ] ) ) {
			return null;
		}

		return [
			'prc_email_mc_' . $status,
			$labels[ $status ],
		];
	}

	/**
	 * Post state key and label for Mandrill send status (classic list table).
	 *
	 * @return array{0: string, 1: string}|null [ state_key, label ] or null when no state.
	 */
	public static function mandrill_post_state( string $status ): ?array {
		$status = sanitize_text_field( $status );
		if ( '' === $status ) {
			return null;
		}

		$labels = self::mandrill_labels();
		if ( ! isset( $labels[ $status ] ) ) {
			return null;
		}

		return [
			'prc_email_mandrill_' . $status,
			$labels[ $status ],
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function mailchimp_labels(): array {
		$labels = [];
		foreach ( self::mailchimp_options() as $option ) {
			$labels[ $option['value'] ] = $option['label'];
		}
		return $labels;
	}

	/**
	 * @return array<string, string>
	 */
	private static function mandrill_labels(): array {
		$labels = [];
		foreach ( self::mandrill_options() as $option ) {
			$labels[ $option['value'] ] = $option['label'];
		}
		return $labels;
	}

	private static function normalize_lookup_key( string $status ): string {
		return '' === sanitize_text_field( $status ) ? '__empty__' : sanitize_text_field( $status );
	}
}
