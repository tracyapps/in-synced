<?php
/**
 * Uninstall routine for In Synced.
 *
 * Removes plugin options, profiles, run history, and plugin transients.
 * Database backup files under uploads/in-synced-backups/ are left in place
 * by default so a mistaken uninstall cannot destroy the safety net; return
 * true from the `in_synced_uninstall_delete_backups` filter before
 * uninstalling to remove them as well.
 *
 * @package In_Synced
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('in_synced_settings');
delete_option('in_synced_profiles');
delete_option('in_synced_last_runs');

// Progress transients, nonce claims, last-run logs, and their timeout rows.
$transient_like = $wpdb->esc_like('_transient_in_synced_') . '%';
$timeout_like = $wpdb->esc_like('_transient_timeout_in_synced_') . '%';
$nonce_like = $wpdb->esc_like('in_synced_nonce_') . '%';
$option_names = $wpdb->get_col($wpdb->prepare(
    "SELECT option_name FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
    $transient_like,
    $timeout_like,
    $nonce_like
));

foreach ($option_names as $option_name) {
    delete_option((string) $option_name);
}

$delete_backups = (bool) apply_filters('in_synced_uninstall_delete_backups', false);
if ($delete_backups) {
    $uploads = wp_upload_dir();
    if (!empty($uploads['basedir'])) {
        $directory = trailingslashit($uploads['basedir']) . 'in-synced-backups';
        if (is_dir($directory)) {
            $backups = glob(trailingslashit($directory) . 'db-*.sql');
            foreach ((array) $backups as $backup) {
                @unlink($backup);
            }

            @unlink(trailingslashit($directory) . '.htaccess');
            @unlink(trailingslashit($directory) . 'index.php');
            @rmdir($directory);
        }
    }
}
