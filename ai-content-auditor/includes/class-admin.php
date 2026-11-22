<?php
/**
 * Admin page shell and assets. The dashboard itself is rendered by assets/admin.js.
 *
 * @package AICA
 */

namespace AICA;

use AICA\Tools\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class Admin {

	const SLUG = 'ai-content-auditor';

	/**
	 * Hook suffix of our page.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AICA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		$icon       = 'data:image/svg+xml;base64,' . base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black"><path d="M8.5 2a6.5 6.5 0 0 1 5.18 10.43l3.95 3.95-1.25 1.25-3.95-3.95A6.5 6.5 0 1 1 8.5 2Zm0 1.75a4.75 4.75 0 1 0 0 9.5 4.75 4.75 0 0 0 0-9.5Zm.6 1.6.5 1.45 1.45.5-1.45.5-.5 1.45-.5-1.45-1.45-.5 1.45-.5.5-1.45Z"/></svg>'
		);
		self::$hook = add_menu_page(
			__( 'Content Audit', 'ai-content-auditor' ),
			__( 'Content Audit', 'ai-content-auditor' ),
			Plugin::capability(),
			self::SLUG,
			array( __CLASS__, 'render' ),
			$icon,
			58
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open dashboard', 'ai-content-auditor' ) . '</a>' );
		return $links;
	}

	/**
	 * Enqueue assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'aica-admin', AICA_URL . 'assets/admin.css', array(), AICA_VERSION );
		wp_enqueue_script( 'aica-admin', AICA_URL . 'assets/admin.js', array( 'wp-api-fetch', 'wp-i18n' ), AICA_VERSION, true );
		wp_set_script_translations( 'aica-admin', 'ai-content-auditor' );

		$tools = array();
		foreach ( Tool_Registry::mcp_tools() as $t ) {
			$tools[] = array(
				'name'        => $t['name'],
				'title'       => $t['title'],
				'description' => $t['description'],
				'readonly'    => $t['annotations']['readOnlyHint'],
				'destructive' => $t['annotations']['destructiveHint'],
			);
		}

		wp_add_inline_script(
			'aica-admin',
			'window.aicaConfig = ' . wp_json_encode(
				array(
					'restPath'        => '/aica/v1',
					'mcpUrl'          => rest_url( 'aica/v1/mcp' ),
					'mcpEnabled'      => (bool) Settings::get( 'mcp_enabled' ),
					'mcpAllowFixes'   => (bool) Settings::get( 'mcp_allow_fixes' ),
					'abilitiesApi'    => function_exists( 'wp_register_ability' ),
					'appPasswordsUrl' => admin_url( 'profile.php#application-passwords-section' ),
					'settingsUrl'     => admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' ),
					'hasKey'          => Secrets::has_key(),
					'siteName'        => get_bloginfo( 'name' ),
					'userLogin'       => wp_get_current_user()->user_login,
					'tools'           => $tools,
					'demoActive'      => defined( 'AICA_DEMO_VERSION' ),
					'demoUrl'         => admin_url( 'tools.php?page=aica-demo' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Render page shell.
	 */
	public static function render() {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ai-content-auditor' ) );
		}
		$tabs = array(
			'dashboard' => __( 'Dashboard', 'ai-content-auditor' ),
			'findings'  => __( 'Findings', 'ai-content-auditor' ),
			'activity'  => __( 'Agent activity', 'ai-content-auditor' ),
			'connect'   => __( 'Connect (MCP)', 'ai-content-auditor' ),
			'settings'  => __( 'Settings', 'ai-content-auditor' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$current = isset( $tabs[ $current ] ) ? $current : 'dashboard';
		?>
		<div class="wrap aica-app" id="aica-app" data-initial-tab="<?php echo esc_attr( $current ); ?>">
			<header class="aica-header">
				<div class="aica-header__brand">
					<span class="aica-logo" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m20 20-4.6-4.6"/><path d="M10.5 7.5v6M7.5 10.5h6" stroke-width="1.6"/></svg>
					</span>
					<div>
						<h1 class="aica-title"><?php esc_html_e( 'Content Audit', 'ai-content-auditor' ); ?></h1>
						<p class="aica-subtitle"><?php esc_html_e( 'SEO, accessibility & freshness — audited by Claude, approved by you.', 'ai-content-auditor' ); ?></p>
					</div>
				</div>
				<div class="aica-header__actions" id="aica-header-actions"></div>
			</header>
			<hr class="wp-header-end" />

			<nav class="aica-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Content Audit sections', 'ai-content-auditor' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<button type="button" role="tab" class="aica-tab" id="aica-tab-<?php echo esc_attr( $key ); ?>" data-tab="<?php echo esc_attr( $key ); ?>" aria-controls="aica-panel-<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $key === $current ? 'true' : 'false'; ?>" tabindex="<?php echo $key === $current ? '0' : '-1'; ?>">
						<?php echo esc_html( $label ); ?>
						<?php if ( 'findings' === $key ) : ?>
							<span class="aica-tab__count" id="aica-findings-count" hidden></span>
						<?php endif; ?>
					</button>
				<?php endforeach; ?>
			</nav>

			<?php foreach ( array_keys( $tabs ) as $key ) : ?>
				<section class="aica-panel" id="aica-panel-<?php echo esc_attr( $key ); ?>" role="tabpanel" aria-labelledby="aica-tab-<?php echo esc_attr( $key ); ?>" tabindex="0" <?php echo $key === $current ? '' : 'hidden'; ?>>
					<?php if ( 'settings' === $key ) : ?>
						<?php self::render_settings(); ?>
					<?php else : ?>
						<div class="aica-skeleton" aria-hidden="true"><span></span><span></span><span></span></div>
						<p class="screen-reader-text"><?php esc_html_e( 'Loading…', 'ai-content-auditor' ); ?></p>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>

			<div class="aica-live screen-reader-text" id="aica-live" role="status" aria-live="polite"></div>
			<div class="aica-toast-region" id="aica-toasts" aria-live="assertive"></div>
			<noscript><div class="notice notice-error"><p><?php esc_html_e( 'The Content Audit dashboard needs JavaScript.', 'ai-content-auditor' ); ?></p></div></noscript>
		</div>
		<?php
	}

	/**
	 * Settings form (Settings API).
	 */
	private static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p>' . esc_html__( 'Only administrators can change settings.', 'ai-content-auditor' ) . '</p>';
			return;
		}
		settings_errors();
		?>
		<div class="aica-card aica-settings">
			<form method="post" action="options.php">
				<?php
				settings_fields( Settings::GROUP );
				do_settings_sections( Settings::PAGE );
				submit_button( __( 'Save settings', 'ai-content-auditor' ) );
				?>
			</form>
		</div>
		<?php
	}
}
