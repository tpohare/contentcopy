<?php
declare(strict_types=1);

namespace ContentCopy\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Collects records for the duration of one request so the push response can
 * report exactly what happened without the caller re-reading the log file.
 */
final class MemoryHandler extends AbstractProcessingHandler {

	/**
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $records = [];

	/**
	 * Start collecting at the given severity.
	 *
	 * Info by default: the editor shows warnings and errors, but the full
	 * trail (info included) ships in the REST response for anyone debugging.
	 * Bubble stays true so a file handler further up the stack still sees
	 * everything even when the collector ignores debug records.
	 *
	 * @param int|string|Level $level Minimum severity to collect.
	 */
	public function __construct( int|string|Level $level = Level::Info ) {
		parent::__construct( $level, bubble: true );
	}

	/**
	 * Store one record in memory, reduced to JSON-safe scalars.
	 *
	 * Runs for every record at or above the collection level; `scalarise()`
	 * is what keeps an accidental object in context from breaking the REST
	 * response serialisation later.
	 *
	 * @param LogRecord $record The record to keep.
	 *
	 * @return void
	 */
	protected function write( LogRecord $record ): void {
		$this->records[] = [
			'level'   => $record->level->getName(),
			'message' => $record->message,
			'context' => self::scalarise( $record->context ),
		];
	}

	/**
	 * @return list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	public function records(): array {
		return $this->records;
	}

	/**
	 * Messages at warning or above, for surfacing in the editor.
	 *
	 * @return list<string>
	 */
	public function problems(): array {
		$levels = [ 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' ];

		return array_values(
			array_map(
				static fn ( array $record ): string => $record['message'],
				array_filter(
					$this->records,
					static fn ( array $record ): bool => in_array( $record['level'], $levels, true )
				)
			)
		);
	}

	/**
	 * Context is returned over REST, so reduce it to JSON-safe scalars.
	 *
	 * @param array<array-key, mixed> $context Raw context.
	 * @return array<array-key, mixed>
	 */
	private static function scalarise( array $context ): array {
		$safe = [];

		foreach ( $context as $key => $value ) {
			$safe[ $key ] = match ( true ) {
				is_scalar( $value ), null === $value => $value,
				is_array( $value )                   => self::scalarise( $value ),
				$value instanceof \Throwable         => $value->getMessage(),
				default                              => get_debug_type( $value ),
			};
		}

		return $safe;
	}
}
