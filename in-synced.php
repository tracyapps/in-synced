<?php
/**
 * Plugin Name: In Synced
 * Description: Securely push selected database rows, uploads, and wp-content files between paired WordPress sites.
 * Version: 0.1.0
 * Author: Tapps
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: in-synced
 * Domain Path: /languages
 * Requires at least: 5.5
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IN_SYNCED_VERSION', '0.1.0');
define('IN_SYNCED_FILE', __FILE__);
define('IN_SYNCED_DIR', plugin_dir_path(__FILE__));

require_once IN_SYNCED_DIR . 'includes/class-in-synced.php';

add_action('plugins_loaded', static function () {
    load_plugin_textdomain('in-synced', false, dirname(plugin_basename(IN_SYNCED_FILE)) . '/languages');
    In_Synced::instance();
});

if (defined('WP_CLI') && WP_CLI) {
    require_once IN_SYNCED_DIR . 'includes/class-in-synced-cli.php';
}
