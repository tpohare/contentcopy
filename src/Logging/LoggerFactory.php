<?php
declare(strict_types=1);

namespace ContentCopy\Logging;

use ContentCopy\Settings;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the Monolog channel used for a push or an import.
 */
final class LoggerFactory {

	private const RETAIN_FILES = 14;

	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Builds the logger for one side of the operation.
	 *
	 * Every logger gets three sinks in spirit: the request collector (when
	 * given, so the editor or REST response can echo what happened), the
	 * rotating file (when enabled and writable), and a null sink fallback so
	 * logging can never fatal a push — a full disk must not break content.
	 *
	 * @param string             $channel   Either `source` or `destination`. Names the Monolog channel and the log file.
	 * @param MemoryHandler|null $collector Optional in-request record collector.
	 *
	 * @return LoggerInterface Ready-to-use PSR-3 logger.
	 */
	public function create( string $channel, ?MemoryHandler $collector = null ): LoggerInterface {
		$logger = new Logger( 'contentcopy.' . $channel );

		$logger->pushProcessor( new PsrLogMessageProcessor( removeUsedContextFields: false ) );
		$logger->pushProcessor(
			static function ( \Monolog\LogRecord $record ) use ( $channel ): \Monolog\LogRecord {
				$record->extra['site'] = home_url();
				$record->extra['role'] = $channel;

				return $record;
			}
		);

		if ( null !== $collector ) {
			$logger->pushHandler( $collector );
		}

		$file = $this->settings->loggingEnabled ? LogDirectory::file( $channel ) : null;

		if ( null === $file ) {
			$logger->pushHandler( new NullHandler() );

			return $logger;
		}

		$handler = new RotatingFileHandler( $file, self::RETAIN_FILES, $this->level(), bubble: true );
		$handler->setFormatter(
			new LineFormatter(
				format: "[%datetime%] %level_name%: %message% %context% %extra%\n",
				dateFormat: 'Y-m-d H:i:s',
				allowInlineLineBreaks: true,
				ignoreEmptyContextAndExtra: true,
			)
		);

		$logger->pushHandler( $handler );

		return $logger;
	}

	/**
	 * Map the configured level name to a Monolog level.
	 *
	 * Unknown values fall through to Info rather than exploding: a hand-edited
	 * option row should degrade to sensible logging, not to no logging.
	 *
	 * @return Level The minimum severity that reaches the file.
	 */
	private function level(): Level {
		return match ( $this->settings->logLevel ) {
			'debug'   => Level::Debug,
			'warning' => Level::Warning,
			'error'   => Level::Error,
			default   => Level::Info,
		};
	}

	/**
	 * Levels offered in the settings screen.
	 *
	 * Debug is deliberately chatty (every field, term and file) — it exists
	 * for diagnosing one failing push, not for leaving on. Info is the
	 * everyday default: one line per item and file.
	 *
	 * @return array<string, string> Level value => human label.
	 */
	public static function levelChoices(): array {
		return [
			'debug'   => __( 'Debug — every field, term and file', 'contentcopy' ),
			'info'    => __( 'Info — one line per item and file', 'contentcopy' ),
			'warning' => __( 'Warning — only problems', 'contentcopy' ),
			'error'   => __( 'Error — only failures', 'contentcopy' ),
		];
	}
}
