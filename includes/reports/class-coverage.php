<?php
/**
 * Honesty contract for an engagement report.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder\Reports;

/**
 * What the numbers cover, and whether opens/clicks were observed.
 *
 * Rates are null when engagement is none — never 0.0 to mean "unknown".
 */
final class Coverage {

	public const VOLUME_COMPLETE = 'complete';
	public const VOLUME_NONE     = 'none';

	public const ENGAGEMENT_FULL         = 'full';
	public const ENGAGEMENT_FORWARD_ONLY = 'forward_only';
	public const ENGAGEMENT_NONE         = 'none';

	public const AUDIENCE_FULL         = 'full';
	public const AUDIENCE_CRM_CONTACTS = 'crm_contacts';

	/**
	 * Create a coverage contract for one envelope.
	 *
	 * @param string      $volume              complete|none.
	 * @param string      $engagement          full|forward_only|none.
	 * @param string      $audience            full|crm_contacts.
	 * @param string|null $engagement_since    ISO-8601 UTC when tracking began.
	 * @param int|null    $tracked_emails_sent Distinct recipients in the engagement window.
	 */
	public function __construct(
		public readonly string $volume,
		public readonly string $engagement,
		public readonly string $audience,
		public readonly ?string $engagement_since = null,
		public readonly ?int $tracked_emails_sent = null,
	) {}

	/**
	 * Whether volume is known (something was sent).
	 */
	public function stats_available(): bool {
		return self::VOLUME_COMPLETE === $this->volume;
	}

	/**
	 * Derived availability slug for callers that want one word.
	 */
	public function availability(): string {
		if ( self::VOLUME_NONE === $this->volume ) {
			return 'unavailable';
		}
		if ( self::ENGAGEMENT_NONE === $this->engagement ) {
			return 'volume_only';
		}
		if ( self::ENGAGEMENT_FORWARD_ONLY === $this->engagement ) {
			return 'forward_only';
		}
		return 'available';
	}

	/**
	 * Envelope coverage object.
	 *
	 * @return array{volume: string, engagement: string, audience: string, engagement_since: string|null, tracked_emails_sent: int|null}
	 */
	public function to_array(): array {
		return array(
			'volume'              => $this->volume,
			'engagement'          => $this->engagement,
			'audience'            => $this->audience,
			'engagement_since'    => $this->engagement_since,
			'tracked_emails_sent' => $this->tracked_emails_sent,
		);
	}

	/**
	 * Hydrate coverage from an envelope payload.
	 *
	 * @param array<string, mixed> $raw Envelope coverage payload.
	 */
	public static function from_array( array $raw ): self {
		$since   = $raw['engagement_since'] ?? null;
		$since   = is_string( $since ) && '' !== $since ? $since : null;
		$tracked = $raw['tracked_emails_sent'] ?? null;
		$tracked = is_numeric( $tracked ) ? (int) $tracked : null;

		return new self(
			(string) ( $raw['volume'] ?? self::VOLUME_NONE ),
			(string) ( $raw['engagement'] ?? self::ENGAGEMENT_NONE ),
			(string) ( $raw['audience'] ?? self::AUDIENCE_FULL ),
			$since,
			$tracked
		);
	}
}
