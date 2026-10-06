<?php
/** Integration tests use only the WordPress test library's disposable database. */
final class ReceiverTest extends WP_UnitTestCase
{
    private $plugin;
    private $table;
    private $session;
    private $upload_root;

    public function set_up(): void
    {
        parent::set_up();
        global $wpdb;
        $this->plugin = In_Synced::instance();
        $this->table = $wpdb->prefix . 'in_synced_fixture';
        $this->session = wp_generate_uuid4();
        $this->upload_root = sys_get_temp_dir() . '/in-synced-receiver-' . wp_generate_password(12, false, false);
        mkdir($this->upload_root);
        // Real fixture DDL in the disposable database exercises actual SHOW CREATE.
        remove_filter('query', [$this, '_create_temporary_tables']);
        $wpdb->query('CREATE TABLE `' . $this->table . '` (`id` bigint unsigned NOT NULL, `payload` longblob, PRIMARY KEY (`id`)) ENGINE=InnoDB');
        add_filter('query', [$this, '_create_temporary_tables']);
        update_option('in_synced_settings', array_merge(In_Synced::default_settings(), ['shared_secret' => str_repeat('s', 48), 'remote_url' => 'https://receiver.example', 'allow_db_receive' => true, 'allow_media_receive' => true]));
        delete_option('in_synced_site_lease');
        add_filter('upload_dir', [$this, 'upload_dir']);
    }
    public function tear_down(): void
    {
        global $wpdb;
        remove_filter('query', [$this, '_drop_temporary_tables']);
        $wpdb->query('DROP TABLE IF EXISTS `' . $this->table . '`');
        delete_option('in_synced_site_lease');
        remove_filter('upload_dir', [$this, 'upload_dir']);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->upload_root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
        rmdir($this->upload_root);
        parent::tear_down();
    }
    public function upload_dir($uploads) { $uploads['basedir'] = $this->upload_root; $uploads['path'] = $this->upload_root; return $uploads; }
    private function request($route, array $payload): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/in-synced/v1/' . $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(array_merge(['session_id' => $this->session], $payload)));
        return $request;
    }
    private function manifest(): array
    {
        global $wpdb;
        $schema = $wpdb->get_row('SHOW CREATE TABLE `' . $this->table . '`', ARRAY_N);
        return [['source' => $this->table, 'schema' => $schema[1], 'columns' => ['id', 'payload']]];
    }
    private function begin($mode = 'upsert')
    {
        global $wpdb;
        return $this->plugin->rest_begin_db($this->request('begin-db', ['source_prefix' => $wpdb->prefix, 'mode' => $mode, 'tables' => $this->manifest()]));
    }
    public function test_signed_nonce_is_bound_and_claimed_atomically(): void
    {
        $request = $this->request('ping', []);
        $nonce = wp_generate_uuid4(); $timestamp = (string) time(); $secret = str_repeat('s', 48);
        $request->set_header('X-In-Synced-Timestamp', $timestamp);
        $request->set_header('X-In-Synced-Nonce', $nonce);
        $request->set_header('X-In-Synced-Signature', In_Synced_Safety::signature('POST', $request->get_route(), $timestamp, $nonce, $request->get_body(), $secret));
        $this->assertTrue($this->plugin->authorize_rest_request($request));
        $this->assertSame('in_synced_replay', $this->plugin->authorize_rest_request($request)->get_error_code());
        $request->set_header('X-In-Synced-Nonce', wp_generate_uuid4());
        $this->assertSame('in_synced_bad_signature', $this->plugin->authorize_rest_request($request)->get_error_code());
        $request->set_header('X-In-Synced-Signature', 'sha256=' . str_repeat('0', 64));
        $this->assertSame('in_synced_unsigned', $this->plugin->authorize_rest_request($request)->get_error_code());
    }
    public function test_stale_and_oversized_requests_are_refused_before_mutation(): void
    {
        $request = $this->request('ping', []); $timestamp = (string) (time() - 301); $nonce = wp_generate_uuid4();
        $request->set_header('X-In-Synced-Timestamp', $timestamp); $request->set_header('X-In-Synced-Nonce', $nonce);
        $request->set_header('X-In-Synced-Signature', In_Synced_Safety::signature('POST', $request->get_route(), $timestamp, $nonce, $request->get_body(), str_repeat('s', 48)));
        $this->assertSame('in_synced_stale', $this->plugin->authorize_rest_request($request)->get_error_code());
        $request->set_header('X-In-Synced-Timestamp', (string) time()); $request->set_body(str_repeat('x', 8388609));
        $this->assertSame('in_synced_payload_large', $this->plugin->authorize_rest_request($request)->get_error_code());
    }
    public function test_receiver_permissions_cover_compare_and_import(): void
    {
        $settings = $this->plugin->get_settings(); $settings['allow_db_receive'] = false; update_option('in_synced_settings', $settings);
        $this->assertSame('in_synced_db_receive_disabled', $this->plugin->rest_compare_content($this->request('compare-content', []))->get_error_code());
        $this->assertSame('in_synced_db_receive_disabled', $this->begin()->get_error_code());
    }
    public function test_binary_row_import_preserves_exact_bytes_and_nulls(): void
    {
        global $wpdb;
        $this->assertInstanceOf(WP_REST_Response::class, $this->begin());
        $bytes = "\x00\xff\x80'\\\n";
        $response = $this->plugin->rest_table_batch($this->request('table-batch', ['source_prefix' => $wpdb->prefix, 'table' => $this->table, 'rows' => In_Synced_Safety::encode_rows([['id' => '1', 'payload' => $bytes], ['id' => '2', 'payload' => null]])]));
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(2, $response->get_data()['inserted']);
        $this->assertSame(bin2hex($bytes), strtolower($wpdb->get_var('SELECT HEX(payload) FROM `' . $this->table . '` WHERE id=1')));
        $this->assertNull($wpdb->get_var('SELECT payload FROM `' . $this->table . '` WHERE id=2'));
    }
    public function test_schema_type_mismatch_aborts_replace_and_preserves_existing_rows(): void
    {
        global $wpdb;
        $wpdb->query('INSERT INTO `' . $this->table . '` VALUES (1, X\'616263\')');
        $tables = $this->manifest(); $tables[0]['schema'] = str_replace('longblob', 'varchar(12)', $tables[0]['schema']);
        $response = $this->plugin->rest_begin_db($this->request('begin-db', ['source_prefix' => $wpdb->prefix, 'mode' => 'replace', 'tables' => $tables]));
        $this->assertSame('in_synced_schema_mismatch', $response->get_error_code());
        $this->assertSame('abc', $wpdb->get_var('SELECT payload FROM `' . $this->table . '` WHERE id=1'));
    }
    public function test_unknown_columns_reject_entire_batch_without_partial_import(): void
    {
        global $wpdb; $this->begin();
        $response = $this->plugin->rest_table_batch($this->request('table-batch', ['source_prefix' => $wpdb->prefix, 'table' => $this->table, 'rows' => In_Synced_Safety::encode_rows([['id' => '1', 'payload' => 'good'], ['id' => '2', 'unknown' => 'bad']])]));
        $this->assertSame('in_synced_schema_mismatch', $response->get_error_code());
        $this->assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM `' . $this->table . '`'));
    }
    public function test_other_session_cannot_begin_import_or_release_owned_receive_lease(): void
    {
        global $wpdb;
        $this->assertInstanceOf(WP_REST_Response::class, $this->begin());
        $this->session = wp_generate_uuid4();
        $this->assertSame('in_synced_busy', $this->begin()->get_error_code());
        $batch = $this->plugin->rest_table_batch($this->request('table-batch', ['source_prefix' => $wpdb->prefix, 'table' => $this->table, 'rows' => []]));
        $this->assertSame('in_synced_wrong_session', $batch->get_error_code());
        $this->assertSame('in_synced_wrong_session', $this->plugin->rest_end_db($this->request('end-db', []))->get_error_code());
    }
    public function test_verified_backup_has_every_row_and_completion_marker_before_replace(): void
    {
        global $wpdb;
        $wpdb->query('INSERT INTO `' . $this->table . '` VALUES (1, X\'00ff275c\'), (2,NULL)');
        $response = $this->begin('replace'); $this->assertInstanceOf(WP_REST_Response::class, $response);
        $backup = $response->get_data()['backup'];
        $this->assertFileExists($backup); $dump = file_get_contents($backup);
        $this->assertStringContainsString("UNHEX('00ff275c')", $dump);
        $this->assertSame(2, substr_count($dump, 'INSERT INTO `' . $this->table . '`'));
        $this->assertStringContainsString('-- In Synced backup complete', $dump);
        $this->assertStringNotContainsString(rtrim(ABSPATH, '/'), $backup);
        $this->assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM `' . $this->table . '`'));
        unlink($backup);
    }
    public function test_backup_read_failure_aborts_before_truncate(): void
    {
        global $wpdb;
        $wpdb->query('INSERT INTO `' . $this->table . '` VALUES (1, X\'616263\')');
        $table = $this->table;
        $fail = static function ($sql) use ($table) { return strpos($sql, 'SELECT * FROM `' . $table . '`') === 0 ? 'SELECT invalid_column_for_in_synced_test FROM `' . $table . '`' : $sql; };
        add_filter('query', $fail); $old = $wpdb->suppress_errors(true);
        try { $response = $this->begin('replace'); } finally { remove_filter('query', $fail); $wpdb->suppress_errors($old); }
        $this->assertSame('in_synced_backup_failed', $response->get_error_code());
        $this->assertSame('abc', $wpdb->get_var('SELECT payload FROM `' . $this->table . '` WHERE id=1'));
    }
    private function file_chunk($path, $data, $offset, $total, $hash, $final)
    {
        $this->plugin->rest_begin_sync($this->request('begin-sync', ['media' => true]));
        return $this->plugin->rest_file_chunk($this->request('file-chunk', ['target' => 'uploads', 'relative_path' => $path, 'data' => base64_encode($data), 'offset' => $offset, 'total_size' => $total, 'sha256' => $hash, 'final' => $final]));
    }
    public function test_existing_file_is_preserved_until_full_verified_atomic_replacement(): void
    {
        file_put_contents($this->upload_root . '/photo.jpg', 'old'); $hash = hash('sha256', 'new-file');
        $this->assertInstanceOf(WP_REST_Response::class, $this->file_chunk('photo.jpg', 'new-', 0, 8, $hash, false));
        $this->assertSame('old', file_get_contents($this->upload_root . '/photo.jpg'));
        $destination = realpath($this->upload_root) . '/photo.jpg';
        $stage = get_transient('in_synced_transfer_' . hash('sha256', $destination . $hash));
        $this->assertIsArray($stage);
        foreach ([ABSPATH, WP_CONTENT_DIR, $this->upload_root] as $public_root) {
            $this->assertStringStartsNotWith(trailingslashit(realpath($public_root)), $stage['path']);
        }
        $this->assertSame('new-', file_get_contents($stage['path']));
        $this->assertSame(0600, fileperms($stage['path']) & 0777);
        $this->assertSame([], glob($this->upload_root . '/.in-synced-*.part'));
        $this->assertInstanceOf(WP_REST_Response::class, $this->file_chunk('photo.jpg', 'file', 4, 8, $hash, true));
        $this->assertSame('new-file', file_get_contents($this->upload_root . '/photo.jpg'));
        $this->assertFileDoesNotExist($stage['path']);
    }
    public function test_public_symlink_staging_is_refused_without_changing_live_file(): void
    {
        $this->assertInstanceOf(WP_REST_Response::class, $this->file_chunk('photo.jpg', 'old', 0, 3, hash('sha256', 'old'), true));
        $method = new ReflectionMethod(In_Synced::class, 'private_storage_directory'); $method->setAccessible(true);
        $directory = $method->invoke($this->plugin, 'transfers');
        $saved = $directory . '.test-' . wp_generate_password(12, false, false);
        rename($directory, $saved); symlink($this->upload_root, $directory);
        try {
            $response = $this->file_chunk('photo.jpg', 'new', 0, 3, hash('sha256', 'new'), true);
            $this->assertSame('in_synced_private_staging_unavailable', $response->get_error_code());
            $this->assertSame('old', file_get_contents($this->upload_root . '/photo.jpg'));
            $this->assertSame([], glob($this->upload_root . '/.in-synced-*.part'));
        } finally { unlink($directory); rename($saved, $directory); }
    }
    public function test_parent_document_root_and_alias_refuse_backup_and_file_staging(): void
    {
        global $wpdb;
        $wpdb->query('INSERT INTO `' . $this->table . '` VALUES (1, UNHEX(\'6f6c64\'))');
        file_put_contents($this->upload_root . '/photo.jpg', 'old');
        $had_root = array_key_exists('DOCUMENT_ROOT', $_SERVER);
        $old_root = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $document_root = dirname(rtrim(ABSPATH, '/\\'));
        $alias = sys_get_temp_dir() . '/in-synced-docroot-' . wp_generate_password(12, false, false);
        symlink($document_root, $alias);
        try {
            foreach ([$document_root, $alias] as $root) {
                $_SERVER['DOCUMENT_ROOT'] = $root;
                $response = $this->begin('replace');
                $this->assertSame('in_synced_backup_failed', $response->get_error_code());
                $this->assertSame('old', $wpdb->get_var('SELECT payload FROM `' . $this->table . '` WHERE id=1'));
                $response = $this->file_chunk('photo.jpg', 'new', 0, 3, hash('sha256', 'new'), true);
                $this->assertSame('in_synced_private_staging_unavailable', $response->get_error_code());
                $this->assertSame('old', file_get_contents($this->upload_root . '/photo.jpg'));
            }
        } finally {
            if ($had_root) { $_SERVER['DOCUMENT_ROOT'] = $old_root; } else { unset($_SERVER['DOCUMENT_ROOT']); }
            unlink($alias);
        }
    }
    public function test_failed_hash_and_out_of_order_chunk_preserve_existing_file(): void
    {
        file_put_contents($this->upload_root . '/photo.jpg', 'old');
        $response = $this->file_chunk('photo.jpg', 'bad', 0, 3, hash('sha256', 'yes'), true);
        $this->assertSame('in_synced_file_integrity', $response->get_error_code());
        $this->assertSame('old', file_get_contents($this->upload_root . '/photo.jpg'));
        $response = $this->file_chunk('photo.jpg', 'x', 2, 3, hash('sha256', 'yes'), true);
        $this->assertSame('in_synced_transfer_missing', $response->get_error_code());
    }
    public function test_upload_executables_dotfiles_and_symlink_destinations_are_refused(): void
    {
        foreach (['x.php', '.htaccess', 'web.config', 'x.php.jpg'] as $path) {
            $this->assertSame('in_synced_upload_type_denied', $this->file_chunk($path, 'x', 0, 1, hash('sha256', 'x'), true)->get_error_code());
        }
        $outside = tempnam(sys_get_temp_dir(), 'in-synced-outside-'); file_put_contents($outside, 'outside');
        symlink($outside, $this->upload_root . '/photo.jpg');
        try { $this->assertSame('in_synced_symlink_denied', $this->file_chunk('photo.jpg', 'x', 0, 1, hash('sha256', 'x'), true)->get_error_code()); $this->assertSame('outside', file_get_contents($outside)); } finally { unlink($outside); }
    }
    public function test_repair_preflight_refuses_collision_with_unrelated_post_type(): void
    {
        global $wpdb;
        $id = self::factory()->post->create(['post_type' => 'page']);
        $response = $this->plugin->rest_validate_batch($this->request('validate-batch', ['source_prefix' => $wpdb->prefix, 'table' => $wpdb->posts, 'post_types' => ['event'], 'rows' => In_Synced_Safety::encode_rows([['ID' => (string) $id, 'post_type' => 'event']])]));
        $this->assertSame('in_synced_repair_collision', $response->get_error_code());
        $this->assertSame('page', get_post_type($id));
    }
    public function test_sender_refuses_self_push_and_never_sends_destructive_request(): void
    {
        $routes = [];
        $stub = static function ($pre, $args, $url) use (&$routes) { $routes[] = basename($url); return ['response' => ['code' => 200], 'body' => wp_json_encode(['ok' => true, 'site_url' => home_url()])]; };
        add_filter('pre_http_request', $stub, 10, 3); $log = [];
        try { $response = $this->plugin->run_sync('default', $log); } finally { remove_filter('pre_http_request', $stub, 10); }
        $this->assertSame('in_synced_self_push', $response->get_error_code());
        $this->assertNotContains('begin-db', $routes);
        $this->assertNotContains('table-batch', $routes);
    }
    public function test_transport_refuses_redirects_html_and_incomplete_acknowledgements(): void
    {
        $method = new ReflectionMethod(In_Synced::class, 'remote_request'); $method->setAccessible(true); $log = [];
        foreach ([[302, ''], [200, '<html>Login</html>'], [200, '{"ok":true,"inserted":0,"skipped":1}']] as $case) {
            $stub = static function ($pre, $args) use ($case) { if ($args['redirection'] !== 0) { throw new RuntimeException('Redirects must be disabled'); } return ['response' => ['code' => $case[0]], 'body' => $case[1]]; };
            add_filter('pre_http_request', $stub, 10, 2);
            try { $response = $method->invokeArgs($this->plugin, ['table-batch', ['rows' => [['id' => '1']]], &$log]); } finally { remove_filter('pre_http_request', $stub, 10); }
            $this->assertWPError($response);
        }
    }
    public function test_generated_schema_is_refused_before_mutation(): void
    {
        $tables = $this->manifest(); $tables[0]['schema'] = str_replace('longblob', 'int GENERATED ALWAYS AS (`id` + 1) STORED', $tables[0]['schema']);
        global $wpdb;
        $result = $this->plugin->rest_begin_db($this->request('begin-db', ['source_prefix' => $wpdb->prefix, 'mode' => 'replace', 'tables' => $tables]));
        $this->assertSame('in_synced_unsafe_schema', $result->get_error_code());
    }
    public function test_receiver_lease_blocks_a_site_already_sending(): void
    {
        $claim = new ReflectionMethod(In_Synced::class, 'claim_lease'); $claim->setAccessible(true);
        $sender = wp_generate_uuid4();
        $this->assertIsArray($claim->invoke($this->plugin, 'in_synced_site_lease', $sender));
        $this->assertSame('in_synced_busy', $this->plugin->rest_begin_sync($this->request('begin-sync', ['media' => true]))->get_error_code());
    }
    public function test_database_completion_keeps_lease_for_following_file_phase(): void
    {
        $this->begin();
        $this->assertInstanceOf(WP_REST_Response::class, $this->plugin->rest_end_db($this->request('end-db', [])));
        $owner = $this->session; $this->session = wp_generate_uuid4();
        $this->assertSame('in_synced_busy', $this->plugin->rest_begin_sync($this->request('begin-sync', ['media' => true]))->get_error_code());
        $this->session = $owner;
        $this->assertInstanceOf(WP_REST_Response::class, $this->plugin->rest_end_sync($this->request('end-sync', [])));
        $this->session = wp_generate_uuid4();
        $this->assertInstanceOf(WP_REST_Response::class, $this->plugin->rest_begin_sync($this->request('begin-sync', ['media' => true])));
    }
    public function test_expired_owner_cannot_import_after_another_session_claims_site(): void
    {
        global $wpdb; $this->begin(); $old = $this->session;
        update_option('in_synced_site_lease', wp_json_encode(['session' => $old, 'expires' => time() - 1, 'tables' => [$this->table], 'post_types' => []]));
        $this->session = wp_generate_uuid4(); $this->begin();
        $this->session = $old;
        $result = $this->plugin->rest_table_batch($this->request('table-batch', ['source_prefix' => $wpdb->prefix, 'table' => $this->table, 'rows' => In_Synced_Safety::encode_rows([['id' => '1', 'payload' => 'old-owner']])]));
        $this->assertSame('in_synced_wrong_session', $result->get_error_code());
        $this->assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM `' . $this->table . '`'));
    }
    private function source_profile(): void
    {
        $profiles = In_Synced::default_profiles();
        $profiles['default']['include_tables'] = 'in_synced_fixture';
        $profiles['default']['sync_media'] = false; $profiles['default']['sync_theme'] = false;
        update_option('in_synced_profiles', $profiles);
    }
    public function test_source_transform_never_attempts_receive_lease_and_filters_transients(): void
    {
        global $wpdb;
        $method = new ReflectionMethod(In_Synced::class, 'transform_rows_for_remote'); $method->setAccessible(true);
        $rows = [['option_name' => 'normal', 'option_value' => 'ok'], ['option_name' => '_transient_in_synced_progress_x', 'option_value' => 'private'], ['option_name' => 'in_synced_settings', 'option_value' => 'secret']];
        $this->assertSame([$rows[0]], $method->invoke($this->plugin, $wpdb->options, $rows, []));
    }
    public function test_source_count_read_failure_never_sends_destructive_begin(): void
    {
        global $wpdb; $this->source_profile(); $routes = []; $table = $this->table;
        $stub = static function ($pre, $args, $url) use (&$routes) { $routes[] = basename($url); return ['response' => ['code' => 200], 'body' => wp_json_encode(['ok' => true, 'site_url' => 'https://receiver.example'])]; };
        $fail = static function ($sql) use ($table) { return strpos($sql, 'SELECT COUNT(*) FROM `' . $table . '`') === 0 ? 'SELECT unknown_in_synced_test FROM `' . $table . '`' : $sql; };
        add_filter('pre_http_request', $stub, 10, 3); add_filter('query', $fail); $old = $wpdb->suppress_errors(true); $log = [];
        try { $result = $this->plugin->run_sync('default', $log, ['mode' => 'replace']); } finally { remove_filter('pre_http_request', $stub, 10); remove_filter('query', $fail); $wpdb->suppress_errors($old); }
        $this->assertSame('in_synced_source_count', $result->get_error_code());
        $this->assertNotContains('begin-db', $routes); $this->assertNotContains('table-batch', $routes);
    }
    public function test_uncertain_network_and_proxy_timeout_do_not_release_remote_lease(): void
    {
        $this->source_profile();
        foreach ([new WP_Error('http_request_failed', 'timeout'), ['response' => ['code' => 504], 'body' => 'upstream timeout'], ['response' => ['code' => 200], 'body' => '<html>proxy failure</html>']] as $timeout) {
            $routes = [];
            $stub = static function ($pre, $args, $url) use (&$routes, $timeout) { $route = basename($url); $routes[] = $route; return $route === 'begin-db' ? $timeout : ['response' => ['code' => 200], 'body' => wp_json_encode(['ok' => true, 'site_url' => 'https://receiver.example'])]; };
            add_filter('pre_http_request', $stub, 10, 3); $log = [];
            try { $result = $this->plugin->run_sync('default', $log, ['mode' => 'replace']); } finally { remove_filter('pre_http_request', $stub, 10); }
            $this->assertWPError($result); $this->assertContains('begin-db', $routes); $this->assertNotContains('end-sync', $routes);
        }
    }
    public function test_cancellation_between_requests_stops_and_releases_acknowledged_run(): void
    {
        $this->source_profile(); $routes = []; $run = 'run-test-cancel';
        $stub = static function ($pre, $args, $url) use (&$routes, $run) { $route = basename($url); $routes[] = $route; if ($route === 'begin-sync') { add_option('in_synced_cancel_' . $run, time(), '', false); } return ['response' => ['code' => 200], 'body' => wp_json_encode(['ok' => true, 'site_url' => 'https://receiver.example'])]; };
        add_filter('pre_http_request', $stub, 10, 3); $log = [];
        try { $result = $this->plugin->run_sync('default', $log, [], $run); } finally { remove_filter('pre_http_request', $stub, 10); delete_option('in_synced_cancel_' . $run); }
        $this->assertSame('in_synced_cancelled', $result->get_error_code());
        $this->assertNotContains('begin-db', $routes); $this->assertContains('end-sync', $routes);
        $this->assertFalse(get_option('in_synced_site_lease'));
    }

    public function test_binary_columns_do_not_receive_url_replacements(): void
    {
        $method = new ReflectionMethod(In_Synced::class, 'transform_rows_for_remote'); $method->setAccessible(true);
        $bytes = "\x00\xffhttps://source.example/path\x80";
        $rows = [['id' => '1', 'payload' => $bytes]];
        $this->assertSame($rows, $method->invoke($this->plugin, $this->table, $rows, ['https://source.example' => 'https://receiver.example']));
    }

    public function test_protected_option_in_later_row_rejects_whole_batch(): void
    {
        global $wpdb;
        $schema = $wpdb->get_row('SHOW CREATE TABLE `' . $wpdb->options . '`', ARRAY_N);
        $columns = $wpdb->get_col('SHOW COLUMNS FROM `' . $wpdb->options . '`');
        $this->assertInstanceOf(WP_REST_Response::class, $this->plugin->rest_begin_db($this->request('begin-db', ['source_prefix' => $wpdb->prefix, 'mode' => 'upsert', 'tables' => [['source' => $wpdb->options, 'schema' => $schema[1], 'columns' => $columns]]])));
        // Use a non-plugin name for the first row to prove no partial write occurs.
        $option = 'test_normal_' . wp_generate_password(8, false, false);
        $rows = [['option_name' => $option, 'option_value' => 'ordinary', 'autoload' => 'no'], ['option_name' => '_transient_in_synced_progress_secret', 'option_value' => 'private', 'autoload' => 'no']];
        $response = $this->plugin->rest_table_batch($this->request('table-batch', ['source_prefix' => $wpdb->prefix, 'table' => $wpdb->options, 'rows' => In_Synced_Safety::encode_rows($rows)]));
        $this->assertSame('in_synced_protected_option', $response->get_error_code());
        $this->assertFalse(get_option($option));
    }
    public function test_signed_scalar_body_is_refused_without_type_failure(): void
    {
        $request = $this->request('ping', []); $request->set_body('"scalar"');
        $timestamp = (string) time(); $nonce = wp_generate_uuid4();
        $request->set_header('X-In-Synced-Timestamp', $timestamp); $request->set_header('X-In-Synced-Nonce', $nonce);
        $request->set_header('X-In-Synced-Signature', In_Synced_Safety::signature('POST', $request->get_route(), $timestamp, $nonce, $request->get_body(), str_repeat('s', 48)));
        $this->assertSame('in_synced_bad_payload', $this->plugin->authorize_rest_request($request)->get_error_code());
    }

}
