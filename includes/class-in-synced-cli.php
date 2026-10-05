<?php

if (!defined('ABSPATH')) {
    exit;
}

final class In_Synced_CLI
{
    /**
     * Push a configured In Synced profile to the paired remote site.
     *
     * ## OPTIONS
     *
     * [--profile=<profile>]
     * : Profile key to run. Defaults to "default".
     *
     * [--scope=<scope>]
     * : Push scope: full, content_media, database, media, theme, or files. The theme scope sends configured wp-content file roots.
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
     */
    public function push(array $args, array $assoc_args): void
    {
        $profile = isset($assoc_args['profile']) ? sanitize_key((string) $assoc_args['profile']) : 'default';
        $overrides = $this->overrides_from_args($assoc_args);
        $log = [];
        $result = In_Synced::instance()->run_sync($profile, $log, $overrides);

        foreach ($log as $line) {
            WP_CLI::log($line);
        }

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success('In Synced profile completed.');
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
     * : Preview scope: full, content_media, database, media, theme, or files. The theme scope sends configured wp-content file roots.
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
     *     wp in-synced preview --scope=theme
     */
    public function preview(array $args, array $assoc_args): void
    {
        $profile = isset($assoc_args['profile']) ? sanitize_key((string) $assoc_args['profile']) : 'default';
        $preview = In_Synced::instance()->build_sync_preview($profile, $this->overrides_from_args($assoc_args));

        WP_CLI::log('Profile: ' . $preview['profile_name']);
        WP_CLI::log('Remote: ' . ($preview['connection']['remote_url'] ?: 'not configured'));
        WP_CLI::log('Mode: ' . $preview['mode']);
        WP_CLI::log('Database: ' . $preview['database']['table_count'] . ' table(s), ' . $preview['database']['row_count'] . ' row(s)');
        WP_CLI::log('Media: ' . $preview['uploads']['file_count'] . ' file(s), ' . ($preview['uploads']['bytes_label'] ?? '0 B'));
        WP_CLI::log('Files: ' . $preview['theme']['file_count'] . ' file(s), ' . ($preview['theme']['bytes_label'] ?? '0 B'));

        foreach ($preview['warnings'] as $warning) {
            WP_CLI::warning($warning);
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
     */
    public function compare(array $args, array $assoc_args): void
    {
        $profile = isset($assoc_args['profile']) ? sanitize_key((string) $assoc_args['profile']) : 'default';
        $compare = In_Synced::instance()->compare_content($profile, $this->overrides_from_args($assoc_args));

        if (is_wp_error($compare)) {
            WP_CLI::error($compare->get_error_message());
        }

        WP_CLI::log('Remote: ' . ($compare['remote_site'] ?: 'connected'));
        WP_CLI::log('Post types:');
        foreach ($compare['post_types'] as $item) {
            WP_CLI::log('  ' . $item['post_type'] . "\tlocal=" . $item['local'] . "\tremote=" . $item['remote'] . "\tdelta=" . ((int) $item['local'] - (int) $item['remote']));
        }
    }

    /**
     * Show configured profiles.
     *
     * ## EXAMPLES
     *
     *     wp in-synced profiles
     */
    public function profiles(): void
    {
        $profiles = In_Synced::instance()->get_profiles();

        foreach ($profiles as $key => $profile) {
            WP_CLI::log($key . "\t" . ($profile['name'] ?? ''));
        }
    }

    private function overrides_from_args(array $assoc_args): array
    {
        $post_types = [];
        if (!empty($assoc_args['post-types'])) {
            $post_types = array_map('sanitize_key', array_map('trim', explode(',', (string) $assoc_args['post-types'])));
            $post_types = array_values(array_filter($post_types));
        }

        return [
            'scope' => isset($assoc_args['scope']) ? sanitize_key((string) $assoc_args['scope']) : '',
            'mode' => isset($assoc_args['mode']) ? sanitize_key((string) $assoc_args['mode']) : '',
            'incremental_files' => isset($assoc_args['all-files']) ? false : null,
            'database_scope' => $post_types ? 'post_types' : '',
            'post_type_filter' => $post_types,
        ];
    }
}

WP_CLI::add_command('in-synced', 'In_Synced_CLI');
