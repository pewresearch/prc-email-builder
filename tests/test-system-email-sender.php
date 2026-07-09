<?php
declare(strict_types=1);
/**
 * Regression coverage for System_Email_Sender direct Mandrill dispatch.
 *
 * Proves dynamic system emails POST to messages/send (never send-template),
 * carry the system-email discriminator tag, and surface Mandrill rejections.
 *
 * Run with:
 *   php plugins/prc-email-builder/tests/test-system-email-sender.php
 */

namespace PRC\Platform\Email_Builder {

	class Mailchimp {
		public static function get_settings(): array {
			return [
				'from_email'          => 'newsletters@pewresearch.org',
				'from_name'           => 'Pew Research Center',
				'track_opens'         => true,
				'track_clicks'        => false,
				'reply_to'            => '',
				'mandrill_subaccount' => 'test-sub',
				'mandrill_tags'       => [ 'prc-newsletter' ],
			];
		}
	}
}

namespace {

	define( 'PRC_PLATFORM_MANDRILL_KEY', 'test-key' );

	$GLOBALS['__http_requests'] = [];
	$GLOBALS['__http_response'] = null;

	function wp_remote_post( string $url, array $args = [] ) {
		$GLOBALS['__http_requests'][] = [
			'url'  => $url,
			'args' => $args,
		];
		return $GLOBALS['__http_response'];
	}

	function wp_remote_retrieve_response_code( $response ): int {
		return (int) ( $response['response']['code'] ?? 200 );
	}

	function wp_remote_retrieve_body( $response ): string {
		return (string) ( $response['body'] ?? '' );
	}

	function wp_json_encode( $data, $options = 0, $depth = 512 ): string {
		return json_encode( $data, $options, $depth ) ?: '';
	}

	function is_email( string $email ): bool {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	class WP_Error {
		public function __construct(
			private readonly string $code,
			private readonly string $message,
			private readonly array $data = []
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}

	function assert_true( bool $cond, string $msg ): void {
		if ( ! $cond ) {
			fwrite( STDERR, "FAIL: {$msg}\n" );
			exit( 1 );
		}
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

	function assert_instanceof( string $class, mixed $obj, string $msg ): void {
		if ( ! ( $obj instanceof $class ) ) {
			fwrite( STDERR, "FAIL: {$msg}\n" );
			exit( 1 );
		}
	}

	require dirname( __DIR__ ) . '/includes/system-email/class-system-email-sender.php';

	$dispatch = new ReflectionMethod( PRC\Platform\Email_Builder\System_Email_Sender::class, 'dispatch' );

	// ── Success path: messages/send with system-email tag ───────────────────
	$GLOBALS['__http_response'] = [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode( [ [ 'status' => 'sent', 'email' => 'user@example.com' ] ] ),
	];

	$result = $dispatch->invoke( null, [ 'user@example.com' ], 'Test Subject', '<html>body</html>' );
	assert_same( [ 'user@example.com' ], $result['sent'] ?? null, 'dispatch reports recipient sent' );
	assert_same( [], $result['failed'] ?? null, 'dispatch reports no failures' );
	assert_same( 1, count( $GLOBALS['__http_requests'] ), 'one HTTP request issued' );
	assert_true(
		str_contains( $GLOBALS['__http_requests'][0]['url'], 'messages/send' ),
		'posts to messages/send'
	);
	assert_true(
		! str_contains( $GLOBALS['__http_requests'][0]['url'], 'send-template' ),
		'does not use send-template'
	);

	$payload = json_decode( $GLOBALS['__http_requests'][0]['args']['body'], true );
	assert_same( false, $payload['async'], 'async is false for transactional send' );
	assert_same(
		[ 'prc-newsletter', 'system-email' ],
		$payload['message']['tags'],
		'carries base tags plus system-email discriminator'
	);
	assert_same( true, $payload['message']['track_opens'], 'track_opens from settings' );
	assert_same( false, $payload['message']['track_clicks'], 'track_clicks from settings' );
	assert_same( 'test-sub', $payload['message']['subaccount'], 'subaccount from settings' );

	// ── Rejection path ──────────────────────────────────────────────────────
	$GLOBALS['__http_response'] = [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode(
			[
				[
					'status'        => 'rejected',
					'email'         => 'user@example.com',
					'reject_reason' => 'invalid-sender',
				],
			]
		),
	];

	$rejected = $dispatch->invoke( null, [ 'user@example.com' ], 'Test', '<html></html>' );
	assert_same( [], $rejected['sent'] ?? null, 'rejected recipient is not in sent' );
	assert_same(
		[ 'user@example.com' => 'invalid-sender' ],
		$rejected['failed'] ?? null,
		'rejected recipient carries reject reason'
	);

	// ── Batch path: one POST, multi-recipient to array, mixed statuses ──────
	$GLOBALS['__http_requests'] = [];
	$GLOBALS['__http_response'] = [
		'response' => [ 'code' => 200 ],
		'body'     => wp_json_encode(
			[
				[ 'status' => 'sent', 'email' => 'a@example.com' ],
				[ 'status' => 'queued', 'email' => 'b@example.com' ],
				[
					'status'        => 'rejected',
					'email'         => 'c@example.com',
					'reject_reason' => 'hard-bounce',
				],
			]
		),
	];

	$batch = $dispatch->invoke(
		null,
		[ 'a@example.com', 'b@example.com', 'c@example.com', 'd@example.com' ],
		'Batch Subject',
		'<html>batch</html>'
	);

	assert_same( 1, count( $GLOBALS['__http_requests'] ), 'batch issues a single HTTP request' );

	$batch_payload = json_decode( $GLOBALS['__http_requests'][0]['args']['body'], true );
	assert_same(
		[
			[ 'email' => 'a@example.com', 'type' => 'to' ],
			[ 'email' => 'b@example.com', 'type' => 'to' ],
			[ 'email' => 'c@example.com', 'type' => 'to' ],
			[ 'email' => 'd@example.com', 'type' => 'to' ],
		],
		$batch_payload['message']['to'],
		'all recipients carried in a single to array'
	);
	assert_same( false, $batch_payload['message']['preserve_recipients'], 'recipients hidden from each other' );

	assert_same( [ 'a@example.com', 'b@example.com' ], $batch['sent'] ?? null, 'sent and queued count as sent' );
	assert_same(
		[
			'c@example.com' => 'hard-bounce',
			'd@example.com' => 'no recipient status returned',
		],
		$batch['failed'] ?? null,
		'rejects and missing statuses partition into failed'
	);

	// ── Whole-call failure path ──────────────────────────────────────────────
	$GLOBALS['__http_response'] = [
		'response' => [ 'code' => 500 ],
		'body'     => wp_json_encode( [ 'name' => 'GeneralError', 'message' => 'upstream down' ] ),
	];

	$whole_call = $dispatch->invoke( null, [ 'a@example.com', 'b@example.com' ], 'Test', '<html></html>' );
	assert_instanceof( WP_Error::class, $whole_call, 'whole-call HTTP failure yields WP_Error' );
	assert_same( 'mandrill_api_error', $whole_call->get_error_code(), 'whole-call failure error code' );

	fwrite( STDOUT, "OK: test-system-email-sender.php passed\n" );
}
