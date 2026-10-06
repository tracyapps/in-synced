<?php
/**
 * Synchronization coordinator and admin endpoints.
 *
 * @package In_Synced
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Coordinates authenticated sync, state and admin rendering. */
final class In_Synced {

	private const SETTINGS_OPTION           = 'in_synced_settings';
	private const PROFILES_OPTION           = 'in_synced_profiles';
	private const LAST_RUNS_OPTION          = 'in_synced_last_runs';
	private const REST_NAMESPACE            = 'in-synced/v1';
	private const MAX_CLOCK_SKEW            = 300;
	private const FILE_CHUNK_SIZE           = 786432;
	private const PROGRESS_TRANSIENT_PREFIX = 'in_synced_progress_';
	private const PROGRESS_TTL              = 3600;

	private static $instance        = null;
	private $binary_columns_cache   = array();
	private $table_columns_cache    = array();
	private $primary_key_cache      = array();
	private $active_run_id          = '';
	private $active_session_id      = '';
	private $receiver_session_id    = '';
	private $receiver_uncertain     = false;
	private $receiver_session_open  = false;
	private const LEASE_TTL         = 900;
	private const MAX_REQUEST_BYTES = 8388608;
	private const MAX_BATCH_ROWS    = 1000;

	public static function instance(): In_Synced {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_post_in_synced_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_in_synced_save_profile', array( $this, 'handle_save_profile' ) );
		add_action( 'admin_post_in_synced_delete_profile', array( $this, 'handle_delete_profile' ) );
		add_action( 'admin_post_in_synced_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'wp_ajax_in_synced_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_in_synced_compare', array( $this, 'ajax_compare' ) );
		add_action( 'wp_ajax_in_synced_run', array( $this, 'ajax_run' ) );
		add_action( 'wp_ajax_in_synced_progress', array( $this, 'ajax_progress' ) );
		add_action( 'wp_ajax_in_synced_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public static function activate(): void {
		if ( ! get_option( self::SETTINGS_OPTION ) ) {
			add_option( self::SETTINGS_OPTION, self::default_settings(), '', false );
		}

		if ( ! get_option( self::PROFILES_OPTION ) ) {
			add_option( self::PROFILES_OPTION, self::default_profiles(), '', false );
		}
	}

	public static function default_settings(): array {
		return array(
			'remote_url'          => '',
			'local_url'           => home_url(),
			'remote_public_url'   => '',
			'shared_secret'       => '',
			'allow_db_receive'    => false,
			'allow_media_receive' => false,
			'allow_theme_receive' => false,
			'allow_file_receive'  => false,
			'theme_slug'          => function_exists( 'get_stylesheet' ) ? get_stylesheet() : '',
			'allowed_file_roots'  => function_exists( 'get_stylesheet' ) ? 'themes/' . get_stylesheet() : '',
		);
	}

	public static function default_profiles(): array {
		return array(
			'default' => array(
				'name'              => __( 'Default staging push', 'in-synced' ),
				'mode'              => 'upsert',
				'sync_database'     => true,
				'sync_media'        => true,
				'sync_theme'        => true,
				'incremental_files' => true,
				'include_users'     => false,
				'include_tables'    => '',
				'exclude_tables'    => "users\nusermeta",
				'database_scope'    => 'tables',
				'post_type_filter'  => '',
				'upload_includes'   => '',
				'upload_excludes'   => "in-synced-backups\n*.tmp\n*.log",
				'batch_size'        => 100,
				'theme_slug'        => function_exists( 'get_stylesheet' ) ? get_stylesheet() : '',
				'file_roots'        => function_exists( 'get_stylesheet' ) ? 'themes/' . get_stylesheet() : '',
				'theme_excludes'    => ".git\nnode_modules\nvendor\n.DS_Store\n*.map\n*.log",
				'file_excludes'     => ".git\nnode_modules\nvendor\n.DS_Store\n*.map\n*.log\n.cache",
			),
		);
	}

	public function get_settings(): array {
		return array_merge( self::default_settings(), (array) get_option( self::SETTINGS_OPTION, array() ) );
	}

	public function get_profiles(): array {
		$defaults = self::default_profiles();
		$profiles = $defaults;
		$saved    = (array) get_option( self::PROFILES_OPTION, array() );

		foreach ( $saved as $key => $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}

			$base             = $defaults[ $key ] ?? $defaults['default'];
			$profiles[ $key ] = array_merge( $base, $profile );
		}

		return $profiles;
	}

	public function get_profile( string $profile_key ): array {
		$profiles = $this->get_profiles();

		return $profiles[ $profile_key ] ?? $profiles['default'];
	}

	public function register_rest_routes(): void {
		$routes = array(
			'ping'            => 'rest_ping',
			'begin-sync'      => 'rest_begin_sync',
			'end-sync'        => 'rest_end_sync',
			'begin-db'        => 'rest_begin_db',
			'validate-batch'  => 'rest_validate_batch',
			'table-batch'     => 'rest_table_batch',
			'end-db'          => 'rest_end_db',
			'begin-content'   => 'rest_begin_content',
			'compare-content' => 'rest_compare_content',
			'file-chunk'      => 'rest_file_chunk',
		);

		foreach ( $routes as $route => $method ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $method ),
					'permission_callback' => array( $this, 'authorize_rest_request' ),
				)
			);
		}
	}

	public function authorize_rest_request( WP_REST_Request $request ) {
		if ( is_multisite() ) {
			return new WP_Error( 'in_synced_multisite_unsupported', __( 'Sync is currently supported only on single-site WordPress installs.', 'in-synced' ), array( 'status' => 403 ) );
		}
		$settings = $this->get_settings();
		$secret   = $this->get_shared_secret( $settings );

		if ( ! $secret ) {
			return new WP_Error( 'in_synced_no_secret', __( 'In Synced is not paired on this site.', 'in-synced' ), array( 'status' => 403 ) );
		}

		$timestamp_header = (string) $request->get_header( 'x_in_synced_timestamp' );
		$timestamp        = ctype_digit( $timestamp_header ) ? (int) $timestamp_header : 0;
		$nonce            = (string) $request->get_header( 'x_in_synced_nonce' );
		$signature        = (string) $request->get_header( 'x_in_synced_signature' );

		if ( ! $timestamp || ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/D', $nonce ) || ! preg_match( '/^v2=[a-f0-9]{64}$/D', $signature ) ) {
			return new WP_Error( 'in_synced_unsigned', __( 'In Synced requires v2 signature headers. Upgrade both paired sites before syncing.', 'in-synced' ), array( 'status' => 401 ) );
		}

		if ( abs( time() - $timestamp ) > self::MAX_CLOCK_SKEW ) {
			return new WP_Error( 'in_synced_stale', __( 'In Synced request timestamp is outside the allowed window.', 'in-synced' ), array( 'status' => 401 ) );
		}

		$nonce_key = 'in_synced_nonce_' . md5( $nonce );
		if ( get_transient( $nonce_key ) || get_option( $nonce_key ) ) {
			return new WP_Error( 'in_synced_replay', __( 'In Synced request nonce has already been used.', 'in-synced' ), array( 'status' => 401 ) );
		}

		$body = $request->get_body();
		if ( strlen( $body ) > self::MAX_REQUEST_BYTES ) {
			return new WP_Error( 'in_synced_payload_large', __( 'The sync request exceeds the 8 MB limit. Reduce the batch size.', 'in-synced' ), array( 'status' => 413 ) );
		}
		if ( ! is_object( json_decode( $body ) ) ) {
			return new WP_Error( 'in_synced_bad_payload', __( 'Sync requests must contain a JSON object.', 'in-synced' ), array( 'status' => 400 ) );
		}
		$expected = In_Synced_Safety::signature( $request->get_method(), $request->get_route(), $timestamp_header, $nonce, $body, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'in_synced_bad_signature', __( 'Invalid In Synced request signature.', 'in-synced' ), array( 'status' => 401 ) );
		}

		// Atomic single-use claim: add_option() is a unique INSERT, so concurrent replays cannot both pass.
		if ( ! add_option( $nonce_key, time(), '', false ) ) {
			return new WP_Error( 'in_synced_replay', __( 'In Synced request nonce has already been used.', 'in-synced' ), array( 'status' => 401 ) );
		}

		// Opportunistic cleanup: a nonce only needs to outlive the full request
		// acceptance span (2 x clock skew plus margin), never longer.
		if ( wp_rand( 1, 10 ) === 1 ) {
			$this->sweep_expired_nonces( self::MAX_CLOCK_SKEW * 2 + 60 );
		}

		return true;
	}

	private function sweep_expired_nonces( int $ttl ): void {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
				'SELECT option_name, option_value FROM `' . $this->escape_identifier( $wpdb->options ) . '` WHERE option_name LIKE %s',
				$wpdb->esc_like( 'in_synced_nonce_' ) . '%'
			),
			ARRAY_A
		);

		$cutoff = time() - max( 60, $ttl );
		foreach ( $rows as $row ) {
			if ( (int) $row['option_value'] < $cutoff ) {
				delete_option( (string) $row['option_name'] );
			}
		}
	}

	public function register_admin_page(): void {
		add_management_page(
			'In Synced',
			'In Synced',
			'manage_options',
			'in-synced',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access In Synced.', 'in-synced' ) );
		}

		$settings = $this->get_settings();
		$profiles = $this->get_profiles();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display selection; mutations use separate capability and nonce protected handlers.
		$profile_key        = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : 'default';
		$profile            = $profiles[ $profile_key ] ?? $profiles['default'];
		$last_log           = get_transient( 'in_synced_last_log_' . get_current_user_id() );
		$last_run           = $this->last_run_record( $profile_key );
		$secret_fingerprint = $this->secret_fingerprint( $settings );
		$ajax_nonce         = wp_create_nonce( 'in_synced_ajax' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display selection; mutations use separate capability and nonce protected handlers.
		$notice_key = isset( $_GET['in_synced_notice'] ) ? sanitize_key( wp_unslash( $_GET['in_synced_notice'] ) ) : '';
		$notices    = $this->admin_notices_map();
		$notice     = $notices[ $notice_key ] ?? null;
		delete_transient( 'in_synced_last_log_' . get_current_user_id() );

		$has_secret  = $this->get_shared_secret( $settings ) !== '';
		$has_remote  = trim( (string) ( $settings['remote_url'] ?? '' ) ) !== '';
		$is_receiver = ! empty( $settings['allow_db_receive'] ) || ! empty( $settings['allow_media_receive'] ) || ! empty( $settings['allow_theme_receive'] ) || ! empty( $settings['allow_file_receive'] );
		$show_setup  = ! $has_secret || ( ! $has_remote && ! $is_receiver );

		$profile_select_keys = array_keys( $profiles );
		if ( ! in_array( $profile_key, $profile_select_keys, true ) ) {
			$profile_select_keys[] = $profile_key;
		}

		ob_start();
		?>
		<details class="in-synced-panel in-synced-details" id="in-synced-connection"<?php echo $show_setup ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'Connection Settings', 'in-synced' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'in_synced_save_settings' ); ?>
				<input type="hidden" name="action" value="in_synced_save_settings" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="in-synced-remote-url"><?php esc_html_e( 'Remote admin/API URL', 'in-synced' ); ?></label></th>
						<td><input class="regular-text" id="in-synced-remote-url" name="remote_url" type="url" value="<?php echo esc_attr( $settings['remote_url'] ); ?>" placeholder="https://staging.example.com" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="in-synced-local-url"><?php esc_html_e( "This site's public URL", 'in-synced' ); ?></label></th>
						<td><input class="regular-text" id="in-synced-local-url" name="local_url" type="url" value="<?php echo esc_attr( $settings['local_url'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="in-synced-remote-public-url"><?php esc_html_e( 'Remote public URL', 'in-synced' ); ?></label></th>
						<td><input class="regular-text" id="in-synced-remote-public-url" name="remote_public_url" type="url" value="<?php echo esc_attr( $settings['remote_public_url'] ); ?>" placeholder="https://staging.example.com" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="in-synced-secret"><?php esc_html_e( 'Shared secret', 'in-synced' ); ?></label></th>
						<td>
							<input class="regular-text" id="in-synced-secret" name="shared_secret" type="password" value="" autocomplete="new-password" placeholder="<?php echo $secret_fingerprint ? esc_attr__( 'Stored secret', 'in-synced' ) : esc_attr__( 'Paste shared secret', 'in-synced' ); ?>" />
							<button type="button" class="button" id="in-synced-generate-secret"><?php esc_html_e( 'Generate suggested secret', 'in-synced' ); ?></button>
							<button type="button" class="button" id="in-synced-copy-secret"><?php esc_html_e( 'Copy secret', 'in-synced' ); ?></button>
							<p class="description">
								<?php
								printf(
									/* translators: %s: current secret fingerprint. */
									esc_html__( 'Current fingerprint: %s. Leave blank to keep the current secret.', 'in-synced' ),
									'<code>' . esc_html( $secret_fingerprint ? $secret_fingerprint : 'none' ) . '</code>'
								);
								?>
								<?php esc_html_e( 'Use the same secret on both sites; copy it into the other site’s Connection Settings. For stronger security, define IN_SYNCED_SHARED_SECRET in wp-config.php on both sites (at least 32 characters) instead of storing it here.', 'in-synced' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Receiver permissions', 'in-synced' ); ?></th>
						<td>
							<fieldset class="in-synced-fieldset">
								<legend class="screen-reader-text"><?php esc_html_e( 'Receiver permissions', 'in-synced' ); ?></legend>
								<label><input type="checkbox" name="allow_db_receive" value="1" <?php checked( $settings['allow_db_receive'] ); ?> /> <?php esc_html_e( 'Allow signed database writes to this site', 'in-synced' ); ?></label><br />
								<label><input type="checkbox" name="allow_media_receive" value="1" <?php checked( $settings['allow_media_receive'] ); ?> /> <?php esc_html_e( 'Allow signed upload writes to this site', 'in-synced' ); ?></label><br />
								<label><input type="checkbox" name="allow_theme_receive" value="1" <?php checked( $settings['allow_theme_receive'] || $settings['allow_file_receive'] ); ?> /> <?php esc_html_e( 'Allow signed wp-content file writes to this site', 'in-synced' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="in-synced-file-roots"><?php esc_html_e( 'Allowed wp-content roots', 'in-synced' ); ?></label></th>
						<td>
							<textarea id="in-synced-file-roots" name="allowed_file_roots" rows="4" class="large-text code"><?php echo esc_textarea( $settings['allowed_file_roots'] ?: ( $settings['theme_slug'] ? 'themes/' . $settings['theme_slug'] : '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One relative wp-content path per line. Examples:', 'in-synced' ); ?> <code>themes/my-theme</code>, <code>plugins/my-custom-plugin</code>. <?php esc_html_e( 'Receivers only accept writes inside these roots.', 'in-synced' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save connection', 'in-synced' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'in_synced_test_connection' ); ?>
				<input type="hidden" name="action" value="in_synced_test_connection" />
				<?php submit_button( __( 'Test remote', 'in-synced' ), 'secondary' ); ?>
			</form>
		</details>
		<?php
		$connection_section = ob_get_clean();
		?>
		<div class="wrap in-synced-wrap">
			<style>
				.in-synced-wrap :focus-visible { outline: 3px solid #135e96; outline-offset: 3px; }
				.in-synced-shell { max-width: 1180px; }
				.in-synced-hero { align-items: flex-start; background: linear-gradient(135deg, #181322, #402367 58%, #62359c); border-radius: 10px; box-shadow: 0 18px 42px rgba(16,21,23,.12); color: #fff; display: flex; gap: 24px; justify-content: space-between; margin: 18px 0 20px; overflow: hidden; padding: 24px; position: relative; }
				.in-synced-hero:after { border: 1px solid rgba(255,255,255,.18); border-radius: 999px; content: ""; height: 170px; position: absolute; right: -56px; top: -70px; width: 170px; }
				.in-synced-hero h1 { color: #fff; font-size: 34px; line-height: 1; margin: 0 0 8px; }
				.in-synced-hero p { color: rgba(255,255,255,.78); max-width: 760px; font-size: 14px; margin: 0; }
				.in-synced-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 16px 0; }
				.in-synced-card, .in-synced-panel { background: #fff; border: 1px solid #e6e1ee; border-radius: 10px; box-shadow: 0 6px 22px rgba(16,21,23,.05); }
				.in-synced-card { border-top: 4px solid #62359c; padding: 14px 16px; }
				.in-synced-card strong { display: block; color: #1d2327; font-size: 12px; letter-spacing: .04em; margin-bottom: 6px; text-transform: uppercase; }
				.in-synced-card span { color: #50575e; overflow-wrap: anywhere; }
				.in-synced-panel { padding: 20px; margin: 16px 0; }
				.in-synced-panel h2 { margin: 0 0 12px; font-size: 19px; }
				.in-synced-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0 16px; }
				.in-synced-run-controls { background: #fcfbff; border: 1px solid #e6e1ee; border-radius: 8px; display: grid; grid-template-columns: 220px repeat(4, max-content); gap: 14px; align-items: center; margin: 16px 0; padding: 14px; }
				.in-synced-preview { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 14px; }
				.in-synced-preview-card { border: 1px solid #e6e1ee; border-radius: 10px; padding: 15px; background: #fcfbff; min-height: 120px; }
				.in-synced-preview-card h3 { color: #402367; margin: 0 0 8px; font-size: 14px; letter-spacing: .04em; text-transform: uppercase; }
				.in-synced-preview-card .count { font-size: 28px; font-weight: 700; color: #101517; }
				.in-synced-preview-card ul { margin: 10px 0 0 18px; max-height: 180px; overflow: auto; }
				.in-synced-progress-bar { height: 14px; border-radius: 999px; overflow: hidden; background: #dcdcde; margin-top: 12px; }
				.in-synced-progress-bar span { display: block; height: 100%; width: 0%; background: linear-gradient(90deg, #402367, #62359c); transition: width .25s ease; }
				.in-synced-progress-meta { display: flex; justify-content: space-between; gap: 20px; margin-top: 8px; color: #50575e; }
				.in-synced-log { white-space: pre-wrap; max-height: 320px; overflow: auto; background: #181322; color: #f1eef7; border-radius: 8px; padding: 14px; margin-top: 14px; font-size: 12px; }
				.in-synced-badge { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.24); border-radius: 999px; color: #fff; display: inline-block; font-size: 12px; padding: 5px 10px; position: relative; z-index: 1; }
				.in-synced-danger { color: #b32d2e; }
				.in-synced-details { margin-top: 18px; }
				.in-synced-details summary { cursor: pointer; font-weight: 600; padding: 12px 0; }
				.in-synced-warnings { background: #fcf0f1; border: 1px solid #f0c7ca; border-left: 4px solid #d63638; border-radius: 6px; color: #50575e; margin: 14px 0 0; padding: 10px 14px; }
				.in-synced-warnings p { margin: 4px 0; }
				.in-synced-checklist { color: #50575e; margin: 8px 0 0 20px; }
				.in-synced-checklist li { margin: 4px 0; }
				.in-synced-checklist li.is-done { color: #62359c; font-weight: 600; }
				.in-synced-fieldset { border: 0; margin: 0; padding: 0; }
				.in-synced-profile-bar { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; margin: 4px 0 14px; }
				.in-synced-profile-bar form { margin: 0; min-width: 0; max-width: 100%; }
				.in-synced-wrap .regular-text { box-sizing: border-box; max-width: 100%; }
				#in-synced-new-profile { min-width: 0; width: 100%; }
				.in-synced-profile-bar form:has(#in-synced-new-profile) { display: flex; flex-wrap: wrap; gap: 8px; flex: 1 1 260px; }
				@media (max-width: 960px) {
					.in-synced-grid, .in-synced-preview { grid-template-columns: 1fr; }
					.in-synced-hero, .in-synced-run-controls { display: block; }
					.in-synced-run-controls label { display: block; margin: 8px 0; }
				}
				@media (prefers-reduced-motion: reduce) {
					.in-synced-progress-bar span { transition: none; }
				}
			</style>

			<div class="in-synced-shell">
				<div class="in-synced-hero">
					<div>
						<h1>In Synced</h1>
						<p><?php esc_html_e( 'Push selected database tables, uploads, and explicit wp-content file roots from this site to a paired WordPress site using signed requests.', 'in-synced' ); ?></p>
					</div>
					<span class="in-synced-badge"><?php echo $secret_fingerprint ? esc_html__( 'Key', 'in-synced' ) . ' ' . esc_html( $secret_fingerprint ) : esc_html__( 'Not paired', 'in-synced' ); ?></span>
				</div>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $last_log ) : ?>
				<div class="notice notice-info">
					<pre tabindex="0" aria-label="<?php echo esc_attr__( 'Previous push log', 'in-synced' ); ?>" style="white-space: pre-wrap; max-height: 420px; overflow: auto;"><?php echo esc_html( $last_log ); ?></pre>
				</div>
			<?php endif; ?>

			<?php if ( $show_setup ) : ?>
				<div class="in-synced-panel" id="in-synced-setup">
					<h2><?php esc_html_e( 'Setup checklist', 'in-synced' ); ?></h2>
					<p><?php esc_html_e( 'Finish pairing this site with its partner before pushing:', 'in-synced' ); ?></p>
					<ol class="in-synced-checklist">
						<li class="<?php echo $has_secret ? 'is-done' : ''; ?>">
							<?php
							if ( $has_secret ) :
								?>
								<span aria-hidden="true">&#10003;</span> <span class="screen-reader-text"><?php esc_html_e( 'Completed —', 'in-synced' ); ?></span><?php endif; ?>
							<?php esc_html_e( 'Use the same long shared secret (32+ characters) on both sites.', 'in-synced' ); ?>
							<a href="#in-synced-secret"><?php esc_html_e( 'Add the shared secret', 'in-synced' ); ?></a>
						</li>
						<li class="<?php echo $has_remote ? 'is-done' : ''; ?>">
							<?php
							if ( $has_remote ) :
								?>
								<span aria-hidden="true">&#10003;</span> <span class="screen-reader-text"><?php esc_html_e( 'Completed —', 'in-synced' ); ?></span><?php endif; ?>
							<?php esc_html_e( 'Set the remote admin/API URL on the sending site (skip if this site only receives).', 'in-synced' ); ?>
							<a href="#in-synced-remote-url"><?php esc_html_e( 'Set the remote URL', 'in-synced' ); ?></a>
						</li>
						<li><?php esc_html_e( 'Run “Test remote” and confirm the receiver permissions on the other site.', 'in-synced' ); ?></li>
						<li><?php esc_html_e( 'Use “Preview push”, then “Push Now”.', 'in-synced' ); ?></li>
					</ol>
				</div>
			<?php endif; ?>

				<div class="in-synced-grid">
					<div class="in-synced-card"><strong><?php esc_html_e( 'Remote', 'in-synced' ); ?></strong><span><?php echo esc_html( $settings['remote_url'] ?: __( 'Not configured', 'in-synced' ) ); ?></span></div>
					<div class="in-synced-card"><strong><?php esc_html_e( 'Find/replace', 'in-synced' ); ?></strong><span><?php echo esc_html( ( $settings['local_url'] ?: home_url() ) . ' -> ' . ( $settings['remote_public_url'] ?: $settings['remote_url'] ?: __( 'remote', 'in-synced' ) ) ); ?></span></div>
					<div class="in-synced-card"><strong><?php esc_html_e( 'Allowed file roots', 'in-synced' ); ?></strong><span><?php echo esc_html( $settings['allowed_file_roots'] ?: $settings['theme_slug'] ?: __( 'Not configured', 'in-synced' ) ); ?></span></div>
					<div class="in-synced-card"><strong><?php esc_html_e( 'Last run', 'in-synced' ); ?></strong><span>
					<?php
					if ( ! $last_run || empty( $last_run['time'] ) ) {
						esc_html_e( 'No completed run yet', 'in-synced' );
					} else {
						$run_bits   = array(
							( $last_run['status'] ?? 'complete' ) === 'failed' ? __( 'Failed', 'in-synced' ) : __( 'Complete', 'in-synced' ),
							gmdate( 'M j, Y H:i', (int) $last_run['time'] ) . ' UTC',
						);
						$run_counts = (array) ( $last_run['counts'] ?? array() );
						if ( ! empty( $run_counts['database_rows'] ) ) {
							$run_bits[] = number_format_i18n( (int) $run_counts['database_rows'] ) . ' ' . __( 'row(s)', 'in-synced' );
						}
						$run_files = (int) ( $run_counts['upload_files'] ?? 0 ) + (int) ( $run_counts['theme_files'] ?? 0 );
						if ( $run_files > 0 ) {
							$run_bits[] = number_format_i18n( $run_files ) . ' ' . __( 'file(s)', 'in-synced' );
						}
						echo esc_html( implode( ' · ', $run_bits ) );
					}
					?>
					</span></div>
				</div>

				<?php
				if ( $show_setup ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffered markup above escapes every dynamic value at output.
					echo $connection_section;
				}
				?>

				<div class="in-synced-panel">
					<h2><?php esc_html_e( 'Push Console', 'in-synced' ); ?></h2>
					<?php if ( $show_setup ) : ?>
						<p class="in-synced-danger" id="in-synced-console-gate"><?php esc_html_e( 'Complete steps 1–2 of the setup checklist above before running a push.', 'in-synced' ); ?></p>
					<?php endif; ?>
					<div class="in-synced-actions" role="radiogroup" aria-label="<?php echo esc_attr__( 'Quick sync scopes', 'in-synced' ); ?>">
						<button type="button" class="button button-primary in-synced-scope" data-scope="full" role="radio" aria-checked="true" tabindex="0" aria-describedby="in-synced-scope-hint" title="<?php echo esc_attr__( 'Database, uploads, and configured wp-content roots.', 'in-synced' ); ?>"><?php esc_html_e( 'Full', 'in-synced' ); ?></button>
						<button type="button" class="button in-synced-scope" data-scope="content_media" role="radio" aria-checked="false" tabindex="-1" aria-describedby="in-synced-scope-hint" title="<?php echo esc_attr__( 'Database and uploads.', 'in-synced' ); ?>"><?php esc_html_e( 'Content + Media', 'in-synced' ); ?></button>
						<button type="button" class="button in-synced-scope" data-scope="database" role="radio" aria-checked="false" tabindex="-1" aria-describedby="in-synced-scope-hint" title="<?php echo esc_attr__( 'Database only.', 'in-synced' ); ?>"><?php esc_html_e( 'Database', 'in-synced' ); ?></button>
						<button type="button" class="button in-synced-scope" data-scope="media" role="radio" aria-checked="false" tabindex="-1" aria-describedby="in-synced-scope-hint" title="<?php echo esc_attr__( 'Uploads only.', 'in-synced' ); ?>"><?php esc_html_e( 'Media', 'in-synced' ); ?></button>
						<button type="button" class="button in-synced-scope" data-scope="files" role="radio" aria-checked="false" tabindex="-1" aria-describedby="in-synced-scope-hint" title="<?php echo esc_attr__( 'Configured wp-content file roots only.', 'in-synced' ); ?>"><?php esc_html_e( 'Files', 'in-synced' ); ?></button>
						<button type="button" class="button in-synced-scope" data-scope="media_files" role="radio" aria-checked="false" tabindex="-1" aria-describedby="in-synced-scope-hint" title="<?php echo esc_attr__( 'Uploads plus configured wp-content roots.', 'in-synced' ); ?>"><?php esc_html_e( 'Media + Files', 'in-synced' ); ?></button>
					</div>

					<p id="in-synced-scope-hint" class="description"><?php esc_html_e( 'Database, uploads, and configured wp-content roots.', 'in-synced' ); ?></p>
					<form id="in-synced-run-form">
						<input type="hidden" name="profile_key" value="<?php echo esc_attr( $profile_key ); ?>" />
						<input type="hidden" id="in-synced-scope" name="scope" value="full" />
						<div class="in-synced-run-controls">
							<label>
								<?php esc_html_e( 'Database mode', 'in-synced' ); ?><br />
								<select name="mode" id="in-synced-run-mode">
									<option value="upsert" <?php selected( $profile['mode'], 'upsert' ); ?>><?php esc_html_e( 'Upsert', 'in-synced' ); ?></option>
									<option value="replace" <?php selected( $profile['mode'], 'replace' ); ?>><?php esc_html_e( 'Replace', 'in-synced' ); ?></option>
								</select>
							</label>
							<label><input type="checkbox" name="sync_database" value="1" <?php checked( $profile['sync_database'] ); ?> /> <?php esc_html_e( 'Database', 'in-synced' ); ?></label>
							<label><input type="checkbox" name="sync_media" value="1" <?php checked( $profile['sync_media'] ); ?> /> <?php esc_html_e( 'Media', 'in-synced' ); ?></label>
							<label><input type="checkbox" name="sync_theme" value="1" <?php checked( $profile['sync_theme'] ); ?> /> <?php esc_html_e( 'Files', 'in-synced' ); ?></label>
							<label><input type="checkbox" name="incremental_files" value="1" <?php checked( $profile['incremental_files'] ); ?> /> <?php esc_html_e( 'Incremental files', 'in-synced' ); ?></label>
							<p id="in-synced-mode-warning" class="in-synced-danger" role="status" style="grid-column: 1 / -1; margin: 0;"></p>
						</div>
						<p>
							<button type="button" class="button button-secondary" id="in-synced-preview-button"><?php esc_html_e( 'Preview push', 'in-synced' ); ?></button>
							<button type="button" class="button button-secondary" id="in-synced-compare-button"><?php esc_html_e( 'Compare Content', 'in-synced' ); ?></button>
							<button type="button" class="button button-primary" id="in-synced-run-button"><?php esc_html_e( 'Push Now', 'in-synced' ); ?></button>
							<button type="button" class="button" id="in-synced-stop-button" disabled><?php esc_html_e( 'Stop push', 'in-synced' ); ?></button>
							<span id="in-synced-inline-status" class="description" role="status"></span>
							<span id="in-synced-sr-announcer" class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true"></span>
						</p>
					</form>

					<div id="in-synced-preview-warnings" class="in-synced-warnings" role="alert" style="display:none;"></div>
					<div id="in-synced-preview" class="in-synced-preview" aria-live="polite"></div>
					<div id="in-synced-compare" class="in-synced-preview" aria-live="polite"></div>

					<div id="in-synced-progress" style="display:none;">
						<div class="in-synced-progress-bar" id="in-synced-progress-bar" role="progressbar" aria-label="<?php echo esc_attr__( 'Push progress', 'in-synced' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="in-synced-progress-fill"></span></div>
						<div class="in-synced-progress-meta">
							<span id="in-synced-progress-message" role="status"><?php esc_html_e( 'Waiting.', 'in-synced' ); ?></span>
							<strong id="in-synced-progress-percent">0%</strong>
						</div>
						<pre id="in-synced-progress-log" class="in-synced-log" tabindex="0" aria-label="<?php echo esc_attr__( 'Push log', 'in-synced' ); ?>"></pre>
					</div>

					<p class="description"><?php esc_html_e( 'For very large pushes, WP-CLI is still the most durable path:', 'in-synced' ); ?> <code>wp in-synced push --profile=<?php echo esc_html( $profile_key ); ?></code></p>
				</div>

				<?php
				if ( ! $show_setup ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffered markup above escapes every dynamic value at output.
					echo $connection_section;
				}
				?>

				<details class="in-synced-panel in-synced-details">
					<summary><?php esc_html_e( 'Profile Settings', 'in-synced' ); ?></summary>
					<div class="in-synced-profile-bar">
						<label for="in-synced-profile-select"><?php esc_html_e( 'Profile', 'in-synced' ); ?></label>
						<select id="in-synced-profile-select">
							<?php foreach ( $profile_select_keys as $select_key ) : ?>
								<?php
								$select_label = ( isset( $profiles[ $select_key ]['name'] ) && $profiles[ $select_key ]['name'] !== '' )
									? $profiles[ $select_key ]['name']
									: $select_key;
								if ( ! isset( $profiles[ $select_key ] ) ) {
									$select_label .= ' (' . __( 'new', 'in-synced' ) . ')';
								}
								?>
								<option value="<?php echo esc_attr( $select_key ); ?>" <?php selected( $select_key, $profile_key ); ?>><?php echo esc_html( $select_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
							<input type="hidden" name="page" value="in-synced" />
							<label for="in-synced-new-profile" class="screen-reader-text"><?php esc_html_e( 'New profile key', 'in-synced' ); ?></label>
							<input type="text" id="in-synced-new-profile" name="profile" value="" class="regular-text" placeholder="<?php echo esc_attr__( 'new-profile-key', 'in-synced' ); ?>" />
							<button type="submit" class="button"><?php esc_html_e( 'New profile', 'in-synced' ); ?></button>
						</form>
						<?php if ( $profile_key !== 'default' ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="in-synced-delete-profile-form">
								<?php wp_nonce_field( 'in_synced_delete_profile' ); ?>
								<input type="hidden" name="action" value="in_synced_delete_profile" />
								<input type="hidden" name="profile_key" value="<?php echo esc_attr( $profile_key ); ?>" />
								<button type="submit" class="button"><?php esc_html_e( 'Delete profile', 'in-synced' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'in_synced_save_profile' ); ?>
						<input type="hidden" name="action" value="in_synced_save_profile" />
						<input type="hidden" name="profile_key" value="<?php echo esc_attr( $profile_key ); ?>" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="in-synced-profile-name"><?php esc_html_e( 'Name', 'in-synced' ); ?></label></th>
								<td><input class="regular-text" id="in-synced-profile-name" name="profile[name]" type="text" value="<?php echo esc_attr( $profile['name'] ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Sync scope', 'in-synced' ); ?></th>
								<td>
									<fieldset class="in-synced-fieldset">
										<legend class="screen-reader-text"><?php esc_html_e( 'Sync scope', 'in-synced' ); ?></legend>
										<label><input type="checkbox" name="profile[sync_database]" value="1" <?php checked( $profile['sync_database'] ); ?> /> <?php esc_html_e( 'Database', 'in-synced' ); ?></label><br />
										<label><input type="checkbox" name="profile[sync_media]" value="1" <?php checked( $profile['sync_media'] ); ?> /> <?php esc_html_e( 'Uploads/media', 'in-synced' ); ?></label><br />
										<label><input type="checkbox" name="profile[sync_theme]" value="1" <?php checked( $profile['sync_theme'] ); ?> /> <?php esc_html_e( 'wp-content files', 'in-synced' ); ?></label>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="in-synced-mode"><?php esc_html_e( 'Database mode', 'in-synced' ); ?></label></th>
								<td>
									<select id="in-synced-mode" name="profile[mode]">
										<option value="upsert" <?php selected( $profile['mode'], 'upsert' ); ?>><?php esc_html_e( 'Upsert rows without wiping tables', 'in-synced' ); ?></option>
										<option value="replace" <?php selected( $profile['mode'], 'replace' ); ?>><?php esc_html_e( 'Replace selected tables first', 'in-synced' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="in-synced-database-scope"><?php esc_html_e( 'Database scope type', 'in-synced' ); ?></label></th>
								<td>
									<select id="in-synced-database-scope" name="profile[database_scope]">
										<option value="tables" <?php selected( $profile['database_scope'] ?? 'tables', 'tables' ); ?>><?php esc_html_e( 'Selected tables', 'in-synced' ); ?></option>
										<option value="post_types" <?php selected( $profile['database_scope'] ?? 'tables', 'post_types' ); ?>><?php esc_html_e( 'Post type repair', 'in-synced' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Post type repair sends posts, postmeta, comments, and taxonomy relationships for the post types below. In replace mode, matching remote posts are deleted before import.', 'in-synced' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="in-synced-post-types"><?php esc_html_e( 'Post types for compare/repair', 'in-synced' ); ?></label></th>
								<td>
									<textarea id="in-synced-post-types" name="profile[post_type_filter]" rows="4" class="large-text code"><?php echo esc_textarea( $profile['post_type_filter'] ?? '' ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Optional for compare, required for post type repair. One post type per line, such as page, post, or a custom post type.', 'in-synced' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'User tables', 'in-synced' ); ?></th>
								<td><p class="description"><?php esc_html_e( 'Users and usermeta are always excluded from sync.', 'in-synced' ); ?></p></td>
							</tr>
							<tr>
								<th scope="row"><label for="in-synced-include-tables"><?php esc_html_e( 'Included table suffixes', 'in-synced' ); ?></label></th>
								<td>
									<textarea id="in-synced-include-tables" name="profile[include_tables]" rows="4" class="large-text code"><?php echo esc_textarea( $profile['include_tables'] ?? '' ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Optional. When filled, only these table suffixes are synced (for example: posts, postmeta, options).', 'in-synced' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="in-synced-exclude-tables"><?php esc_html_e( 'Excluded table suffixes', 'in-synced' ); ?></label></th>
								<td>
									<textarea id="in-synced-exclude-tables" name="profile[exclude_tables]" rows="5" class="large-text code"><?php echo esc_textarea( $profile['exclude_tables'] ); ?></textarea>
									<p class="description"><?php esc_html_e( 'One suffix per line, without the WordPress table prefix. Example: users', 'in-synced' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="in-synced-batch-size"><?php esc_html_e( 'Database batch size', 'in-synced' ); ?></label></th>
								<td><input id="in-synced-batch-size" name="profile[batch_size]" type="number" min="10" max="1000" value="<?php echo esc_attr( (int) $profile['batch_size'] ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'File incrementals', 'in-synced' ); ?></th>
								<td><label><input type="checkbox" name="profile[incremental_files]" value="1" <?php checked( $profile['incremental_files'] ); ?> /> <?php esc_html_e( 'Only send upload/file-root items modified since the previous run that pushed files', 'in-synced' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><label for="in-synced-upload-includes"><?php esc_html_e( 'Upload include patterns', 'in-synced' ); ?></label></th>
								<td>
									<textarea id="in-synced-upload-includes" name="profile[upload_includes]" rows="4" class="large-text code"><?php echo esc_textarea( $profile['upload_includes'] ?? '' ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Optional. One glob or folder per line. Leave empty to include all uploads.', 'in-synced' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="in-synced-upload-excludes"><?php esc_html_e( 'Upload excludes', 'in-synced' ); ?></label></th>
								<td><textarea id="in-synced-upload-excludes" name="profile[upload_excludes]" rows="4" class="large-text code"><?php echo esc_textarea( $profile['upload_excludes'] ?? '' ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><label for="in-synced-file-roots-profile"><?php esc_html_e( 'wp-content file roots', 'in-synced' ); ?></label></th>
								<td>
									<textarea id="in-synced-file-roots-profile" name="profile[file_roots]" rows="5" class="large-text code"><?php echo esc_textarea( $profile['file_roots'] ?? ( $profile['theme_slug'] ? 'themes/' . $profile['theme_slug'] : '' ) ); ?></textarea>
									<p class="description"><?php esc_html_e( 'One relative wp-content path per line. Examples:', 'in-synced' ); ?> <code>themes/<?php echo esc_html( get_stylesheet() ); ?></code>, <code>plugins/my-custom-plugin</code>.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="in-synced-file-excludes"><?php esc_html_e( 'File excludes', 'in-synced' ); ?></label></th>
								<td><textarea id="in-synced-file-excludes" name="profile[file_excludes]" rows="5" class="large-text code"><?php echo esc_textarea( $profile['file_excludes'] ?? $profile['theme_excludes'] ); ?></textarea></td>
							</tr>
						</table>
						<?php submit_button( __( 'Save profile', 'in-synced' ) ); ?>
					</form>
				</details>

				<details class="in-synced-panel in-synced-details" id="in-synced-help">
					<summary><?php esc_html_e( 'Help & Notes', 'in-synced' ); ?></summary>
					<h3><?php esc_html_e( 'Pairing', 'in-synced' ); ?></h3>
					<p><?php esc_html_e( 'Install and activate this plugin on both sites, then use the same long shared secret (32+ characters) on both. Set the remote admin/API URL on the sending site only. Requests are signed and must arrive within five minutes of the receiver clock.', 'in-synced' ); ?></p>
					<h3><?php esc_html_e( 'Scopes', 'in-synced' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'Full: database, uploads, and configured wp-content roots.', 'in-synced' ); ?></li>
						<li><?php esc_html_e( 'Content + Media: database and uploads.', 'in-synced' ); ?></li>
						<li><?php esc_html_e( 'Database: database only.', 'in-synced' ); ?></li>
						<li><?php esc_html_e( 'Media: uploads only.', 'in-synced' ); ?></li>
						<li><?php esc_html_e( 'Files: configured wp-content file roots only. The CLI scope key is "files" (legacy alias: "theme").', 'in-synced' ); ?></li>
						<li><?php esc_html_e( 'Media + Files: uploads plus configured wp-content roots. The CLI scope key is "media_files".', 'in-synced' ); ?></li>
					</ul>
					<h3><?php esc_html_e( 'Replace mode and backups', 'in-synced' ); ?></h3>
					<p><?php esc_html_e( 'Replace mode clears selected receiver tables only after a complete verified SQL backup is written outside the public web root. Backups default to in-synced-private-backups beside WordPress; configure IN_SYNCED_BACKUP_DIR if needed. The newest five completed backups are retained. Pause receiver edits/background jobs during replacement. Stop push keeps completed changes; restore backups manually when needed.', 'in-synced' ); ?></p>
					<p><?php esc_html_e( 'Every media/file push also needs writable private staging, even with database replace disabled. Private storage must share the target filesystem so verified files can be replaced atomically. Configure a private IN_SYNCED_BACKUP_DIR on that filesystem if the default path is unavailable.', 'in-synced' ); ?></p>
					<h3><?php esc_html_e( 'Repairing interrupted content pushes', 'in-synced' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Set "Database scope type" to "Post type repair".', 'in-synced' ); ?></li>
						<li><?php esc_html_e( 'List the affected post types under "Post types for compare/repair".', 'in-synced' ); ?></li>
						<li><?php esc_html_e( 'Click "Compare Content" to inspect local vs remote counts.', 'in-synced' ); ?></li>
						<li><?php esc_html_e( 'Push with only Database selected and replace mode to overwrite just those post types.', 'in-synced' ); ?></li>
					</ol>
					<h3><?php esc_html_e( 'Troubleshooting', 'in-synced' ); ?></h3>
					<ul>
						<li><?php esc_html_e( '"Invalid signature" (401): the shared secrets differ between the two sites.', 'in-synced' ); ?></li>
						<li><?php esc_html_e( '"Timestamp outside the allowed window" (401): the server clocks differ by more than five minutes.', 'in-synced' ); ?></li>
						<li><?php esc_html_e( '"Database/media/file receiving is disabled" (403): enable the matching receiver permission on the other site.', 'in-synced' ); ?></li>
						<li><?php esc_html_e( 'User tables (users, usermeta) are never imported from a paired site, even when they are listed on the sender.', 'in-synced' ); ?></li>
					</ul>
				</details>
			</div>

			<script>
				(function () {
					var config =
					<?php
					echo wp_json_encode(
						array(
							'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
							'pageUrl'    => admin_url( 'tools.php?page=in-synced' ),
							'nonce'      => $ajax_nonce,
							'profileKey' => $profile_key,
							'remoteUrl'  => ( $settings['remote_public_url'] ?: $settings['remote_url'] ) ?: '',
							'strings'    => array(
								'buildingPreview'      => __( 'Building preview...', 'in-synced' ),
								'previewReady'         => __( 'Preview ready.', 'in-synced' ),
								'previewFailed'        => __( 'Preview failed.', 'in-synced' ),
								'comparing'            => __( 'Comparing local and remote content...', 'in-synced' ),
								'compareReady'         => __( 'Compare ready.', 'in-synced' ),
								'compareFailed'        => __( 'Compare failed.', 'in-synced' ),
								'stopRequested'        => __( 'Stop requested. Completed changes remain on the receiver.', 'in-synced' ),
								'stopFailed'           => __( 'The stop request failed. Retry while the run is active.', 'in-synced' ),
								'secureRandomRequired' => __( 'Your browser cannot generate a secure secret. Use a password manager to generate at least 32 random characters.', 'in-synced' ),
								'pushRunning'          => __( 'Push running...', 'in-synced' ),
								'pushComplete'         => __( 'Push complete.', 'in-synced' ),
								'pushFailed'           => __( 'Push failed.', 'in-synced' ),
								'pushRequestFailed'    => __( 'Push request failed. Check the latest progress log.', 'in-synced' ),
								'startingSync'         => __( 'Starting sync.', 'in-synced' ),
								'syncComplete'         => __( 'Sync complete.', 'in-synced' ),
								'syncFailed'           => __( 'Sync failed.', 'in-synced' ),
								'pushCancelled'        => __( 'Push cancelled.', 'in-synced' ),
								'remoteSite'           => __( 'the remote site', 'in-synced' ),
								/* translators: %s: remote site URL. */
								'confirmPush'          => __( 'Push the selected scope to %s now?', 'in-synced' ),
								/* translators: %s: remote site URL. */
								'confirmReplace'       => __( 'This will DROP/TRUNCATE data on %s. Type REPLACE to confirm.', 'in-synced' ),
								'confirmDeleteProfile' => __( 'Delete this profile? This cannot be undone.', 'in-synced' ),
								'copied'               => __( 'Copied', 'in-synced' ),
								'copyFailed'           => __( 'Copy failed. The secret is selected — press Ctrl+C or Cmd+C to copy it.', 'in-synced' ),
								'copySecret'           => __( 'Copy secret', 'in-synced' ),
								'modeWarning'          => __( 'Replace mode TRUNCATEs the selected remote tables before import. The push aborts if the receiver cannot write a backup first.', 'in-synced' ),
								'cardDatabase'         => __( 'Database', 'in-synced' ),
								'cardMedia'            => __( 'Media', 'in-synced' ),
								'cardFiles'            => __( 'Files', 'in-synced' ),
								'cardPostTypes'        => __( 'Post Types', 'in-synced' ),
								'cardTables'           => __( 'Tables', 'in-synced' ),
								'cardRemote'           => __( 'Remote', 'in-synced' ),
								'connected'            => __( 'Connected', 'in-synced' ),
								'compareComplete'      => __( 'Compare complete.', 'in-synced' ),
								/* translators: %s: row count. */
								'rows'                 => __( '%s rows', 'in-synced' ),
								/* translators: %1$s: row count, %2$s: database mode. */
								'rowsMode'             => __( '%1$s rows in %2$s mode', 'in-synced' ),
								/* translators: %1$s: file size, %2$s: UTC date. */
								'sinceSize'            => __( '%1$s since %2$s', 'in-synced' ),
								/* translators: %1$s: local count, %2$s: remote count, %3$s: difference. */
								'compareCounts'        => __( 'Local %1$s, remote %2$s, difference %3$s', 'in-synced' ),
								/* translators: %1$s: local count, %2$s: remote count. */
								'tableCounts'          => __( 'Local %1$s, remote %2$s', 'in-synced' ),
							),
						),
						JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
					);
					?>
									;
					var form = document.getElementById('in-synced-run-form');
					var scopeInput = document.getElementById('in-synced-scope');
					var status = document.getElementById('in-synced-inline-status');
					var preview = document.getElementById('in-synced-preview');
					var previewWarnings = document.getElementById('in-synced-preview-warnings');
					var compare = document.getElementById('in-synced-compare');
					var progress = document.getElementById('in-synced-progress');
					var progressBar = document.getElementById('in-synced-progress-bar');
					var progressFill = document.getElementById('in-synced-progress-fill');
					var progressPercent = document.getElementById('in-synced-progress-percent');
					var progressMessage = document.getElementById('in-synced-progress-message');
					var progressLog = document.getElementById('in-synced-progress-log');
					var modeSelect = document.getElementById('in-synced-run-mode');
					var modeWarning = document.getElementById('in-synced-mode-warning');
					var srAnnouncer = document.getElementById('in-synced-sr-announcer');
					var pollTimer = null;
					var activeRunId = '';
					var stopButton = document.getElementById('in-synced-stop-button');
					var runButton = document.getElementById('in-synced-run-button');
					var lastProgressWrite = {phase: null, percent: -1};
					var strings = config.strings || {};

					function formPayload(action, runId) {
						var data = new FormData(form);
						data.append('action', action);
						data.append('nonce', config.nonce);
						data.append('profile_key', config.profileKey);
						['sync_database', 'sync_media', 'sync_theme', 'incremental_files'].forEach(function (name) {
							var field = form.querySelector('[name="' + name + '"]');
							data.set(name, field && field.checked ? '1' : '0');
						});
						if (runId) {
							data.append('run_id', runId);
						}
						return data;
					}

					function post(action, runId) {
						return fetch(config.ajaxUrl, {
							method: 'POST',
							credentials: 'same-origin',
							body: formPayload(action, runId)
						}).then(function (response) {
							return response.json();
						});
					}

					function escapeHtml(value) {
						return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
							return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character];
						});
					}

					function announce(message) {
						if (!message) {
							return;
						}
						if (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function') {
							window.wp.a11y.speak(message);
							return;
						}
						if (srAnnouncer) {
							srAnnouncer.textContent = '';
							window.setTimeout(function () {
								srAnnouncer.textContent = message;
							}, 50);
						}
					}

					function formatText(template, values) {
						return String(template || '').replace(/%(?:(\d+)\$)?s/g, function (match, position) {
							return String(values[position ? Number(position) - 1 : 0]);
						});
					}

					function renderPreview(data) {
						var warnings = (data.warnings || []).map(function (warning) {
							return '<p>' + escapeHtml(warning) + '</p>';
						}).join('');
						if (previewWarnings) {
							previewWarnings.innerHTML = warnings;
							previewWarnings.style.display = warnings ? 'block' : 'none';
						}
						var tables = (data.database.tables || []).slice(0, 12).map(function (table) {
							return '<li>' + escapeHtml(table.label) + ' - ' + escapeHtml(formatText(strings.rows, [table.rows])) + '</li>';
						}).join('');
						var uploads = (data.uploads.sample || []).slice(0, 10).map(function (file) {
							return '<li>' + escapeHtml(file.path) + ' - ' + escapeHtml(file.size_label) + '</li>';
						}).join('');
						var files = (data.theme.sample || []).slice(0, 10).map(function (file) {
							return '<li>' + escapeHtml(file.path) + ' - ' + escapeHtml(file.size_label) + '</li>';
						}).join('');

						preview.innerHTML =
							'<div class="in-synced-preview-card"><h3>' + escapeHtml(strings.cardDatabase || 'Database') + '</h3><div class="count">' + escapeHtml(data.database.table_count) + '</div><p>' + escapeHtml(formatText(strings.rowsMode, [data.database.row_count, data.mode])) + '</p><ul tabindex="0">' + tables + '</ul></div>' +
							'<div class="in-synced-preview-card"><h3>' + escapeHtml(strings.cardMedia || 'Media') + '</h3><div class="count">' + escapeHtml(data.uploads.file_count) + '</div><p>' + escapeHtml(data.uploads.since ? formatText(strings.sinceSize, [data.uploads.bytes_label || '0 B', data.uploads.since]) : (data.uploads.bytes_label || '0 B')) + '</p><ul tabindex="0">' + uploads + '</ul></div>' +
							'<div class="in-synced-preview-card"><h3>' + escapeHtml(strings.cardFiles || 'Files') + '</h3><div class="count">' + escapeHtml(data.theme.file_count) + '</div><p>' + escapeHtml(data.theme.theme_slug) + ' - ' + escapeHtml(data.theme.bytes_label || '0 B') + '</p><ul tabindex="0">' + files + '</ul></div>';
					}

					function renderCompare(data) {
						var rows = (data.post_types || []).map(function (item) {
							var delta = Number(item.local || 0) - Number(item.remote || 0);
							return '<li><strong>' + escapeHtml(item.post_type) + '</strong>: ' + escapeHtml(formatText(strings.compareCounts, [item.local, item.remote, delta])) + '</li>';
						}).join('');
						var tableRows = (data.tables || []).slice(0, 10).map(function (item) {
							return '<li><strong>' + escapeHtml(item.label) + '</strong>: ' + escapeHtml(formatText(strings.tableCounts, [item.local, item.remote])) + '</li>';
						}).join('');

						compare.innerHTML =
							'<div class="in-synced-preview-card"><h3>' + escapeHtml(strings.cardPostTypes || 'Post Types') + '</h3><div class="count">' + escapeHtml(data.post_types.length) + '</div><ul tabindex="0">' + rows + '</ul></div>' +
							'<div class="in-synced-preview-card"><h3>' + escapeHtml(strings.cardTables || 'Tables') + '</h3><div class="count">' + escapeHtml(data.tables.length) + '</div><ul tabindex="0">' + tableRows + '</ul></div>' +
							'<div class="in-synced-preview-card"><h3>' + escapeHtml(strings.cardRemote || 'Remote') + '</h3><p>' + escapeHtml(data.remote_site || strings.connected || 'Connected') + '</p><p>' + escapeHtml(strings.compareComplete || 'Compare complete.') + '</p></div>';
					}

					function setProgress(data) {
						progress.style.display = 'block';
						var percent = Math.max(0, Math.min(100, parseInt(data.percent || 0, 10)));
						var phase = data.phase || '';
						progressFill.style.width = percent + '%';
						if (progressBar) {
							progressBar.setAttribute('aria-valuenow', String(percent));
						}
						progressPercent.textContent = percent + '%';

						// Throttle live-region updates: write on phase changes and ~10% steps,
						// so screen readers and the visible message are not flooded by polls.
						var message = (phase ? '[' + phase + '] ' : '') + (data.message || '');
						if (phase !== lastProgressWrite.phase || percent >= lastProgressWrite.percent + 10 || percent >= 100 || data.status === 'failed') {
							lastProgressWrite.phase = phase;
							lastProgressWrite.percent = percent;
							progressMessage.textContent = message + ' (' + percent + '%)';
						}

						progressLog.textContent = (data.log || []).join("\n");
					}

					function poll(runId) {
						clearInterval(pollTimer);
						pollTimer = setInterval(function () {
							post('in_synced_progress', runId).then(function (response) {
								if (response && response.success) {
									setProgress(response.data);
									if (response.data.status === 'complete' || response.data.status === 'failed') {
										clearInterval(pollTimer);
									}
								}
							});
						}, 1200);
					}

					function previewRun() {
						status.textContent = strings.buildingPreview || 'Building preview...';
						post('in_synced_preview').then(function (response) {
							if (!response.success) {
								status.textContent = response.data && response.data.message ? response.data.message : (strings.previewFailed || 'Preview failed.');
								return;
							}
							renderPreview(response.data);
							status.textContent = strings.previewReady || 'Preview ready.';
						}).catch(function () {
							status.textContent = strings.previewFailed || 'Preview failed.';
						});
					}

					function compareRun() {
						status.textContent = strings.comparing || 'Comparing local and remote content...';
						post('in_synced_compare').then(function (response) {
							if (!response.success) {
								status.textContent = response.data && response.data.message ? response.data.message : (strings.compareFailed || 'Compare failed.');
								return;
							}
							renderCompare(response.data);
							status.textContent = strings.compareReady || 'Compare ready.';
						}).catch(function () {
							status.textContent = strings.compareFailed || 'Compare failed.';
						});
					}

					function confirmRun() {
						var mode = modeSelect ? modeSelect.value : 'upsert';
						var remote = config.remoteUrl || strings.remoteSite || 'the remote site';
						if (mode === 'replace') {
							var template = (strings.confirmReplace || 'This will replace the selected remote data on %s. Type REPLACE to confirm.');
							var answer = window.prompt(template.split('%s').join(remote));
							if (answer !== 'REPLACE') {
								status.textContent = strings.pushCancelled || 'Push cancelled.';
								return false;
							}
							return true;
						}
						var confirmTemplate = (strings.confirmPush || 'Push the selected scope to %s now?');
						return window.confirm(confirmTemplate.split('%s').join(remote));
					}

					function runSync() {
						if (!confirmRun()) {
							return;
						}
						var runId = 'run-' + Date.now() + '-' + Math.random().toString(16).slice(2);
						activeRunId = runId;
						runButton.disabled = true;
						stopButton.disabled = false;
						status.textContent = strings.pushRunning || 'Push running...';
						setProgress({phase: 'starting', message: strings.startingSync || '', percent: 1, log: []});
						poll(runId);
						post('in_synced_run', runId).then(function (response) {
							if (response && response.success) {
								setProgress(response.data.progress || {phase: 'complete', message: strings.syncComplete || '', percent: 100, log: []});
								status.textContent = strings.pushComplete || 'Push complete.';
							} else {
								setProgress(response.data && response.data.progress ? response.data.progress : {phase: 'failed', message: strings.syncFailed || '', percent: 100, log: []});
								status.textContent = response.data && response.data.message ? response.data.message : (strings.pushFailed || 'Push failed.');
							}
							clearInterval(pollTimer);
							activeRunId = '';
							stopButton.disabled = true;
							runButton.disabled = false;
						}).catch(function () {
							status.textContent = strings.pushRequestFailed || 'Push request failed. Check the latest progress log.';
						});
					}

					document.querySelectorAll('.in-synced-scope').forEach(function (button) {
						button.addEventListener('click', function () {
							scopeInput.value = button.getAttribute('data-scope');
							document.querySelectorAll('.in-synced-scope').forEach(function (item) {
								var pressed = item === button;
								item.classList.toggle('button-primary', pressed);
								item.setAttribute('aria-checked', pressed ? 'true' : 'false');
								item.setAttribute('tabindex', pressed ? '0' : '-1');
							});
							document.getElementById('in-synced-scope-hint').textContent = button.getAttribute('title') || '';
							var scope = scopeInput.value;
							form.querySelector('[name="sync_database"]').checked = ['full', 'database', 'content', 'content_media'].indexOf(scope) !== -1;
							form.querySelector('[name="sync_media"]').checked = ['full', 'media', 'media_files', 'content_media'].indexOf(scope) !== -1;
							form.querySelector('[name="sync_theme"]').checked = ['full', 'files', 'media_files'].indexOf(scope) !== -1;
							previewRun();
						});
					});

					document.querySelectorAll('.in-synced-scope').forEach(function (button) {
						button.addEventListener('keydown', function (event) {
							var items = Array.prototype.slice.call(document.querySelectorAll('.in-synced-scope'));
							var index = items.indexOf(button);
							if (['ArrowLeft', 'ArrowUp', 'ArrowRight', 'ArrowDown', 'Home', 'End'].indexOf(event.key) === -1) { return; }
							event.preventDefault();
							if (event.key === 'Home') { index = 0; }
							else if (event.key === 'End') { index = items.length - 1; }
							else { index = (index + (event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 1) + items.length) % items.length; }
							items[index].focus();
							items[index].click();
						});
					});
					stopButton.addEventListener('click', function () {
						if (!activeRunId) { return; }
						post('in_synced_cancel', activeRunId).then(function (response) {
							status.textContent = response.success ? strings.stopRequested : (response.data && response.data.message ? response.data.message : strings.stopFailed);
							announce(status.textContent);
							if (response.success) { stopButton.disabled = true; }
						}).catch(function () { status.textContent = strings.stopFailed; });
					});

					function syncModeWarning() {
						if (modeWarning && modeSelect) {
							// Set/clear the text instead of toggling display so the role="status"
							// region stays in the accessibility tree and reliably announces changes.
							modeWarning.textContent = modeSelect.value === 'replace' ? (strings.modeWarning || '') : '';
						}
					}

					if (modeSelect) {
						modeSelect.addEventListener('change', syncModeWarning);
					}
					syncModeWarning();

					document.getElementById('in-synced-preview-button').addEventListener('click', previewRun);
					document.getElementById('in-synced-compare-button').addEventListener('click', compareRun);
					document.getElementById('in-synced-run-button').addEventListener('click', runSync);
					var generateSecret = document.getElementById('in-synced-generate-secret');
					if (generateSecret) {
						generateSecret.addEventListener('click', function () {
							var field = document.getElementById('in-synced-secret');
							var bytes = new Uint8Array(48);
							if (window.crypto && window.crypto.getRandomValues) {
								window.crypto.getRandomValues(bytes);
							} else {
								announce(strings.secureRandomRequired);
								field.focus();
								return;
							}
							field.type = 'text';
							field.value = Array.prototype.map.call(bytes, function (byte) {
								return ('0' + byte.toString(16)).slice(-2);
							}).join('');
							field.focus();
							field.select();
						});
					}
					var copySecret = document.getElementById('in-synced-copy-secret');
					if (copySecret) {
						copySecret.addEventListener('click', function () {
							var field = document.getElementById('in-synced-secret');
							if (!field || !field.value) {
								if (field) { field.focus(); }
								return;
							}
							var done = function () {
								copySecret.textContent = strings.copied || 'Copied';
								announce(strings.copied || 'Copied');
								window.setTimeout(function () {
									copySecret.textContent = strings.copySecret || 'Copy secret';
								}, 1500);
							};
							if (navigator.clipboard && navigator.clipboard.writeText) {
								navigator.clipboard.writeText(field.value).then(done, function () {
									field.focus();
									field.select();
									announce(strings.copyFailed || 'Copy failed.');
								});
							} else {
								field.type = 'text';
								field.select();
								document.execCommand('copy');
								done();
							}
						});
					}
					var profileSelect = document.getElementById('in-synced-profile-select');
					if (profileSelect) {
						profileSelect.addEventListener('change', function () {
							window.location = config.pageUrl + '&profile=' + encodeURIComponent(profileSelect.value);
						});
					}
					var deleteProfileForm = document.getElementById('in-synced-delete-profile-form');
					if (deleteProfileForm) {
						deleteProfileForm.addEventListener('submit', function (event) {
							if (!window.confirm(strings.confirmDeleteProfile || 'Delete this profile? This cannot be undone.')) {
								event.preventDefault();
							}
						});
					}
					previewRun();
				})();
			</script>
		</div>
		<?php
	}

	private function admin_notices_map(): array {
		return array(
			'settings_saved'         => array(
				'type'    => 'success',
				'message' => __( 'Settings saved.', 'in-synced' ),
			),
			'profile_saved'          => array(
				'type'    => 'success',
				'message' => __( 'Profile saved.', 'in-synced' ),
			),
			'profile_deleted'        => array(
				'type'    => 'success',
				'message' => __( 'Profile deleted.', 'in-synced' ),
			),
			'profile_delete_blocked' => array(
				'type'    => 'warning',
				'message' => __( 'The default profile cannot be deleted.', 'in-synced' ),
			),
			'connection_ok'          => array(
				'type'    => 'success',
				'message' => __( 'Connection OK.', 'in-synced' ),
			),
			'connection_failed'      => array(
				'type'    => 'error',
				'message' => __( 'Connection failed. Details are in the log below.', 'in-synced' ),
			),
		);
	}

	public function handle_save_settings(): void {
		$this->require_admin_action( 'in_synced_save_settings' );

		$settings = $this->get_settings();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
		$secret = isset( $_POST['shared_secret'] ) ? $this->sanitize_shared_secret( wp_unslash( $_POST['shared_secret'] ) ) : '';
		if ( $secret === '' ) {
			$secret = (string) ( $settings['shared_secret'] ?? '' );
		}

		$settings = array(
			'remote_url'          => $this->sanitize_url_field( 'remote_url' ),
			'local_url'           => $this->sanitize_url_field( 'local_url' ) ?: home_url(),
			'remote_public_url'   => $this->sanitize_url_field( 'remote_public_url' ),
			'shared_secret'       => $secret,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
			'allow_db_receive'    => ! empty( $_POST['allow_db_receive'] ),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
			'allow_media_receive' => ! empty( $_POST['allow_media_receive'] ),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
			'allow_theme_receive' => ! empty( $_POST['allow_theme_receive'] ),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
			'allow_file_receive'  => ! empty( $_POST['allow_theme_receive'] ),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Custom validators reject invalid secret/root shapes; profile fields are sanitized individually and URL uses esc_url_raw.
			'theme_slug'          => $this->sanitize_theme_slug( $_POST['theme_slug'] ?? $settings['theme_slug'] ),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Custom validators reject invalid secret/root shapes; profile fields are sanitized individually and URL uses esc_url_raw.
			'allowed_file_roots'  => $this->sanitize_file_roots_text( $_POST['allowed_file_roots'] ?? $settings['allowed_file_roots'] ?? '' ),
		);

		update_option( self::SETTINGS_OPTION, $settings, false );
		$this->redirect_admin( array( 'in_synced_notice' => 'settings_saved' ) );
	}

	public function handle_save_profile(): void {
		$this->require_admin_action( 'in_synced_save_profile' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
		$profile_key = isset( $_POST['profile_key'] ) ? sanitize_key( wp_unslash( $_POST['profile_key'] ) ) : 'default';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
		$raw      = isset( $_POST['profile'] ) && is_array( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : array();
		$profiles = $this->get_profiles();

		$existing = $profiles[ $profile_key ] ?? array();

		$profiles[ $profile_key ] = array(
			'name'              => sanitize_text_field( $raw['name'] ?? __( 'Default staging push', 'in-synced' ) ),
			'mode'              => ( ( $raw['mode'] ?? 'upsert' ) === 'replace' ) ? 'replace' : 'upsert',
			'sync_database'     => ! empty( $raw['sync_database'] ),
			'sync_media'        => ! empty( $raw['sync_media'] ),
			'sync_theme'        => ! empty( $raw['sync_theme'] ),
			'incremental_files' => ! empty( $raw['incremental_files'] ),
			'include_users'     => false,
			'include_tables'    => sanitize_textarea_field( $raw['include_tables'] ?? '' ),
			'exclude_tables'    => sanitize_textarea_field( $raw['exclude_tables'] ?? '' ),
			'database_scope'    => ( ( $raw['database_scope'] ?? 'tables' ) === 'post_types' ) ? 'post_types' : 'tables',
			'post_type_filter'  => sanitize_textarea_field( $raw['post_type_filter'] ?? '' ),
			'upload_includes'   => sanitize_textarea_field( $raw['upload_includes'] ?? '' ),
			'upload_excludes'   => sanitize_textarea_field( $raw['upload_excludes'] ?? '' ),
			'batch_size'        => max( 10, min( 1000, (int) ( $raw['batch_size'] ?? 100 ) ) ),
			// Legacy fields without form controls: keep the stored values unless posted.
			'theme_slug'        => array_key_exists( 'theme_slug', $raw ) ? $this->sanitize_theme_slug( $raw['theme_slug'] ) : (string) ( $existing['theme_slug'] ?? get_stylesheet() ),
			'theme_excludes'    => array_key_exists( 'theme_excludes', $raw ) ? sanitize_textarea_field( $raw['theme_excludes'] ) : (string) ( $existing['theme_excludes'] ?? '' ),
			'file_roots'        => $this->sanitize_file_roots_text( $raw['file_roots'] ?? '' ),
			'file_excludes'     => sanitize_textarea_field( $raw['file_excludes'] ?? '' ),
		);

		update_option( self::PROFILES_OPTION, $profiles, false );
		$this->redirect_admin(
			array(
				'profile'          => $profile_key,
				'in_synced_notice' => 'profile_saved',
			)
		);
	}

	public function handle_delete_profile(): void {
		$this->require_admin_action( 'in_synced_delete_profile' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
		$profile_key = isset( $_POST['profile_key'] ) ? sanitize_key( wp_unslash( $_POST['profile_key'] ) ) : '';
		if ( $profile_key === '' || $profile_key === 'default' ) {
			$this->redirect_admin( array( 'in_synced_notice' => 'profile_delete_blocked' ) );
		}

		$profiles = $this->get_profiles();
		if ( isset( $profiles[ $profile_key ] ) ) {
			unset( $profiles[ $profile_key ] );
			update_option( self::PROFILES_OPTION, $profiles, false );
		}

		$this->redirect_admin( array( 'in_synced_notice' => 'profile_deleted' ) );
	}

	public function handle_test_connection(): void {
		$this->require_admin_action( 'in_synced_test_connection' );

		$log       = array();
		$response  = $this->remote_request( 'ping', array( 'site' => home_url() ), $log );
		$connected = ! is_wp_error( $response );
		if ( ! $connected ) {
			/* translators: %s: error message returned by the remote site. */
			$log[] = sprintf( __( 'Connection failed: %s', 'in-synced' ), $response->get_error_message() );
		} else {
			/* translators: %s: remote site URL. */
			$log[] = sprintf( __( 'Connected to remote site: %s', 'in-synced' ), $response['site_url'] ?? 'unknown' );
			$log[] = sprintf(
				/* translators: %1$s: database permission, %2$s: media permission, %3$s: files permission. */
				__( 'Receiver permissions: database=%1$s, media=%2$s, files=%3$s', 'in-synced' ),
				$this->bool_label( $response['allow_db_receive'] ?? false ),
				$this->bool_label( $response['allow_media_receive'] ?? false ),
				$this->bool_label( ( $response['allow_file_receive'] ?? false ) || ( $response['allow_theme_receive'] ?? false ) )
			);
		}

		set_transient( 'in_synced_last_log_' . get_current_user_id(), implode( "\n", $log ), HOUR_IN_SECONDS );
		$this->redirect_admin( array( 'in_synced_notice' => $connected ? 'connection_ok' : 'connection_failed' ) );
	}

	public function ajax_preview(): void {
		$this->require_ajax_action();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
		$profile_key = isset( $_POST['profile_key'] ) ? sanitize_key( wp_unslash( $_POST['profile_key'] ) ) : 'default';
		$preview     = $this->build_sync_preview( $profile_key, $this->runtime_overrides_from_request() );

		wp_send_json_success( $preview );
	}

	public function ajax_compare(): void {
		$this->require_ajax_action();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
		$profile_key = isset( $_POST['profile_key'] ) ? sanitize_key( wp_unslash( $_POST['profile_key'] ) ) : 'default';
		$compare     = $this->compare_content( $profile_key, $this->runtime_overrides_from_request() );

		if ( is_wp_error( $compare ) ) {
			wp_send_json_error( array( 'message' => $compare->get_error_message() ) );
		}

		wp_send_json_success( $compare );
	}

	public function ajax_run(): void {
		$this->require_ajax_action();
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); }

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
		$profile_key = isset( $_POST['profile_key'] ) ? sanitize_key( wp_unslash( $_POST['profile_key'] ) ) : 'default';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
		$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : wp_generate_uuid4();
		$log    = array();

		$this->update_progress(
			$run_id,
			array(
				'status'  => 'running',
				'user_id' => get_current_user_id(),
				'phase'   => 'starting',
				'message' => __( 'Starting sync.', 'in-synced' ),
				'percent' => 1,
				'log'     => array(),
			)
		);

		$result = $this->run_sync( $profile_key, $log, $this->runtime_overrides_from_request(), $run_id );

		delete_option( 'in_synced_cancel_' . $run_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message'  => $result->get_error_message(),
					'progress' => $this->get_progress( $run_id ),
				)
			);
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Sync completed.', 'in-synced' ),
				'progress' => $this->get_progress( $run_id ),
			)
		);
	}

	public function ajax_cancel(): void {
		$this->require_ajax_action();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
		$run_id   = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
		$progress = $this->get_progress( $run_id );
		if ( ! $run_id || (int) ( $progress['user_id'] ?? 0 ) !== get_current_user_id() || ( $progress['status'] ?? '' ) !== 'running' ) {
			wp_send_json_error( array( 'message' => __( 'This run is not active or does not belong to you.', 'in-synced' ) ), 409 );
		}
		add_option( 'in_synced_cancel_' . $run_id, time(), '', false );
		wp_send_json_success( array( 'message' => __( 'Stop requested. The current request will finish first; completed changes remain on the receiver.', 'in-synced' ) ) );
	}

	private function claim_lease( string $key, string $session, array $context = array() ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $session ) ) {
			return new WP_Error( 'in_synced_bad_session', __( 'A valid database session ID is required.', 'in-synced' ), array( 'status' => 400 ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
		$raw     = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $this->escape_identifier( $wpdb->options ) . '` WHERE option_name = %s', $key ) );
		$current = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( is_array( $current ) && ( $current['expires'] ?? 0 ) < time() ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $this->escape_identifier( $wpdb->options ) . '` WHERE option_name = %s AND option_value = %s', $key, $raw ) );
			$current = null;
			$raw     = null;
		}
		if ( $current && ( $current['session'] ?? '' ) !== $session ) {
			return new WP_Error( 'in_synced_busy', __( 'Another sync owns this site. Wait for it to finish or for its 15-minute lease to expire.', 'in-synced' ), array( 'status' => 409 ) );
		}
		$lease = array_merge(
			is_array( $current ) ? $current : array(),
			$context,
			array(
				'session' => $session,
				'expires' => time() + self::LEASE_TTL,
			)
		);
		$next  = wp_json_encode( $lease );
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( $raw === null ) {
			if ( ! add_option( $key, $next, '', false ) ) {
				return new WP_Error( 'in_synced_busy', __( 'Another sync claimed this site first. Retry after it finishes.', 'in-synced' ), array( 'status' => 409 ) );
			}
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE `' . $this->escape_identifier( $wpdb->options ) . '` SET option_value = %s WHERE option_name = %s AND option_value = %s', $next, $key, $raw ) );
			if ( $updated === false || ( $updated === 0 && $next !== $raw ) ) {
				return new WP_Error( 'in_synced_lease_lost', __( 'The sync lease changed. The push stopped.', 'in-synced' ), array( 'status' => 409 ) );
			}
		}
		wp_cache_delete( $key, 'options' );
		return $lease;
	}

	private function release_lease( string $key, string $session ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
		$raw   = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $this->escape_identifier( $wpdb->options ) . '` WHERE option_name = %s', $key ) );
		$lease = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! $lease || ( $lease['session'] ?? '' ) !== $session ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $this->escape_identifier( $wpdb->options ) . '` WHERE option_name = %s AND option_value = %s', $key, $raw ) );
		wp_cache_delete( $key, 'options' );
		return (bool) $deleted;
	}

	private function receive_lease( array $payload, $tables = null, $post_types = null, bool $preflight = false ) {
		global $wpdb;
		$session = is_string( $payload['session_id'] ?? null ) ? $payload['session_id'] : '';
		if ( $tables === null && ! $preflight ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$raw   = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $this->escape_identifier( $wpdb->options ) . '` WHERE option_name = %s', 'in_synced_site_lease' ) );
			$lease = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( ! $lease || ( $lease['session'] ?? '' ) !== $session || ( $lease['expires'] ?? 0 ) < time() ) {
				return new WP_Error( 'in_synced_wrong_session', __( 'The receive session is missing or expired. Start a new push.', 'in-synced' ), array( 'status' => 409 ) );
			}
		}
		$context = $tables === null ? array() : array(
			'tables'     => $tables,
			'post_types' => $post_types,
		);
		$lease   = $this->claim_lease( 'in_synced_site_lease', $session, $context );
		if ( ! is_wp_error( $lease ) ) {
			$this->receiver_session_id = $session; }
		return $lease;
	}

	private function cancellation_requested(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
		return $this->active_run_id !== '' && (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $this->escape_identifier( $wpdb->options ) . '` WHERE option_name = %s', 'in_synced_cancel_' . $this->active_run_id ) );
	}

	public function ajax_progress(): void {
		$this->require_ajax_action();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
		$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
		wp_send_json_success( $this->get_progress( $run_id ) );
	}

	public function build_sync_preview( string $profile_key = 'default', array $overrides = array() ): array {
		global $wpdb;

		$profile     = $this->apply_runtime_overrides( $this->get_profile( $profile_key ), $overrides );
		$settings    = $this->get_settings();
		$since       = $this->file_since_timestamp( $profile_key, $profile );
		$last_record = $this->last_run_record( $profile_key );
		$preview     = array(
			'profile_name'      => (string) ( $profile['name'] ?? $profile_key ),
			'scope'             => array(
				'database' => ! empty( $profile['sync_database'] ),
				'uploads'  => ! empty( $profile['sync_media'] ),
				'theme'    => ! empty( $profile['sync_theme'] ),
			),
			'connection'        => array(
				'remote_url'         => $this->normalize_remote_base_url( (string) ( $settings['remote_url'] ?? '' ) ),
				'local_url'          => (string) ( $settings['local_url'] ?? home_url() ),
				'remote_public_url'  => (string) ( $settings['remote_public_url'] ?? '' ),
				'secret_fingerprint' => $this->secret_fingerprint( $settings ),
				'paired'             => $this->get_shared_secret( $settings ) !== '',
			),
			'mode'              => ( ( $profile['mode'] ?? 'upsert' ) === 'replace' ) ? 'replace' : 'upsert',
			'incremental_files' => ! empty( $profile['incremental_files'] ),
			'last_success'      => ! empty( $last_record['success_time'] ) ? gmdate( 'c', (int) $last_record['success_time'] ) : '',
			'database'          => array(
				'tables'      => array(),
				'table_count' => 0,
				'row_count'   => 0,
				'scope'       => (string) ( $profile['database_scope'] ?? 'tables' ),
				'post_types'  => array(),
			),
			'uploads'           => array(
				'file_count' => 0,
				'bytes'      => 0,
				'sample'     => array(),
				'since'      => $since ? gmdate( 'c', $since ) : '',
			),
			'theme'             => array(
				'theme_slug' => $this->preview_file_roots_label( $profile ),
				'file_count' => 0,
				'bytes'      => 0,
				'sample'     => array(),
				'since'      => $since ? gmdate( 'c', $since ) : '',
			),
			'warnings'          => array(),
		);

		if ( empty( $settings['remote_url'] ) ) {
			$preview['warnings'][] = __( 'Remote URL is not configured.', 'in-synced' );
		}

		if ( ! $this->get_shared_secret( $settings ) ) {
			$preview['warnings'][] = __( 'Shared secret is missing or shorter than 32 characters.', 'in-synced' );
		}

		if ( ! empty( $profile['sync_database'] ) && ( ( $profile['database_scope'] ?? 'tables' ) === 'post_types' ) ) {
			$post_types                        = $this->selected_post_types( $profile, false );
			$preview['database']['post_types'] = $post_types;
			if ( ! $post_types ) {
				$preview['warnings'][] = __( 'Post type repair needs at least one post type in the profile.', 'in-synced' );
			} else {
				foreach ( $this->content_table_queries( $post_types ) as $table => $query ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
					$rows                              = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->escape_identifier( $table ) . '` WHERE ' . $query['where'] );
					$preview['database']['tables'][]   = array(
						'name'  => $table,
						'label' => $this->table_suffix( $table ),
						'rows'  => $rows,
					);
					$preview['database']['row_count'] += $rows;
				}
				$preview['database']['table_count'] = count( $preview['database']['tables'] );
			}
		} elseif ( ! empty( $profile['sync_database'] ) ) {
			$tables = $this->select_tables( $profile );
			foreach ( $tables as $table ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
				$rows                              = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->escape_identifier( $table ) . '`' );
				$preview['database']['tables'][]   = array(
					'name'  => $table,
					'label' => $this->table_suffix( $table ),
					'rows'  => $rows,
				);
				$preview['database']['row_count'] += $rows;
			}
			$preview['database']['table_count'] = count( $preview['database']['tables'] );
		}

		if ( ! empty( $profile['sync_media'] ) ) {
			$uploads = wp_get_upload_dir();
			if ( ! empty( $uploads['basedir'] ) && is_dir( $uploads['basedir'] ) ) {
				$preview['uploads'] = array_merge(
					$preview['uploads'],
					$this->summarize_files(
						$uploads['basedir'],
						$this->line_list( $profile['upload_excludes'] ?? '' ),
						$since,
						$this->line_list( $profile['upload_includes'] ?? '' )
					)
				);
			} else {
				$preview['warnings'][] = __( 'Uploads directory was not found.', 'in-synced' );
			}
		}

		if ( ! empty( $profile['sync_theme'] ) ) {
			$roots    = $this->select_file_roots( $profile );
			$excludes = $this->line_list( $profile['file_excludes'] ?? $profile['theme_excludes'] ?? '' );

			if ( ! $roots ) {
				$preview['warnings'][] = __( 'No wp-content file roots are configured.', 'in-synced' );
			}

			foreach ( $roots as $root ) {
				$base = trailingslashit( WP_CONTENT_DIR ) . $root;
				if ( ! is_dir( $base ) ) {
					/* translators: %s: sync detail. */
					$preview['warnings'][] = sprintf( __( 'File root was not found: wp-content/%s', 'in-synced' ), $root );
					continue;
				}

				$summary                         = $this->summarize_files( $base, $excludes, $since );
				$preview['theme']['file_count'] += $summary['file_count'];
				$preview['theme']['bytes']      += $summary['bytes'];
				foreach ( $summary['sample'] as $sample ) {
					if ( count( $preview['theme']['sample'] ) >= 24 ) {
						break;
					}
					$sample['path']               = $root . '/' . $sample['path'];
					$preview['theme']['sample'][] = $sample;
				}
			}
			$preview['theme']['bytes_label'] = size_format( (int) $preview['theme']['bytes'], 1 );
		}

		return $preview;
	}

	public function compare_content( string $profile_key = 'default', array $overrides = array() ) {
		$profile    = $this->apply_runtime_overrides( $this->get_profile( $profile_key ), $overrides );
		$post_types = $this->selected_post_types( $profile, true );
		$local      = $this->build_content_inventory( $profile, $post_types );
		$log        = array();
		$remote     = $this->remote_request(
			'compare-content',
			array(
				'post_types' => $post_types,
				'tables'     => array_map(
					function ( $table ) {
						return $this->table_suffix( $table );
					},
					$this->select_tables( $profile )
				),
			),
			$log,
			60
		);

		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		return $this->merge_content_inventory( $local, is_array( $remote ) ? $remote : array() );
	}

	public function run_sync( string $profile_key = 'default', array &$log = array(), array $overrides = array(), string $run_id = '' ) {
		$session = wp_generate_uuid4();
		$lease   = $this->claim_lease( 'in_synced_site_lease', $session );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		$this->active_session_id     = $session;
		$this->receiver_uncertain    = false;
		$this->receiver_session_open = false;
		try {
			return $this->execute_sync( $profile_key, $log, $overrides, $run_id );
		} finally {
			// Releasing the receive lease is safe even after a partial push. It does
			// not roll back data, and a different session cannot release this lease.
			$cleanup_log = array();
			if ( $this->receiver_session_open && ! $this->receiver_uncertain ) {
				$this->remote_request( 'end-sync', array(), $cleanup_log, 20 );
			}
			$this->release_lease( 'in_synced_site_lease', $session );
			$this->active_session_id = '';
			$this->active_run_id     = '';
		}
	}

	private function execute_sync( string $profile_key, array &$log, array $overrides, string $run_id ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); }
		if ( is_multisite() ) {
			return new WP_Error( 'in_synced_multisite_unsupported', __( 'Sync is currently supported only on single-site WordPress installs.', 'in-synced' ) );
		}
		$this->active_run_id = $run_id;

		$profile    = $this->apply_runtime_overrides( $this->get_profile( $profile_key ), $overrides );
		$started_at = time();
		$stats      = array();
		/* translators: %1$s: sync detail, %2$s: sync detail. */
		$log[] = sprintf( __( 'Starting profile "%1$s" at %2$s', 'in-synced' ), ( $profile['name'] ?? $profile_key ), gmdate( 'c', $started_at ) );
		$this->update_progress(
			$run_id,
			array(
				'status'  => 'running',
				'phase'   => 'connecting',
				'message' => __( 'Testing the remote connection.', 'in-synced' ),
				'percent' => 2,
				'log'     => $log,
			)
		);

		$ping = $this->remote_request( 'ping', array( 'site' => home_url() ), $log );
		if ( is_wp_error( $ping ) ) {
			$this->update_progress(
				$run_id,
				array(
					'status'  => 'failed',
					'phase'   => 'connecting',
					'message' => $ping->get_error_message(),
					'percent' => 100,
					'log'     => $log,
				)
			);
			$this->record_last_run( $profile_key, $profile, 'failed', $stats, $ping->get_error_message() );
			return $ping;
		}

		if ( untrailingslashit( (string) ( $ping['site_url'] ?? '' ) ) === untrailingslashit( home_url() ) ) {
			$error = new WP_Error( 'in_synced_self_push', __( 'The receiver identifies as this site. Syncing a site to itself is refused.', 'in-synced' ) );
			$this->update_progress(
				$run_id,
				array(
					'status'  => 'failed',
					'percent' => 100,
					'message' => $error->get_error_message(),
					'log'     => $log,
				)
			);
			return $error;
		}

		$begin_sync = $this->remote_request(
			'begin-sync',
			array(
				'database' => ! empty( $profile['sync_database'] ),
				'media'    => ! empty( $profile['sync_media'] ),
				'files'    => ! empty( $profile['sync_theme'] ),
			),
			$log
		);
		if ( is_wp_error( $begin_sync ) ) {
			$this->update_progress(
				$run_id,
				array(
					'status'  => 'failed',
					'percent' => 100,
					'message' => $begin_sync->get_error_message(),
					'log'     => $log,
				)
			);
			return $begin_sync;
		}
		$this->receiver_session_open = true;

		if ( ! empty( $profile['sync_database'] ) ) {
			$result = ( ( $profile['database_scope'] ?? 'tables' ) === 'post_types' )
				? $this->push_post_type_content( $profile, $log, $run_id )
				: $this->push_database( $profile, $log, $run_id );
			if ( is_wp_error( $result ) ) {
				$this->update_progress(
					$run_id,
					array(
						'status'  => $result->get_error_code() === 'in_synced_cancelled' ? 'cancelled' : 'failed',
						'message' => $result->get_error_message(),
						'percent' => 100,
						'log'     => $log,
					)
				);
				$this->record_last_run( $profile_key, $profile, 'failed', $stats, $result->get_error_message() );
				return $result;
			}
			$stats = array_merge( $stats, is_array( $result ) ? $result : array() );
		}

		if ( ! empty( $profile['sync_media'] ) ) {
			$result = $this->push_uploads( $profile_key, $profile, $log, $run_id );
			if ( is_wp_error( $result ) ) {
				$this->update_progress(
					$run_id,
					array(
						'status'  => $result->get_error_code() === 'in_synced_cancelled' ? 'cancelled' : 'failed',
						'message' => $result->get_error_message(),
						'percent' => 100,
						'log'     => $log,
					)
				);
				$this->record_last_run( $profile_key, $profile, 'failed', $stats, $result->get_error_message() );
				return $result;
			}
			$stats = array_merge( $stats, is_array( $result ) ? $result : array() );
		}

		if ( ! empty( $profile['sync_theme'] ) ) {
			$result = $this->push_theme( $profile_key, $profile, $log, $run_id );
			if ( is_wp_error( $result ) ) {
				$this->update_progress(
					$run_id,
					array(
						'status'  => $result->get_error_code() === 'in_synced_cancelled' ? 'cancelled' : 'failed',
						'message' => $result->get_error_message(),
						'percent' => 100,
						'log'     => $log,
					)
				);
				$this->record_last_run( $profile_key, $profile, 'failed', $stats, $result->get_error_message() );
				return $result;
			}
			$stats = array_merge( $stats, is_array( $result ) ? $result : array() );
		}

		$finished = $this->remote_request( 'end-sync', array(), $log, 45 );
		if ( is_wp_error( $finished ) ) {
			$this->update_progress(
				$run_id,
				array(
					'status'  => 'failed',
					'percent' => 100,
					'message' => $finished->get_error_message(),
					'log'     => $log,
				)
			);
			return $finished;
		}
		$this->receiver_session_open = false;

		$this->record_last_run( $profile_key, $profile, 'complete', $stats, '', $started_at );

		/* translators: %s: sync detail. */
		$log[] = sprintf( __( 'Sync completed at %s', 'in-synced' ), gmdate( 'c' ) );
		$this->update_progress(
			$run_id,
			array(
				'status'  => 'complete',
				'phase'   => 'complete',
				'message' => __( 'Sync completed.', 'in-synced' ),
				'percent' => 100,
				'log'     => $log,
			)
		);

		return true;
	}

	private function push_database( array $profile, array &$log, string $run_id = '' ) {
		global $wpdb;

		$settings = $this->get_settings();
		$tables   = $this->select_tables( $profile );
		$manifest = array();

		foreach ( $tables as $table ) {
			$target     = $this->map_table_name( $table, $wpdb->prefix, '__REMOTE_PREFIX__' );
			$manifest[] = array(
				'source'          => $table,
				'target_template' => $target,
				'schema'          => $this->get_create_table_sql( $table ),
				'columns'         => $this->get_table_columns( $table ),
			);
		}

		$mode = ( ( $profile['mode'] ?? 'upsert' ) === 'replace' ) ? 'replace' : 'upsert';
		/* translators: %1$s: sync detail, %2$s: sync detail. */
		$log[] = sprintf( __( 'Database: preparing %1$s table(s) in %2$s mode.', 'in-synced' ), count( $manifest ), $mode );
		$this->update_progress(
			$run_id,
			array(
				'status'  => 'running',
				'phase'   => 'database',
				/* translators: %s: sync detail. */
				'message' => sprintf( __( 'Preparing %s database table(s).', 'in-synced' ), count( $manifest ) ),
				'percent' => 8,
				'log'     => $log,
			)
		);

		$total_rows   = 0;
		$table_counts = array();

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$table_counts[ $table ] = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->escape_identifier( $table ) . '`' );
			if ( $table_counts[ $table ] === null || $wpdb->last_error ) {
				return new WP_Error( 'in_synced_source_count', __( 'Source row counts could not be read. No destructive preparation was sent.', 'in-synced' ) );
			}
			$total_rows += $table_counts[ $table ];
		}

		$begin = $this->remote_request(
			'begin-db',
			array(
				'mode'          => $mode,
				'source_prefix' => $wpdb->prefix,
				'tables'        => $manifest,
			),
			$log,
			120
		);

		if ( is_wp_error( $begin ) ) {
			return $begin;
		}

		if ( $mode === 'replace' ) {
			$backup = is_array( $begin ) ? (string) ( $begin['backup'] ?? '' ) : '';
			if ( $backup === '' ) {
				return new WP_Error( 'in_synced_backup_missing', __( 'Replace mode was aborted: the receiver did not confirm a database backup before truncating tables.', 'in-synced' ) );
			}
			/* translators: %s: sync detail. */
			$log[] = sprintf( __( 'Database: receiver backup saved at %s', 'in-synced' ), $backup );
		}

		$replacements = $this->replacement_pairs( $settings );
		$batch_size   = max( 10, min( 1000, (int) ( $profile['batch_size'] ?? 100 ) ) );

		$sent_rows = 0;

		foreach ( $tables as $table ) {
			$count = (int) $table_counts[ $table ];
			/* translators: %1$s: sync detail, %2$s: sync detail. */
			$log[] = sprintf( __( 'Database: sending %1$s (%2$s rows).', 'in-synced' ), $table, $count );
			$this->update_progress(
				$run_id,
				array(
					'status'  => 'running',
					'phase'   => 'database',
					/* translators: %1$s: sync detail, %2$s: sync detail. */
					'message' => sprintf( __( 'Sending %1$s (%2$s rows).', 'in-synced' ), $this->table_suffix( $table ), $count ),
					'percent' => $this->scale_percent( $sent_rows, max( 1, $total_rows ), 10, 55 ),
					'log'     => $log,
				)
			);

			for ( $offset = 0; $offset < $count; $offset += $batch_size ) {
				$rows = $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
					'SELECT * FROM `' . $this->escape_identifier( $table ) . '`' . $this->table_order_clause( $table ) . ' LIMIT ' . (int) $offset . ', ' . (int) $batch_size,
					ARRAY_A
				);
				if ( $wpdb->last_error || ! is_array( $rows ) || count( $rows ) !== min( $batch_size, $count - $offset ) ) {
					return new WP_Error( 'in_synced_source_read', __( 'A source database batch could not be read. The push stopped.', 'in-synced' ) );
				}
				$rows = $this->transform_rows_for_remote( $table, $rows, $replacements );

				$response = $this->remote_request(
					'table-batch',
					array(
						'mode'          => $mode,
						'source_prefix' => $wpdb->prefix,
						'table'         => $table,
						'rows'          => $rows,
					),
					$log,
					120
				);

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$sent_rows += count( $rows );
				$this->update_progress(
					$run_id,
					array(
						'status'  => 'running',
						'phase'   => 'database',
						/* translators: %1$s: sync detail, %2$s: sync detail. */
						'message' => sprintf( __( 'Sent %1$s of %2$s database row(s).', 'in-synced' ), number_format_i18n( $sent_rows ), number_format_i18n( $total_rows ) ),
						'percent' => $this->scale_percent( $sent_rows, max( 1, $total_rows ), 10, 55 ),
						'log'     => $log,
					)
				);
			}
		}

		$end = $this->remote_request( 'end-db', array(), $log, 120 );
		if ( is_wp_error( $end ) ) {
			return $end;
		}

		$log[] = __( 'Database: complete.', 'in-synced' );
		$this->update_progress(
			$run_id,
			array(
				'status'  => 'running',
				'phase'   => 'database',
				'message' => __( 'Database sync complete.', 'in-synced' ),
				'percent' => 58,
				'log'     => $log,
			)
		);

		return array( 'database_rows' => $sent_rows );
	}

	private function push_post_type_content( array $profile, array &$log, string $run_id = '' ) {
		global $wpdb;

		$settings   = $this->get_settings();
		$post_types = $this->selected_post_types( $profile, false );
		if ( ! $post_types ) {
			return new WP_Error( 'in_synced_no_post_types', __( 'Post type repair requires at least one post type in the profile.', 'in-synced' ) );
		}

		$tables   = $this->content_table_queries( $post_types );
		$manifest = array();
		foreach ( $tables as $table => $query ) {
			$manifest[] = array(
				'source'          => $table,
				'target_template' => $this->map_table_name( $table, $wpdb->prefix, '__REMOTE_PREFIX__' ),
				'schema'          => $this->get_create_table_sql( $table ),
				'columns'         => $this->get_table_columns( $table ),
			);
		}

		$mode = ( ( $profile['mode'] ?? 'upsert' ) === 'replace' ) ? 'replace' : 'upsert';
		/* translators: %1$s: sync detail, %2$s: sync detail. */
		$log[] = sprintf( __( 'Content repair: preparing post types %1$s in %2$s mode.', 'in-synced' ), implode( ', ', $post_types ), $mode );
		$this->update_progress(
			$run_id,
			array(
				'status'  => 'running',
				'phase'   => 'database',
				/* translators: %s: sync detail. */
				'message' => sprintf( __( 'Preparing post type repair for %s.', 'in-synced' ), implode( ', ', $post_types ) ),
				'percent' => 8,
				'log'     => $log,
			)
		);

		$preflight_size = max( 10, min( 1000, (int) ( $profile['batch_size'] ?? 100 ) ) );
		foreach ( $tables as $table => $query ) {
			$offset = 0;
			do {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
				$rows = $wpdb->get_results( 'SELECT * FROM `' . $this->escape_identifier( $table ) . '` WHERE ' . $query['where'] . $this->table_order_clause( $table ) . ' LIMIT ' . $offset . ', ' . $preflight_size, ARRAY_A );
				if ( $wpdb->last_error ) {
					return new WP_Error( 'in_synced_source_read', __( 'The source database could not be read for repair preflight.', 'in-synced' ) );
				}
				$check = $this->remote_request(
					'validate-batch',
					array(
						'source_prefix' => $wpdb->prefix,
						'table'         => $table,
						'rows'          => $rows,
						'post_types'    => $post_types,
					),
					$log,
					120
				);
				if ( is_wp_error( $check ) ) {
					return $check;
				}
				$offset         += $preflight_size;
				$preflight_count = count( $rows );
			} while ( $preflight_count === $preflight_size );
		}

		$table_counts = array();
		$total_rows   = 0;

		foreach ( $tables as $table => $query ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$count = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->escape_identifier( $table ) . '` WHERE ' . $query['where'] );
			if ( $count === null || $wpdb->last_error ) {
				return new WP_Error( 'in_synced_source_count', __( 'Source row counts could not be read. No destructive preparation was sent.', 'in-synced' ) );
			}
			$table_counts[ $table ] = $count;
			$total_rows            += $count;
		}

		$begin = $this->remote_request(
			'begin-content',
			array(
				'mode'          => $mode,
				'source_prefix' => $wpdb->prefix,
				'post_types'    => $post_types,
				'tables'        => $manifest,
			),
			$log,
			120
		);

		if ( is_wp_error( $begin ) ) {
			return $begin;
		}

		if ( $mode === 'replace' ) {
			$backup = is_array( $begin ) ? (string) ( $begin['backup'] ?? '' ) : '';
			if ( $backup === '' ) {
				return new WP_Error( 'in_synced_backup_missing', __( 'Replace mode was aborted: the receiver did not confirm a database backup before deleting content.', 'in-synced' ) );
			}
			/* translators: %s: sync detail. */
			$log[] = sprintf( __( 'Content repair: receiver backup saved at %s', 'in-synced' ), $backup );
		}

		$replacements = $this->replacement_pairs( $settings );
		$batch_size   = max( 10, min( 1000, (int) ( $profile['batch_size'] ?? 100 ) ) );

		$sent_rows = 0;
		foreach ( $tables as $table => $query ) {
			$count = (int) $table_counts[ $table ];
			/* translators: %1$s: sync detail, %2$s: sync detail. */
			$log[] = sprintf( __( 'Content repair: sending %1$s (%2$s rows).', 'in-synced' ), $this->table_suffix( $table ), $count );

			for ( $offset = 0; $offset < $count; $offset += $batch_size ) {
				$rows = $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
					'SELECT * FROM `' . $this->escape_identifier( $table ) . '` WHERE ' . $query['where'] . $this->table_order_clause( $table ) . ' LIMIT ' . (int) $offset . ', ' . (int) $batch_size,
					ARRAY_A
				);
				if ( $wpdb->last_error || ! is_array( $rows ) || count( $rows ) !== min( $batch_size, $count - $offset ) ) {
					return new WP_Error( 'in_synced_source_read', __( 'A source database batch could not be read. The push stopped.', 'in-synced' ) );
				}
				$rows = $this->transform_rows_for_remote( $table, $rows, $replacements );

				$response = $this->remote_request(
					'table-batch',
					array(
						'mode'          => $mode,
						'source_prefix' => $wpdb->prefix,
						'table'         => $table,
						'rows'          => $rows,
						'post_types'    => $post_types,
					),
					$log,
					120
				);

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$sent_rows += count( $rows );
				$this->update_progress(
					$run_id,
					array(
						'status'  => 'running',
						'phase'   => 'database',
						/* translators: %1$s: sync detail, %2$s: sync detail. */
						'message' => sprintf( __( 'Sent %1$s of %2$s repair row(s).', 'in-synced' ), number_format_i18n( $sent_rows ), number_format_i18n( $total_rows ) ),
						'percent' => $this->scale_percent( $sent_rows, max( 1, $total_rows ), 10, 55 ),
						'log'     => $log,
					)
				);
			}
		}

		$end = $this->remote_request( 'end-db', array(), $log, 120 );
		if ( is_wp_error( $end ) ) {
			return $end;
		}

		$log[] = __( 'Content repair: complete.', 'in-synced' );
		$this->update_progress(
			$run_id,
			array(
				'status'  => 'running',
				'phase'   => 'database',
				'message' => __( 'Post type repair complete.', 'in-synced' ),
				'percent' => 58,
				'log'     => $log,
			)
		);

		return array( 'database_rows' => $sent_rows );
	}

	private function push_uploads( string $profile_key, array $profile, array &$log, string $run_id = '' ) {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) || ! is_dir( $uploads['basedir'] ) ) {
			$log[] = __( 'Uploads: no upload directory found.', 'in-synced' );
			return array();
		}

		$since = $this->file_since_timestamp( $profile_key, $profile );
		$files = $this->list_files(
			$uploads['basedir'],
			$this->line_list( $profile['upload_excludes'] ?? '' ),
			$since,
			$this->line_list( $profile['upload_includes'] ?? '' )
		);
		$files = array_values(
			array_filter(
				$files,
				function ( $file ) use ( $uploads ) {
					return In_Synced_Safety::upload_path_allowed( $this->relative_path( $uploads['basedir'], $file ) );
				}
			)
		);
		/* translators: %1$s: sync detail, %2$s: sync detail. */
		$log[] = sprintf( __( 'Uploads: sending %1$s file(s)%2$s.', 'in-synced' ), count( $files ), ( $since ? ' changed since ' . gmdate( 'c', $since ) : '' ) );
		$this->update_progress(
			$run_id,
			array(
				'status'  => 'running',
				'phase'   => 'uploads',
				/* translators: %s: sync detail. */
				'message' => sprintf( __( 'Preparing %s upload file(s).', 'in-synced' ), count( $files ) ),
				'percent' => 60,
				'log'     => $log,
			)
		);

		$sent  = 0;
		$total = count( $files );
		foreach ( $files as $file ) {
			$relative = $this->relative_path( $uploads['basedir'], $file );
			$result   = $this->send_file( 'uploads', '', $relative, $file, $log );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			++$sent;
			$this->update_progress(
				$run_id,
				array(
					'status'  => 'running',
					'phase'   => 'uploads',
					/* translators: %1$s: sync detail, %2$s: sync detail, %3$s: sync detail. */
					'message' => sprintf( __( 'Sent upload file %1$s of %2$s: %3$s', 'in-synced' ), $sent, $total, $relative ),
					'percent' => $this->scale_percent( $sent, max( 1, $total ), 60, 78 ),
					'log'     => $log,
				)
			);
		}

		return array( 'upload_files' => $sent );
	}

	private function push_theme( string $profile_key, array $profile, array &$log, string $run_id = '' ) {
		$roots = $this->select_file_roots( $profile );
		if ( ! $roots ) {
			return new WP_Error( 'in_synced_file_roots_missing', __( 'No wp-content file roots are configured.', 'in-synced' ) );
		}

		$excludes = $this->line_list( $profile['file_excludes'] ?? $profile['theme_excludes'] ?? '' );
		$since    = $this->file_since_timestamp( $profile_key, $profile );
		$batches  = array();
		$total    = 0;

		foreach ( $roots as $root ) {
			$base = trailingslashit( WP_CONTENT_DIR ) . $root;
			if ( ! is_dir( $base ) ) {
				/* translators: %s: sync detail. */
				return new WP_Error( 'in_synced_file_root_missing', sprintf( __( 'File root not found: wp-content/%s', 'in-synced' ), $root ) );
			}
			$files     = $this->list_files( $base, $excludes, $since );
			$batches[] = array(
				'root'  => $root,
				'base'  => $base,
				'files' => $files,
			);
			$total    += count( $files );
		}

		/* translators: %1$s: sync detail, %2$s: sync detail, %3$s: sync detail. */
		$log[] = sprintf( __( 'Files: sending %1$s root(s), %2$s file(s)%3$s.', 'in-synced' ), count( $roots ), $total, ( $since ? ' changed since ' . gmdate( 'c', $since ) : '' ) );
		$this->update_progress(
			$run_id,
			array(
				'status'  => 'running',
				'phase'   => 'theme',
				/* translators: %s: sync detail. */
				'message' => sprintf( __( 'Preparing %s wp-content file(s).', 'in-synced' ), $total ),
				'percent' => 80,
				'log'     => $log,
			)
		);

		$sent = 0;
		foreach ( $batches as $batch ) {
			foreach ( $batch['files'] as $file ) {
				$relative = $this->relative_path( $batch['base'], $file );
				$result   = $this->send_file( 'content', $batch['root'], $relative, $file, $log );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				++$sent;
				$this->update_progress(
					$run_id,
					array(
						'status'  => 'running',
						'phase'   => 'theme',
						/* translators: %1$s: sync detail, %2$s: sync detail, %3$s: sync detail, %4$s: sync detail. */
						'message' => sprintf( __( 'Sent wp-content file %1$s of %2$s: %3$s/%4$s', 'in-synced' ), $sent, $total, $batch['root'], $relative ),
						'percent' => $this->scale_percent( $sent, max( 1, $total ), 80, 96 ),
						'log'     => $log,
					)
				);
			}
		}

		return array( 'theme_files' => $sent );
	}

	private function send_file( string $target, string $content_root, string $relative, string $file, array &$log ) {
		$size  = filesize( $file );
		$hash  = hash_file( 'sha256', $file );
		$mtime = filemtime( $file ) ?: time();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
		$handle = fopen( $file, 'rb' );

		if ( ! $handle ) {
			/* translators: %s: sync detail. */
			return new WP_Error( 'in_synced_file_read', sprintf( __( 'Could not read file: %s', 'in-synced' ), $file ) );
		}

		$offset = 0;
		do {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
			$data = fread( $handle, self::FILE_CHUNK_SIZE );
			if ( $data === false ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
				fclose( $handle );
				/* translators: %s: sync detail. */
				return new WP_Error( 'in_synced_file_read_chunk', sprintf( __( 'Could not read file chunk: %s', 'in-synced' ), $file ) );
			}

			$final    = feof( $handle );
			$response = $this->remote_request(
				'file-chunk',
				array(
					'target'        => $target,
					'theme_slug'    => $content_root,
					'content_root'  => $content_root,
					'relative_path' => $relative,
					'offset'        => $offset,
					'total_size'    => $size,
					'sha256'        => $hash,
					'mtime'         => $mtime,
					'final'         => $final,
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Lossless transport of database/file bytes, never evaluation or execution of encoded content.
					'data'          => base64_encode( $data ),
				),
				$log,
				120
			);

			if ( is_wp_error( $response ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
				fclose( $handle );
				return $response;
			}

			$offset += strlen( $data );
		} while ( ! $final );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
		fclose( $handle );
		/* translators: %1$s: sync detail, %2$s: sync detail. */
		$log[] = sprintf( __( 'File: %1$s%2$s', 'in-synced' ), ( $content_root ? $content_root . '/' : $target . '/' ), $relative );

		return true;
	}

	public function rest_begin_sync( WP_REST_Request $request ) {
		$settings = $this->get_settings();
		$payload  = $request->get_json_params();
		if ( ( ! empty( $payload['database'] ) && empty( $settings['allow_db_receive'] ) ) || ( ! empty( $payload['media'] ) && empty( $settings['allow_media_receive'] ) ) || ( ! empty( $payload['files'] ) && empty( $settings['allow_file_receive'] ) && empty( $settings['allow_theme_receive'] ) ) ) {
			return new WP_Error( 'in_synced_receive_disabled', __( 'The receiver has not enabled every selected sync scope.', 'in-synced' ), array( 'status' => 403 ) );
		}
		$lease = $this->receive_lease( $payload, array(), array() );
		return is_wp_error( $lease ) ? $lease : rest_ensure_response( array( 'ok' => true ) );
	}

	public function rest_end_sync( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$session = is_string( $payload['session_id'] ?? null ) ? $payload['session_id'] : '';
		if ( ! $this->release_lease( 'in_synced_site_lease', $session ) ) {
			return new WP_Error( 'in_synced_wrong_session', __( 'This sync session does not own the receive lease.', 'in-synced' ), array( 'status' => 409 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature required by WordPress; ping intentionally has no payload.
	public function rest_ping( WP_REST_Request $request ): WP_REST_Response {
		$settings = $this->get_settings();

		return rest_ensure_response(
			array(
				'ok'                  => true,
				'site_url'            => home_url(),
				'allow_db_receive'    => (bool) $settings['allow_db_receive'],
				'allow_media_receive' => (bool) $settings['allow_media_receive'],
				'allow_theme_receive' => (bool) ( $settings['allow_theme_receive'] || $settings['allow_file_receive'] ),
				'allow_file_receive'  => (bool) ( $settings['allow_file_receive'] || $settings['allow_theme_receive'] ),
				'theme_slug'          => $settings['theme_slug'],
				'allowed_file_roots'  => $settings['allowed_file_roots'] ?? '',
			)
		);
	}

	public function rest_begin_db( WP_REST_Request $request ) {
		global $wpdb;

		$settings = $this->get_settings();
		if ( empty( $settings['allow_db_receive'] ) ) {
			return new WP_Error( 'in_synced_db_receive_disabled', __( 'Database receiving is disabled on this site.', 'in-synced' ), array( 'status' => 403 ) );
		}

		$payload       = $request->get_json_params();
		$mode          = ( ( $payload['mode'] ?? 'upsert' ) === 'replace' ) ? 'replace' : 'upsert';
		$source_prefix = (string) ( $payload['source_prefix'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $source_prefix ) ) {
			return new WP_Error( 'in_synced_bad_prefix', __( 'A valid source table prefix is required.', 'in-synced' ), array( 'status' => 400 ) );
		}
		$tables   = is_array( $payload['tables'] ?? null ) ? $payload['tables'] : array();
		$prepared = array();

		if ( ! $tables || count( $tables ) > 500 ) {
			return new WP_Error( 'in_synced_bad_manifest', __( 'A manifest must contain between 1 and 500 tables.', 'in-synced' ), array( 'status' => 400 ) );
		}
		foreach ( $tables as $table ) {
			if ( ! is_array( $table ) ) {
				return new WP_Error( 'in_synced_bad_manifest', __( 'Invalid table manifest.', 'in-synced' ), array( 'status' => 400 ) );
			}
			$source = (string) ( $table['source'] ?? '' );
			$target = $this->map_table_name( $source, $source_prefix, $wpdb->prefix );

			if ( $this->is_user_table( $target ) ) {
				/* translators: %s: sync detail. */
				return new WP_Error( 'in_synced_users_table_denied', sprintf( __( 'User tables are not accepted by this receiver: %s', 'in-synced' ), $target ), array( 'status' => 403 ) );
			}

			if ( ! $this->is_allowed_table( $target ) ) {
				/* translators: %s: sync detail. */
				return new WP_Error( 'in_synced_bad_table', sprintf( __( 'Table is outside the WordPress prefix: %s', 'in-synced' ), $target ), array( 'status' => 400 ) );
			}

			$schema = $this->rewrite_create_table_sql( (string) ( $table['schema'] ?? '' ), $source, $target );
			if ( ! $schema ) {
				return new WP_Error( 'in_synced_unsafe_schema', __( 'This table schema is unsupported or unsafe. Exclude the table or simplify its schema before syncing.', 'in-synced' ), array( 'status' => 400 ) );
			}
			if ( $this->table_exists( $target ) ) {
				$incoming_columns = is_array( $table['columns'] ?? null ) ? $table['columns'] : array();
				if ( ! $incoming_columns || $incoming_columns !== $this->get_table_columns( $target ) || In_Synced_Safety::schema_fingerprint( $schema ) !== In_Synced_Safety::schema_fingerprint( $this->get_create_table_sql( $target ) ) ) {
					return new WP_Error(
						'in_synced_schema_mismatch',
						sprintf(
						/* translators: %s: database table name. */
							__( 'Schema mismatch for %s. Update the receiver schema before syncing; no tables were cleared.', 'in-synced' ),
							$target
						),
						array( 'status' => 409 )
					);
				}
			}
			$prepared[] = $target;
		}
		$lease = $this->receive_lease( $payload, $prepared, $post_types ?? array() );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		foreach ( $tables as $table ) {
			$source = (string) $table['source'];
			$target = $this->map_table_name( $source, $source_prefix, $wpdb->prefix );
			if ( ! $this->table_exists( $target ) ) {
				$schema = $this->rewrite_create_table_sql( (string) ( $table['schema'] ?? '' ), $source, $target );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit authorized sync executes one allowlisted CREATE statement with a validated table target.
				$created = $schema ? $wpdb->query( $schema ) : false;
				if ( $created === false ) {
					/* translators: %s: database table name. */
					return new WP_Error( 'in_synced_create_table_failed', sprintf( __( 'Could not create table: %s', 'in-synced' ), $target ), array( 'status' => 500 ) );
				}
			}
		}

		$backup = '';
		if ( $mode === 'replace' && $prepared ) {
			$backup = $this->backup_tables( $prepared );

			if ( $backup === '' ) {
				return new WP_Error( 'in_synced_backup_failed', __( 'Replace mode was aborted: a database backup could not be created before truncating tables. Configure a writable private IN_SYNCED_BACKUP_DIR outside the public web root and retry.', 'in-synced' ), array( 'status' => 500 ) );
			}

			foreach ( $prepared as $target ) {
				$lease = $this->claim_lease( 'in_synced_site_lease', $this->receiver_session_id );
				if ( is_wp_error( $lease ) ) {
					return $lease; }
				if ( $target === $wpdb->options ) {
					// Keep pairing and atomic nonce claims continuously present, even while
					// another PHP worker authenticates a chunk during an options replacement.
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
					$cleared = $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $this->escape_identifier( $target ) . '` WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s', $wpdb->esc_like( 'in_synced_' ) . '%', $wpdb->esc_like( '_transient_in_synced_' ) . '%', $wpdb->esc_like( '_transient_timeout_in_synced_' ) . '%' ) );
				} else {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
					$cleared = $wpdb->query( 'TRUNCATE TABLE `' . $this->escape_identifier( $target ) . '`' );
				}
				if ( $cleared === false ) {
					return new WP_Error( 'in_synced_clear_failed', __( 'The receiver could not clear a table. Stop and restore the reported backup before retrying.', 'in-synced' ), array( 'status' => 500 ) );
				}
			}
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'mode'   => $mode,
				'tables' => $prepared,
				'backup' => $backup,
			)
		);
	}

	public function rest_begin_content( WP_REST_Request $request ) {
		global $wpdb;

		$settings = $this->get_settings();
		if ( empty( $settings['allow_db_receive'] ) ) {
			return new WP_Error( 'in_synced_db_receive_disabled', __( 'Database receiving is disabled on this site.', 'in-synced' ), array( 'status' => 403 ) );
		}

		$payload       = $request->get_json_params();
		$mode          = ( ( $payload['mode'] ?? 'upsert' ) === 'replace' ) ? 'replace' : 'upsert';
		$source_prefix = (string) ( $payload['source_prefix'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $source_prefix ) ) {
			return new WP_Error( 'in_synced_bad_prefix', __( 'A valid source table prefix is required.', 'in-synced' ), array( 'status' => 400 ) );
		}
		$post_types = $this->sanitize_post_types( $payload['post_types'] ?? array() );
		$tables     = is_array( $payload['tables'] ?? null ) ? $payload['tables'] : array();
		$prepared   = array();

		if ( ! $post_types ) {
			return new WP_Error( 'in_synced_missing_post_types', __( 'No post types were provided for content repair.', 'in-synced' ), array( 'status' => 400 ) );
		}

		if ( ! $tables || count( $tables ) > 500 ) {
			return new WP_Error( 'in_synced_bad_manifest', __( 'A manifest must contain between 1 and 500 tables.', 'in-synced' ), array( 'status' => 400 ) );
		}
		foreach ( $tables as $table ) {
			if ( ! is_array( $table ) ) {
				return new WP_Error( 'in_synced_bad_manifest', __( 'Invalid table manifest.', 'in-synced' ), array( 'status' => 400 ) );
			}
			$source = (string) ( $table['source'] ?? '' );
			$target = $this->map_table_name( $source, $source_prefix, $wpdb->prefix );

			if ( $this->is_user_table( $target ) ) {
				/* translators: %s: sync detail. */
				return new WP_Error( 'in_synced_users_table_denied', sprintf( __( 'User tables are not accepted by this receiver: %s', 'in-synced' ), $target ), array( 'status' => 403 ) );
			}

			if ( ! $this->is_allowed_table( $target ) ) {
				/* translators: %s: sync detail. */
				return new WP_Error( 'in_synced_bad_table', sprintf( __( 'Table is outside the WordPress prefix: %s', 'in-synced' ), $target ), array( 'status' => 400 ) );
			}

			$schema = $this->rewrite_create_table_sql( (string) ( $table['schema'] ?? '' ), $source, $target );
			if ( ! $schema ) {
				return new WP_Error( 'in_synced_unsafe_schema', __( 'This table schema is unsupported or unsafe. Exclude the table or simplify its schema before syncing.', 'in-synced' ), array( 'status' => 400 ) );
			}
			if ( $this->table_exists( $target ) ) {
				$incoming_columns = is_array( $table['columns'] ?? null ) ? $table['columns'] : array();
				if ( ! $incoming_columns || $incoming_columns !== $this->get_table_columns( $target ) || In_Synced_Safety::schema_fingerprint( $schema ) !== In_Synced_Safety::schema_fingerprint( $this->get_create_table_sql( $target ) ) ) {
					return new WP_Error(
						'in_synced_schema_mismatch',
						sprintf(
						/* translators: %s: database table name. */
							__( 'Schema mismatch for %s. Update the receiver schema before syncing; no tables were cleared.', 'in-synced' ),
							$target
						),
						array( 'status' => 409 )
					);
				}
			}
			$prepared[] = $target;
		}
		$lease = $this->receive_lease( $payload, $prepared, $post_types ?? array() );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		foreach ( $tables as $table ) {
			$source = (string) $table['source'];
			$target = $this->map_table_name( $source, $source_prefix, $wpdb->prefix );
			if ( ! $this->table_exists( $target ) ) {
				$schema = $this->rewrite_create_table_sql( (string) ( $table['schema'] ?? '' ), $source, $target );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit authorized sync executes one allowlisted CREATE statement with a validated table target.
				$created = $schema ? $wpdb->query( $schema ) : false;
				if ( $created === false ) {
					/* translators: %s: database table name. */
					return new WP_Error( 'in_synced_create_table_failed', sprintf( __( 'Could not create table: %s', 'in-synced' ), $target ), array( 'status' => 500 ) );
				}
			}
		}

		$backup = '';
		if ( $mode === 'replace' ) {
			$content_tables = array_values(
				array_intersect(
					$prepared,
					array(
						$wpdb->posts,
						$wpdb->postmeta,
						$wpdb->comments,
						$wpdb->commentmeta,
						$wpdb->term_relationships,
					)
				)
			);

			if ( ! $content_tables ) {
				return new WP_Error( 'in_synced_backup_failed', __( 'Replace mode was aborted: the request did not include any content tables (posts, postmeta, comments, commentmeta, term relationships) to back up before deleting content.', 'in-synced' ), array( 'status' => 500 ) );
			}

			$backup = $this->backup_tables( $content_tables );

			if ( $backup === '' ) {
				return new WP_Error( 'in_synced_backup_failed', __( 'Replace mode was aborted: a database backup could not be created before deleting content. Configure a writable private IN_SYNCED_BACKUP_DIR outside the public web root and retry.', 'in-synced' ), array( 'status' => 500 ) );
			}

			$deleted = $this->delete_remote_post_type_rows( $post_types );
			if ( is_wp_error( $deleted ) ) {
				return $deleted; }
		}

		return rest_ensure_response(
			array(
				'ok'         => true,
				'mode'       => $mode,
				'post_types' => $post_types,
				'tables'     => $prepared,
				'backup'     => $backup,
			)
		);
	}

	public function rest_compare_content( WP_REST_Request $request ) {
		if ( empty( $this->get_settings()['allow_db_receive'] ) ) {
			return new WP_Error( 'in_synced_db_receive_disabled', __( 'Database receiving is disabled on this site.', 'in-synced' ), array( 'status' => 403 ) );
		}
		$payload    = $request->get_json_params();
		$post_types = $this->sanitize_post_types( $payload['post_types'] ?? array() );
		$tables     = $this->sanitize_table_suffixes( $payload['tables'] ?? array() );

		return rest_ensure_response( $this->build_content_inventory( array(), $post_types, $tables ) );
	}

	public function rest_validate_batch( WP_REST_Request $request ) {
		return $this->rest_table_batch( $request, true );
	}

	public function rest_table_batch( WP_REST_Request $request, bool $validate_only = false ) {
		global $wpdb;

		$settings = $this->get_settings();
		if ( empty( $settings['allow_db_receive'] ) ) {
			return new WP_Error( 'in_synced_db_receive_disabled', __( 'Database receiving is disabled on this site.', 'in-synced' ), array( 'status' => 403 ) );
		}

		$payload       = $request->get_json_params();
		$source_prefix = (string) ( $payload['source_prefix'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $source_prefix ) ) {
			return new WP_Error( 'in_synced_bad_prefix', __( 'A valid source table prefix is required.', 'in-synced' ), array( 'status' => 400 ) );
		}
		$source_table = (string) ( $payload['table'] ?? '' );
		$target       = $this->map_table_name( $source_table, $source_prefix, $wpdb->prefix );
		$rows         = is_array( $payload['rows'] ?? null ) ? $payload['rows'] : array();
		if ( count( $rows ) > self::MAX_BATCH_ROWS ) {
			return new WP_Error( 'in_synced_batch_large', __( 'A database batch may contain at most 1,000 rows.', 'in-synced' ), array( 'status' => 413 ) );
		}
		$rows = In_Synced_Safety::decode_rows( $rows );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		if ( $this->is_user_table( $target ) ) {
			/* translators: %s: sync detail. */
			return new WP_Error( 'in_synced_users_table_denied', sprintf( __( 'User tables are not accepted by this receiver: %s', 'in-synced' ), $target ), array( 'status' => 403 ) );
		}

		if ( ! $this->is_allowed_table( $target ) ) {
			/* translators: %s: sync detail. */
			return new WP_Error( 'in_synced_bad_table', sprintf( __( 'Table is outside the WordPress prefix: %s', 'in-synced' ), $target ), array( 'status' => 400 ) );
		}

		if ( ! $this->table_exists( $target ) ) {
			/* translators: %s: sync detail. */
			return new WP_Error( 'in_synced_missing_table', sprintf( __( 'Table has not been prepared: %s', 'in-synced' ), $target ), array( 'status' => 400 ) );
		}

		$lease = $this->receive_lease( $payload, null, null, $validate_only );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		if ( ! $validate_only && ! in_array( $target, $lease['tables'] ?? array(), true ) ) {
			return new WP_Error( 'in_synced_unprepared_batch', __( 'This table was not prepared by the current database session.', 'in-synced' ), array( 'status' => 409 ) );
		}
		if ( ! $validate_only && $this->sanitize_post_types( $payload['post_types'] ?? array() ) !== ( $lease['post_types'] ?? array() ) ) {
			return new WP_Error( 'in_synced_wrong_scope', __( 'The batch does not match the prepared content repair scope.', 'in-synced' ), array( 'status' => 409 ) );
		}
		$columns = $this->get_table_columns( $target );
		if ( ! $columns ) {
			/* translators: %s: sync detail. */
			return new WP_Error( 'in_synced_missing_columns', sprintf( __( 'Could not read the column list for table: %s', 'in-synced' ), $target ), array( 'status' => 500 ) );
		}

		// Validate the entire batch before its first mutation.
		foreach ( $rows as $row ) {
			if ( $this->is_sync_option_row( $target, $row ) ) {
				return new WP_Error( 'in_synced_protected_option', __( 'Sync state and transient options cannot be imported.', 'in-synced' ), array( 'status' => 403 ) );
			}
			if ( array_diff( array_keys( $row ), $columns ) ) {
				return new WP_Error( 'in_synced_schema_mismatch', __( 'A database row contains columns missing from the receiver. Update its schema before syncing.', 'in-synced' ), array( 'status' => 409 ) );
			}
			$conflict = $this->repair_row_conflict( $target, $row, $this->sanitize_post_types( $payload['post_types'] ?? array() ) );
			if ( is_wp_error( $conflict ) ) {
				return $conflict;
			}
		}
		$inserted         = 0;
		$skipped          = 0;
		$rejected_columns = array();
		foreach ( $rows as $row ) {
			$lease = $this->claim_lease( 'in_synced_site_lease', $this->receiver_session_id );
			if ( is_wp_error( $lease ) ) {
				return $lease; }
			if ( $this->is_sync_option_row( $target, $row ) ) {
				return new WP_Error( 'in_synced_protected_option', __( 'Sync state and transient options cannot be imported.', 'in-synced' ), array( 'status' => 403 ) );
			}
			if ( $validate_only ) {
				++$inserted;
				continue;
			}
			if ( $target === $wpdb->options ) {
				unset( $row['option_id'] );
			}
			$column_sql = array_map(
				function ( $column ) {
					return '`' . $this->escape_identifier( $column ) . '`';
				},
				array_keys( $row )
			);
			$value_sql  = array_map( array( $this, 'sql_literal' ), array_values( $row ) );
			// Hex literals preserve BLOB and invalid UTF-8 bytes without wpdb's text sanitization.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$result = $wpdb->query( 'REPLACE INTO `' . $this->escape_identifier( $target ) . '` (' . implode( ', ', $column_sql ) . ') VALUES (' . implode( ', ', $value_sql ) . ')' );
			if ( $result === false || $wpdb->get_results( 'SHOW WARNINGS', ARRAY_A ) ) {
				return new WP_Error( 'in_synced_insert_failed', __( 'The receiver could not import a row without database warnings. The push stopped; inspect its backup before retrying.', 'in-synced' ), array( 'status' => 500 ) );
			}
			++$inserted;
		}

		return rest_ensure_response(
			array(
				'ok'               => true,
				'table'            => $target,
				'inserted'         => $inserted,
				'skipped'          => $skipped,
				'rejected_columns' => $rejected_columns,
			)
		);
	}

	public function rest_end_db( WP_REST_Request $request ) {
		$settings = $this->get_settings();
		if ( empty( $settings['allow_db_receive'] ) ) {
			return new WP_Error( 'in_synced_db_receive_disabled', __( 'Database receiving is disabled on this site.', 'in-synced' ), array( 'status' => 403 ) );
		}

		$payload = $request->get_json_params();
		$session = is_string( $payload['session_id'] ?? null ) ? $payload['session_id'] : '';
		if ( ! $session || is_wp_error( $this->receive_lease( $payload ) ) ) {
			return new WP_Error( 'in_synced_wrong_session', __( 'This database session does not own the receive lease.', 'in-synced' ), array( 'status' => 409 ) );
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function rest_file_chunk( WP_REST_Request $request ) {
		$settings = $this->get_settings();
		$payload  = $request->get_json_params();
		$target   = sanitize_key( $payload['target'] ?? '' );

		if ( $target === 'uploads' && empty( $settings['allow_media_receive'] ) ) {
			return new WP_Error( 'in_synced_media_receive_disabled', __( 'Media receiving is disabled on this site.', 'in-synced' ), array( 'status' => 403 ) );
		}

		if ( in_array( $target, array( 'theme', 'content' ), true ) && empty( $settings['allow_theme_receive'] ) && empty( $settings['allow_file_receive'] ) ) {
			return new WP_Error( 'in_synced_theme_receive_disabled', __( 'File receiving is disabled on this site.', 'in-synced' ), array( 'status' => 403 ) );
		}

		$relative = $this->sanitize_relative_path( $payload['relative_path'] ?? '' );
		if ( ! $relative ) {
			return new WP_Error( 'in_synced_bad_path', __( 'Invalid relative file path.', 'in-synced' ), array( 'status' => 400 ) );
		}

		$root = '';
		if ( $target === 'uploads' ) {
			$uploads = wp_get_upload_dir();
			$root    = $uploads['basedir'];
		} elseif ( $target === 'theme' ) {
			$theme_slug = $this->sanitize_theme_slug( $payload['theme_slug'] ?? '' );
			if ( ! $theme_slug || $theme_slug !== $settings['theme_slug'] ) {
				return new WP_Error( 'in_synced_theme_not_allowed', __( 'Theme directory is not allowed on this receiver.', 'in-synced' ), array( 'status' => 403 ) );
			}
			$root = trailingslashit( get_theme_root() ) . $theme_slug;
		} elseif ( $target === 'content' ) {
			$content_root = $this->sanitize_relative_path( $payload['content_root'] ?? $payload['theme_slug'] ?? '' );
			if ( ! $content_root || ! $this->file_root_is_allowed( $content_root, $settings ) ) {
				/* translators: %s: sync detail. */
				return new WP_Error( 'in_synced_file_root_not_allowed', sprintf( __( 'File root is not allowed on this receiver: %s', 'in-synced' ), $content_root ), array( 'status' => 403 ) );
			}
			$root = trailingslashit( WP_CONTENT_DIR ) . $content_root;
		} else {
			return new WP_Error( 'in_synced_bad_target', __( 'Invalid file target.', 'in-synced' ), array( 'status' => 400 ) );
		}

		$lease = $this->receive_lease( $payload );
		if ( is_wp_error( $lease ) ) {
			return $lease; }

		if ( $target === 'uploads' && ! In_Synced_Safety::upload_path_allowed( $relative ) ) {
			return new WP_Error( 'in_synced_upload_type_denied', __( 'Executable files, server configuration, dotfiles and backup files cannot be synced into uploads.', 'in-synced' ), array( 'status' => 403 ) );
		}
		if ( $target !== 'uploads' ) {
			$content_real = realpath( WP_CONTENT_DIR );
			$ancestor     = $root;
			while ( ! file_exists( $ancestor ) && dirname( $ancestor ) !== $ancestor ) {
				$ancestor = dirname( $ancestor );
			}
			$ancestor_real = realpath( $ancestor );
			$root_relative = $this->relative_path( WP_CONTENT_DIR, $root );
			if ( ! In_Synced_Safety::confined_path( WP_CONTENT_DIR, $root_relative ) ) {
				return new WP_Error( 'in_synced_symlink_denied', __( 'The approved file root contains a symlink.', 'in-synced' ), array( 'status' => 403 ) );
			}
			if ( ! $content_real || ! $ancestor_real || ( $ancestor_real !== $content_real && strpos( $ancestor_real, $content_real . DIRECTORY_SEPARATOR ) !== 0 ) || is_link( $root ) ) {
				return new WP_Error( 'in_synced_symlink_denied', __( 'The approved file root resolves outside wp-content or is a symlink.', 'in-synced' ), array( 'status' => 403 ) );
			}
		}
		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return new WP_Error( 'in_synced_root_create_failed', __( 'Could not create target root.', 'in-synced' ), array( 'status' => 500 ) );
		}
		if ( ! In_Synced_Safety::confined_path( $root, $relative ) ) {
			return new WP_Error( 'in_synced_symlink_denied', __( 'The file path contains a symlink or escapes its approved root.', 'in-synced' ), array( 'status' => 403 ) );
		}
		$destination   = trailingslashit( realpath( $root ) ) . $relative;
		$directory     = dirname( $destination );
		$offset        = $payload['offset'] ?? null;
		$total_size    = $payload['total_size'] ?? null;
		$expected_hash = $payload['sha256'] ?? '';
		$encoded       = $payload['data'] ?? null;
		$max_file_size = max( 1, min( 1073741824, (int) apply_filters( 'in_synced_max_file_size', 268435456 ) ) );
		if ( ! is_int( $offset ) || ! is_int( $total_size ) || $offset < 0 || $total_size < 0 || $total_size > $max_file_size || ! is_string( $expected_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) || ! is_string( $encoded ) || strlen( $encoded ) > 1048576 ) {
			return new WP_Error( 'in_synced_bad_chunk', __( 'Invalid file metadata or file/chunk size limit exceeded.', 'in-synced' ), array( 'status' => 413 ) );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Lossless transport of database/file bytes, never evaluation or execution of encoded content.
		$data = base64_decode( $encoded, true );
		if ( $data === false || strlen( $data ) > self::FILE_CHUNK_SIZE || $offset + strlen( $data ) > $total_size ) {
			return new WP_Error( 'in_synced_bad_chunk', __( 'Invalid or oversized file chunk.', 'in-synced' ), array( 'status' => 400 ) );
		}
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'in_synced_dir_create_failed', __( 'Could not create target directory.', 'in-synced' ), array( 'status' => 500 ) );
		}
		if ( ! In_Synced_Safety::confined_path( $root, $relative ) ) {
			return new WP_Error( 'in_synced_symlink_denied', __( 'The target path changed during directory creation.', 'in-synced' ), array( 'status' => 403 ) );
		}
		$transfer_directory = $this->private_storage_directory( 'transfers' );
		if ( ! $transfer_directory || ! In_Synced_Safety::same_filesystem( $transfer_directory, $directory ) ) {
			return new WP_Error( 'in_synced_private_staging_unavailable', __( 'File receiving requires writable private staging outside the public web roots on the same filesystem as the target. Configure IN_SYNCED_BACKUP_DIR with a suitable private directory. The existing file was preserved.', 'in-synced' ), array( 'status' => 500 ) );
		}
		foreach ( (array) glob( $transfer_directory . '/.in-synced-*.part' ) as $stale ) {
			if ( ! is_link( $stale ) && is_file( $stale ) && filemtime( $stale ) < time() - self::PROGRESS_TTL ) {
				wp_delete_file( $stale );
			}
		}
		// Private staging prevents public servers from disclosing partial PHP source.
		$transfer_key = 'in_synced_transfer_' . hash( 'sha256', $destination . $expected_hash );
		$transfer     = get_transient( $transfer_key );
		if ( $offset === 0 ) {
			if ( is_array( $transfer ) && ! empty( $transfer['path'] ) && dirname( $transfer['path'] ) === $transfer_directory && ! is_link( $transfer['path'] ) ) {
				wp_delete_file( $transfer['path'] );
			}
			$transfer = array(
				'path' => $transfer_directory . '/.in-synced-' . wp_generate_password( 32, false, false ) . '.part',
				'size' => $total_size,
				'hash' => $expected_hash,
			);
			set_transient( $transfer_key, $transfer, self::PROGRESS_TTL );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
			$handle = fopen( $transfer['path'], 'x+b' );
		} else {
			if ( ! is_array( $transfer ) || ( $transfer['size'] ?? null ) !== $total_size || ( $transfer['hash'] ?? '' ) !== $expected_hash || dirname( (string) ( $transfer['path'] ?? '' ) ) !== $transfer_directory || is_link( $transfer['path'] ) || ! is_file( $transfer['path'] ) ) {
				return new WP_Error( 'in_synced_transfer_missing', __( 'The file transfer expired or was not started. Retry the file push.', 'in-synced' ), array( 'status' => 409 ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
			$handle = fopen( $transfer['path'], 'r+b' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Private staged files contain sensitive code/data; native permissions precede all writes.
		if ( ! $handle || ! chmod( $transfer['path'], 0600 ) || ! flock( $handle, LOCK_EX ) ) {
			if ( $handle ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
				fclose( $handle ); }
			return new WP_Error( 'in_synced_file_write_open', __( 'Could not lock the staged file for writing.', 'in-synced' ), array( 'status' => 500 ) );
		}
		$stat = fstat( $handle );
		if ( (int) $stat['size'] !== $offset || fseek( $handle, $offset ) !== 0 || ! In_Synced_Safety::write_all( $handle, $data ) || ! fflush( $handle ) ) {
			flock( $handle, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
			fclose( $handle );
			wp_delete_file( $transfer['path'] );
			delete_transient( $transfer_key );
			return new WP_Error( 'in_synced_file_write_failed', __( 'The file chunk could not be written completely at its expected offset. Retry the file push.', 'in-synced' ), array( 'status' => 500 ) );
		}
		if ( ! empty( $payload['final'] ) ) {
			clearstatcache( true, $transfer['path'] );
			if ( $total_size !== filesize( $transfer['path'] ) || ! hash_equals( $expected_hash, (string) hash_file( 'sha256', $transfer['path'] ) ) || ! In_Synced_Safety::confined_path( $root, $relative ) ) {
				flock( $handle, LOCK_UN );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
				fclose( $handle );
				wp_delete_file( $transfer['path'] );
				delete_transient( $transfer_key );
				return new WP_Error( 'in_synced_file_integrity', __( 'The staged file failed size, hash or path verification. The existing file was preserved.', 'in-synced' ), array( 'status' => 500 ) );
			}
			$permissions = is_file( $destination ) ? ( fileperms( $destination ) & 0777 ) : ( defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Preserve existing target permissions or standard WordPress file permissions before committing the verified private stage.
			if ( ! chmod( $transfer['path'], $permissions ) || ! In_Synced_Safety::atomic_replace( $transfer['path'], $destination ) ) {
				flock( $handle, LOCK_UN );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
				fclose( $handle );
				wp_delete_file( $transfer['path'] );
				delete_transient( $transfer_key );
				return new WP_Error( 'in_synced_file_replace_failed', __( 'Could not atomically replace the target file. Private staging and the target must remain on the same filesystem; check IN_SYNCED_BACKUP_DIR. The existing file was preserved.', 'in-synced' ), array( 'status' => 500 ) );
			}
			delete_transient( $transfer_key );
			if ( ! empty( $payload['mtime'] ) && is_int( $payload['mtime'] ) && $payload['mtime'] > 0 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Administrator-approved root sync uses verified confined paths; native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
				touch( $destination, $payload['mtime'] );
			}
		}
		flock( $handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
		fclose( $handle );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	private function remote_request( string $route, array $payload, array &$log, int $timeout = 45 ) {
		$settings   = $this->get_settings();
		$remote_url = $this->normalize_remote_base_url( (string) $settings['remote_url'] );
		$secret     = $this->get_shared_secret( $settings );

		if ( ! $remote_url ) {
			return new WP_Error( 'in_synced_missing_remote', __( 'Remote URL is not configured.', 'in-synced' ) );
		}

		if ( strtolower( (string) wp_parse_url( $remote_url, PHP_URL_SCHEME ) ) !== 'https' && ! ( defined( 'IN_SYNCED_ALLOW_INSECURE_HTTP' ) && IN_SYNCED_ALLOW_INSECURE_HTTP ) ) {
			return new WP_Error( 'in_synced_https_required', __( 'The remote URL must use HTTPS. HTTP requires an explicit IN_SYNCED_ALLOW_INSECURE_HTTP development override.', 'in-synced' ) );
		}
		if ( ! $secret ) {
			return new WP_Error( 'in_synced_missing_secret', __( 'Shared secret is not configured.', 'in-synced' ) );
		}

		$rest_route = '/' . self::REST_NAMESPACE . '/' . trim( $route, '/' );
		if ( ! in_array( $route, array( 'end-db', 'end-sync' ), true ) && $this->cancellation_requested() ) {
			return new WP_Error( 'in_synced_cancelled', __( 'Push stopped. Completed changes remain on the receiver; use its backup to restore database changes.', 'in-synced' ) );
		}
		if ( in_array( $route, array( 'table-batch', 'validate-batch' ), true ) && isset( $payload['rows'] ) ) {
			$payload['rows'] = In_Synced_Safety::encode_rows( $payload['rows'] );
		}
		if ( $this->active_session_id ) {
			$payload['session_id'] = $this->active_session_id;
			$sender_lease          = $this->claim_lease( 'in_synced_site_lease', $this->active_session_id );
			if ( is_wp_error( $sender_lease ) ) {
				return $sender_lease;
			}
		}
		// Native encoding fails on malformed metadata instead of silently rewriting bytes.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fail on invalid metadata instead of WordPress silently repairing UTF-8; row/file bytes are base64 encoded separately.
		$body = json_encode( $payload );
		if ( $body === false ) {
			return new WP_Error( 'in_synced_json_failed', __( 'The request could not be encoded as JSON. Check table schema text and reduce the batch size.', 'in-synced' ) );
		}
		if ( strlen( $body ) > self::MAX_REQUEST_BYTES ) {
			return new WP_Error( 'in_synced_payload_large', __( 'The sync request exceeds the 8 MB limit. Reduce the batch size.', 'in-synced' ) );
		}
		$nonce     = wp_generate_uuid4();
		$timestamp = time();
		$url       = trailingslashit( $remote_url ) . 'wp-json/' . self::REST_NAMESPACE . '/' . trim( $route, '/' );
		$response  = wp_remote_post(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'          => 'application/json',
					'X-In-Synced-Timestamp' => (string) $timestamp,
					'X-In-Synced-Nonce'     => $nonce,
					'X-In-Synced-Signature' => In_Synced_Safety::signature( 'POST', $rest_route, (string) $timestamp, $nonce, $body, $secret ),
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( in_array( $route, array( 'begin-sync', 'begin-db', 'begin-content', 'table-batch', 'file-chunk', 'end-sync' ), true ) ) {
				$this->receiver_uncertain = true; }
			return $response;
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$raw      = (string) wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw, true );
		$mutating = in_array( $route, array( 'begin-sync', 'begin-db', 'begin-content', 'table-batch', 'file-chunk', 'end-sync' ), true );
		if ( $mutating && ( $code >= 500 || ( $code >= 200 && $code < 300 && ( ! is_array( $decoded ) || empty( $decoded['ok'] ) ) ) ) ) {
			$this->receiver_uncertain = true; }
		if ( $code >= 300 && $code < 400 ) {
			return new WP_Error( 'in_synced_redirect_denied', __( 'The receiver redirected the signed request. Set the remote URL to its final HTTPS address; redirects are refused.', 'in-synced' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			if ( is_array( $decoded ) && isset( $decoded['message'] ) && is_scalar( $decoded['message'] ) ) {
				$message = (string) $decoded['message'];
			} else {
				$message = $raw;
			}

			$message = trim( wp_strip_all_tags( $message ) );
			if ( function_exists( 'mb_substr' ) && mb_strlen( $message ) > 300 ) {
				$message = mb_substr( $message, 0, 300 ) . ' [truncated]';
			} elseif ( strlen( $message ) > 300 ) {
				$message = substr( $message, 0, 300 ) . ' [truncated]';
			}

			/* translators: %1$s: sync detail, %2$s: sync detail, %3$s: sync detail. */
			return new WP_Error( 'in_synced_remote_error', sprintf( __( 'Remote %1$s failed (%2$s): %3$s', 'in-synced' ), $route, $code, $message ) );
		}

		if ( ! is_array( $decoded ) || empty( $decoded['ok'] ) ) {
			return new WP_Error( 'in_synced_bad_response', __( 'The receiver returned an invalid sync acknowledgement.', 'in-synced' ) );
		}
		if ( in_array( $route, array( 'table-batch', 'validate-batch' ), true ) && ( ! empty( $decoded['skipped'] ) || (int) ( $decoded['inserted'] ?? -1 ) !== count( $payload['rows'] ) ) ) {
			return new WP_Error( 'in_synced_incomplete_batch', __( 'The receiver did not import every row. The push stopped to prevent a false success report.', 'in-synced' ) );
		}
		return $decoded;
	}

	private function require_admin_action( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage In Synced.', 'in-synced' ) );
		}

		check_admin_referer( $nonce_action );
	}

	private function require_ajax_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage In Synced.', 'in-synced' ) ), 403 );
		}

		check_ajax_referer( 'in_synced_ajax', 'nonce' );
	}

	private function runtime_overrides_from_request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';

		return array(
			'scope'             => $scope,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
			'mode'              => isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
			'sync_database'     => isset( $_POST['sync_database'] ) ? (bool) absint( $_POST['sync_database'] ) : null,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
			'sync_media'        => isset( $_POST['sync_media'] ) ? (bool) absint( $_POST['sync_media'] ) : null,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
			'sync_theme'        => isset( $_POST['sync_theme'] ) ? (bool) absint( $_POST['sync_theme'] ) : null,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
			'incremental_files' => isset( $_POST['incremental_files'] ) ? (bool) absint( $_POST['incremental_files'] ) : null,
		);
	}

	private function apply_runtime_overrides( array $profile, array $overrides ): array {
		$scope = isset( $overrides['scope'] ) ? sanitize_key( (string) $overrides['scope'] ) : '';
		$scope = array(
			'theme'   => 'files',
			'content' => 'database',
		)[ $scope ] ?? $scope;

		if ( $scope ) {
			// Canonical names are files (roots only) and media_files. Legacy aliases
			// are resolved in this single location.
			$profile['sync_database'] = in_array( $scope, array( 'full', 'database', 'content', 'content_media' ), true );
			$profile['sync_media']    = in_array( $scope, array( 'full', 'media', 'media_files', 'content_media' ), true );
			$profile['sync_theme']    = in_array( $scope, array( 'full', 'files', 'media_files' ), true );
		}

		foreach ( array( 'sync_database', 'sync_media', 'sync_theme', 'incremental_files' ) as $key ) {
			if ( array_key_exists( $key, $overrides ) && $overrides[ $key ] !== null ) {
				$profile[ $key ] = (bool) $overrides[ $key ];
			}
		}

		if ( ! empty( $overrides['database_scope'] ) ) {
			$profile['database_scope'] = $overrides['database_scope'] === 'post_types' ? 'post_types' : 'tables';
		}

		if ( ! empty( $overrides['post_type_filter'] ) ) {
			$profile['post_type_filter'] = implode( "\n", $this->sanitize_post_types( $overrides['post_type_filter'] ) );
		}

		if ( ! empty( $overrides['mode'] ) ) {
			$profile['mode'] = $overrides['mode'] === 'replace' ? 'replace' : 'upsert';
		}

		return $profile;
	}

	private function get_progress( string $run_id ): array {
		if ( ! $run_id ) {
			return array(
				'status'  => 'idle',
				'phase'   => '',
				'message' => '',
				'percent' => 0,
				'log'     => array(),
			);
		}

		$progress = get_transient( self::PROGRESS_TRANSIENT_PREFIX . $run_id );

		return is_array( $progress ) ? $progress : array(
			'status'  => 'idle',
			'phase'   => '',
			'message' => __( 'No progress has been recorded for this run.', 'in-synced' ),
			'percent' => 0,
			'log'     => array(),
		);
	}

	private function update_progress( string $run_id, array $updates ): void {
		if ( ! $run_id ) {
			return;
		}

		$progress               = $this->get_progress( $run_id );
		$progress               = array_merge( $progress, $updates );
		$progress['updated_at'] = gmdate( 'c' );
		$progress['percent']    = max( 0, min( 100, (int) ( $progress['percent'] ?? 0 ) ) );

		if ( isset( $progress['log'] ) && is_array( $progress['log'] ) && count( $progress['log'] ) > 200 ) {
			$progress['log'] = array_slice( $progress['log'], -200 );
		}

		set_transient( self::PROGRESS_TRANSIENT_PREFIX . $run_id, $progress, self::PROGRESS_TTL );
	}

	private function scale_percent( int $current, int $total, int $start, int $end ): int {
		if ( $total <= 0 ) {
			return $end;
		}

		$ratio = max( 0, min( 1, $current / $total ) );

		return (int) round( $start + ( ( $end - $start ) * $ratio ) );
	}

	private function redirect_admin( array $args = array() ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php?page=in-synced' ) ) );
		exit;
	}

	private function sanitize_shared_secret( $value ): string {
		return is_string( $value ) && strlen( $value ) <= 512 && ! preg_match( '/[\x00-\x1f\x7f]/', $value ) ? trim( $value ) : '';
	}

	private function sanitize_url_field( string $field ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Handler calls require_admin_action/require_ajax_action before this input; private request helpers are used only by those guarded handlers.
		$url = isset( $_POST[ $field ] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST[ $field ] ) ) ) : '';

		return $field === 'remote_url' ? $this->normalize_remote_base_url( $url ) : $url;
	}

	private function normalize_remote_base_url( string $url ): string {
		$url = untrailingslashit( trim( $url ) );
		$url = preg_replace( '#/(wp-admin|wp-json)(/.*)?$#', '', $url );

		return $url ? esc_url_raw( $url ) : '';
	}

	private function sanitize_theme_slug( $value ): string {
		$value = trim( (string) wp_unslash( $value ) );

		if ( ! preg_match( '/^[A-Za-z0-9._-]+$/', $value ) ) {
			return '';
		}

		if ( strpos( $value, '.' ) === 0 ) {
			return '';
		}

		return $value;
	}

	private function get_shared_secret( array $settings ): string {
		if ( defined( 'IN_SYNCED_SHARED_SECRET' ) && IN_SYNCED_SHARED_SECRET ) {
			$constant = (string) IN_SYNCED_SHARED_SECRET;

			return strlen( $constant ) >= 32 ? $constant : '';
		}

		$secret = (string) ( $settings['shared_secret'] ?? '' );
		return strlen( $secret ) >= 32 ? $secret : '';
	}

	private function bool_label( $value ): string {
		return $value ? __( 'yes', 'in-synced' ) : __( 'no', 'in-synced' );
	}

	private function secret_fingerprint( array $settings ): string {
		$secret = $this->get_shared_secret( $settings );

		return $secret ? substr( hash( 'sha256', $secret ), 0, 12 ) : '';
	}

	private function table_suffix( string $table ): string {
		global $wpdb;

		if ( strpos( $table, $wpdb->prefix ) === 0 ) {
			return substr( $table, strlen( $wpdb->prefix ) );
		}

		return $table;
	}

	private function summarize_files( string $base, array $excludes = array(), int $since = 0, array $includes = array() ): array {
		$files  = $this->list_files( $base, $excludes, $since, $includes );
		$bytes  = 0;
		$sample = array();

		foreach ( $files as $file ) {
			$size   = (int) filesize( $file );
			$bytes += $size;

			if ( count( $sample ) < 24 ) {
				$sample[] = array(
					'path'       => $this->relative_path( $base, $file ),
					'size'       => $size,
					'size_label' => size_format( $size, 1 ),
					'modified'   => gmdate( 'c', filemtime( $file ) ?: time() ),
				);
			}
		}

		return array(
			'file_count'  => count( $files ),
			'bytes'       => $bytes,
			'bytes_label' => size_format( $bytes, 1 ),
			'sample'      => $sample,
		);
	}

	private function select_tables( array $profile ): array {
		global $wpdb;

		$like              = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables            = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		$included_suffixes = $this->line_list( $profile['include_tables'] ?? '' );
		$excluded_suffixes = $this->line_list( $profile['exclude_tables'] ?? '' );

		// User accounts are never transferred by the sender or receiver.
		$excluded_suffixes[] = 'users';
		$excluded_suffixes[] = 'usermeta';

		return array_values(
			array_filter(
				$tables,
				function ( $table ) use ( $wpdb, $included_suffixes, $excluded_suffixes ) {
					$suffix = $this->table_suffix( $table );

					if ( $included_suffixes && ! in_array( $suffix, $included_suffixes, true ) ) {
						return false;
					}

					foreach ( $excluded_suffixes as $suffix ) {
						if ( $table === $wpdb->prefix . $suffix ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	private function selected_post_types( array $profile, bool $fallback_all = false ): array {
		global $wpdb;

		$post_types = $this->sanitize_post_types( $this->line_list( (string) ( $profile['post_type_filter'] ?? '' ) ) );

		if ( ! $post_types && $fallback_all ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$post_types = (array) $wpdb->get_col( 'SELECT DISTINCT post_type FROM `' . $this->escape_identifier( $wpdb->posts ) . "` WHERE post_type NOT IN ('revision', 'nav_menu_item')" );
			$post_types = $this->sanitize_post_types( $post_types );
		}

		return $post_types;
	}

	private function sanitize_post_types( $value ): array {
		$items = is_array( $value ) ? $value : $this->line_list( (string) $value );
		$clean = array();

		foreach ( $items as $item ) {
			$item = sanitize_key( (string) $item );
			if ( $item !== '' ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private function sanitize_table_suffixes( $value ): array {
		$items = is_array( $value ) ? $value : $this->line_list( (string) $value );
		$clean = array();

		foreach ( $items as $item ) {
			$item = trim( (string) $item );
			if ( $item !== '' && preg_match( '/^[A-Za-z0-9_]+$/', $item ) ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private function build_content_inventory( array $profile = array(), array $post_types = array(), array $table_suffixes = array() ): array {
		global $wpdb;

		if ( ! $post_types ) {
			$post_types = $this->selected_post_types( $profile, true );
		}

		if ( ! $table_suffixes && $profile ) {
			$table_suffixes = array_map(
				function ( $table ) {
					return $this->table_suffix( $table );
				},
				$this->select_tables( $profile )
			);
		}

		$inventory = array(
			'ok'         => true,
			'site_url'   => home_url(),
			'post_types' => array(),
			'tables'     => array(),
		);

		foreach ( $post_types as $post_type ) {
			$inventory['post_types'][ $post_type ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
					'SELECT COUNT(*) FROM `' . $this->escape_identifier( $wpdb->posts ) . '` WHERE post_type = %s',
					$post_type
				)
			);
		}

		foreach ( $table_suffixes as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			if ( ! $this->is_allowed_table( $table ) || ! $this->table_exists( $table ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$inventory['tables'][ $suffix ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->escape_identifier( $table ) . '`' );
		}

		return $inventory;
	}

	private function merge_content_inventory( array $local, array $remote ): array {
		$post_type_keys = array_unique( array_merge( array_keys( $local['post_types'] ?? array() ), array_keys( $remote['post_types'] ?? array() ) ) );
		sort( $post_type_keys );
		$table_keys = array_unique( array_merge( array_keys( $local['tables'] ?? array() ), array_keys( $remote['tables'] ?? array() ) ) );
		sort( $table_keys );

		return array(
			'remote_site' => (string) ( $remote['site_url'] ?? '' ),
			'post_types'  => array_map(
				static function ( $key ) use ( $local, $remote ) {
					return array(
						'post_type' => $key,
						'local'     => (int) ( $local['post_types'][ $key ] ?? 0 ),
						'remote'    => (int) ( $remote['post_types'][ $key ] ?? 0 ),
					);
				},
				$post_type_keys
			),
			'tables'      => array_map(
				static function ( $key ) use ( $local, $remote ) {
					return array(
						'label'  => $key,
						'local'  => (int) ( $local['tables'][ $key ] ?? 0 ),
						'remote' => (int) ( $remote['tables'][ $key ] ?? 0 ),
					);
				},
				$table_keys
			),
		);
	}

	private function content_table_queries( array $post_types ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
		$type_where        = $post_types ? $wpdb->prepare( 'post_type IN (' . $placeholders . ')', $post_types ) : '0=1';
		$posts             = 'SELECT ID FROM `' . $this->escape_identifier( $wpdb->posts ) . '` WHERE ' . $type_where;
		$post_id_where     = $type_where;
		$object_id_where   = 'object_id IN (' . $posts . ')';
		$postmeta_where    = 'post_id IN (' . $posts . ')';
		$comment_where     = 'comment_post_ID IN (' . $posts . ')';
		$commentmeta_where = 'comment_id IN (SELECT comment_ID FROM `' . $this->escape_identifier( $wpdb->comments ) . '` WHERE ' . $comment_where . ')';

		$queries = array(
			$wpdb->posts              => array( 'where' => $post_id_where ),
			$wpdb->postmeta           => array( 'where' => $postmeta_where ),
			$wpdb->term_relationships => array( 'where' => $object_id_where ),
			$wpdb->comments           => array( 'where' => $comment_where ),
			$wpdb->commentmeta        => array( 'where' => $commentmeta_where ),
			$wpdb->terms              => array( 'where' => '1=1' ),
			$wpdb->term_taxonomy      => array( 'where' => '1=1' ),
			$wpdb->termmeta           => array( 'where' => '1=1' ),
		);

		return array_filter(
			$queries,
			function ( $query, $table ) {
				return $table && $this->table_exists( $table );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	private function repair_row_conflict( string $table, array $row, array $post_types ) {
		global $wpdb;
		if ( ! $post_types ) {
			return true;
		}
		$parents = array(
			$wpdb->posts              => array( 'ID', 'ID', $wpdb->posts ),
			$wpdb->postmeta           => array( 'meta_id', 'post_id', $wpdb->postmeta ),
			$wpdb->comments           => array( 'comment_ID', 'comment_post_ID', $wpdb->comments ),
			$wpdb->term_relationships => array( 'object_id', 'object_id', $wpdb->term_relationships ),
		);
		if ( $table === $wpdb->commentmeta && isset( $row['meta_id'] ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$comment_id = $wpdb->get_var( $wpdb->prepare( 'SELECT comment_id FROM `' . $this->escape_identifier( $wpdb->commentmeta ) . '` WHERE meta_id = %d', (int) $row['meta_id'] ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$post_id = $comment_id ? $wpdb->get_var( $wpdb->prepare( 'SELECT comment_post_ID FROM `' . $this->escape_identifier( $wpdb->comments ) . '` WHERE comment_ID = %d', (int) $comment_id ) ) : null;
		} elseif ( isset( $parents[ $table ] ) ) {
			[$key, $parent, $parent_table] = $parents[ $table ];
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$post_id = isset( $row[ $key ] ) ? $wpdb->get_var( $wpdb->prepare( 'SELECT `' . $parent . '` FROM `' . $this->escape_identifier( $parent_table ) . '` WHERE `' . $key . '` = %d LIMIT 1', (int) $row[ $key ] ) ) : null;
		} else {
			return true;
		}
		if ( $post_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$type = $wpdb->get_var( $wpdb->prepare( 'SELECT post_type FROM `' . $this->escape_identifier( $wpdb->posts ) . '` WHERE ID = %d', (int) $post_id ) );
			if ( $type !== null && ! in_array( $type, $post_types, true ) ) {
				return new WP_Error( 'in_synced_repair_collision', __( 'Repair stopped: a source row ID belongs to unrelated receiver content. Use sites with matching ID lineage or resolve the collision first.', 'in-synced' ), array( 'status' => 409 ) );
			}
		}
		return true;
	}

	private function selected_post_ids( array $post_types ): array {
		global $wpdb;

		if ( ! $post_types ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
					'SELECT ID FROM `' . $this->escape_identifier( $wpdb->posts ) . "` WHERE post_type IN ($placeholders)",
					$post_types
				)
			)
		);
	}

	private function selected_comment_ids( array $post_ids ): array {
		global $wpdb;

		if ( ! $post_ids ) {
			return array();
		}

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
				'SELECT comment_ID FROM `' . $this->escape_identifier( $wpdb->comments ) . '` WHERE comment_post_ID IN (' . implode( ',', array_map( 'intval', $post_ids ) ) . ')'
			)
		);
	}

	private function delete_remote_post_type_rows( array $post_types ) {
		global $wpdb;
		$queries = $this->content_table_queries( $post_types );
		foreach ( array( $wpdb->commentmeta, $wpdb->comments, $wpdb->postmeta, $wpdb->term_relationships, $wpdb->posts ) as $table ) {
			$lease = $this->claim_lease( 'in_synced_site_lease', $this->receiver_session_id );
			if ( is_wp_error( $lease ) ) {
				return $lease; }
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			if ( isset( $queries[ $table ] ) && $wpdb->query( 'DELETE FROM `' . $this->escape_identifier( $table ) . '` WHERE ' . $queries[ $table ]['where'] ) === false ) {
				return new WP_Error( 'in_synced_repair_delete_failed', __( 'Content repair deletion failed. Restore the reported backup before retrying.', 'in-synced' ), array( 'status' => 500 ) );
			}
		}
		return true;
	}

	private function select_file_roots( array $profile ): array {
		$roots = $this->line_list( (string) ( $profile['file_roots'] ?? '' ) );

		if ( ! $roots && ! empty( $profile['theme_slug'] ) ) {
			$roots[] = 'themes/' . $this->sanitize_theme_slug( $profile['theme_slug'] );
		}

		return $this->sanitize_file_roots( $roots );
	}

	private function sanitize_file_roots_text( $value ): string {
		return implode( "\n", $this->sanitize_file_roots( $this->line_list( (string) wp_unslash( $value ) ) ) );
	}

	private function sanitize_file_roots( array $roots ): array {
		$clean = array();

		foreach ( $roots as $root ) {
			$root = $this->sanitize_relative_path( $root );
			if ( ! $root ) {
				continue;
			}

			$parts = explode( '/', $root );
			$top   = $parts[0] ?? '';
			if ( ! in_array( $top, array( 'themes', 'plugins', 'mu-plugins', 'languages' ), true ) ) {
				continue;
			}

			$clean[] = $root;
		}

		return array_values( array_unique( $clean ) );
	}

	private function preview_file_roots_label( array $profile ): string {
		$roots = $this->select_file_roots( $profile );

		return $roots ? implode(
			', ',
			array_map(
				static function ( $root ) {
					return 'wp-content/' . $root;
				},
				$roots
			)
		) : 'No file roots configured';
	}

	private function file_root_is_allowed( string $root, array $settings ): bool {
		$root    = $this->sanitize_relative_path( $root );
		$allowed = $this->sanitize_file_roots( $this->line_list( (string) ( $settings['allowed_file_roots'] ?? '' ) ) );

		if ( ! $allowed && ! empty( $settings['theme_slug'] ) ) {
			$allowed[] = 'themes/' . $this->sanitize_theme_slug( $settings['theme_slug'] );
		}

		return $root && in_array( $root, $allowed, true );
	}

	private function line_list( string $text ): array {
		$items = preg_split( '/\R+/', $text ) ?: array();
		$items = array_map( 'trim', $items );
		$items = array_filter(
			$items,
			static function ( $item ) {
				return $item !== '';
			}
		);

		return array_values( array_unique( $items ) );
	}

	private function replacement_pairs( array $settings ): array {
		$local  = untrailingslashit( (string) ( $settings['local_url'] ?: home_url() ) );
		$remote = untrailingslashit( (string) ( $settings['remote_public_url'] ?: $settings['remote_url'] ) );

		if ( ! $local || ! $remote || $local === $remote ) {
			return array();
		}

		$pairs = array(
			$local                           => $remote,
			trailingslashit( $local )        => trailingslashit( $remote ),
			str_replace( '/', '\/', $local ) => str_replace( '/', '\/', $remote ),
			str_replace( '/', '\/', trailingslashit( $local ) ) => str_replace( '/', '\/', trailingslashit( $remote ) ),
		);

		return array_unique( $pairs );
	}

	private function binary_columns( string $table ): array {
		global $wpdb;
		if ( ! isset( $this->binary_columns_cache[ $table ] ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$columns                              = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $this->escape_identifier( $table ) . '`', ARRAY_A );
			$this->binary_columns_cache[ $table ] = array();
			foreach ( (array) $columns as $column ) {
				if ( preg_match( '/^(?:tinyblob|blob|mediumblob|longblob|binary|varbinary|bit)\b/i', (string) $column['Type'] ) ) {
					$this->binary_columns_cache[ $table ][] = $column['Field'];
				}
			}
		}
		return $this->binary_columns_cache[ $table ];
	}

	private function transform_rows_for_remote( string $table, array $rows, array $replacements ): array {
		global $wpdb;

		$binary_columns = $this->binary_columns( $table );
		$out            = array();
		foreach ( $rows as $row ) {
			if ( $this->is_sync_option_row( $this->map_table_name( $table, $wpdb->prefix, $wpdb->prefix ), $row ) ) {
				continue;
			}

			$new_row = array();
			foreach ( $row as $key => $value ) {
				$new_row[ $key ] = is_string( $value ) && ! in_array( $key, $binary_columns, true ) ? $this->replace_serialized_value( $value, $replacements ) : $value;
			}
			$out[] = $new_row;
		}

		return $out;
	}

	private function replace_serialized_value( string $value, array $replacements ): string {
		if ( ! $replacements ) {
			return $value;
		}

		if ( is_serialized( $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid serialized local data must pass through unchanged; optional private-directory defense files do not control backup integrity.
			$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );

			// A serialized-looking value that fails to decode must be sent through untouched,
			// not rewritten to "b:0;" by a failed reserialize round trip.
			if ( $decoded === false && trim( $value ) !== 'b:0;' ) {
				return $value;
			}

			if ( $this->contains_serialized_object( $decoded ) ) {
				return $value;
			}
			return maybe_serialize( $this->replace_recursive( $decoded, $replacements ) );
		}

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $value );
	}

	private function contains_serialized_object( $value, int $depth = 0 ): bool {
		if ( $depth >= 64 ) {
			return true; }
		if ( is_object( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->contains_serialized_object( $item, $depth + 1 ) ) {
					return true; }
			}
		}
		return false;
	}

	private function replace_recursive( $value, array $replacements ) {
		if ( is_string( $value ) ) {
			return str_replace( array_keys( $replacements ), array_values( $replacements ), $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->replace_recursive( $item, $replacements );
			}
		} elseif ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$value->$key = $this->replace_recursive( $item, $replacements );
			}
		}

		return $value;
	}

	private function get_create_table_sql( string $table ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Explicit administrator-authorized sync; CREATE is constrained to one validated schema statement before mutation.
		$row = $wpdb->get_row( 'SHOW CREATE TABLE `' . $this->escape_identifier( $table ) . '`', ARRAY_N );

		return is_array( $row ) && isset( $row[1] ) ? (string) $row[1] : '';
	}

	private function rewrite_create_table_sql( string $sql, string $source, string $target ): string {
		return In_Synced_Safety::create_table_sql( $sql, $source, $target );
	}

	private function map_table_name( string $table, string $source_prefix, string $target_prefix ): string {
		if ( $source_prefix && strpos( $table, $source_prefix ) === 0 ) {
			return $target_prefix . substr( $table, strlen( $source_prefix ) );
		}

		return $table;
	}

	private function is_allowed_table( string $table ): bool {
		global $wpdb;

		if ( ! $this->table_is_prefixed( $table ) ) {
			return false;
		}

		// Receiver-side guard: user tables are never accepted from a remote payload,
		// so even a signed request cannot truncate or overwrite them.
		return ! $this->is_user_table( $table );
	}

	private function table_is_prefixed( string $table ): bool {
		global $wpdb;

		return (bool) preg_match( '/^[A-Za-z0-9_]+$/', $table ) && strpos( $table, $wpdb->prefix ) === 0;
	}

	private function is_user_table( string $table ): bool {
		global $wpdb;

		$suffix = $table;
		if ( strpos( $suffix, $wpdb->prefix ) === 0 ) {
			$suffix = substr( $suffix, strlen( $wpdb->prefix ) );
		}

		return in_array( $table, array( $wpdb->users, $wpdb->usermeta ), true ) || in_array( $suffix, array( 'users', 'usermeta' ), true );
	}

	private function get_table_columns( string $table ): array {
		global $wpdb;

		if ( isset( $this->table_columns_cache[ $table ] ) ) {
			return $this->table_columns_cache[ $table ];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
		$columns                             = (array) $wpdb->get_col( 'SHOW COLUMNS FROM `' . $this->escape_identifier( $table ) . '`' );
		$this->table_columns_cache[ $table ] = array_map( 'strval', $columns );

		return $this->table_columns_cache[ $table ];
	}

	private function primary_key_columns( string $table ): array {
		global $wpdb;

		if ( isset( $this->primary_key_cache[ $table ] ) ) {
			return $this->primary_key_cache[ $table ];
		}

		$columns = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
		$keys = (array) $wpdb->get_results( 'SHOW KEYS FROM `' . $this->escape_identifier( $table ) . "` WHERE Key_name = 'PRIMARY'", ARRAY_A );

		usort(
			$keys,
			static function ( $a, $b ) {
				return (int) ( $a['Seq_in_index'] ?? 0 ) <=> (int) ( $b['Seq_in_index'] ?? 0 );
			}
		);

		foreach ( $keys as $key ) {
			if ( ! empty( $key['Column_name'] ) ) {
				$columns[] = (string) $key['Column_name'];
			}
		}

		$this->primary_key_cache[ $table ] = $columns;

		return $columns;
	}

	private function table_order_clause( string $table ): string {
		$columns = $this->primary_key_columns( $table );
		if ( ! $columns ) {
			return '';
		}

		$quoted = array_map(
			function ( $column ) {
				return '`' . $this->escape_identifier( $column ) . '`';
			},
			$columns
		);

		return ' ORDER BY ' . implode( ', ', $quoted );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	private function backup_tables( array $tables ): string {
		global $wpdb;

		$directory = $this->private_storage_directory();
		if ( ! $directory || ! $tables ) {
			return '';
		}
		$file    = trailingslashit( $directory ) . 'db-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 24, false, false ) . '.sql';
		$partial = $file . '.partial';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
		$handle = fopen( $partial, 'xb' );
		if ( ! $handle ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
		chmod( $partial, 0600 );
		$digest   = hash_init( 'sha256' );
		$bytes    = 0;
		$write    = static function ( $data ) use ( $handle, $digest, &$bytes ) {
			if ( ! In_Synced_Safety::write_all( $handle, $data ) ) {
				return false;
			}
			hash_update( $digest, $data );
			$bytes += strlen( $data );
			return true;
		};
		$ok       = $write( '-- In Synced verified backup ' . gmdate( 'c' ) . "\nSET FOREIGN_KEY_CHECKS=0;\n\n" );
		$sections = 0;
		foreach ( array_unique( $tables ) as $table ) {
			if ( ! $ok || ! $this->is_allowed_table( $table ) || ! $this->table_exists( $table ) ) {
				$ok = false;
				break;
			}
			$schema = $this->get_create_table_sql( $table );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$count = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->escape_identifier( $table ) . '`' );
			if ( $schema === '' || $count === null || $wpdb->last_error ) {
				$ok = false;
				break;
			}
			$ok      = $write( 'DROP TABLE IF EXISTS `' . $this->escape_identifier( $table ) . "`;\n" . $schema . ";\n\n" );
			$emitted = 0;
			for ( $offset = 0; $ok && $offset < (int) $count; $offset += 500 ) {
				if ( $this->receiver_session_id && is_wp_error( $this->claim_lease( 'in_synced_site_lease', $this->receiver_session_id ) ) ) {
					$ok = false;
					break; }
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
				$rows = $wpdb->get_results( 'SELECT * FROM `' . $this->escape_identifier( $table ) . '`' . $this->table_order_clause( $table ) . ' LIMIT ' . $offset . ', 500', ARRAY_A );
				if ( ! is_array( $rows ) || $wpdb->last_error || ! $rows ) {
					$ok = false;
					break;
				}
				foreach ( $rows as $row ) {
					$columns = array_map(
						function ( $column ) {
							return '`' . $this->escape_identifier( $column ) . '`';
						},
						array_keys( $row )
					);
					$values  = array_map( array( $this, 'sql_literal' ), array_values( $row ) );
					if ( ! $write( 'INSERT INTO `' . $this->escape_identifier( $table ) . '` (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ");\n" ) ) {
						$ok = false;
						break;
					}
					++$emitted;
				}
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers are validated and escaped; data uses placeholders, integer casts or byte-safe UNHEX literals.
			$after = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->escape_identifier( $table ) . '`' );
			if ( $wpdb->last_error || $emitted !== (int) $count || $after === null || (int) $after !== (int) $count ) {
				$ok = false;
			}
			++$sections;
			$ok = $ok && $write( "\n" );
		}
		$ok            = $ok && $sections === count( array_unique( $tables ) ) && $write( "SET FOREIGN_KEY_CHECKS=1;\n-- In Synced backup complete\n" );
		$expected_hash = hash_final( $digest );
		$flushed       = fflush( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
		$closed = fclose( $handle );
		clearstatcache( true, $partial );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Administrator-approved root sync uses verified confined paths; native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
		if ( ! $ok || ! $flushed || ! $closed || ! is_file( $partial ) || filesize( $partial ) !== $bytes || ! hash_equals( $expected_hash, (string) hash_file( 'sha256', $partial ) ) || ! rename( $partial, $file ) ) {
			wp_delete_file( $partial );
			return '';
		}
		$this->prune_backups( $directory );
		return $file;
	}

	private function backup_directory(): string {
		$base = defined( 'IN_SYNCED_BACKUP_DIR' ) ? (string) IN_SYNCED_BACKUP_DIR : dirname( rtrim( ABSPATH, '/\\' ) ) . '/in-synced-private-backups';
		if ( ! $base || ! preg_match( '#^(?:/|[A-Za-z]:[\\/])#', $base ) ) {
			return '';
		}
		return trailingslashit( $base ) . substr( hash( 'sha256', home_url() ), 0, 16 );
	}

	private function private_storage_directory( string $subdirectory = '' ): string {
		$directory = $this->backup_directory();
		if ( ! $directory || ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) ) {
			return '';
		}
		$uploads      = wp_get_upload_dir();
		$public_roots = array( ABSPATH, WP_CONTENT_DIR, WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, get_theme_root(), $uploads['basedir'] );
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && $_SERVER['DOCUMENT_ROOT'] !== '' ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server-managed root is accepted only when scalar, absolute and resolvable; canonical private-root checks enforce its boundary.
			$document_root = is_string( $_SERVER['DOCUMENT_ROOT'] ) ? wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : '';
			if ( ! preg_match( '#^(?:/|[A-Za-z]:[\\\\/])#', $document_root ) || ! realpath( $document_root ) ) {
				return '';
			}
			$public_roots[] = $document_root;
		}
		if ( ! In_Synced_Safety::private_directory( $directory, $public_roots ) ) {
			return '';
		}
		$directory = realpath( $directory );
		if ( $subdirectory !== '' ) {
			if ( ! In_Synced_Safety::confined_path( $directory, $subdirectory ) ) {
				return '';
			}
			$directory .= DIRECTORY_SEPARATOR . $subdirectory;
			if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
				return '';
			}
			if ( ! In_Synced_Safety::private_directory( $directory, $public_roots ) || is_link( $directory ) ) {
				return '';
			}
			$directory = realpath( $directory );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Plugin-owned private storage uses native streams and restrictive permissions; web deny files cannot substitute for the canonical root check.
		if ( ! is_writable( $directory ) || ! chmod( $directory, 0700 ) ) {
			return '';
		}
		$this->protect_backup_directory( $directory );
		return $directory;
	}

	private function protect_backup_directory( string $directory ): void {
		$htaccess = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Invalid serialized local data must pass through unchanged; optional private-directory defense files do not control backup integrity.
			@file_put_contents(
				$htaccess,
				"# In Synced: deny web access to private backups and file stages.\n\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n"
			);
		}

		$index = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Invalid serialized local data must pass through unchanged; optional private-directory defense files do not control backup integrity.
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	private function prune_backups( string $directory ): void {
		$keep = (int) apply_filters( 'in_synced_backup_retention', 5 );
		if ( $keep < 1 ) {
			return;
		}

		$files = glob( trailingslashit( $directory ) . 'db-*.sql' );
		if ( ! $files || count( $files ) <= $keep ) {
			return;
		}

		usort(
			$files,
			static function ( $a, $b ) {
				$a_time = filemtime( $a ) ?: 0;
				$b_time = filemtime( $b ) ?: 0;

				if ( $a_time === $b_time ) {
					return strcmp( $b, $a );
				}

				return $b_time <=> $a_time;
			}
		);

		foreach ( array_slice( $files, $keep ) as $expired ) {
			wp_delete_file( $expired );
		}
	}

	private function sql_literal( $value ): string {
		if ( $value === null ) {
			return 'NULL';
		}

		return "UNHEX('" . bin2hex( (string) $value ) . "')";
	}

	private function is_sync_option_row( string $table, array $row ): bool {
		global $wpdb;

		return $table === $wpdb->options
			&& isset( $row['option_name'] )
			&& ( strpos( (string) $row['option_name'], 'in_synced_' ) === 0
				|| strpos( (string) $row['option_name'], '_transient_' ) === 0
				|| strpos( (string) $row['option_name'], '_site_transient_' ) === 0 );
	}

	private function list_files( string $base, array $excludes = array(), int $since = 0, array $includes = array() ): array {
		$files = array();
		$base  = rtrim( $base, DIRECTORY_SEPARATOR );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$path     = $item->getPathname();
			$relative = $this->relative_path( $base, $path );

			if ( $this->path_is_excluded( $relative, $excludes ) ) {
				if ( $item->isDir() ) {
					$iterator->next();
				}
				continue;
			}

			if ( preg_match( '/^\.in-synced-[A-Za-z0-9]+\.part$/D', basename( $relative ) ) || $item->isLink() || ! In_Synced_Safety::confined_path( $base, $relative ) || ! $item->isFile() ) {
				continue;
			}

			if ( ! $this->path_is_included( $relative, $includes ) ) {
				continue;
			}

			if ( $since && $item->getMTime() <= $since ) {
				continue;
			}

			$files[] = $path;
		}

		sort( $files );

		return $files;
	}

	private function path_is_excluded( string $relative, array $patterns ): bool {
		$relative = str_replace( '\\', '/', $relative );

		foreach ( $patterns as $pattern ) {
			$pattern = trim( str_replace( '\\', '/', $pattern ) );
			if ( $pattern === '' ) {
				continue;
			}

			if ( fnmatch( $pattern, basename( $relative ) ) || fnmatch( $pattern, $relative ) || strpos( $relative . '/', trim( $pattern, '/' ) . '/' ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	private function path_is_included( string $relative, array $patterns ): bool {
		$patterns = array_values(
			array_filter(
				array_map( 'trim', $patterns ),
				static function ( $pattern ) {
					return $pattern !== '';
				}
			)
		);

		if ( ! $patterns ) {
			return true;
		}

		$relative = str_replace( '\\', '/', $relative );

		foreach ( $patterns as $pattern ) {
			$pattern = trim( str_replace( '\\', '/', $pattern ) );
			if ( fnmatch( $pattern, basename( $relative ) ) || fnmatch( $pattern, $relative ) || strpos( $relative . '/', trim( $pattern, '/' ) . '/' ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	private function relative_path( string $base, string $path ): string {
		$base = rtrim( str_replace( '\\', '/', $base ), '/' );
		$path = str_replace( '\\', '/', $path );

		return ltrim( substr( $path, strlen( $base ) ), '/' );
	}

	private function sanitize_relative_path( $path ): string {
		return In_Synced_Safety::relative_path( $path );
	}

	private function last_run_record( string $profile_key ): array {
		$last_runs = (array) get_option( self::LAST_RUNS_OPTION, array() );
		$record    = $last_runs[ $profile_key ] ?? array();

		if ( is_array( $record ) ) {
			return $record;
		}

		// Back-compat: older versions stored a bare timestamp of the last successful run.
		return $record ? array(
			'time'         => (int) $record,
			'status'       => 'complete',
			'success_time' => (int) $record,
			'files_time'   => (int) $record,
		) : array();
	}

	private function record_last_run( string $profile_key, array $profile, string $status, array $stats = array(), string $message = '', int $started_at = 0 ): void {
		$last_runs = (array) get_option( self::LAST_RUNS_OPTION, array() );
		$record    = $this->last_run_record( $profile_key );

		$record['time']   = time();
		$record['status'] = $status === 'failed' ? 'failed' : 'complete';
		$record['mode']   = ( ( $profile['mode'] ?? 'upsert' ) === 'replace' ) ? 'replace' : 'upsert';
		$record['scopes'] = array(
			'database' => ! empty( $profile['sync_database'] ),
			'media'    => ! empty( $profile['sync_media'] ),
			'theme'    => ! empty( $profile['sync_theme'] ),
		);
		$record['counts'] = $stats;

		if ( $message !== '' ) {
			$record['message'] = $message;
		}

		if ( $record['status'] === 'complete' ) {
			$record['success_time'] = $record['time'];

			// The file watermark only advances when file scopes actually pushed, so
			// database-only runs can no longer hide un-sent file changes.
			if ( ! empty( $profile['sync_media'] ) || ! empty( $profile['sync_theme'] ) ) {
				$record['files_time'] = $started_at ?: $record['time'];
			}
		}

		$last_runs[ $profile_key ] = $record;
		update_option( self::LAST_RUNS_OPTION, $last_runs, false );
	}

	private function file_since_timestamp( string $profile_key, array $profile ): int {
		if ( empty( $profile['incremental_files'] ) ) {
			return 0;
		}

		$record = $this->last_run_record( $profile_key );

		// Only runs that actually pushed file scopes advance this watermark, so a
		// database-only run never hides un-sent file changes. When no file-capable
		// run has ever completed, 0 means "send everything".
		return max( 0, (int) ( $record['files_time'] ?? 0 ) - 1 );
	}

	private function escape_identifier( string $identifier ): string {
		return str_replace( '`', '``', $identifier );
	}
}

register_activation_hook( IN_SYNCED_FILE, array( 'In_Synced', 'activate' ) );
