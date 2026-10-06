<?php
/**
 * Remove plugin state on every site; retain verified backups unless explicitly opted in.
 *
 * @package In_Synced
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function in_synced_uninstall_site(): void {
	global $wpdb;
	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'in_synced_' ) . '%',
			$wpdb->esc_like( '_transient_in_synced_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_in_synced_' ) . '%'
		)
	);
	foreach ( $names as $name ) {
		delete_option( (string) $name );
	}
	if ( ! apply_filters( 'in_synced_uninstall_delete_backups', false ) ) {
		return;
	}
	$base        = defined( 'IN_SYNCED_BACKUP_DIR' ) ? (string) IN_SYNCED_BACKUP_DIR : dirname( rtrim( ABSPATH, '/\\' ) ) . '/in-synced-private-backups';
	$directories = array( trailingslashit( $base ) . substr( hash( 'sha256', home_url() ), 0, 16 ) );
	$uploads     = wp_get_upload_dir();
	if ( ! empty( $uploads['basedir'] ) ) {
		$directories[] = trailingslashit( $uploads['basedir'] ) . 'in-synced-backups';
	}
	foreach ( $directories as $directory ) {
		if ( ! is_dir( $directory ) || is_link( $directory ) ) {
			continue;
		}
		foreach ( (array) glob( trailingslashit( $directory ) . 'db-*.sql' ) as $backup ) {
			wp_delete_file( $backup );
		}
		wp_delete_file( trailingslashit( $directory ) . '.htaccess' );
		wp_delete_file( trailingslashit( $directory ) . 'index.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
		rmdir( $directory );
	}
}

if ( is_multisite() ) {
	$in_synced_offset = 0;
	do {
		$in_synced_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $in_synced_offset,
			)
		);
		foreach ( $in_synced_site_ids as $in_synced_site_id ) {
			switch_to_blog( (int) $in_synced_site_id );
			in_synced_uninstall_site();
			restore_current_blog();
		}
		$in_synced_offset += 100;
		$in_synced_count   = count( $in_synced_site_ids );
	} while ( $in_synced_count === 100 );
} else {
	in_synced_uninstall_site();
}
