<?php
/**
 * Stored status of a Mandrill bulk send.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Values of `prc_email_mandrill_send_status` that a bulk send writes.
 *
 * A run moves a post to Sending, then to one end state. A later run may start
 * again from any end state, because it resumes from the batch checkpoint.
 * NeedsReview means a batch POST has an unknown outcome: the run wrote the
 * attempt and never saw Mandrill accept it. The sender never resends such a
 * batch. A webhook event for the batch moves it to accepted, and the status to
 * Sent or Partial.
 * The key also holds "active" for dynamic system emails. Those posts never
 * run a bulk send, so the key is not guarded.
 */
enum Mandrill_Send_Status: string {
	case Sending = 'sending';
	case Sent    = 'sent';
	case Partial = 'partial';
	case Failed  = 'failed';
	case NeedsReview = 'needs_review';

	/**
	 * The Meta_State edge table.
	 *
	 * @return array<int, array{0: self, 1: self}>
	 */
	public static function edges(): array {
		return array(
			array( self::Sending, self::Sent ),
			array( self::Sending, self::Partial ),
			array( self::Sending, self::Failed ),
			array( self::Sending, self::NeedsReview ),
			array( self::NeedsReview, self::Sent ),
			array( self::NeedsReview, self::Partial ),
			array( self::NeedsReview, self::Sending ),
			array( self::Sent, self::Sending ),
			array( self::Partial, self::Sending ),
			array( self::Failed, self::Sending ),
		);
	}
}
