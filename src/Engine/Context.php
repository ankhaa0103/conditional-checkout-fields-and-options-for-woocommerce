<?php
/**
 * Lazy source value lookup.
 *
 * @package CCFO
 */

declare(strict_types=1);

namespace CCFO\Engine;

/**
 * Holds known source values and computes the rest on demand, once per request.
 */
final class Context {

	/**
	 * Resolved values.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Resolver for values that are not given up front.
	 *
	 * @var (\Closure(string): mixed)|null
	 */
	private ?\Closure $resolver;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>           $values   Known values keyed by source id.
	 * @param (\Closure(string): mixed)|null $resolver Computes a missing value.
	 */
	public function __construct( array $values = [], ?\Closure $resolver = null ) {
		$this->values   = $values;
		$this->resolver = $resolver;
	}

	/**
	 * Value of a source.
	 *
	 * @param string $source Source id.
	 */
	public function get( string $source ): mixed {
		if ( ! array_key_exists( $source, $this->values ) ) {
			$this->values[ $source ] = null === $this->resolver ? null : ( $this->resolver )( $source );
		}
		return $this->values[ $source ];
	}

	/**
	 * Values for a set of sources.
	 *
	 * @param list<string> $sources Source ids.
	 * @return array<string, mixed>
	 */
	public function snapshot( array $sources ): array {
		$out = [];
		foreach ( $sources as $source ) {
			$out[ $source ] = $this->get( $source );
		}
		ksort( $out );
		return $out;
	}
}
