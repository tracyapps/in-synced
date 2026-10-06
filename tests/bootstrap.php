<?php
$test_dir = getenv('WP_TESTS_DIR');
if (!$test_dir || !file_exists($test_dir . '/includes/functions.php')) {
    fwrite(STDERR, "Set WP_TESTS_DIR to a WordPress PHPUnit test library configured for a disposable test database.\n");
    exit(1);
}
require $test_dir . '/includes/functions.php';
tests_add_filter('muplugins_loaded', function () { require dirname(__DIR__) . '/in-synced.php'; });
require $test_dir . '/includes/bootstrap.php';
