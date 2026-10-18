<?php
/**
 * What the rules did during this request.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Engine;

/**
 * Collects applied rules (stored on the order) and "everything removed"
 * events (shown to the customer and reported to the admin).
 */
final class RequestLog {

	public const BLOCKED_OPTION = 'ccfo_blocked_log';

	/**
	 * Applied rule ids by target, e.g. ['payment:cod' => ['r1']].
	 *
	 * @var array<string, list<string>>
	 */
	private array $applied = [];

	/**
	 * Blocked types in this request with the responsible rule ids.
	 *
	 * @var array<string, list<string>>
	 */
	private array $blocked = [];

	/**
	 * Record a decision that changed the default outcome.
	 *
	 * @param string   $target   Target, e.g. "field:billing_company".
	 * @param Decision $decision Decision.
	 */
	public function record( string $target, Decision $decision ): void {
		if ( $decision->changed && [] !== $decision->applied ) {
			$this->applied[ $target ] = $decision->applied;
		}
	}

	/**
	 * Forget recorded decisions for a target type (e.g. when rates are re-filtered).
	 *
	 * @param string $prefix Target prefix, e.g. "payment:".
	 */
	public function forget( string $prefix ): void {
		foreach ( array_keys( $this->applied ) as $target ) {
			if ( str_starts_with( $target, $prefix ) ) {
				unset( $this->applied[ $target ] );
			}
		}
	}

	/**
	 * Applied rules.
	 *
	 * @return array<string, list<string>>
	 */
	public function applied(): array {
		return $this->applied;
	}

	/**
	 * Mark that rules removed every payment or shipping method.
	 *
	 * @param string       $type     payment|shipping.
	 * @param list<string> $rule_ids Rules that removed methods.
	 */
	public function block( string $type, array $rule_ids ): void {
		$this->blocked[ $type ] = array_values( array_unique( $rule_ids ) );

		$log  = get_option( self::BLOCKED_OPTION, [] );
		$log  = is_array( $log ) ? $log : [];
		$last = $log[0] ?? null;

		// Store at most one entry per type and rule set every 10 minutes.
		if ( is_array( $last ) && $last['type'] === $type && $last['rules'] === $this->blocked[ $type ] && time() - (int) $last['time'] < 600 ) {
			return;
		}

		array_unshift(
			$log,
			[
				'type'  => $type,
				'rules' => $this->blocked[ $type ],
				'time'  => time(),
			]
		);
		update_option( self::BLOCKED_OPTION, array_slice( $log, 0, 20 ), false );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->notice(
				sprintf( 'Rules %s removed every %s method at checkout.', implode( ', ', $this->blocked[ $type ] ), $type ),
				[ 'source' => 'ccfo' ]
			);
		}
	}

	/**
	 * Clear the blocked state of a type (methods were recalculated and some remain).
	 *
	 * @param string $type payment|shipping.
	 */
	public function unblock( string $type ): void {
		unset( $this->blocked[ $type ] );
	}

	/**
	 * Whether rules removed every method of the type in this request.
	 *
	 * @param string $type payment|shipping.
	 */
	public function is_blocked( string $type ): bool {
		return isset( $this->blocked[ $type ] );
	}
}
