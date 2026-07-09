<?php
declare(strict_types=1);
/**
 * Coverage for Mailchimp newsletter opt-in on sendSystemEmail form submissions.
 *
 * Run with:
 *   php plugins/prc-email-builder/tests/test-form-send-system-email-mailchimp.php
 */

namespace PRC\Platform {

	class Mailchimp_API {
		public static int $subscribe_calls = 0;

		public function __construct(
			public readonly mixed $email,
			public readonly array $args
		) {}

		public function subscribe_to_list(
			$name = null,
			$interests = [],
			$origin_url = false,
			$form_id = false
		): array {
			++self::$subscribe_calls;
			return [ 'success' => true ];
		}
	}
}

namespace PRC\Platform\Mailchimp {
	const DEFAULT_LIST_ID = '3e953b9b70';
}

namespace {

	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
		define( 'MINUTE_IN_SECONDS', 60 );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	$GLOBALS['__options'] = [];
	$GLOBALS['__filters'] = [];

	function get_option( string $key, $default = false ) {
		return $GLOBALS['__options'][ $key ] ?? $default;
	}

	function update_option( string $key, $value ): bool {
		$GLOBALS['__options'][ $key ] = $value;
		return true;
	}

	function apply_filters( string $hook, $value, ...$args ) {
		if ( ! isset( $GLOBALS['__filters'][ $hook ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['__filters'][ $hook ] as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}

	function add_filter( string $hook, callable $callback ): void {
		$GLOBALS['__filters'][ $hook ][] = $callback;
	}

	function esc_url_raw( string $url ): string {
		return $url;
	}

	function sanitize_text_field( string $value ): string {
		return trim( $value );
	}

	function test_error_log( string $message ): void {
		$GLOBALS['__error_logs'][] = $message;
	}

	class WP_REST_Request {
		private array $headers = [];

		public function get_header( string $name ): ?string {
			$key = strtolower( $name );
			return $this->headers[ $key ] ?? null;
		}

		public function set_header( string $name, string $value ): void {
			$this->headers[ strtolower( $name ) ] = $value;
		}
	}

	class WP_Error {
		public function __construct(
			private readonly string $code,
			private readonly string $message
		) {}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}

	function assert_same( mixed $expected, mixed $actual, string $msg ): void {
		if ( $expected !== $actual ) {
			fwrite(
				STDERR,
				"FAIL: {$msg}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n"
			);
			exit( 1 );
		}
	}

	function assert_null( mixed $actual, string $msg ): void {
		if ( null !== $actual ) {
			fwrite( STDERR, "FAIL: {$msg}\n  actual: " . var_export( $actual, true ) . "\n" );
			exit( 1 );
		}
	}

	require dirname( __DIR__ ) . '/includes/system-email/class-form-send-system-email.php';

	$handler = new PRC\Platform\Email_Builder\Form_Send_System_Email();

	$find_optin = new ReflectionMethod(
		PRC\Platform\Email_Builder\Form_Send_System_Email::class,
		'find_mailchimp_optin'
	);
	$find_optin->setAccessible( true );

	$maybe_subscribe = new ReflectionMethod(
		PRC\Platform\Email_Builder\Form_Send_System_Email::class,
		'maybe_subscribe_to_mailchimp'
	);
	$maybe_subscribe->setAccessible( true );

	$checked_field = [
		'name'    => 'mailchimp_signup',
		'type'    => 'checkbox',
		'value'   => 'abc123interest',
		'checked' => true,
	];

	assert_same(
		'abc123interest',
		$find_optin->invoke( $handler, [ $checked_field ] ),
		'checked mailchimp_signup returns sanitized interest id'
	);

	assert_null(
		$find_optin->invoke(
			$handler,
			[
				array_merge( $checked_field, [ 'checked' => false ] ),
			]
		),
		'unchecked field returns null'
	);

	assert_null(
		$find_optin->invoke( $handler, [] ),
		'missing field returns null'
	);

	assert_null(
		$find_optin->invoke(
			$handler,
			[
				array_merge( $checked_field, [ 'value' => '' ] ),
			]
		),
		'checked field with empty value returns null'
	);

	assert_null(
		$find_optin->invoke(
			$handler,
			[
				array_merge( $checked_field, [ 'checked' => 'false' ] ),
			]
		),
		'string false checked value is treated as unchecked'
	);

	assert_same(
		'abc123interest',
		$find_optin->invoke(
			$handler,
			[
				array_merge( $checked_field, [ 'checked' => false ] ),
				$checked_field,
			]
		),
		'later checked mailchimp_signup is found after an unchecked sibling'
	);

	assert_null(
		$find_optin->invoke(
			$handler,
			[
				array_merge( $checked_field, [ 'value' => '!@#$%' ] ),
			]
		),
		'interest id with no alphanumeric characters is rejected'
	);

	assert_null(
		$find_optin->invoke(
			$handler,
			[
				array_merge( $checked_field, [ 'value' => str_repeat( 'a', 33 ) ] ),
			]
		),
		'interest id longer than 32 characters is rejected'
	);

	update_option(
		'prc_mailchimp_segment_ids',
		[
			[
				'id'          => 1,
				'name'        => 'Weekly',
				'interest_id' => 'abc123interest',
			],
		]
	);

	$request = new WP_REST_Request();
	$request->set_header( 'referer', 'https://example.org/quiz/' );

	assert_null(
		$maybe_subscribe->invoke(
			$handler,
			'reader@example.com',
			[],
			42,
			[],
			$request
		),
		'absent mailchimp_signup field returns null'
	);

	PRC\Platform\Mailchimp_API::$subscribe_calls = 0;
	assert_same(
		'subscribed',
		$maybe_subscribe->invoke(
			$handler,
			'reader@example.com',
			[ $checked_field ],
			42,
			[ 'formId' => 'quiz-form' ],
			$request
		),
		'checked opt-in with valid cached interest subscribes'
	);
	assert_same( 1, PRC\Platform\Mailchimp_API::$subscribe_calls, 'subscribe_to_list called once' );

	assert_same(
		'skipped',
		$maybe_subscribe->invoke(
			$handler,
			'reader@example.com',
			[ array_merge( $checked_field, [ 'checked' => false ] ) ],
			42,
			[],
			$request
		),
		'unchecked opt-in is skipped'
	);

	update_option(
		'prc_mailchimp_segment_ids',
		[
			[
				'id'          => 1,
				'name'        => 'Other',
				'interest_id' => 'otherinterest',
			],
		]
	);

	assert_same(
		'skipped',
		$maybe_subscribe->invoke(
			$handler,
			'reader@example.com',
			[ $checked_field ],
			42,
			[],
			$request
		),
		'interest not in cached segment list is skipped'
	);

	$GLOBALS['__filters'] = [];
	add_filter(
		'prc_email_builder_system_email_mailchimp_optin',
		static fn(): array => []
	);

	assert_same(
		'skipped',
		$maybe_subscribe->invoke(
			$handler,
			'reader@example.com',
			[ $checked_field ],
			42,
			[],
			$request
		),
		'filter can suppress signup with empty interests'
	);

	$GLOBALS['__filters'] = [];
	add_filter(
		'prc_email_builder_system_email_mailchimp_optin',
		static fn(): array => [ 'replacedinterest' ]
	);
	update_option(
		'prc_mailchimp_segment_ids',
		[
			[
				'id'          => 2,
				'name'        => 'Replaced',
				'interest_id' => 'replacedinterest',
			],
		]
	);

	PRC\Platform\Mailchimp_API::$subscribe_calls = 0;
	assert_same(
		'subscribed',
		$maybe_subscribe->invoke(
			$handler,
			'reader@example.com',
			[ $checked_field ],
			42,
			[],
			$request
		),
		'filter can replace interest ids'
	);

	echo "OK: all Form_Send_System_Email Mailchimp opt-in assertions passed.\n";
}
