<?php
/**
 * Plugin Name:       ContentPulse AI Auditor
 * Plugin URI:        https://github.com/debarjun-sen/ai-content-auditor
 * Description:       A Claude-powered agent that audits your content for SEO, accessibility, and stale-content issues, then proposes fixes you approve.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Debarjun Sen
 * Author URI:        https://github.com/debarjun-sen
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contentpulse-ai-auditor
 *
 * @package AICA
 */

defined( 'ABSPATH' ) || exit;

define( 'AICA_VERSION', '1.0.0' );
define( 'AICA_DB_VERSION', '1' );
define( 'AICA_FILE', __FILE__ );
define( 'AICA_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICA_URL', plugin_dir_url( __FILE__ ) );

require_once AICA_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'AICA\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AICA\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'AICA\\Plugin', 'instance' ) );
