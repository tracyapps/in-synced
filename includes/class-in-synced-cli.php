<?php
/**
 * Command-line synchronization entry points.
 *
 * @package In_Synced
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WP-CLI commands for saved profiles. */
final class In_Synced_CLI {

	/**
	 * Push a configured In Synced profile to the paired remote site.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<profile>]
	 * : Profile key to run. Defaults to "default".
	 *
	 * [--scope=<scope>]
	 * : Push scope: full, content_media, database, media, files, or media_files. The theme alias means files.
	 *
	 * [--mode=<mode>]
	 * : Database mode: upsert or replace.
	 *
	 * [--post-types=<types>]
	 * : Comma-separated post types for targeted post type repair. Also switches database scope to post_types.
	 *
	 * [--all-files]
	 * : Send all uploads and configured wp-content files instead of only files changed since the last run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp in-synced push --profile=default
	 *
	 * @param array $args Positional CLI arguments.
	 * @param array $assoc_args Named CLI options.
	 */
	public function push( array $args, array $assoc_args ): void {
		$profile   = isset( $assoc_args['profile'] ) ? sanitize_key( (string) $assoc_args['profile'] ) : 'default';
		$overrides = $this->overrides_from_args( $assoc_args );
		$log       = array();
		$result    = In_Synced::instance()->run_sync( $profile, $log, $overrides );

		foreach ( $log as $line ) {
			WP_CLI::log( $line );
		}

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( __( 'In Synced profile completed.', 'in-synced' ) );
	}

	/**
	 * Preview what a configured In Synced profile would push.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<profile>]
	 * : Profile key to inspect. Defaults to "default".
	 *
	 * [--scope=<scope>]
	 * : Preview scope: full, content_media, database, media, files, or media_files. The theme alias means files.
	 *
	 * [--mode=<mode>]
	 * : Database mode: upsert or replace.
	 *
	 * [--post-types=<types>]
	 * : Comma-separated post types for targeted content comparison/repair preview.
	 *
	 * [--all-files]
	 * : Count all uploads and configured wp-content files instead of only files changed since the last run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp in-synced preview --scope=files
	 *
	 * @param array $args Positional CLI arguments.
	 * @param array $assoc_args Named CLI options.
	 */
	public function preview( array $args, array $assoc_args ): void {
		$profile = isset( $assoc_args['profile'] ) ? sanitize_key( (string) $assoc_args['profile'] ) : 'default';
		$preview = In_Synced::instance()->build_sync_preview( $profile, $this->overrides_from_args( $assoc_args ) );

		/* translators: %s: sync detail. */
		WP_CLI::log( sprintf( __( 'Profile: %s', 'in-synced' ), $preview['profile_name'] ) );
		/* translators: %s: sync detail. */
		WP_CLI::log( sprintf( __( 'Remote: %s', 'in-synced' ), ( $preview['connection']['remote_url'] ?: __( 'not configured', 'in-synced' ) ) ) );
		/* translators: %s: sync detail. */
		WP_CLI::log( sprintf( __( 'Mode: %s', 'in-synced' ), $preview['mode'] ) );
		/* translators: %1$s: sync detail, %2$s: sync detail. */
		WP_CLI::log( sprintf( __( 'Database: %1$s table(s), %2$s row(s)', 'in-synced' ), $preview['database']['table_count'], $preview['database']['row_count'] ) );
		/* translators: %1$s: sync detail, %2$s: sync detail. */
		WP_CLI::log( sprintf( __( 'Media: %1$s file(s), %2$s', 'in-synced' ), $preview['uploads']['file_count'], ( $preview['uploads']['bytes_label'] ?? '0 B' ) ) );
		/* translators: %1$s: sync detail, %2$s: sync detail. */
		WP_CLI::log( sprintf( __( 'Files: %1$s file(s), %2$s', 'in-synced' ), $preview['theme']['file_count'], ( $preview['theme']['bytes_label'] ?? '0 B' ) ) );

		foreach ( $preview['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
	}

	/**
	 * Compare local and remote content counts for a configured profile.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<profile>]
	 * : Profile key to inspect. Defaults to "default".
	 *
	 * [--post-types=<types>]
	 * : Comma-separated post types to compare.
	 *
	 * ## EXAMPLES
	 *
	 *     wp in-synced compare --post-types=book,event
	 *
	 * @param array $args Positional CLI arguments.
	 * @param array $assoc_args Named CLI options.
	 */
	public function compare( array $args, array $assoc_args ): void {
		$profile = isset( $assoc_args['profile'] ) ? sanitize_key( (string) $assoc_args['profile'] ) : 'default';
		$compare = In_Synced::instance()->compare_content( $profile, $this->overrides_from_args( $assoc_args ) );

		if ( is_wp_error( $compare ) ) {
			WP_CLI::error( $compare->get_error_message() );
		}

		/* translators: %s: sync detail. */
		WP_CLI::log( sprintf( __( 'Remote: %s', 'in-synced' ), ( $compare['remote_site'] ?: __( 'connected', 'in-synced' ) ) ) );
		WP_CLI::log( __( 'Post types:', 'in-synced' ) );
		foreach ( $compare['post_types'] as $item ) {
			/* translators: %1$s: sync detail, %2$s: sync detail, %3$s: sync detail, %4$s: sync detail. */
			WP_CLI::log( sprintf( __( '  %1$s local=%2$s remote=%3$s delta=%4$s', 'in-synced' ), $item['post_type'], $item['local'], $item['remote'], ( (int) $item['local'] - (int) $item['remote'] ) ) );
		}
	}

	/**
	 * Show configured profiles.
	 *
	 * ## EXAMPLES
	 *
	 *     wp in-synced profiles
	 */
	public function profiles(): void {
		$profiles = In_Synced::instance()->get_profiles();

		foreach ( $profiles as $key => $profile ) {
			/* translators: %1$s: sync detail, %2$s: sync detail. */
			WP_CLI::log( sprintf( __( '%1$s: %2$s', 'in-synced' ), $key, ( $profile['name'] ?? '' ) ) );
		}
	}

	private function overrides_from_args( array $assoc_args ): array {
		$post_types = array();
		if ( ! empty( $assoc_args['post-types'] ) ) {
			$post_types = array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $assoc_args['post-types'] ) ) );
			$post_types = array_values( array_filter( $post_types ) );
		}

		return array(
			'scope'             => isset( $assoc_args['scope'] ) ? sanitize_key( (string) $assoc_args['scope'] ) : '',
			'mode'              => isset( $assoc_args['mode'] ) ? sanitize_key( (string) $assoc_args['mode'] ) : '',
			'incremental_files' => isset( $assoc_args['all-files'] ) ? false : null,
			'database_scope'    => $post_types ? 'post_types' : '',
			'post_type_filter'  => $post_types,
		);
	}
}

WP_CLI::add_command( 'in-synced', 'In_Synced_CLI' );
