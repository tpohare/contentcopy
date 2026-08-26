<?php

declare(strict_types=1);

namespace ContentCopy;

use ContentCopy\Logging\LogDirectory;
use ContentCopy\Logging\LoggerFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Destination configuration plus the Tools settings screen.
 *
 * Credentials live in one of two places, in this order of preference:
 *
 * 1. `wp-config.php` constants (`CONTENTCOPY_DEST_URL/USER/PASSWORD`) — kept
 *    out of the database entirely, so exports, backups and revision control
 *    never see them. When defined, the matching settings fields lock.
 * 2. The `contentcopy_settings` option — the password field submits blank to
 *    mean "keep what is stored", so saving unrelated settings never wipes it.
 *
 * Everything else (fallback author, logging toggle, log level) always lives
 * in the option. Property hooks (`Settings->destinationUrl`) keep call sites
 * reading plain properties while the constant-or-option resolution stays in
 * exactly one place per value.
 */
final class Settings {

	/**
	 * Option key holding the database-backed settings.
	 *
	 * @var string
	 */
	public const OPTION = 'contentcopy_settings';

	/**
	 * Destination site URL, without a trailing slash.
	 *
	 * Constants win over the database; an empty constant is treated as
	 * unset so a half-written `wp-config.php` line cannot blank the URL.
	 *
	 * @var string
	 */
	public string $destinationUrl {
		get => untrailingslashit(
			$this->constant( 'CONTENTCOPY_DEST_URL' ) ?? $this->stored( 'destination_url' )
		);
	}

	/**
	 * Destination username (the application password's owner).
	 *
	 * @var string
	 */
	public string $username {
		get => $this->constant( 'CONTENTCOPY_DEST_USER' ) ?? $this->stored( 'username' );
	}

	/**
	 * Destination application password.
	 *
	 * Spaces are stripped because core strips them when authenticating, so
	 * the grouped display format (`abcd efgh …`) pastes in as-is.
	 *
	 * @var string
	 */
	public string $password {
		get => str_replace(
			' ',
			'',
			$this->constant( 'CONTENTCOPY_DEST_PASSWORD' ) ?? $this->stored( 'password' )
		);
	}

	/**
	 * Whether every credential comes from constants rather than the database.
	 *
	 * All-or-nothing by design: mixed sourcing (URL from constants, password
	 * from the DB) would be a debugging maze, so the UI locks only when the
	 * full trio is defined.
	 *
	 * @var bool
	 */
	public bool $usesConstants {
		get => null !== $this->constant( 'CONTENTCOPY_DEST_URL' )
			&& null !== $this->constant( 'CONTENTCOPY_DEST_USER' )
			&& null !== $this->constant( 'CONTENTCOPY_DEST_PASSWORD' );
	}

	/**
	 * Username or email on the destination used when the source author has
	 * no match there.
	 *
	 * Deliberately not a user ID: IDs mean different humans on different
	 * installs, while an email identifies the same person everywhere. Blank
	 * means "the user the push authenticated as".
	 *
	 * @var string
	 */
	public string $fallbackAuthor {
		get => $this->stored( 'fallback_author' );
	}

	/**
	 * Whether pushes and imports append to the rotating file log.
	 *
	 * The editor always sees the in-request warnings regardless; this only
	 * controls the on-disk record.
	 *
	 * @var bool
	 */
	public bool $loggingEnabled {
		get => '0' !== $this->stored( 'logging_enabled', '1' );
	}

	/**
	 * Log detail level: one of `debug`, `info`, `warning`, `error`.
	 *
	 * @var string
	 */
	public string $logLevel {
		get => $this->stored( 'log_level', 'info' );
	}

	/**
	 * Whether a push is even attemptable: URL, username and password all set.
	 *
	 * The editor and the pusher both check this first, so a half-configured
	 * install explains itself instead of failing mid-push.
	 *
	 * @var bool
	 */
	public bool $isConfigured {
		get => '' !== $this->destinationUrl && '' !== $this->username && '' !== $this->password;
	}

	/**
	 * Register the Tools page, the save handler and the settings assets.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_menu', $this->addPage( ... ) );
		add_action( 'admin_post_contentcopy_save_settings', $this->handleSave( ... ) );
		add_action( 'admin_enqueue_scripts', $this->enqueue( ... ) );
	}

	/**
	 * Read the whole option row as an array.
	 *
	 * A corrupt or unexpected option value degrades to empty settings rather
	 * than fataling the settings screen — a broken row should be viewable
	 * (and fixable) exactly where settings live.
	 *
	 * @return array<string, mixed> Stored settings, or empty when unreadable.
	 */
	private function option(): array {
		$stored = get_option( self::OPTION, [] );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Read one database-backed string setting.
	 *
	 * @param string $key     Option array key.
	 * @param string $default Value when unset or non-scalar.
	 *
	 * @return string The stored value.
	 */
	private function stored( string $key, string $default = '' ): string {
		$value = $this->option()[ $key ] ?? $default;

		return is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * Read one configuration constant, treating empty as unset.
	 *
	 * Only genuine strings count: a `false` or empty definition (from a
	 * half-edited `wp-config.php`, or an environment loader that defines
	 * everything) falls through to the database value instead of blanking it.
	 *
	 * @param string $name Constant name, e.g. `CONTENTCOPY_DEST_URL`.
	 *
	 * @return string|null The constant's value, or null when undefined or empty.
	 */
	private function constant( string $name ): ?string {
		if ( ! defined( $name ) ) {
			return null;
		}

		$value = constant( $name );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Load `wp-api-fetch` on the settings screen for the connection test.
	 *
	 * The inline test script calls `wp.apiFetch`, which only exists when the
	 * handle is enqueued — and it is enqueued nowhere else in wp-admin by
	 * default, so this must be explicit.
	 *
	 * @param string $hookSuffix Current admin page hook.
	 *
	 * @return void
	 */
	public function enqueue( string $hookSuffix ): void {
		if ( 'tools_page_contentcopy' !== $hookSuffix ) {
			return;
		}

		wp_enqueue_script( 'wp-api-fetch' );
	}

	/**
	 * Register the Tools → Content Copy page.
	 *
	 * Tools, not Settings: pushing content is an *action* administrators take,
	 * and the capability checked is `manage_options` either way.
	 *
	 * @return void
	 */
	public function addPage(): void {
		add_submenu_page(
			parent_slug: 'tools.php',
			page_title: __( 'Content Copy', 'contentcopy' ),
			menu_title: __( 'Content Copy', 'contentcopy' ),
			capability: 'manage_options',
			menu_slug: 'contentcopy',
			callback: $this->renderPage( ... ),
		);
	}

	/**
	 * Persist the settings form.
	 *
	 * Capability-checked *and* nonce-checked; the password field is
	 * write-only in effect (empty submission keeps the stored secret), and
	 * the log level is validated against the known choices so a crafted POST
	 * cannot inject an arbitrary Monolog level.
	 *
	 * @return void Redirects back to the settings screen; never returns normally.
	 */
	public function handleSave(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'contentcopy' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'contentcopy_settings' );

		$stored = $this->option();

		$url = isset( $_POST['destination_url'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['destination_url'] ) ) : '';

		$stored['destination_url'] = '' === $url ? '' : untrailingslashit( esc_url_raw( $url ) );
		$stored['username']        = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['username'] ) ) : '';
		$stored['fallback_author'] = isset( $_POST['fallback_author'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['fallback_author'] ) ) : '';
		$stored['logging_enabled'] = empty( $_POST['logging_enabled'] ) ? '0' : '1';

		$level               = isset( $_POST['log_level'] ) ? sanitize_key( wp_unslash( (string) $_POST['log_level'] ) ) : 'info';
		$stored['log_level'] = array_key_exists( $level, LoggerFactory::levelChoices() ) ? $level : 'info';

		// An empty password field leaves the stored password untouched.
		$submitted = isset( $_POST['password'] ) ? trim( wp_unslash( (string) $_POST['password'] ) ) : '';

		if ( '' !== $submitted ) {
			$stored['password'] = str_replace( ' ', '', $submitted );
		}

		update_option( self::OPTION, $stored, false );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'             => 'contentcopy',
					'contentcopy-note' => 'saved',
				],
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings screen: destination, logging, connection test.
	 *
	 * Sections read top to bottom in setup order: where to push, whether to
	 * log, then the button that proves the first two work. The connection
	 * test posts to the plugin's own `local/test` route via `apiFetch` (cookie
	 * auth, like the rest of wp-admin) and pretty-prints the destination's
	 * diagnostics — auth, header presence, upload limits — into the `<pre>`.
	 *
	 * @return void Echoes the settings page.
	 */
	public function renderPage(): void {
		$hasPassword = '' !== $this->password;
		$constants   = $this->usesConstants;
		$logFile     = LogDirectory::file( 'source' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content Copy', 'contentcopy' ); ?></h1>

			<?php if ( 'saved' === ( $_GET['contentcopy-note'] ?? '' ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'contentcopy' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'Install this plugin on both sites. This screen configures where this site pushes content to. The receiving site needs no configuration — only a user with an application password.', 'contentcopy' ); ?>
			</p>

			<?php if ( $constants ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'Credentials are defined in wp-config.php and cannot be edited here.', 'contentcopy' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="contentcopy_save_settings" />
				<?php wp_nonce_field( 'contentcopy_settings' ); ?>

				<h2><?php esc_html_e( 'Destination', 'contentcopy' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="contentcopy-url"><?php esc_html_e( 'Site URL', 'contentcopy' ); ?></label></th>
						<td>
							<input name="destination_url" id="contentcopy-url" type="url" class="regular-text code"
								value="<?php echo esc_attr( $this->destinationUrl ); ?>"
								placeholder="https://example.com" <?php disabled( $constants ); ?> />
							<p class="description"><?php esc_html_e( 'The site root, without a trailing slash.', 'contentcopy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="contentcopy-user"><?php esc_html_e( 'Username', 'contentcopy' ); ?></label></th>
						<td>
							<input name="username" id="contentcopy-user" type="text" class="regular-text"
								value="<?php echo esc_attr( $this->username ); ?>" <?php disabled( $constants ); ?> />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="contentcopy-pass"><?php esc_html_e( 'Application password', 'contentcopy' ); ?></label></th>
						<td>
							<input name="password" id="contentcopy-pass" type="password" class="regular-text" autocomplete="new-password"
								placeholder="<?php echo $hasPassword ? esc_attr__( 'Saved — leave blank to keep', 'contentcopy' ) : ''; ?>"
								<?php disabled( $constants ); ?> />
							<p class="description">
								<?php esc_html_e( 'Created on the destination site under Users → Profile → Application Passwords. Spaces are optional.', 'contentcopy' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="contentcopy-fallback"><?php esc_html_e( 'Fallback author', 'contentcopy' ); ?></label></th>
						<td>
							<input name="fallback_author" id="contentcopy-fallback" type="text" class="regular-text"
								value="<?php echo esc_attr( $this->fallbackAuthor ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Username or email address on the destination site, used when no user there matches the source author. Leave blank to use the application password’s own user.', 'contentcopy' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Logging', 'contentcopy' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Write a log file', 'contentcopy' ); ?></th>
						<td>
							<label>
								<input name="logging_enabled" type="checkbox" value="1" <?php checked( $this->loggingEnabled ); ?> />
								<?php esc_html_e( 'Record pushes and imports to a rotating log file, kept for 14 days.', 'contentcopy' ); ?>
							</label>
							<?php if ( null !== $logFile ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: log file path. */
										esc_html__( 'Written to %s', 'contentcopy' ),
										'<code>' . esc_html( str_replace( ABSPATH, '', $logFile ) ) . '</code>'
									);
									?>
								</p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'The log directory could not be created, so file logging is unavailable.', 'contentcopy' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="contentcopy-level"><?php esc_html_e( 'Detail', 'contentcopy' ); ?></label></th>
						<td>
							<select name="log_level" id="contentcopy-level">
								<?php foreach ( LoggerFactory::levelChoices() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->logLevel, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Connection test', 'contentcopy' ); ?></h2>
			<p><?php esc_html_e( 'Checks authentication, whether the host passes through the Authorization header, and the destination upload limits.', 'contentcopy' ); ?></p>
			<p>
				<button type="button" class="button" id="contentcopy-test" <?php disabled( ! $this->isConfigured ); ?>>
					<?php esc_html_e( 'Test connection', 'contentcopy' ); ?>
				</button>
			</p>
			<pre id="contentcopy-test-result" style="white-space:pre-wrap;max-width:48em"></pre>

			<script>
			( function () {
				const button = document.getElementById( 'contentcopy-test' );
				const output = document.getElementById( 'contentcopy-test-result' );

				if ( ! button ) {
					return;
				}

				button.addEventListener( 'click', function () {
					button.disabled = true;
					output.textContent = <?php echo wp_json_encode( __( 'Testing…', 'contentcopy' ) ); ?>;

					wp.apiFetch( { path: '/contentcopy/v1/local/test', method: 'POST' } )
						.then( ( result ) => {
							output.textContent = JSON.stringify( result, null, 2 );
						} )
						.catch( ( error ) => {
							output.textContent = ( error && error.message ) || <?php echo wp_json_encode( __( 'Request failed.', 'contentcopy' ) ); ?>;
						} )
						.finally( () => {
							button.disabled = false;
						} );
				} );
			} )();
			</script>
		</div>
		<?php
	}
}
