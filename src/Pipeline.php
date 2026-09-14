<?php

declare(strict_types=1);

namespace ContentCopy;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * A tiny Laravel-style pipeline.
 *
 * Each stage (or "pipe") receives the passable plus a `$next` closure. A
 * stage either calls `return $next( $job )` to keep the push moving, or
 * returns a `WP_Error` to abort the whole pipeline with that error. The final
 * `then()` callback runs only when every stage passed the job along.
 *
 * Example — the shape every stage follows:
 *
 * ```php
 * private function collectMedia( PushJob $job, Closure $next ): mixed {
 *     $job->mediaIds = $this->collector->collect( $job->post );
 *
 *     return $next( $job );
 * }
 * ```
 *
 * This is deliberately onion-style (stages wrap each other) rather than a
 * straight loop, mirroring `Illuminate\Pipeline`: a stage could do work after
 * `$next()` returns, e.g. timing or cleanup, without any extra machinery.
 */
final class Pipeline {

	/**
	 * Ordered stages, each shaped `fn( $passable, Closure $next ): mixed`.
	 *
	 * @var list<callable(mixed, Closure): mixed>
	 */
	private array $pipes = [];

	/**
	 * The value travelling through the stages.
	 *
	 * @var mixed
	 */
	private mixed $passable;

	/**
	 * Pipelines are created through `send()`, never directly.
	 *
	 * @param mixed $passable The value the stages will share.
	 */
	private function __construct( mixed $passable ) {
		$this->passable = $passable;
	}

	/**
	 * Start a pipeline carrying the given value.
	 *
	 * @param mixed $passable The value the stages will share.
	 *
	 * @return self A pipeline waiting for `through()` and `then()`.
	 */
	public static function send( mixed $passable ): self {
		return new self( $passable );
	}

	/**
	 * Set the stages the passable travels through, in order.
	 *
	 * @param list<callable(mixed, Closure): mixed> $pipes Stage callables.
	 *
	 * @return self The same pipeline, for chaining.
	 */
	public function through( array $pipes ): self {
		$this->pipes = array_values( $pipes );

		return $this;
	}

	/**
	 * Run the pipeline and finish with the destination callback.
	 *
	 * Stages are nested inside-out so the first stage listed is the
	 * outermost: it runs first on the way in and last on the way out, exactly
	 * like middleware.
	 *
	 * @param Closure $destination Final callback receiving the passable. Runs only if no stage aborted.
	 *
	 * @return mixed Whatever the destination callback returns — or the `WP_Error` a stage aborted with.
	 */
	public function then( Closure $destination ): mixed {
		$carry = $destination;

		foreach ( array_reverse( $this->pipes ) as $pipe ) {
			$carry = static fn ( mixed $passable ): mixed => $pipe( $passable, $carry );
		}

		return $carry( $this->passable );
	}
}
