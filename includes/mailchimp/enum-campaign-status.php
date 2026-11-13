<?php
/**
 * Cached Mailchimp campaign status of a newsletter post.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Stored values of `prc_email_mailchimp_campaign_status`.
 *
 * The first four cases are Mailchimp's own words, in the order a campaign
 * moves through them. The status moves forward along that order, and a case
 * may skip ahead (save to sent). One edge goes back: schedule to save, for a
 * campaign that someone unscheduled in Mailchimp. Sending, sent and unavailable
 * never move back. Unavailable is the one case that is not Mailchimp's: it
 * marks a campaign that Mailchimp no longer has (HTTP 404), and it is final.
 * Only an unlink clears it.
 */
enum Campaign_Status: string {
	case Save        = 'save';
	case Schedule    = 'schedule';
	case Sending     = 'sending';
	case Sent        = 'sent';
	case Unavailable = 'unavailable';

	/**
	 * Position in the send order. Unavailable ends the order.
	 */
	private function rank(): int {
		return match ( $this ) {
			self::Save        => 1,
			self::Schedule    => 2,
			self::Sending     => 3,
			self::Sent        => 4,
			self::Unavailable => 5,
		};
	}

	/**
	 * Whether a post in this status may move to $to.
	 *
	 * Sent and Unavailable are final. A sent campaign that Mailchimp later
	 * drops stays sent, so the report and first-day jobs keep their record.
	 * Schedule may also return to Save: the campaign is a draft again, and the
	 * resend gate must open.
	 *
	 * @param self $to Target status.
	 */
	public function may_move_to( self $to ): bool {
		if ( self::Sent === $this || self::Unavailable === $this ) {
			return false;
		}
		if ( self::Schedule === $this && self::Save === $to ) {
			return true;
		}
		return $to->rank() > $this->rank();
	}

	/**
	 * The Meta_State edge table: every forward pair, and schedule to save.
	 *
	 * @return array<int, array{0: self, 1: self}>
	 */
	public static function edges(): array {
		$edges = array();
		foreach ( self::cases() as $from ) {
			foreach ( self::cases() as $to ) {
				if ( $from->may_move_to( $to ) ) {
					$edges[] = array( $from, $to );
				}
			}
		}
		return $edges;
	}

	/**
	 * Map a status that the Mailchimp API reported to a case.
	 *
	 * Mailchimp reports campaign states this plugin does not track (paused,
	 * canceling, canceled, archived). They map to null, and the caller keeps the
	 * stored status. Unavailable is never an API value.
	 *
	 * @param string $api_status Status from GET /campaigns/{id}.
	 */
	public static function from_api( string $api_status ): ?self {
		$status = self::tryFrom( $api_status );
		return self::Unavailable === $status ? null : $status;
	}
}
