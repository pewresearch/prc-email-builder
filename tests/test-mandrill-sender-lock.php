<?php
declare(strict_types=1);
/**
 * Concurrency / locking regression coverage for the Mandrill system-email sender.
 *
 * Proves the send lock is acquired atomically, refreshed (heartbeat) across a
 * long batch loop so it never goes stale mid-send, and that a second concurrent
 * worker cannot start while a fresh lock is held — the conditions that
 * previously allowed overlapping workers to re-send the same batch and deliver
 * duplicate emails.
 *
 * Run with:
 *   php plugins/prc-email-builder/tests/test-mandrill-sender-lock.php
 */

namespace {

	// ── Mutable clock so heartbeat / TTL behaviour is testable ──────────────
	$GLOBALS['__now'] = 1_000_000;

	function test_now(): int {
		return $GLOBALS['__now'];
	}

	function test_advance( int $seconds ): void {
		$GLOBALS['__now'] += $seconds;
	}

	// The sender calls time(); shadow it inside the namespaced class below.

	class WP_Error {
		public function __construct(
			private readonly string $code,
			private readonly string $message
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

	// ── In-memory post meta ─────────────────────────────────────────────────
	$GLOBALS['__meta'] = [];

	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		return $GLOBALS['__meta'][ $post_id ][ $key ] ?? '';
	}

	function update_post_meta( int $post_id, string $key, $value ): void {
		$GLOBALS['__meta'][ $post_id ][ $key ] = $value;
	}

	function delete_post_meta( int $post_id, string $key ): void {
		unset( $GLOBALS['__meta'][ $post_id ][ $key ] );
	}

	// ── In-memory options ───────────────────────────────────────────────────
	$GLOBALS['__options'] = [];

	function get_option( string $key, $default = false ) {
		return $GLOBALS['__options'][ $key ] ?? $default;
	}

	function wp_cache_delete( $key, string $group = '' ): bool {
		return true;
	}

	function get_the_title( int $post_id ): string {
		return 'Newsletter Title';
	}

	function get_post_type( $post_id ): string {
		return 'prc_email_txn';
	}

	function is_email( string $email ): bool {
		return str_contains( $email, '@' );
	}

	function current_time( string $type, $gmt = 0 ): string {
		return '2026-01-01 00:00:00';
	}

	function wp_json_encode( $data ): string {
		return json_encode( $data );
	}

	// ── HTTP stub: record every Mandrill send so we can detect duplicates ────
	$GLOBALS['__http_calls']       = [];
	$GLOBALS['__http_status']      = 200;
	$GLOBALS['__http_body']        = '[]';
	$GLOBALS['__advance_per_call'] = 0;

	function wp_remote_post( string $url, array $args = [] ) {
		$payload = json_decode( $args['body'] ?? '{}', true );
		$GLOBALS['__http_calls'][] = $payload['message']['to'] ?? [];
		// Simulate per-batch wall-clock time elapsing during a long send.
		if ( $GLOBALS['__advance_per_call'] > 0 ) {
			test_advance( $GLOBALS['__advance_per_call'] );
		}
		return [ 'stubbed' => true ];
	}

	function wp_remote_retrieve_response_code( $response ): int {
		return $GLOBALS['__http_status'];
	}

	function wp_remote_retrieve_body( $response ): string {
		return $GLOBALS['__http_body'];
	}

	// ── Minimal $wpdb emulating the options table (unique option_name) ───────
	class Fake_WPDB {
		public string $options = 'wp_options';
		/** @var array<string,string> */
		public array $rows = [];

		public function prepare( string $query, ...$args ): array {
			return [ '__q' => $query, '__a' => $args ];
		}

		private function key_from( $prepared ): string {
			// Only query used: SELECT option_value ... WHERE option_name = %s
			return (string) ( $prepared['__a'][0] ?? '' );
		}

		public function get_var( $prepared ) {
			$name = $this->key_from( $prepared );
			return $this->rows[ $name ] ?? null;
		}

		public function insert( string $table, array $data ) {
			$name = $data['option_name'];
			if ( isset( $this->rows[ $name ] ) ) {
				return false; // UNIQUE(option_name) violation -> atomic failure.
			}
			$this->rows[ $name ] = (string) $data['option_value'];
			return 1;
		}

		public function update( string $table, array $data, array $where ) {
			$name = $where['option_name'] ?? '';
			if ( ! isset( $this->rows[ $name ] ) ) {
				return 0;
			}
			// Compare-and-swap: only succeed when current value matches.
			if ( array_key_exists( 'option_value', $where )
				&& $this->rows[ $name ] !== $where['option_value'] ) {
				return 0;
			}
			$this->rows[ $name ] = (string) $data['option_value'];
			return 1;
		}

		public function delete( string $table, array $where ) {
			$name = $where['option_name'] ?? '';
			if ( ! isset( $this->rows[ $name ] ) ) {
				return 0;
			}
			if ( array_key_exists( 'option_value', $where )
				&& $this->rows[ $name ] !== $where['option_value'] ) {
				return 0;
			}
			unset( $this->rows[ $name ] );
			return 1;
		}
	}

	$GLOBALS['wpdb'] = new Fake_WPDB();
}

namespace PRC\Platform\Email_Builder {

	// Shadow time() so the sender sees the mutable test clock.
	function time(): int {
		return \test_now();
	}

	class Loader {
		public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ): void {}
	}

	class Migration {
		public static function is_migrated( int $post_id ): bool {
			return false;
		}
	}

	class Mailchimp {
		public static function get_settings(): array {
			return [ 'from_email' => 'news@example.test', 'from_name' => 'PRC' ];
		}
	}

	class Post_Type {
		public const TRANSACTIONAL_POST_TYPE = 'prc_email_txn';

		public static function is_transactional_post( \WP_Post|int $post ): bool {
			if ( $post instanceof \WP_Post ) {
				return self::TRANSACTIONAL_POST_TYPE === $post->post_type;
			}
			return self::TRANSACTIONAL_POST_TYPE === get_post_type( (int) $post );
		}
	}
}

namespace {

	require_once dirname( __DIR__ ) . '/includes/mandrill/class-mandrill-sender.php';

	use PRC\Platform\Email_Builder\Loader;
	use PRC\Platform\Email_Builder\Mandrill_Sender;

	$failures = 0;

	function check( bool $condition, string $message ): void {
		global $failures;
		if ( $condition ) {
			echo "  PASS: {$message}\n";
		} else {
			echo "  FAIL: {$message}\n";
			$failures++;
		}
	}

	function reset_world( int $emails_count ): array {
		$GLOBALS['__meta']       = [];
		$GLOBALS['__options']    = [];
		$GLOBALS['__http_calls'] = [];
		$GLOBALS['__http_status'] = 200;
		$GLOBALS['__http_body']  = '[]';
		$GLOBALS['__now']        = 1_000_000;
		$GLOBALS['wpdb']->rows   = [];

		$post_id = 555;
		update_post_meta( $post_id, 'prc_email_delivery_mode', 'mandrill' );
		update_post_meta( $post_id, 'prc_email_audience_option_key', 'prc_audience_test' );
		update_post_meta( $post_id, 'prc_email_subject', 'Subject' );

		$emails = [];
		for ( $i = 0; $i < $emails_count; $i++ ) {
			$emails[] = sprintf( 'user%05d@example.test', $i );
		}
		$GLOBALS['__options']['prc_audience_test'] = $emails;

		if ( ! defined( 'PRC_PLATFORM_MANDRILL_KEY' ) ) {
			define( 'PRC_PLATFORM_MANDRILL_KEY', 'test-key' );
		}

		return [ $post_id, $emails ];
	}

	/** Total recipient addresses across all recorded Mandrill sends. */
	function total_recipients(): int {
		$count = 0;
		foreach ( $GLOBALS['__http_calls'] as $to ) {
			$count += count( $to );
		}
		return $count;
	}

	/** True if any recipient address appears in more than one send (duplicate). */
	function has_duplicate_recipient(): bool {
		$seen = [];
		foreach ( $GLOBALS['__http_calls'] as $to ) {
			foreach ( $to as $recipient ) {
				$email = $recipient['email'];
				if ( isset( $seen[ $email ] ) ) {
					return true;
				}
				$seen[ $email ] = true;
			}
		}
		return false;
	}

	function lock_option_name( int $post_id ): string {
		return 'prc_email_mandrill_lock_' . $post_id;
	}

	$lock_ttl = Mandrill_Sender::LOCK_TTL;

	// ── Test A: a fresh lock held by another worker blocks a second send ─────
	echo "Test A: second concurrent send_to_audience must not start under a fresh lock\n";
	[ $post_id ] = reset_world( 1200 ); // 3 batches of 500.
	// Simulate worker A holding a fresh lock (expiry in the future).
	$GLOBALS['wpdb']->rows[ lock_option_name( $post_id ) ] = 'worker-a-token|' . ( test_now() + $lock_ttl );
	update_post_meta( $post_id, Mandrill_Sender::STATUS_META, 'sending' );

	$sender  = new Mandrill_Sender( new Loader() );
	$result  = $sender->send_to_audience( $post_id, '<p>Hi</p>' );
	check( is_wp_error( $result ), 'returns WP_Error while another worker holds a fresh lock' );
	check( total_recipients() === 0, 'sends ZERO emails while a fresh lock is held (no duplicates)' );

	// ── Test B: a stale lock (expired) can be taken over and the send runs ──
	echo "Test B: stale lock is reclaimed and the send proceeds\n";
	[ $post_id, $emails ] = reset_world( 1200 );
	$GLOBALS['wpdb']->rows[ lock_option_name( $post_id ) ] = 'dead-worker|' . ( test_now() - 5 );
	update_post_meta( $post_id, Mandrill_Sender::STATUS_META, 'sending' );

	$sender = new Mandrill_Sender( new Loader() );
	$result = $sender->send_to_audience( $post_id, '<p>Hi</p>' );
	check( ! is_wp_error( $result ), 'reclaims the stale lock and runs' );
	check( total_recipients() === count( $emails ), 'delivers to every recipient exactly once' );
	check( ! has_duplicate_recipient(), 'no recipient is sent to twice' );
	check( get_post_meta( $post_id, Mandrill_Sender::STATUS_META, true ) === 'sent', 'marks status "sent"' );
	check( ! $sender->is_locked( $post_id ), 'releases the lock after completion' );

	// ── Test C: lock is atomic — only one acquirer wins ─────────────────────
	echo "Test C: atomic acquisition — only one of two racers wins the lock\n";
	[ $post_id ] = reset_world( 500 );
	if ( method_exists( Mandrill_Sender::class, 'acquire_lock' ) ) {
		$acquire = new ReflectionMethod( Mandrill_Sender::class, 'acquire_lock' );
		$acquire->setAccessible( true );
		$sender = new Mandrill_Sender( new Loader() );
		$token_a = $acquire->invoke( $sender, $post_id );
		$token_b = $acquire->invoke( $sender, $post_id );
		check( '' !== (string) $token_a, 'first acquirer gets a token' );
		check( '' === (string) $token_b, 'second acquirer is rejected (mutual exclusion)' );
	} else {
		check( false, 'acquire_lock() exists (atomic lock primitive implemented)' );
	}

	// ── Test D: heartbeat keeps the lock fresh across a long send ───────────
	echo "Test D: heartbeat refreshes the lock so it never goes stale mid-send\n";
	[ $post_id, $emails ] = reset_world( 5000 ); // 10 batches.
	// Each batch "takes" 70s of wall-clock; 10 * 70 = 700s > LOCK_TTL (600s).
	// Without a heartbeat the lock would expire partway through.
	$GLOBALS['__advance_per_call'] = 70;
	$sender = new Mandrill_Sender( new Loader() );
	$result = $sender->send_to_audience( $post_id, '<p>Hi</p>' );
	$GLOBALS['__advance_per_call'] = 0;
	check( ! is_wp_error( $result ), 'long send completes without error' );
	check( total_recipients() === count( $emails ), 'long send reaches every recipient once' );
	check( ! has_duplicate_recipient(), 'long send produces no duplicate recipients' );

	// ── Test E: fencing — a worker that lost its lock cannot interfere ──────
	echo "Test E: stale worker is fenced out and cannot disturb the new owner\n";
	[ $post_id ] = reset_world( 1000 );
	if ( method_exists( Mandrill_Sender::class, 'acquire_lock' ) ) {
		$sender   = new Mandrill_Sender( new Loader() );
		$acquire  = new ReflectionMethod( Mandrill_Sender::class, 'acquire_lock' );
		$owns     = new ReflectionMethod( Mandrill_Sender::class, 'owns_lock' );
		$refresh  = new ReflectionMethod( Mandrill_Sender::class, 'refresh_lock' );
		$release  = new ReflectionMethod( Mandrill_Sender::class, 'release_lock' );
		foreach ( [ $acquire, $owns, $refresh, $release ] as $m ) {
			$m->setAccessible( true );
		}

		// Worker A acquires, then stalls until the lock expires.
		$token_a = $acquire->invoke( $sender, $post_id );
		check( '' !== (string) $token_a, 'worker A acquires the lock' );
		test_advance( $lock_ttl + 1 );

		// Worker B reclaims the now-stale lock.
		$token_b = $acquire->invoke( $sender, $post_id );
		check( '' !== (string) $token_b, 'worker B reclaims the stale lock' );

		// Worker A wakes up: it must recognize it no longer owns the lock,
		// must fail to refresh it, and must not release worker B's lock.
		check( false === $owns->invoke( $sender, $post_id, $token_a ), 'stale worker A no longer owns the lock' );
		check( true === $owns->invoke( $sender, $post_id, $token_b ), 'worker B owns the lock' );
		check( false === $refresh->invoke( $sender, $post_id, $token_a ), 'stale worker A cannot heartbeat the lock' );
		$release->invoke( $sender, $post_id, $token_a );
		check( $sender->is_locked( $post_id ), 'worker A release does NOT drop worker B\'s lock' );
		check( true === $owns->invoke( $sender, $post_id, $token_b ), 'worker B still owns the lock after A bows out' );
	} else {
		check( false, 'lock primitives exist for fencing test' );
	}

	echo "\n";
	if ( $failures > 0 ) {
		echo "RESULT: {$failures} assertion(s) failed.\n";
		exit( 1 );
	}
	echo "RESULT: all Mandrill_Sender lock tests passed.\n";
}
