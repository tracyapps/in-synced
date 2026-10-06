<?php
use PHPUnit\Framework\TestCase;

final class SafetyTest extends TestCase
{
    public function test_signature_matches_independent_v2_vector_and_binds_every_field(): void
    {
        $secret = str_repeat('s', 32);
        $expected = 'v2=' . hash_hmac('sha256', "POST\n/in-synced/v1/ping\n1700000000\n0123456789abcdef\n" . hash('sha256', '{"site":"test"}'), $secret);
        $this->assertSame($expected, In_Synced_Safety::signature('post', '/in-synced/v1/ping', '1700000000', '0123456789abcdef', '{"site":"test"}', $secret));
        foreach ([['GET', '/in-synced/v1/ping', '1700000000', '0123456789abcdef', '{"site":"test"}'], ['POST', '/in-synced/v1/begin-db', '1700000000', '0123456789abcdef', '{"site":"test"}'], ['POST', '/in-synced/v1/ping', '1700000001', '0123456789abcdef', '{"site":"test"}'], ['POST', '/in-synced/v1/ping', '1700000000', 'fedcba9876543210', '{"site":"test"}'], ['POST', '/in-synced/v1/ping', '1700000000', '0123456789abcdef', '{}']] as $fields) {
            $this->assertNotSame($expected, In_Synced_Safety::signature($fields[0], $fields[1], $fields[2], $fields[3], $fields[4], $secret));
        }
    }

    public function test_binary_rows_survive_json_round_trip_exactly(): void
    {
        $rows = [['ID' => '1', 'blob' => "\x00\xff\xfe\x80'\\", 'text' => '💜 café', 'absent' => null, 'integer' => 123]];
        $json = json_encode(In_Synced_Safety::encode_rows($rows));
        $this->assertIsString($json);
        $this->assertSame($rows, In_Synced_Safety::decode_rows(json_decode($json, true)));
        $this->assertInstanceOf(WP_Error::class, In_Synced_Safety::decode_rows([['blob' => ['encoding' => 'base64', 'data' => '@invalid']]]));
        $this->assertInstanceOf(WP_Error::class, In_Synced_Safety::decode_rows([['blob' => 'unencoded']]));
    }

    /** @dataProvider unsafe_paths */
    public function test_unsafe_paths_are_refused($path): void
    {
        $this->assertSame('', In_Synced_Safety::relative_path($path));
    }
    public function unsafe_paths(): array
    {
        return array_map(function ($value) { return [$value]; }, ['../x', 'a/../b', '/absolute', 'C:\\file.php', 'a//b', "a\0b", 'a/.', 'file.php ', 'file.php.', 'CON.png', 'a/NUL', ['bad']]);
    }

    /** @dataProvider executable_uploads */
    public function test_uploads_cannot_accept_executables_or_server_configuration($path): void
    {
        $this->assertFalse(In_Synced_Safety::upload_path_allowed($path));
    }
    public function executable_uploads(): array
    {
        return array_map(function ($value) { return [$value]; }, ['shell.php', 'shell.PHP7', 'image.php.jpg', 'shell.phtml', 'shell.phar', '.htaccess', '.user.ini', '2026/.hidden/x.jpg', 'web.config', 'shell.aspx', 'in-synced-backups/db.sql', 'file.cgi']);
    }
    public function test_regular_uploads_and_nontraversing_double_dots_are_allowed(): void
    {
        $this->assertTrue(In_Synced_Safety::upload_path_allowed('2026/10/photo..jpg'));
        $this->assertTrue(In_Synced_Safety::upload_path_allowed('documents/brochure.pdf'));
    }

    public function test_symlink_directory_and_file_cannot_escape_approved_root(): void
    {
        $dir = sys_get_temp_dir() . '/in-synced-unit-' . bin2hex(random_bytes(6));
        mkdir($dir); mkdir($dir . '/root'); mkdir($dir . '/outside');
        file_put_contents($dir . '/outside/file.txt', 'outside');
        symlink($dir . '/outside', $dir . '/root/link');
        symlink($dir . '/outside/file.txt', $dir . '/root/file.txt');
        try {
            $this->assertFalse(In_Synced_Safety::confined_path($dir . '/root', 'link/file.txt'));
            $this->assertFalse(In_Synced_Safety::confined_path($dir . '/root', 'file.txt'));
            $this->assertTrue(In_Synced_Safety::confined_path($dir . '/root', 'new/directory/file.txt'));
        } finally {
            unlink($dir . '/root/link'); unlink($dir . '/root/file.txt'); unlink($dir . '/outside/file.txt'); rmdir($dir . '/root'); rmdir($dir . '/outside'); rmdir($dir);
        }
    }

    public function test_ddl_is_one_create_and_cannot_escape_table_boundary(): void
    {
        $schema = "CREATE TABLE `src_posts` (\n `ID` bigint unsigned NOT NULL,\n `text` varchar(30) DEFAULT 'a; DROP TABLE x'\n) ENGINE=InnoDB AUTO_INCREMENT=123 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->assertStringStartsWith('CREATE TABLE `wp_posts`', In_Synced_Safety::create_table_sql($schema, 'src_posts', 'wp_posts'));
        foreach ([$schema . '; DROP TABLE wp_users', $schema . ' /* executable */', 'CREATE TABLE `src_posts_extra` (`ID` int)', 'CREATE TABLE `src_posts` (`ID` int REFERENCES `wp_users` (`ID`))', 'CREATE TABLE `src_posts` (`ID` int) ENGINE=FEDERATED CONNECTION=\'mysql://x\'', 'CREATE TABLE `src_posts` (`ID` int) AS SELECT * FROM wp_users', 'CREATE TABLE `src_posts` (`ID` int) DATA DIRECTORY=\'/tmp\'', 'CREATE TABLE `src_posts` (`ID` int);'] as $unsafe) {
            $this->assertSame('', In_Synced_Safety::create_table_sql($unsafe, 'src_posts', 'wp_posts'));
        }
    }

    public function test_schema_fingerprint_ignores_counter_and_table_name_but_detects_types_keys_and_nullability(): void
    {
        $one = 'CREATE TABLE `src_x` (`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB AUTO_INCREMENT=3';
        $two = 'CREATE TABLE `wp_x` (`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB AUTO_INCREMENT=9';
        $this->assertSame(In_Synced_Safety::schema_fingerprint($one), In_Synced_Safety::schema_fingerprint($two));
        foreach ([str_replace('int', 'bigint', $two), str_replace('NOT NULL', 'NULL', $two), str_replace(', PRIMARY KEY (`id`)', '', $two)] as $changed) {
            $this->assertNotSame(In_Synced_Safety::schema_fingerprint($one), In_Synced_Safety::schema_fingerprint($changed));
        }
    }

    public function test_schema_fingerprint_preserves_quoted_spaces_and_auto_increment_text(): void
    {
        $one = "CREATE TABLE `x` (`label` enum('one  two')) ENGINE=InnoDB";
        $this->assertNotSame(In_Synced_Safety::schema_fingerprint($one), In_Synced_Safety::schema_fingerprint(str_replace('one  two', 'one two', $one)));
        $one = "CREATE TABLE `x` (`label` varchar(50) DEFAULT 'AUTO_INCREMENT=3') ENGINE=InnoDB";
        $this->assertNotSame(In_Synced_Safety::schema_fingerprint($one), In_Synced_Safety::schema_fingerprint(str_replace('=3', '=9', $one)));
    }

    public function test_complete_writes_retry_short_writes_and_fail_on_stalled_stream(): void
    {
        stream_wrapper_register('insshort', InSyncedShortStream::class);
        try {
            $handle = fopen('insshort://ok', 'wb');
            $this->assertTrue(In_Synced_Safety::write_all($handle, '1234567'));
            $this->assertSame('1234567', InSyncedShortStream::$bytes); fclose($handle);
            $handle = fopen('insshort://stalled', 'wb');
            $this->assertFalse(In_Synced_Safety::write_all($handle, '123')); fclose($handle);
        } finally { stream_wrapper_unregister('insshort'); }
    }

    public function test_private_storage_rejects_public_roots_and_symlink_aliases(): void
    {
        $dir = sys_get_temp_dir() . '/in-synced-private-unit-' . bin2hex(random_bytes(6));
        mkdir($dir); mkdir($dir . '/public'); mkdir($dir . '/public/staged'); mkdir($dir . '/private');
        symlink($dir . '/public/staged', $dir . '/alias');
        try {
            $this->assertFalse(In_Synced_Safety::private_directory($dir . '/public', [$dir . '/public']));
            $this->assertFalse(In_Synced_Safety::private_directory($dir . '/alias', [$dir . '/public']));
            $this->assertTrue(In_Synced_Safety::private_directory($dir . '/private', [$dir . '/public']));
        } finally {
            unlink($dir . '/alias'); rmdir($dir . '/public/staged'); rmdir($dir . '/public'); rmdir($dir . '/private'); rmdir($dir);
        }
    }

    public function test_cross_device_atomic_replacement_fails_without_touching_live_file(): void
    {
        $live = tempnam(sys_get_temp_dir(), 'in-synced-live-');
        file_put_contents($live, 'old-live-file');
        $stat = stat(dirname($live));
        InSyncedDeviceStream::$device = $stat['dev'] + 1;
        stream_wrapper_register('insdevice', InSyncedDeviceStream::class);
        try {
            $this->assertTrue(In_Synced_Safety::same_filesystem($live, dirname($live)));
            $this->assertFalse(In_Synced_Safety::same_filesystem('insdevice://staged', dirname($live)));
            $this->assertFalse(In_Synced_Safety::atomic_replace('insdevice://staged', $live));
            $this->assertSame('old-live-file', file_get_contents($live));
        } finally { stream_wrapper_unregister('insdevice'); unlink($live); }
    }
}
class InSyncedShortStream {
    public $context;
    public static $bytes = '';
    private $stalled;
    public function stream_open($path, $mode, $options, &$opened_path) { self::$bytes = ''; $this->stalled = strpos($path, 'stalled') !== false; return true; }
    public function stream_write($data) { if ($this->stalled) { return 0; } $data = substr($data, 0, 2); self::$bytes .= $data; return strlen($data); }
    public function stream_close() {}
}
class InSyncedDeviceStream {
    public $context;
    public static $device;
    public function url_stat($path, $flags) { return ['dev' => self::$device, 'mode' => 0100600, 'size' => 1]; }
}
