<?php
/**
 * Trimmed subject that is legal to send.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Trimmed subject that is legal to send.
 */
final class Ready_Subject {
	/**
	 * Construct.
	 *
	 * @param string $line Trimmed non-empty subject.
	 */
	public function __construct(
		private string $line
	) {}

	/**
	 * Line.
	 *
	 * @return string Trimmed subject line.
	 */
	public function line(): string {
		return $this->line;
	}
}
