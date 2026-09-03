<?php

declare(strict_types=1);

namespace ContentCopy;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated HTTP client for the destination site's Content Copy routes.
 *
 * Every request carries the destination's application password as HTTP Basic
 * auth — which is exactly what application passwords are for — and every
 * response is normalised into either a decoded array or a `WP_Error` whose
 * message is safe to show the person pushing.
 *
 * Two deliberate transport choices:
 *
 * - Routes are addressed with `?rest_route=` instead of pretty `/wp-json/`
 *   permalinks, so pushes work regardless of the destination's permalink
 *   settings. A site with plain permalinks receives pushes just fine.
 * - File bytes travel raw (`postFile()`), not base64-encoded inside JSON.
 *   Base64 would inflate a 20 MB PDF by a third for no benefit; the small
 *   metadata envelope rides along as a header instead.
 */
final class Client {

	/**
	 * REST namespace shared by every Content Copy route, both sides.
	 *
	 * @var string
	 */
	private const ROUTE_NAMESPACE = '/contentcopy/v1/';

	/**
	 * @var LoggerInterface Request/response narration; silent when omitted.
	 */
	private LoggerInterface $logger;

	/**
	 * @param Settings          $settings Destination URL and credentials.
	 * @param LoggerInterface|null $logger Where to narrate requests; silent when omitted.
	 */
	public function __construct(
		private readonly Settings $settings,
		?LoggerInterface $logger = null,
	) {
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Clone the client onto a different logger.
	 *
	 * The pusher re-wraps its client per stage so each stage's requests land
	 * in the same collector: `$client->withLogger( $this->logger )`.
	 *
	 * @param LoggerInterface $logger The logger the clone should narrate to.
	 *
	 * @return self A new client with identical settings and the given logger.
	 */
	public function withLogger( LoggerInterface $logger ): self {
		return new self( $this->settings, $logger );
	}

	/**
	 * The configured destination site URL.
	 *
	 * @return string Destination root, no trailing slash.
	 */
	public function destinationUrl(): string {
		return $this->settings->destinationUrl;
	}

	/**
	 * GET a destination route and decode its JSON body.
	 *
	 * Used for the small reads: `remote/info` (preflight) and `remote/lookup`
	 * (identity and conflict checks).
	 *
	 * @param string                $route   Route below the plugin namespace, e.g. `remote/info`.
	 * @param array<string, scalar> $query   Extra query arguments (the UUID lookup rides here).
	 * @param int                   $timeout Timeout in seconds; reads are small so 30 is generous.
	 *
	 * @return array<array-key, mixed>|WP_Error Decoded body, or a descriptive error.
	 */
	public function get( string $route, array $query = [], int $timeout = 30 ): array|WP_Error {
		$this->logger->debug( 'GET {route}', [ 'route' => $route ] );

		return $this->handle(
			wp_remote_get(
				$this->url( $route, $query ),
				[
					'timeout' => $timeout,
					'headers' => [
						'Authorization' => $this->authorization(),
						'Accept'        => 'application/json',
					],
				]
			),
			$route
		);
	}

	/**
	 * POST a JSON body to a destination route.
	 *
	 * The workhorse for `remote/receive`: the whole remapped payload goes in
	 * one request. Two minutes accommodates slow shared hosting; the
	 * destination writes drafts first, so a timeout mid-write still cannot
	 * leave a half-published post.
	 *
	 * @param array<array-key, mixed> $body    Body to JSON-encode.
	 * @param string                  $route   Route below the plugin namespace, e.g. `remote/receive`.
	 * @param int                     $timeout Timeout in seconds.
	 *
	 * @return array<array-key, mixed>|WP_Error Decoded body, or a descriptive error.
	 */
	public function postJson( string $route, array $body, int $timeout = 120 ): array|WP_Error {
		$this->logger->debug( 'POST {route}', [ 'route' => $route ] );

		return $this->handle(
			wp_remote_post(
				$this->url( $route ),
				[
					'timeout' => $timeout,
					'headers' => [
						'Authorization' => $this->authorization(),
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
					],
					'body'    => (string) wp_json_encode( $body ),
				]
			),
			$route
		);
	}

	/**
	 * POST raw file bytes with a JSON metadata envelope in a header.
	 *
	 * The five-minute timeout is for large media on slow uplinks; the
	 * destination verifies the envelope's SHA-256 against the received bytes,
	 * so a timeout or truncated transfer fails loudly instead of storing a
	 * half-file.
	 *
	 * @param string               $route    Route below the plugin namespace (`remote/media`).
	 * @param string               $bytes    Raw file contents, exactly as read from disk.
	 * @param array<string, mixed> $envelope Metadata describing the file (UUID, filename, checksum, titles…).
	 * @param int                  $timeout  Timeout in seconds.
	 *
	 * @return array<array-key, mixed>|WP_Error Decoded descriptor (`id`, `url`, `sizes`), or a descriptive error.
	 */
	public function postFile( string $route, string $bytes, array $envelope, int $timeout = 300 ): array|WP_Error {
		$this->logger->debug(
			'POST {route} ({bytes} bytes)',
			[
				'route' => $route,
				'bytes' => strlen( $bytes ),
			]
		);

		return $this->handle(
			wp_remote_post(
				$this->url( $route ),
				[
					'timeout' => $timeout,
					// Let the HTTP layer set Content-Length itself: declaring
					// it here risks a duplicate header on some transports.
					'headers' => [
						'Authorization'      => $this->authorization(),
						'Content-Type'       => 'application/octet-stream',
						'Accept'             => 'application/json',
						'X-ContentCopy-File' => base64_encode( (string) wp_json_encode( $envelope ) ),
					],
					'body'    => $bytes,
				]
			),
			$route
		);
	}

	/**
	 * Build the full request URL for a route.
	 *
	 * `rest_route` as a query argument reaches WordPress on any permalink
	 * structure — including plain — which pretty `/wp-json/` URLs cannot
	 * promise.
	 *
	 * @param string                $route Route below the plugin namespace.
	 * @param array<string, scalar> $query Extra query arguments.
	 *
	 * @return string Absolute URL to request.
	 */
	private function url( string $route, array $query = [] ): string {
		$query['rest_route'] = self::ROUTE_NAMESPACE . ltrim( $route, '/' );

		return add_query_arg( $query, trailingslashit( $this->settings->destinationUrl ) );
	}

	/**
	 * The `Authorization` header value for the configured credentials.
	 *
	 * Application passwords authenticate as HTTP Basic, with the password's
	 * spaces already stripped by Settings (core strips them too, so the
	 * grouped display format pastes in as-is).
	 *
	 * @return string Ready-to-send header value.
	 */
	private function authorization(): string {
		return 'Basic ' . base64_encode( $this->settings->username . ':' . $this->settings->password );
	}

	/**
	 * Normalise one HTTP response into data or a human-readable error.
	 *
	 * Status codes get specific, actionable messages because each one means a
	 * different fix: 401/403 is credentials *or* a stripped header (the most
	 * confusing failure in this plugin's life, hence the explicit hint),
	 * 404 means the plugin is missing over there, and non-JSON means
	 * something else answered — a firewall, a maintenance page, a host
	 * parking page.
	 *
	 * @param array<string, mixed>|WP_Error $response Raw `wp_remote_*` response.
	 * @param string                        $route    Route requested, for logging.
	 *
	 * @return array<array-key, mixed>|WP_Error Decoded body on 2xx; descriptive error otherwise.
	 */
	private function handle( array|WP_Error $response, string $route ): array|WP_Error {
		if ( $response instanceof WP_Error ) {
			$this->logger->error(
				'Transport failure on {route}: {reason}',
				[
					'route'  => $route,
					'reason' => $response->get_error_message(),
				]
			);

			return new WP_Error(
				'contentcopy_transport',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Could not reach the destination site: %s', 'contentcopy' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		$error = match ( true ) {
			401 === $status, 403 === $status => new WP_Error(
				'contentcopy_auth',
				__( 'The destination site rejected the credentials. Check the username and application password, and that the destination host is not stripping the Authorization header.', 'contentcopy' ),
				[ 'status' => $status ]
			),
			404 === $status                  => new WP_Error(
				'contentcopy_not_installed',
				__( 'The destination site did not recognise the Content Copy endpoint. Confirm the plugin is installed and active there.', 'contentcopy' ),
				[ 'status' => $status ]
			),
			! is_array( $data )              => new WP_Error(
				'contentcopy_bad_response',
				__( 'The destination site returned a response that was not JSON. A security plugin, firewall or maintenance page may be intercepting REST requests.', 'contentcopy' ),
				[
					'status'  => $status,
					'excerpt' => mb_substr( $body, 0, 300 ),
				]
			),
			$status < 200 || $status > 299   => new WP_Error(
				is_string( $data['code'] ?? null ) ? $data['code'] : 'contentcopy_remote_error',
				is_string( $data['message'] ?? null ) ? $data['message'] : __( 'The destination site reported an error.', 'contentcopy' ),
				[ 'status' => $status ] + ( is_array( $data['data'] ?? null ) ? $data['data'] : [] )
			),
			default                          => null,
		};

		if ( $error instanceof WP_Error ) {
			$this->logger->error(
				'{route} failed with HTTP {status}: {reason}',
				[
					'route'  => $route,
					'status' => $status,
					'reason' => $error->get_error_message(),
				]
			);

			return $error;
		}

		/** @var array<array-key, mixed> $data Narrowed by the match above: 2xx with a decoded object body. */
		return $data;
	}
}
