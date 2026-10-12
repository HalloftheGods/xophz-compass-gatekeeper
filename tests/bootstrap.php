<?php
/**
 * Isolated bootstrap for Gatekeeper unit tests: in-memory options, a settable
 * environment type, recorded actions, and the Gatekeeper classes under test.
 * Loaded by tests/php.mjs ahead of each snippet. No WordPress install or database.
 */

define( 'ABSPATH', '/var/www/html/' );

$GLOBALS['gk_options']  = array();
$GLOBALS['gk_env_type'] = 'production';
$GLOBALS['gk_users']    = array();
$GLOBALS['gk_actions']  = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['gk_options'] ) ? $GLOBALS['gk_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['gk_options'][ $key ] = $value;
	return true;
}
function wp_get_environment_type() {
	return $GLOBALS['gk_env_type'];
}
function current_time( $type ) {
	return gmdate( 'Y-m-d H:i:s' );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function sanitize_email( $email ) {
	return filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
}
function get_user_by( $field, $value ) {
	foreach ( $GLOBALS['gk_users'] as $user ) {
		if ( $field === 'email' && strcasecmp( $user->user_email, (string) $value ) === 0 ) {
			return $user;
		}
	}
	return false;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
function do_action( $hook, ...$args ) {
	$GLOBALS['gk_actions'][] = array( 'hook' => $hook, 'args' => $args );
}

// The base class lives in COMPASS Core; the webhook handler needs none of it.
abstract class Xophz_Compass_Plugin_Base {}

$gk_dir = dirname( __DIR__ ) . '/includes/';
require_once $gk_dir . 'class-gatekeeper-keys.php';
require_once $gk_dir . 'class-gatekeeper-licenses.php';
require_once $gk_dir . 'class-gatekeeper-entitlements.php';
require_once $gk_dir . 'class-xophz-compass-gatekeeper.php';
