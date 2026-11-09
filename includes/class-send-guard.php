<?php
/**
 * Production-only gate for real email delivery.
 *
 * Staging, preprod, test slots, and local dev-envs carry copies of the
 * production database and the Mailchimp / Mandrill keys. Every code path that
 * delivers to a real audience calls this guard so those environments never
 * email subscribers. The only non-production exceptions are Preview test sends
 * (not guarded) and Mailchimp campaigns whose live recipients are the
 * "Staging (TEST)" saved segment.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Send Guard class.
 */
class Send_Guard {
	public const ERROR_CODE = 'email_send_blocked_non_production';

	/**
	 * Mailchimp saved segment name that non-production environments may send to.
	 */
	public const STAGING_TEST_SEGMENT_NAME = 'Staging (TEST)';

	/**
	 * Environment type pinned by standalone tests; null reads WordPress.
	 *
	 * @var string|null
	 */
	private static ?string $environment_type_override = null;

	/**
	 * Whether this request runs on production. Fails closed.
	 *
	 * @return bool
	 */
	public static function is_production(): bool {
		if ( null !== self::$environment_type_override ) {
			return 'production' === self::$environment_type_override;
		}

		if ( ! function_exists( 'wp_get_environment_type' ) ) {
			return false;
		}

		return 'production' === wp_get_environment_type();
	}

	/**
	 * Whether a Mailchimp segment name is the staging test segment.
	 *
	 * @param string $name Segment name as returned by Mailchimp.
	 * @return bool
	 */
	public static function is_staging_test_segment_name( string $name ): bool {
		return self::STAGING_TEST_SEGMENT_NAME === trim( $name );
	}

	/**
	 * Error returned when a send is refused on non-production.
	 *
	 * @param string $detail Optional reason appended to the message.
	 * @return WP_Error
	 */
	public static function blocked_error( string $detail = '' ): WP_Error {
		$message = sprintf(
			'Email sends are disabled on the %1$s environment; only production sends email. Here, only Preview test sends and Mailchimp campaigns targeting the "%2$s" segment are delivered.',
			self::environment_name(),
			self::STAGING_TEST_SEGMENT_NAME
		);
		if ( '' !== $detail ) {
			$message .= ' ' . $detail;
		}

		return new WP_Error( self::ERROR_CODE, $message, array( 'status' => 403 ) );
	}

	/**
	 * Error for public form submissions, worded for the person who filled in the form.
	 *
	 * @return WP_Error
	 */
	public static function form_blocked_error(): WP_Error {
		return new WP_Error(
			self::ERROR_CODE,
			sprintf(
				'This is the %s site, and sending email is turned off here. No email was sent. Emails are only sent from the production site.',
				self::environment_name()
			),
			array( 'status' => 403 )
		);
	}

	/**
	 * Human-readable name of the current environment, e.g. "staging" or "test1".
	 *
	 * Prefers the VIP environment name, which tells preprod and test slots apart
	 * where the WordPress environment type does not.
	 *
	 * @return string
	 */
	public static function environment_name(): string {
		if ( null !== self::$environment_type_override ) {
			return self::$environment_type_override;
		}

		if ( defined( 'VIP_GO_APP_ENVIRONMENT' ) && '' !== (string) constant( 'VIP_GO_APP_ENVIRONMENT' ) ) {
			return (string) constant( 'VIP_GO_APP_ENVIRONMENT' );
		}

		if ( function_exists( 'wp_get_environment_type' ) ) {
			return (string) wp_get_environment_type();
		}

		return 'non-production';
	}

	/**
	 * Refuse the send on non-production.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_can_send(): true|WP_Error {
		return self::is_production() ? true : self::blocked_error();
	}

	/**
	 * Pin the environment type in standalone tests. Pass null to restore.
	 *
	 * @param string|null $environment_type Environment type, or null.
	 */
	public static function set_environment_type_for_tests( ?string $environment_type ): void {
		self::$environment_type_override = $environment_type;
	}
}
