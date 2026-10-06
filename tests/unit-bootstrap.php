<?php
// Deliberately isolated from any WordPress database.
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    public $code;
    public function __construct($code, $message = '', $data = []) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function __($text, $domain = '') { return $text; }
function is_wp_error($value) { return $value instanceof WP_Error; }
require dirname(__DIR__) . '/includes/class-in-synced-safety.php';
