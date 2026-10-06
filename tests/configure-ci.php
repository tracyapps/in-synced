<?php
// Configure only the disposable CI WordPress test database.
$path = $argv[1];
$config = file_get_contents($path);
$config = preg_replace("/define\( 'DB_NAME',.*?\);/", "define( 'DB_NAME', 'in_synced_tests' );", $config);
$config = preg_replace("/define\( 'DB_USER',.*?\);/", "define( 'DB_USER', 'root' );", $config);
$config = preg_replace("/define\( 'DB_PASSWORD',.*?\);/", "define( 'DB_PASSWORD', 'root' );", $config);
$config = preg_replace("/define\( 'DB_HOST',.*?\);/", "define( 'DB_HOST', '127.0.0.1:3306' );", $config);
$config = preg_replace("/define\( 'ABSPATH',.*?\);/", "define( 'ABSPATH', " . var_export($argv[2], true) . " );", $config);
$config .= "\ndefine( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', " . var_export(dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills', true) . " );\n";
file_put_contents($path, $config);
