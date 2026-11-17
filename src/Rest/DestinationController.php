<?php

declare(strict_types=1);

namespace ContentCopy\Rest;

use ContentCopy\ChunkStore;
use ContentCopy\GravityFormsBridge;
use ContentCopy\Logging\MemoryHandler;
use ContentCopy\Plugin;
use ContentCopy\Uuid;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Destination-side routes, called server-to-server with an application password.
 *
 * Authentication is HTTP Basic (what application passwords are for), so by
 * the time these callbacks run WordPress has already resolved a user — every
 * permission callback starts from "who is this?" and narrows to "may they?":
 *
 * - `remote/info` and `remote/lookup` — any authenticated user who could
 *   plausibly push (`mayWriteContent()`: edit, upload or any post type's
 *   `create_posts`). Lookup reveals only IDs, hashes and timestamps, never
 *   content, and only for items the user could edit anyway.
 * - `remote/media` and `remote/media/chunk` — require `upload_files`, like
 *   the media library itself, and `edit_post` on the specific attachment
 *   before replacing its bytes. A chunk session also belongs to the user who
 *   opened it, so one uploader cannot append to another's file.
 * - `remote/forms` — same coarse gate as `remote/receive`; the forms
 *   themselves are matched by UUID, so there is no per-item capability to
 *   narrow to beyond being allowed to write content at all.
 * - `remote/receive` — same coarse gate; the `Importer` then checks the
 *   *post-type* capability (`create_posts`) and, for updates, `edit_post`
 *   on the existing copy. A user who can only write pages cannot push posts.
 *
 * Six routes:
 *
 * - `GET /remote/info` — diagnostics the source's connection test displays.
 * - `GET /remote/lookup` — "do you have this UUID?" for dedup and conflicts.
 * - `POST /remote/media` — raw file bytes plus a metadata header.
 * - `POST /remote/media/chunk` — one piece of a file too big for one request.
 * - `POST /remote/forms` — exported Gravity Forms definitions.
 * - `POST /remote/receive` — the remapped post payload.
 */
final class DestinationController {

	/**
	 * REST namespace shared by every Content Copy route, both sides.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'contentcopy/v1';

	/**
	 * @param Plugin $plugin Container (importer factories).
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Register the routes on `rest_api_init`.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', $this->register( ... ) );
	}

	/**
	 * Register the remote routes with their capability gates.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/remote/info',
			[
				'methods'             => 'GET',
				'callback'            => $this->info( ... ),
				'permission_callback' => self::mayWriteContent( ... ),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/remote/lookup',
			[
				'methods'             => 'GET',
				'callback'            => $this->lookup( ... ),
				// Lookup answers "have you seen this UUID?" — useful only to
				// someone about to push, i.e. someone with editing rights.
				// The callback narrows again to the item actually found.
				'permission_callback' => self::mayWriteContent( ... ),
				'args'                => [
					'uuid'      => [
						'type'     => 'string',
						'required' => true,
					],
					'post_type' => [
						'type'    => 'string',
						'default' => '',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/remote/media',
			[
				'methods'             => 'POST',
				'callback'            => $this->media( ... ),
				'permission_callback' => static fn (): bool => current_user_can( 'upload_files' ),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/remote/media/chunk',
			[
				'methods'             => 'POST',
				'callback'            => $this->mediaChunk( ... ),
				// Same gate as the whole-file route: spooling pieces of a
				// file is the first half of uploading one.
				'permission_callback' => static fn (): bool => current_user_can( 'upload_files' ),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/remote/forms',
			[
				'methods'             => 'POST',
				'callback'            => $this->forms( ... ),
				// Coarse gate only — forms have no per-item capability model
				// to check against, so anyone who may write content at all
				// may sync the forms their content embeds.
				'permission_callback' => self::mayWriteContent( ... ),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/remote/receive',
			[
				'methods'             => 'POST',
				'callback'            => $this->receive( ... ),
				// Coarse gate only — "may you write content here at all?" The
				// Importer applies the post-type-specific capability checks
				// once it knows what is actually being written.
				'permission_callback' => self::mayWriteContent( ... ),
			]
		);
	}

	/**
	 * Whether the authenticated user may write content here at all.
	 *
	 * "Logged in" is too weak a gate for these routes: a subscriber has an
	 * account and can hold an application password, but has no business
	 * importing anything or reading back what exists. The three core
	 * capabilities cover the usual roles; the loop after them covers sites
	 * whose custom post types use their own capability sets, where a user may
	 * legitimately create a CPT without holding `edit_posts`.
	 *
	 * This is the coarse gate. Both callbacks that use it narrow again — by
	 * post type and by individual item — once they know what is being asked
	 * for.
	 *
	 * @return bool True when the user can create content of some kind.
	 */
	public static function mayWriteContent(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' ) || current_user_can( 'upload_files' ) ) {
			return true;
		}

		foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $pto ) {
			if ( isset( $pto->cap->create_posts ) && current_user_can( $pto->cap->create_posts ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Diagnostics for the source's connection test.
	 *
	 * Reports the plugin version (mismatched versions across sites explain
	 * otherwise mysterious payload errors), the authenticated user, whether
	 * the `Authorization` header visibly arrived (hosts that strip it are the
	 * number-one cause of confusing 401s), upload limits, and the post types
	 * with UI (so the source can sanity-check targets).
	 *
	 * @return WP_REST_Response Diagnostics array.
	 */
	public function info(): WP_REST_Response {
		$max = wp_max_upload_size();

		return new WP_REST_Response(
			[
				'home'         => home_url(),
				'plugin'       => \ContentCopy\VERSION,
				'user'         => get_current_user_id(),
				'auth_header'  => self::authHeaderStatus(),
				'max_upload'   => [
					'bytes' => $max,
					'human' => size_format( $max ),
				],
				// What the source needs to know to push a file larger than
				// one request allows: that this site can take it in pieces,
				// how big a piece should be, and where the ceiling is.
				'chunking'     => [
					'supported'  => true,
					'chunk_size' => ChunkStore::chunkSize( $max ),
					'max_total'  => ChunkStore::maxTotalSize(),
				],
				'gravity_forms' => [
					'active' => GravityFormsBridge::active(),
				],
				'post_types'   => array_values( get_post_types( [ 'show_ui' => true ] ) ),
			],
			200
		);
	}

	/**
	 * Report how the Authorization header arrived at PHP.
	 *
	 * Apache + CGI/FastCGI famously swallows the header unless the site opts
	 * into passing it through; when that happens the source sees a 401 with
	 * perfectly good credentials. Naming the condition here turns a mystery
	 * into a one-line `.htaccess` fix.
	 *
	 * @return array<string, mixed> Presence flag plus a human explanation.
	 */
	public static function authHeaderStatus(): array {
		$present = isset( $_SERVER['HTTP_AUTHORIZATION'] )
			|| isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] )
			|| isset( $_SERVER['PHP_AUTH_USER'] );

		return [
			'present' => $present,
			'note'    => $present
				? __( 'The header reaches WordPress.', 'contentcopy' )
				: __( 'The header is not visible to PHP. If authentication fails, add "SetEnvIf Authorization" handling to .htaccess.', 'contentcopy' ),
		];
	}

	/**
	 * Answer "do you have this UUID?" for the source's dedup and conflict checks.
	 *
	 * Returns the live record — destination ID, modification time and content
	 * hash — which the source compares against its last-push record. No
	 * content crosses here, only fingerprints.
	 *
	 * @param WP_REST_Request $request Incoming request (`uuid`, optional `post_type`).
	 *
	 * @return WP_REST_Response|WP_Error `{found:false}`, or the live record with `found:true`.
	 */
	public function lookup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$uuid = (string) $request->get_param( 'uuid' );

		if ( ! Uuid::isValid( $uuid ) ) {
			return new WP_Error( 'contentcopy_bad_payload', __( 'A valid UUID is required.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$postType = (string) $request->get_param( 'post_type' );
		$found    = Uuid::findLocal( $uuid, '' !== $postType ? $postType : '' );

		if ( $found <= 0 ) {
			return new WP_REST_Response( [ 'found' => false ], 200 );
		}

		$post = get_post( $found );

		if ( null === $post ) {
			return new WP_REST_Response( [ 'found' => false ], 200 );
		}

		// A hash and a modification time are small, but they are still facts
		// about a specific item — enough to watch a private draft change
		// without being able to read it. Only someone who could edit the item
		// (and so could push over it) gets to see them.
		if ( ! current_user_can( 'edit_post', $found ) ) {
			return new WP_Error(
				'contentcopy_forbidden',
				__( 'Your destination user cannot access the existing copy of this item.', 'contentcopy' ),
				[ 'status' => 403 ]
			);
		}

		return new WP_REST_Response(
			[
				'found'     => true,
				'id'        => $found,
				'post_type' => $post->post_type,
				'modified'  => $post->post_modified_gmt,
				'hash'      => \ContentCopy\ContentHash::ofPost( $post ),
			],
			200
		);
	}

	/**
	 * Store one uploaded file and describe where it landed.
	 *
	 * The body is raw bytes; the metadata envelope (UUID, filename, checksum,
	 * titles) arrives base64-encoded in the `X-ContentCopy-File` header, with
	 * a `$_SERVER` fallback for proxies that mangle custom headers. The
	 * `MediaImporter` matches by UUID, then by content hash, then creates —
	 * so retries and repeat pushes are naturally idempotent.
	 *
	 * @param WP_REST_Request $request Incoming request (raw body + metadata header).
	 *
	 * @return WP_REST_Response|WP_Error Descriptor (`id`, `url`, `sizes`) plus warnings.
	 */
	public function media( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$envelope = self::fileEnvelope( $request );

		if ( $envelope instanceof WP_Error ) {
			return $envelope;
		}

		$bytes = $request->get_body();

		if ( '' === $bytes ) {
			return new WP_Error( 'contentcopy_empty_file', __( 'The file body was empty.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$collector = new MemoryHandler();
		$result    = $this->plugin->mediaImporter( $collector )->importBytes( $bytes, $envelope );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$response = $result->toArray();

		$response['warnings'] = $collector->problems();

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Spool one piece of a file, and import it once the last piece lands.
	 *
	 * The body is the piece itself; the `X-ContentCopy-File` header says
	 * which upload it belongs to and at what offset. The first piece of a
	 * file carries the full metadata envelope and no session, which is how
	 * the store knows to open one.
	 *
	 * Every reply reports how much of the file the destination now holds, so
	 * a source that lost a response can resume from the right place instead
	 * of starting the file again.
	 *
	 * @param WP_REST_Request $request Incoming request (raw piece + metadata header).
	 *
	 * @return WP_REST_Response|WP_Error Progress, or the finished descriptor on the last piece.
	 */
	public function mediaChunk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$envelope = self::fileEnvelope( $request );

		if ( $envelope instanceof WP_Error ) {
			return $envelope;
		}

		$bytes = $request->get_body();

		if ( '' === $bytes ) {
			return new WP_Error( 'contentcopy_empty_file', __( 'A piece of the file arrived empty.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$collector = new MemoryHandler();
		$store     = $this->plugin->chunkStore( $collector );
		$userId    = get_current_user_id();
		$session   = is_string( $envelope['session'] ?? null ) ? $envelope['session'] : '';

		if ( '' === $session ) {
			$opened = $store->open( $envelope, isset( $envelope['total'] ) ? (int) $envelope['total'] : 0, $userId );

			if ( $opened instanceof WP_Error ) {
				return $opened;
			}

			$session = $opened;
		}

		$progress = $store->append(
			$session,
			isset( $envelope['offset'] ) ? (int) $envelope['offset'] : 0,
			$bytes,
			is_string( $envelope['chunk_sha256'] ?? null ) ? $envelope['chunk_sha256'] : '',
			$userId
		);

		if ( $progress instanceof WP_Error ) {
			return $progress;
		}

		$response = [ 'session' => $session ] + $progress;

		if ( ! $progress['complete'] ) {
			return new WP_REST_Response( $response, 200 );
		}

		// The file is whole. Verify it against the source's checksum, then
		// hand it to the same importer a single-request upload goes through.
		$assembled = $store->assemble( $session, $userId );

		if ( $assembled instanceof WP_Error ) {
			return $assembled;
		}

		$media = $this->plugin->mediaImporter( $collector )->importAssembled( $assembled['path'], $assembled['envelope'] );

		$store->discard( $session );

		if ( $media instanceof WP_Error ) {
			return $media;
		}

		return new WP_REST_Response(
			$media->toArray() + $response + [ 'warnings' => $collector->problems() ],
			200
		);
	}

	/**
	 * Import a batch of exported Gravity Forms definitions.
	 *
	 * Expects `['forms' => [...]]` as produced by `GravityFormsBridge::export()`.
	 * The response is the source-to-destination ID map plus warnings — notably
	 * the feed warning, which the source merges into the push report so the
	 * editor sees that feed settings still name the source site's accounts.
	 *
	 * @param WP_REST_Request $request Incoming request (JSON forms batch).
	 *
	 * @return WP_REST_Response|WP_Error ID map and warnings, or why the batch was refused.
	 */
	public function forms( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'contentcopy_bad_payload', __( 'A JSON object was expected.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$collector = new MemoryHandler();
		$result    = $this->plugin->formImporter( $collector )->import( $payload['forms'] ?? null );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$result['warnings'] = array_values(
			array_unique(
				[ ...( $result['warnings'] ?? [] ), ...$collector->problems() ]
			)
		);
		$result['log']      = $collector->records();

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Import one post payload as a create or an in-place update.
	 *
	 * Expects the decoded JSON object `PushPayload::toArray()` produces. The
	 * response carries the destination's report (ID, link, outcome, hash,
	 * modification time) merged with the import's warnings and full log
	 * trail — everything the source needs to record the push and everything
	 * the editor needs to display it.
	 *
	 * @param WP_REST_Request $request Incoming request (JSON payload body).
	 *
	 * @return WP_REST_Response|WP_Error Import report, or why it was refused.
	 */
	public function receive( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'contentcopy_bad_payload', __( 'A JSON object was expected.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$collector = new MemoryHandler();
		$result    = $this->plugin->importer( $collector )->import( $payload, get_current_user_id() );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$result['warnings'] = array_values(
			array_unique(
				[ ...( $result['warnings'] ?? [] ), ...$collector->problems() ]
			)
		);
		$result['log']      = $collector->records();

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Decode the file metadata envelope from the request headers.
	 *
	 * WordPress exposes custom headers through `get_header()`; some proxies
	 * strip or rename them in transit, so a missing header is retried against
	 * the raw `$_SERVER` entry before giving up with a clear error.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return array<string, mixed>|WP_Error Decoded envelope, or why it could not be read.
	 */
	private static function fileEnvelope( WP_REST_Request $request ): array|WP_Error {
		$raw = $request->get_header( 'X-ContentCopy-File' );

		if ( '' === $raw ) {
			// Some proxies mangle custom headers; fall back to the server var.
			$raw = isset( $_SERVER['HTTP_X_CONTENTCOPY_FILE'] ) ? (string) $_SERVER['HTTP_X_CONTENTCOPY_FILE'] : '';
		}

		if ( '' === $raw ) {
			return new WP_Error( 'contentcopy_bad_file', __( 'The file metadata header is missing.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$decoded = json_decode( base64_decode( $raw, true ) ?: '', true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'contentcopy_bad_file', __( 'The file metadata header could not be read.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		return $decoded;
	}
}
