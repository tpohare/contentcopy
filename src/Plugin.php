<?php

declare(strict_types=1);

namespace ContentCopy;

use ContentCopy\Logging\LoggerFactory;
use ContentCopy\Logging\MemoryHandler;
use ContentCopy\Rest\DestinationController;
use ContentCopy\Rest\SourceController;
use Psr\Log\LoggerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin container: builds shared services and wires up every component.
 *
 * One install plays both roles — source and destination — so `boot()`
 * registers both REST sides unconditionally. Which role is exercised depends
 * entirely on who calls: the editor drives the source side, and an
 * authenticated remote site drives the destination side.
 *
 * Shared services (settings, loggers) live here so controllers and UI stay
 * thin: they ask the container for a ready-made pusher or importer instead
 * of assembling clients, collectors and loggers themselves.
 */
final class Plugin {

	/**
	 * @var self|null The singleton instance. One per request is plenty: construction only reads options.
	 */
	private static ?self $instance = null;

	/**
	 * Destination configuration: URL, credentials, fallback author, log level.
	 *
	 * @var Settings
	 */
	public private(set) Settings $settings;

	/**
	 * Builds the Monolog channels for pushes and imports.
	 *
	 * @var LoggerFactory
	 */
	public private(set) LoggerFactory $loggers;

	/**
	 * Private by design — use `instance()`. Construction is cheap (no hooks,
	 * no I/O beyond reading one option row) so booting stays predictable.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->loggers  = new LoggerFactory( $this->settings );
	}

	/**
	 * The singleton accessor.
	 *
	 * @return self The shared container.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Wire up everything: settings page, UUID stamping, editor UI, and both
	 * REST sides.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->settings->hooks();

		( new Uuid() )->hooks();
		( new Editor( $this->settings ) )->hooks();
		( new SourceController( $this ) )->hooks();
		( new DestinationController( $this ) )->hooks();
	}

	/**
	 * Build a push orchestrator wired to a dual-sink logger.
	 *
	 * The logger writes to the rotating file *and* collects records into
	 * `$collector`, so the editor response can narrate exactly what happened
	 * without re-reading the log file.
	 *
	 * @param MemoryHandler $collector In-request record collector for the editor response.
	 *
	 * @return Pusher Ready to `push( $postId )`.
	 */
	public function pusher( MemoryHandler $collector ): Pusher {
		$logger = $this->loggers->create( 'source', $collector );

		return new Pusher(
			client: new Client( $this->settings ),
			settings: $this->settings,
			logger: $logger,
		);
	}

	/**
	 * Build a destination-side post importer.
	 *
	 * @param MemoryHandler|null $collector Optional in-request record collector for the REST response.
	 *
	 * @return Importer Ready to `import( $payload, $userId )`.
	 */
	public function importer( ?MemoryHandler $collector = null ): Importer {
		return new Importer(
			settings: $this->settings,
			logger: $this->destinationLogger( $collector ),
		);
	}

	/**
	 * Build a destination-side file importer.
	 *
	 * @param MemoryHandler|null $collector Optional in-request record collector for the REST response.
	 *
	 * @return MediaImporter Ready to `importBytes( $bytes, $envelope )`.
	 */
	public function mediaImporter( ?MemoryHandler $collector = null ): MediaImporter {
		return new MediaImporter( logger: $this->destinationLogger( $collector ) );
	}

	/**
	 * Build a destination-side form importer.
	 *
	 * @param MemoryHandler|null $collector Optional in-request record collector for the REST response.
	 *
	 * @return FormImporter Ready to `import( $forms )`.
	 */
	public function formImporter( ?MemoryHandler $collector = null ): FormImporter {
		return new FormImporter( logger: $this->destinationLogger( $collector ) );
	}

	/**
	 * Build the destination-side spool for files arriving in pieces.
	 *
	 * @param MemoryHandler|null $collector Optional in-request record collector for the REST response.
	 *
	 * @return ChunkStore Ready to `open()`, `append()` and `assemble()`.
	 */
	public function chunkStore( ?MemoryHandler $collector = null ): ChunkStore {
		return new ChunkStore( logger: $this->destinationLogger( $collector ) );
	}

	/**
	 * The destination channel, optionally also collecting into memory.
	 *
	 * @param MemoryHandler|null $collector Optional in-request record collector.
	 *
	 * @return LoggerInterface Destination logger.
	 */
	private function destinationLogger( ?MemoryHandler $collector ): LoggerInterface {
		return $this->loggers->create( 'destination', $collector );
	}
}
