<?php
/**
 * Plugin Name:       Xophz Gatekeeper
 * Description:       Universal authentication, API key authority, and machine-to-machine gating for COMPASS and YouMeOS.
 * Version:           26.10.25-1087
 * Author:            Hall of the Gods, Inc.
 * Category:          Castle Walls
 * Group:             Security / Identity
 * Text Domain:       xophz-compass-gatekeeper
 * Domain Path:       /languages
 * Update URI:        https://github.com/HalloftheGods/xophz-compass-gatekeeper
 * Color:             #9333EA
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_COMPASS_GATEKEEPER_VERSION', '26.10.25-1087' );
define( 'XOPHZ_COMPASS_GATEKEEPER_PATH', plugin_dir_path( __FILE__ ) );
define( 'XOPHZ_COMPASS_GATEKEEPER_URL', plugin_dir_url( __FILE__ ) );

// Load base class from core or fallback
if ( ! class_exists( 'Xophz_Compass_Plugin_Base' ) ) {
	$core_base = dirname( __DIR__ ) . '/xophz-compass/includes/core/class-compass-plugin-base.php';
	if ( file_exists( $core_base ) ) {
		require_once $core_base;
	}
}

require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-keys.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-licenses.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-entitlements.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-route-guard.php';
// The license engine: product registry, shared db primitives, the per-product store and the helper functions.
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-license-db.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-license-products.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-license-store.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/gatekeeper-license-functions.php';
// The Stripe and RevenueCat adapters, the webhook intake, the per-product REST routes and the setup API.
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-license-stripe.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-license-revenuecat.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-license-intake.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-license-setup.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-license-rest.php';
// XP Pro: the facade and alias (one release).
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-xp-pro-db.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-xp-pro-licenses.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-gatekeeper-rest.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'admin/class-xophz-compass-gatekeeper-admin.php';
require_once XOPHZ_COMPASS_GATEKEEPER_PATH . 'includes/class-xophz-compass-gatekeeper.php';

function run_xophz_compass_gatekeeper() {
	$plugin = new Xophz_Compass_Gatekeeper( 'gatekeeper', XOPHZ_COMPASS_GATEKEEPER_VERSION, __FILE__ );
	$plugin->run();
}

add_action( 'plugins_loaded', 'run_xophz_compass_gatekeeper' );

register_activation_hook( __FILE__, array( 'Xophz_Compass_Gatekeeper', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Xophz_Compass_Gatekeeper', 'deactivate' ) );
