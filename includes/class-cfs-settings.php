<?php
/**
 * Admin settings page for CF-Summarize.
 *
 * @package CF_Summarize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Settings
 *
 * Registers and renders the plugin settings page using the WordPress Settings API.
 */
class CFS_Settings {

	/**
	 * Constructor — hook into WordPress admin.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'handle_cache_clear' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Return the list of supported AI providers.
	 *
	 * Filterable via `cfs_providers` so third-party code can add providers
	 * without touching this file.
	 *
	 * @return array<string,string> provider_slug => display_label
	 */
	private function get_providers(): array {
		return (array) apply_filters(
			'cfs_providers',
			[
				'openai'    => __( 'OpenAI', 'cf-summarize' ),
				'anthropic' => __( 'Anthropic (Claude)', 'cf-summarize' ),
			]
		);
	}

	/**
	 * Add submenu page under Settings.
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'CF-Summarize Settings', 'cf-summarize' ),
			__( 'CF-Summarize', 'cf-summarize' ),
			'manage_options',
			'cf-summarize-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Enqueue inline admin JS — only on our settings page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'settings_page_cf-summarize-settings' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'cfs-admin', CFS_PLUGIN_URL . 'assets/admin.css', [], CFS_VERSION );

		// Register a virtual script handle (no src) so we can attach inline JS.
		wp_register_script( 'cfs-admin', false, [], CFS_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( 'cfs-admin' );
		wp_add_inline_script( 'cfs-admin', $this->get_admin_js() );
	}

	/**
	 * Return the inline JS string for provider-field toggling and key removal.
	 *
	 * @return string Raw JavaScript (no <script> tags).
	 */
	private function get_admin_js(): string {
		$initial = esc_js( (string) get_option( 'cfs_provider', 'openai' ) );

		return <<<JSCODE
( function () {
	'use strict';

	/**
	 * Show field rows belonging to the selected provider; hide all others.
	 *
	 * @param {string} selected Provider slug.
	 */
	function toggleProviderRows( selected ) {
		document.querySelectorAll( '.cfs-field-row[data-provider-field]' ).forEach( function ( row ) {
			row.style.display = ( row.dataset.providerField === selected ) ? '' : 'none';
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {

		// Apply initial state based on saved provider.
		toggleProviderRows( '{$initial}' );

		// Re-apply whenever the provider dropdown changes.
		var select = document.getElementById( 'cfs_provider' );
		if ( select ) {
			select.addEventListener( 'change', function () {
				toggleProviderRows( this.value );
			} );
		}

		// Show/hide cache duration row based on the cache enabled checkbox.
		function toggleCacheDuration() {
			var checkbox    = document.getElementById( 'cfs_cache_enabled' );
			var durationRow = document.querySelector( '.cfs-field-row[data-cache-row]' );
			if ( ! checkbox || ! durationRow ) return;
			durationRow.style.display = checkbox.checked ? 'grid' : 'none';
		}

		toggleCacheDuration();

		var cacheCheckbox = document.getElementById( 'cfs_cache_enabled' );
		if ( cacheCheckbox ) {
			cacheCheckbox.addEventListener( 'change', toggleCacheDuration );
		}

		// "Remove saved key" buttons — clear the input so an empty value is saved.
		document.querySelectorAll( '.cfs-remove-key' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var input = document.getElementById( btn.dataset.target );
				if ( input ) {
					input.value = '';
					input.type  = 'text'; // reveal empty state visually
					input.focus();
				}
				// Hide the "Key saved" badge and this button.
				var badge = document.querySelector( '.cfs-key-badge[data-for="' + btn.dataset.target + '"]' );
				if ( badge ) badge.style.display = 'none';
				btn.style.display = 'none';
			} );
		} );

	} );
}() );
JSCODE;
	}

	/**
	 * Register all settings, sections, and fields.
	 */
	public function register_settings(): void {
		// ── API Configuration ─────────────────────────────────────────────────
		add_settings_section(
			'cfs_section_api',
			__( 'API Configuration', 'cf-summarize' ),
			'__return_false',
			'cf-summarize-settings'
		);

		register_setting( 'cfs_settings_group', 'cfs_provider', [ $this, 'sanitize_provider' ] );
		add_settings_field(
			'cfs_provider',
			__( 'Provider', 'cf-summarize' ),
			[ $this, 'field_provider' ],
			'cf-summarize-settings',
			'cfs_section_api'
		);

		register_setting( 'cfs_settings_group', 'cfs_openai_api_key', 'sanitize_text_field' );
		add_settings_field(
			'cfs_openai_api_key',
			__( 'OpenAI API Key', 'cf-summarize' ),
			[ $this, 'field_openai_api_key' ],
			'cf-summarize-settings',
			'cfs_section_api'
		);

		register_setting( 'cfs_settings_group', 'cfs_model_openai', 'sanitize_text_field' );
		add_settings_field(
			'cfs_model_openai',
			__( 'OpenAI Model', 'cf-summarize' ),
			[ $this, 'field_openai_model' ],
			'cf-summarize-settings',
			'cfs_section_api'
		);

		register_setting( 'cfs_settings_group', 'cfs_anthropic_api_key', 'sanitize_text_field' );
		add_settings_field(
			'cfs_anthropic_api_key',
			__( 'Anthropic API Key', 'cf-summarize' ),
			[ $this, 'field_anthropic_api_key' ],
			'cf-summarize-settings',
			'cfs_section_api'
		);

		register_setting( 'cfs_settings_group', 'cfs_model_anthropic', 'sanitize_text_field' );
		add_settings_field(
			'cfs_model_anthropic',
			__( 'Anthropic Model', 'cf-summarize' ),
			[ $this, 'field_anthropic_model' ],
			'cf-summarize-settings',
			'cfs_section_api'
		);

		// ── Display Settings ──────────────────────────────────────────────────
		add_settings_section(
			'cfs_section_display',
			__( 'Display Settings', 'cf-summarize' ),
			'__return_false',
			'cf-summarize-settings'
		);

		register_setting( 'cfs_settings_group', 'cfs_button_label', 'sanitize_text_field' );
		add_settings_field(
			'cfs_button_label',
			__( 'Button Label', 'cf-summarize' ),
			[ $this, 'field_button_label' ],
			'cf-summarize-settings',
			'cfs_section_display'
		);

		register_setting( 'cfs_settings_group', 'cfs_button_position', [ $this, 'sanitize_position' ] );
		add_settings_field(
			'cfs_button_position',
			__( 'Button Position', 'cf-summarize' ),
			[ $this, 'field_button_position' ],
			'cf-summarize-settings',
			'cfs_section_display'
		);

		register_setting( 'cfs_settings_group', 'cfs_enable_all', [ $this, 'sanitize_checkbox' ] );
		add_settings_field(
			'cfs_enable_all',
			__( 'Enable on All Posts by Default', 'cf-summarize' ),
			[ $this, 'field_enable_all' ],
			'cf-summarize-settings',
			'cfs_section_display'
		);

		// ── Performance ───────────────────────────────────────────────────────
		add_settings_section(
			'cfs_section_performance',
			__( 'Performance', 'cf-summarize' ),
			'__return_false',
			'cf-summarize-settings'
		);

		register_setting( 'cfs_settings_group', 'cfs_max_chars', [ $this, 'sanitize_absint' ] );
		add_settings_field(
			'cfs_max_chars',
			__( 'Max Characters to Send to AI', 'cf-summarize' ),
			[ $this, 'field_max_chars' ],
			'cf-summarize-settings',
			'cfs_section_performance'
		);

		register_setting( 'cfs_settings_group', 'cfs_cache_enabled', [ $this, 'sanitize_checkbox' ] );
		add_settings_field(
			'cfs_cache_enabled',
			__( 'Enable Caching', 'cf-summarize' ),
			[ $this, 'field_cache_enabled' ],
			'cf-summarize-settings',
			'cfs_section_performance'
		);

		register_setting( 'cfs_settings_group', 'cfs_cache_duration', [ $this, 'sanitize_absint' ] );
		add_settings_field(
			'cfs_cache_duration',
			__( 'Cache Duration (seconds)', 'cf-summarize' ),
			[ $this, 'field_cache_duration' ],
			'cf-summarize-settings',
			'cfs_section_performance'
		);
	}

	/**
	 * Handle the "Clear All Summaries Cache" button action.
	 */
	public function handle_cache_clear(): void {
		if (
			! isset( $_POST['cfs_clear_cache'] ) ||
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cfs_settings_group-options' )
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cfs_summary_%'"
		);
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_cfs_summary_%'"
		);
		// phpcs:enable

		add_settings_error(
			'cfs_settings',
			'cfs_cache_cleared',
			__( 'All summary caches have been cleared.', 'cf-summarize' ),
			'updated'
		);
	}

	/**
	 * Render the full settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_provider = (string) get_option( 'cfs_provider', 'openai' );
		$cache_enabled    = (bool) get_option( 'cfs_cache_enabled', false );
		$providers        = $this->get_providers();

		$openai_key   = (string) get_option( 'cfs_openai_api_key', '' );
		$openai_model = (string) get_option( 'cfs_model_openai', '' );
		$anth_key     = (string) get_option( 'cfs_anthropic_api_key', '' );
		$anth_model   = (string) get_option( 'cfs_model_anthropic', '' );
		$btn_label    = (string) get_option( 'cfs_button_label', 'Article Overview' );
		$btn_pos      = (string) get_option( 'cfs_button_position', 'before' );
		$enable_all   = (bool) get_option( 'cfs_enable_all', true );
		$max_chars    = (int) get_option( 'cfs_max_chars', 6000 );
		$cache_dur    = (int) get_option( 'cfs_cache_duration', 86400 );

		settings_errors( 'cfs_settings' );
		?>
		<style>
			/* Hide non-active provider rows before JS runs — prevents flicker */
			.cfs-field-row[data-provider-field]:not([data-provider-field="<?php echo esc_attr( $current_provider ); ?>"]) { display: none; }
			<?php if ( ! $cache_enabled ) : ?>.cfs-field-row[data-cache-row] { display: none; }<?php endif; ?>
		</style>
		<div class="cfs-admin-wrap">

			<div class="cfs-admin-header">
				<div class="cfs-admin-logo" aria-hidden="true">
					<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
						<path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09z"/>
						<path d="M18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456z"/>
					</svg>
				</div>
				<div class="cfs-admin-header-text">
					<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
					<p><?php esc_html_e( 'Configure your AI-powered content summarizer', 'cf-summarize' ); ?></p>
				</div>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'cfs_settings_group' ); ?>

				<!-- ── API Configuration ── -->
				<div class="cfs-admin-card">
					<div class="cfs-admin-card-head">
						<div class="cfs-card-icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
								<path d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25z"/>
							</svg>
						</div>
						<h2><?php esc_html_e( 'API Configuration', 'cf-summarize' ); ?></h2>
					</div>
					<div class="cfs-admin-card-body">

						<div class="cfs-field-row">
							<div class="cfs-field-label">
								<label for="cfs_provider"><?php esc_html_e( 'Provider', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'Only that provider\'s key and model fields will appear below.', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<select name="cfs_provider" id="cfs_provider" class="cfs-select">
									<?php foreach ( $providers as $slug => $label ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_provider, $slug ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<div class="cfs-field-row" data-provider-field="openai">
							<div class="cfs-field-label">
								<label for="cfs_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'cf-summarize' ); ?></label>
							</div>
							<div>
								<input type="password" name="cfs_openai_api_key" id="cfs_openai_api_key"
									value="<?php echo esc_attr( $openai_key ); ?>"
									placeholder="sk-..." autocomplete="new-password" />
								<?php if ( $openai_key ) : ?>
									<span class="cfs-key-badge" data-for="cfs_openai_api_key">&#10003; <?php esc_html_e( 'Key saved', 'cf-summarize' ); ?></span>
									<button type="button" class="cfs-remove-key" data-target="cfs_openai_api_key"><?php esc_html_e( 'Remove', 'cf-summarize' ); ?></button>
								<?php endif; ?>
							</div>
						</div>

						<div class="cfs-field-row" data-provider-field="openai">
							<div class="cfs-field-label">
								<label for="cfs_model_openai"><?php esc_html_e( 'OpenAI Model', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'e.g. gpt-4o-mini, gpt-4o, gpt-4-turbo', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<input type="text" name="cfs_model_openai" id="cfs_model_openai"
									value="<?php echo esc_attr( $openai_model ); ?>"
									placeholder="gpt-4o-mini" />
							</div>
						</div>

						<div class="cfs-field-row" data-provider-field="anthropic">
							<div class="cfs-field-label">
								<label for="cfs_anthropic_api_key"><?php esc_html_e( 'Anthropic API Key', 'cf-summarize' ); ?></label>
							</div>
							<div>
								<input type="password" name="cfs_anthropic_api_key" id="cfs_anthropic_api_key"
									value="<?php echo esc_attr( $anth_key ); ?>"
									placeholder="sk-ant-..." autocomplete="new-password" />
								<?php if ( $anth_key ) : ?>
									<span class="cfs-key-badge" data-for="cfs_anthropic_api_key">&#10003; <?php esc_html_e( 'Key saved', 'cf-summarize' ); ?></span>
									<button type="button" class="cfs-remove-key" data-target="cfs_anthropic_api_key"><?php esc_html_e( 'Remove', 'cf-summarize' ); ?></button>
								<?php endif; ?>
							</div>
						</div>

						<div class="cfs-field-row" data-provider-field="anthropic">
							<div class="cfs-field-label">
								<label for="cfs_model_anthropic"><?php esc_html_e( 'Anthropic Model', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'e.g. claude-haiku-4-5-20251001, claude-sonnet-4-6', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<input type="text" name="cfs_model_anthropic" id="cfs_model_anthropic"
									value="<?php echo esc_attr( $anth_model ); ?>"
									placeholder="claude-haiku-4-5-20251001" />
							</div>
						</div>

					</div>
				</div>

				<!-- ── Display Settings ── -->
				<div class="cfs-admin-card">
					<div class="cfs-admin-card-head">
						<div class="cfs-card-icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
								<path d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
								<path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/>
							</svg>
						</div>
						<h2><?php esc_html_e( 'Display Settings', 'cf-summarize' ); ?></h2>
					</div>
					<div class="cfs-admin-card-body">

						<div class="cfs-field-row">
							<div class="cfs-field-label">
								<label for="cfs_button_label"><?php esc_html_e( 'Button Label', 'cf-summarize' ); ?></label>
							</div>
							<div>
								<input type="text" name="cfs_button_label" id="cfs_button_label"
									value="<?php echo esc_attr( $btn_label ); ?>" />
							</div>
						</div>

						<div class="cfs-field-row">
							<div class="cfs-field-label">
								<label for="cfs_button_position"><?php esc_html_e( 'Button Position', 'cf-summarize' ); ?></label>
							</div>
							<div>
								<select name="cfs_button_position" id="cfs_button_position" class="cfs-select">
									<option value="before" <?php selected( $btn_pos, 'before' ); ?>><?php esc_html_e( 'Before content', 'cf-summarize' ); ?></option>
									<option value="after"  <?php selected( $btn_pos, 'after' ); ?>><?php esc_html_e( 'After content',  'cf-summarize' ); ?></option>
								</select>
							</div>
						</div>

						<div class="cfs-field-row">
							<div class="cfs-field-label">
								<label><?php esc_html_e( 'Enable on All Posts', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'Individual posts can override this via the meta box.', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<label class="cfs-toggle-wrap">
									<span class="cfs-toggle" aria-hidden="true">
										<input type="checkbox" class="cfs-toggle-input" name="cfs_enable_all" id="cfs_enable_all" value="1" <?php checked( $enable_all ); ?> />
										<span class="cfs-toggle-track"></span>
										<span class="cfs-toggle-thumb"></span>
									</span>
									<span class="cfs-toggle-text"><?php esc_html_e( 'Show the summary button on all posts by default', 'cf-summarize' ); ?></span>
								</label>
							</div>
						</div>

					</div>
				</div>

				<!-- ── Performance ── -->
				<div class="cfs-admin-card">
					<div class="cfs-admin-card-head">
						<div class="cfs-card-icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
								<path d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>
							</svg>
						</div>
						<h2><?php esc_html_e( 'Performance', 'cf-summarize' ); ?></h2>
					</div>
					<div class="cfs-admin-card-body">

						<div class="cfs-field-row">
							<div class="cfs-field-label">
								<label for="cfs_max_chars"><?php esc_html_e( 'Max Characters', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'Article characters sent to AI. Recommended: 4000–8000.', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<input type="number" name="cfs_max_chars" id="cfs_max_chars"
									value="<?php echo esc_attr( (string) $max_chars ); ?>"
									min="500" max="50000" />
							</div>
						</div>

						<div class="cfs-field-row">
							<div class="cfs-field-label">
								<label><?php esc_html_e( 'Enable Caching', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'Avoid regenerating summaries on every page visit.', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<label class="cfs-toggle-wrap">
									<span class="cfs-toggle" aria-hidden="true">
										<input type="checkbox" class="cfs-toggle-input" name="cfs_cache_enabled" id="cfs_cache_enabled" value="1" <?php checked( $cache_enabled ); ?> />
										<span class="cfs-toggle-track"></span>
										<span class="cfs-toggle-thumb"></span>
									</span>
									<span class="cfs-toggle-text"><?php esc_html_e( 'Serve cached summaries until they expire', 'cf-summarize' ); ?></span>
								</label>
							</div>
						</div>

						<div class="cfs-field-row" data-cache-row>
							<div class="cfs-field-label">
								<label for="cfs_cache_duration"><?php esc_html_e( 'Cache Duration', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'Seconds. 86400 = 24 hours.', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<input type="number" name="cfs_cache_duration" id="cfs_cache_duration"
									value="<?php echo esc_attr( (string) $cache_dur ); ?>"
									min="1" />
							</div>
						</div>

					</div>
				</div>

				<div class="cfs-admin-actions">
					<button type="submit" class="cfs-save-btn"><?php esc_html_e( 'Save Settings', 'cf-summarize' ); ?></button>
					<button type="submit" name="cfs_clear_cache" value="1" class="cfs-clear-btn"><?php esc_html_e( 'Clear All Summaries Cache', 'cf-summarize' ); ?></button>
				</div>

			</form>
		</div>
		<?php
	}

	// ── Field renderers ───────────────────────────────────────────────────────

	/**
	 * Render the Provider select field.
	 * Options are driven by get_providers() — no hardcoded provider names.
	 */
	public function field_provider(): void {
		$current   = (string) get_option( 'cfs_provider', 'openai' );
		$providers = $this->get_providers();
		?>
		<select name="cfs_provider" id="cfs_provider">
			<?php foreach ( $providers as $slug => $label ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Choose your AI provider. Only that provider\'s API Key and Model fields will be shown below.', 'cf-summarize' ); ?></p>
		<?php
	}

	/**
	 * Open a provider-scoped wrapper div.
	 * The JS reads data-for-provider to decide which <tr> to hide/show.
	 *
	 * @param string $provider Provider slug (e.g. 'openai', 'anthropic').
	 */
	private function open_provider_row( string $provider ): void {
		printf(
			'<div class="cfs-provider-field" data-for-provider="%s">',
			esc_attr( $provider )
		);
	}

	/** Close the provider-scoped wrapper div. */
	private function close_provider_row(): void {
		echo '</div>';
	}

	/**
	 * Render a "✓ Key saved / Remove" indicator next to an API key input.
	 * Shown only when a key is already stored.  The JS "Remove" button clears
	 * the <input> so that saving the form writes an empty value.
	 *
	 * @param string $saved_value The currently stored key (may be empty).
	 * @param string $input_id    The id attribute of the associated <input>.
	 */
	private function render_key_status( string $saved_value, string $input_id ): void {
		if ( '' === $saved_value ) {
			return;
		}
		printf(
			'&nbsp;<span class="cfs-key-badge" data-for="%1$s" style="color:#3a7c3a;font-weight:600;" aria-label="%2$s">&#10003; %2$s</span>' .
			'&nbsp;<button type="button" class="button-link cfs-remove-key" data-target="%1$s" style="color:#a00;" aria-label="%3$s %2$s">%3$s</button>',
			esc_attr( $input_id ),
			esc_html__( 'Key saved', 'cf-summarize' ),
			esc_html__( 'Remove', 'cf-summarize' )
		);
	}

	/** Render OpenAI API Key field (visible only when OpenAI is selected). */
	public function field_openai_api_key(): void {
		$value = (string) get_option( 'cfs_openai_api_key', '' );
		$this->open_provider_row( 'openai' );
		?>
		<input
			type="password"
			name="cfs_openai_api_key"
			id="cfs_openai_api_key"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="sk-..."
			class="regular-text"
			autocomplete="new-password"
		/>
		<?php
		$this->render_key_status( $value, 'cfs_openai_api_key' );
		$this->close_provider_row();
	}

	/** Render OpenAI Model field (visible only when OpenAI is selected). */
	public function field_openai_model(): void {
		$value = (string) get_option( 'cfs_model_openai', '' );
		$this->open_provider_row( 'openai' );
		?>
		<input
			type="text"
			name="cfs_model_openai"
			id="cfs_model_openai"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="e.g. gpt-4o-mini"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Any OpenAI chat model, e.g.', 'cf-summarize' ); ?>
			<code>gpt-4o-mini</code>, <code>gpt-4o</code>, <code>gpt-4-turbo</code>
		</p>
		<?php
		$this->close_provider_row();
	}

	/** Render Anthropic API Key field (visible only when Anthropic is selected). */
	public function field_anthropic_api_key(): void {
		$value = (string) get_option( 'cfs_anthropic_api_key', '' );
		$this->open_provider_row( 'anthropic' );
		?>
		<input
			type="password"
			name="cfs_anthropic_api_key"
			id="cfs_anthropic_api_key"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="sk-ant-..."
			class="regular-text"
			autocomplete="new-password"
		/>
		<?php
		$this->render_key_status( $value, 'cfs_anthropic_api_key' );
		$this->close_provider_row();
	}

	/** Render Anthropic Model field (visible only when Anthropic is selected). */
	public function field_anthropic_model(): void {
		$value = (string) get_option( 'cfs_model_anthropic', '' );
		$this->open_provider_row( 'anthropic' );
		?>
		<input
			type="text"
			name="cfs_model_anthropic"
			id="cfs_model_anthropic"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="e.g. claude-haiku-4-5-20251001"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Any Anthropic messages-API model, e.g.', 'cf-summarize' ); ?>
			<code>claude-haiku-4-5-20251001</code>, <code>claude-sonnet-4-6</code>, <code>claude-opus-4-6</code>
		</p>
		<?php
		$this->close_provider_row();
	}

	/** Render Button Label field. */
	public function field_button_label(): void {
		$value = get_option( 'cfs_button_label', 'Article Overview' );
		?>
		<input
			type="text"
			name="cfs_button_label"
			id="cfs_button_label"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
		/>
		<?php
	}

	/** Render Button Position select field. */
	public function field_button_position(): void {
		$value = get_option( 'cfs_button_position', 'before' );
		?>
		<select name="cfs_button_position" id="cfs_button_position">
			<option value="before" <?php selected( $value, 'before' ); ?>><?php esc_html_e( 'Before content', 'cf-summarize' ); ?></option>
			<option value="after" <?php selected( $value, 'after' ); ?>><?php esc_html_e( 'After content', 'cf-summarize' ); ?></option>
		</select>
		<?php
	}

	/** Render Enable on All Posts by Default checkbox. */
	public function field_enable_all(): void {
		$value = (bool) get_option( 'cfs_enable_all', true );
		?>
		<label for="cfs_enable_all">
			<input
				type="checkbox"
				name="cfs_enable_all"
				id="cfs_enable_all"
				value="1"
				<?php checked( $value ); ?>
			/>
			<?php esc_html_e( 'Show the Article Overview button on all posts by default', 'cf-summarize' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Individual posts can override this via the CF-Summarize meta box in the post editor.', 'cf-summarize' ); ?></p>
		<?php
	}

	/** Render Max Characters field. */
	public function field_max_chars(): void {
		$value = (int) get_option( 'cfs_max_chars', 6000 );
		?>
		<input
			type="number"
			name="cfs_max_chars"
			id="cfs_max_chars"
			value="<?php echo esc_attr( (string) $value ); ?>"
			min="500"
			max="50000"
			class="small-text"
		/>
		<p class="description"><?php esc_html_e( 'Maximum characters of article text sent to the AI. Recommended: 4000–8000.', 'cf-summarize' ); ?></p>
		<?php
	}

	/** Render Enable Caching checkbox. */
	public function field_cache_enabled(): void {
		$value = (bool) get_option( 'cfs_cache_enabled', false );
		?>
		<label for="cfs_cache_enabled">
			<input
				type="checkbox"
				name="cfs_cache_enabled"
				id="cfs_cache_enabled"
				value="1"
				<?php checked( $value ); ?>
			/>
			<?php esc_html_e( 'Cache AI summaries to avoid regenerating on every visit', 'cf-summarize' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Disabled by default — every visit generates a fresh summary. Enable to serve the same summary until the cache expires.', 'cf-summarize' ); ?></p>
		<?php
	}

	/** Render Cache Duration field. */
	public function field_cache_duration(): void {
		$value = (int) get_option( 'cfs_cache_duration', 86400 );
		?>
		<input
			type="number"
			name="cfs_cache_duration"
			id="cfs_cache_duration"
			value="<?php echo esc_attr( (string) $value ); ?>"
			min="1"
			class="small-text"
		/>
		<p class="description"><?php esc_html_e( 'Seconds to cache each summary. 86400 = 24 hours. Only applies when caching is enabled.', 'cf-summarize' ); ?></p>
		<?php
	}

	// ── Sanitization callbacks ────────────────────────────────────────────────

	/**
	 * Sanitize provider option against the registered provider list.
	 *
	 * @param mixed $value Raw input value.
	 * @return string Valid provider slug; falls back to 'openai'.
	 */
	public function sanitize_provider( $value ): string {
		$allowed = array_keys( $this->get_providers() );
		$value   = sanitize_text_field( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : 'openai';
	}

	/**
	 * Sanitize button position option.
	 *
	 * @param mixed $value Raw input value.
	 * @return string 'before' or 'after'.
	 */
	public function sanitize_position( $value ): string {
		$allowed = [ 'before', 'after' ];
		$value   = sanitize_text_field( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : 'before';
	}

	/**
	 * Sanitize a positive integer option.
	 *
	 * @param mixed $value Raw input value.
	 * @return int Non-negative integer.
	 */
	public function sanitize_absint( $value ): int {
		return absint( $value );
	}

	/**
	 * Sanitize a checkbox option.
	 *
	 * Unchecked checkboxes are not submitted in POST data, so $value may be
	 * null; treat that as 0.
	 *
	 * @param mixed $value Raw input value.
	 * @return int 1 if checked, 0 if unchecked/absent.
	 */
	public function sanitize_checkbox( $value ): int {
		return empty( $value ) ? 0 : 1;
	}
}
