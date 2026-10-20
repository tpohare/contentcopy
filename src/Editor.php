<?php

declare(strict_types=1);

namespace ContentCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Editor UI: a block-editor sidebar panel plus a classic-editor metabox.
 *
 * Both surfaces do the same two things — show push status and trigger a
 * push — and both talk to the plugin's own *source-side* REST routes. That
 * indirection is the security story for the whole UI: the browser never sees
 * the destination credentials. WordPress's cookie-authenticated `apiFetch`
 * asks *this* site to push, and the server talks to the destination with the
 * stored application password.
 *
 * No build step: `assets/editor.js` is plain script (no JSX) with the core
 * `wp-*` script handles as dependencies, so the panel is registered through
 * `registerPlugin` + `PluginDocumentSettingPanel` using `createElement`.
 */
final class Editor {

	/**
	 * @param Settings $settings Destination configuration (configured? where to?).
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Register the block assets, the metabox, and the classic script.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'enqueue_block_editor_assets', $this->enqueueBlock( ... ) );
		add_action( 'add_meta_boxes', $this->registerBox( ... ) );
		add_action( 'admin_enqueue_scripts', $this->enqueueClassic( ... ) );
	}

	/**
	 * Enqueue the sidebar panel script for pushable posts in the block editor.
	 *
	 * Skipped for attachments, revisions and post types without UI — none of
	 * those can be pushed, so none gets a panel that could only fail.
	 *
	 * @return void
	 */
	public function enqueueBlock(): void {
		$post = get_post();

		if ( null === $post || ! $this->pushable( $post ) ) {
			return;
		}

		wp_enqueue_script(
			'contentcopy-editor',
			plugins_url( 'assets/editor.js', \ContentCopy\FILE ),
			[ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n' ],
			\ContentCopy\VERSION,
			true
		);
	}

	/**
	 * Enqueue the metabox driver on classic edit screens only.
	 *
	 * The block editor gets the sidebar panel instead (`enqueueBlock`), so
	 * this bails whenever the block editor is active for the post — one post,
	 * one push UI, never both.
	 *
	 * @param string $hookSuffix Current admin page hook.
	 *
	 * @return void
	 */
	public function enqueueClassic( string $hookSuffix ): void {
		if ( ! in_array( $hookSuffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$post = get_post();

		if ( null === $post || ! $this->pushable( $post ) || use_block_editor_for_post( $post ) ) {
			return;
		}

		wp_enqueue_script( 'wp-api-fetch' );
		wp_enqueue_script(
			'contentcopy-editor',
			plugins_url( 'assets/editor.js', \ContentCopy\FILE ),
			[ 'wp-element', 'wp-api-fetch' ],
			\ContentCopy\VERSION,
			true
		);
	}

	/**
	 * Whether a post can be pushed — and therefore deserves push UI.
	 *
	 * Attachments and revisions travel only *inside* a push, never as its
	 * subject; types without UI cannot be edited so cannot be pushed; and the
	 * current user must be allowed to edit the post (the same check gates the
	 * REST route, so the button and the endpoint never disagree).
	 *
	 * @param \WP_Post $post Post to assess.
	 *
	 * @return bool True when pushing this post is meaningful and permitted.
	 */
	private function pushable( \WP_Post $post ): bool {
		if ( in_array( $post->post_type, [ 'attachment', 'revision' ], true ) ) {
			return false;
		}

		$pto = get_post_type_object( $post->post_type );

		return null !== $pto && $pto->show_ui && current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Register the classic metabox on every UI post type except attachments.
	 *
	 * Runs for the block editor too (harmless — the panel is the visible UI
	 * there), which keeps one code path for both editors.
	 *
	 * @return void
	 */
	public function registerBox(): void {
		$types = get_post_types( [ 'show_ui' => true ], 'names' );

		if ( ! is_array( $types ) ) {
			return;
		}

		foreach ( $types as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}

			add_meta_box(
				'contentcopy-push',
				__( 'Content Copy', 'contentcopy' ),
				$this->renderBox( ... ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the classic metabox: destination, last push, push button, result.
	 *
	 * Unconfigured installs get a link to settings instead of a button that
	 * could only fail. The force-overwrite button hides until a conflict
	 * response reveals it — overwriting must always be a second, deliberate
	 * click, never the first thing a misclick reaches.
	 *
	 * @param \WP_Post $post Post being edited.
	 *
	 * @return void Echoes the box HTML.
	 */
	public function renderBox( \WP_Post $post ): void {
		$last = Uuid::getLastPush( $post->ID, $this->settings->destinationUrl );
		?>
		<div class="contentcopy-box">
			<?php if ( ! $this->settings->isConfigured ) : ?>
				<p>
					<a href="<?php echo esc_url( admin_url( 'tools.php?page=contentcopy' ) ); ?>">
						<?php esc_html_e( 'Configure a destination first.', 'contentcopy' ); ?>
					</a>
				</p>
			<?php else : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: destination URL. */
						esc_html__( 'Pushes to %s.', 'contentcopy' ),
						'<strong>' . esc_html( $this->settings->destinationUrl ) . '</strong>'
					);
					?>
				</p>
				<?php if ( is_array( $last ) && isset( $last['time'] ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: date of last push. */
							esc_html__( 'Last pushed %s.', 'contentcopy' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] ) )
						);
						?>
					</p>
				<?php endif; ?>
				<p>
					<button type="button" class="button button-secondary contentcopy-push-button" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
						<?php esc_html_e( 'Push to destination', 'contentcopy' ); ?>
					</button>
					<button type="button" class="button button-link-delete contentcopy-push-force" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>" style="display:none">
						<?php esc_html_e( 'Overwrite destination', 'contentcopy' ); ?>
					</button>
				</p>
				<div class="contentcopy-push-result" aria-live="polite"></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
