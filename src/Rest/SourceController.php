<?php

declare(strict_types=1);

namespace ContentCopy\Rest;

use ContentCopy\Logging\MemoryHandler;
use ContentCopy\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Source-side routes, called by the editor on *this* site.
 *
 * Cookie-authenticated like any other wp-admin request — the destination
 * credentials never leave the server. The browser asks "push post 12", and
 * this controller builds a pusher, runs it, and hands back the destination's
 * report plus the in-request log trail and any warnings.
 *
 * Three routes:
 *
 * - `POST /local/test` — connection diagnostics for the settings screen.
 * - `POST /local/push` — push one post (this is the editor button).
 * - `GET /local/status` — UUID, configuration and last-push state for the UI.
 */
final class SourceController {

	/**
	 * REST namespace shared by every Content Copy route, both sides.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'contentcopy/v1';

	/**
	 * @param Plugin $plugin Container (settings, pusher factory).
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
	 * Register the three local routes.
	 *
	 * Push and status both carry a `post_id` and share `canPush()`: the route
	 * is only reachable by someone allowed to *edit* that post, mirroring
	 * the editor UI's own gate. The settings test instead requires
	 * `manage_options`, like the screen that hosts it.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/local/test',
			[
				'methods'             => 'POST',
				'callback'            => $this->test( ... ),
				'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/local/push',
			[
				'methods'             => 'POST',
				'callback'            => $this->push( ... ),
				'permission_callback' => self::canPush( ... ),
				'args'                => [
					'post_id' => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					],
					'force'   => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/local/status',
			[
				'methods'             => 'GET',
				'callback'            => $this->status( ... ),
				'permission_callback' => self::canPush( ... ),
				'args'                => [
					'post_id' => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					],
				],
			]
		);
	}

	/**
	 * Gate push and status routes on edit access to the post in question.
	 *
	 * A contributor who can edit their own post can push it; a stranger who
	 * merely knows the post ID gets nothing. Runs before validation, so an
	 * invalid ID simply fails closed.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return bool True when the current user may edit the given post.
	 */
	public static function canPush( WP_REST_Request $request ): bool {
		$postId = (int) $request->get_param( 'post_id' );

		if ( $postId <= 0 ) {
			return false;
		}

		return current_user_can( 'edit_post', $postId );
	}

	/**
	 * Run the connection diagnostics for the settings screen.
	 *
	 * Delegates to the destination's `remote/info` and passes it through, so
	 * the editor sees auth status, header-presence detection and upload
	 * limits in one JSON blob.
	 *
	 * @return WP_REST_Response|WP_Error Diagnostics on success; the client's error otherwise.
	 */
	public function test(): WP_REST_Response|WP_Error {
		$settings = $this->plugin->settings;

		if ( ! $settings->isConfigured ) {
			return new WP_Error( 'contentcopy_not_configured', __( 'No destination is configured yet.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$collector = new MemoryHandler();
		$logger    = $this->plugin->loggers->create( 'source', $collector );
		$client    = ( new \ContentCopy\Client( $settings ) )->withLogger( $logger );

		$info = $client->get( 'remote/info' );

		if ( $info instanceof WP_Error ) {
			return $info;
		}

		return new WP_REST_Response(
			[
				'ok'          => true,
				'destination' => $settings->destinationUrl,
				'info'        => $info,
			],
			200
		);
	}

	/**
	 * Push one post and report what happened.
	 *
	 * The response merges three narratives: the destination's report, every
	 * collected log record, and the deduplicated warning list the UI shows.
	 * Conflict aborts (`contentcopy_conflict`, HTTP 409) flow through
	 * untouched with the log attached, so the UI can offer the overwrite
	 * confirmation with full context.
	 *
	 * @param WP_REST_Request $request Incoming request (`post_id`, `force`).
	 *
	 * @return WP_REST_Response|WP_Error Push report, or the reason it failed.
	 */
	public function push( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$postId = (int) $request->get_param( 'post_id' );
		$force  = (bool) $request->get_param( 'force' );

		$collector = new MemoryHandler();
		$result    = $this->plugin->pusher( $collector )->push( $postId, $force );

		if ( $result instanceof WP_Error ) {
			$result->add_data( [ 'log' => $collector->records() ] );

			return $result;
		}

		$result['log']      = $collector->records();
		$result['warnings'] = array_values(
			array_unique(
				[ ...( $result['warnings'] ?? [] ), ...$collector->problems() ]
			)
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Describe one post's push state for the editor UI.
	 *
	 * The panel uses this to decide what to show: unconfigured installs get
	 * a settings link, unpushable types get no button, and everything else
	 * shows the destination plus when it last received this item.
	 *
	 * @param WP_REST_Request $request Incoming request (`post_id`).
	 *
	 * @return WP_REST_Response|WP_Error Status array, or 404 when the post is gone.
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$postId = (int) $request->get_param( 'post_id' );
		$post   = get_post( $postId );

		if ( null === $post ) {
			return new WP_Error( 'contentcopy_not_found', __( 'That item no longer exists.', 'contentcopy' ), [ 'status' => 404 ] );
		}

		$settings = $this->plugin->settings;

		return new WP_REST_Response(
			[
				'post_id'      => $postId,
				'uuid'         => \ContentCopy\Uuid::ensure( $postId ),
				'configured'   => $settings->isConfigured,
				'destination'    => $settings->destinationUrl,
				'last_push'    => \ContentCopy\Uuid::getLastPush( $postId, $settings->destinationUrl ),
				'post_type'    => $post->post_type,
				'post_status'  => $post->post_status,
				'pushable'     => ! in_array( $post->post_type, [ 'attachment', 'revision' ], true ) && 'auto-draft' !== $post->post_status,
			],
			200
		);
	}
}
