<?php
/**
 * RevenueCat adapter for XP Pro, kept for the XP Pro intake until it moves to the generic one.
 *
 * Thin shim over Gatekeeper_License_RevenueCat with the old signature and the fixed xp-pro product
 * (Gatekeeper_Xp_Pro_Stripe::product()). Delete this file, and the Stripe shim beside it, once
 * Gatekeeper_License_Intake takes the events by product.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gatekeeper-license-revenuecat.php';
require_once __DIR__ . '/class-gatekeeper-xp-pro-stripe.php';

class Gatekeeper_Xp_Pro_RevenueCat {

	public static function event_id( array $payload ): string {
		return Gatekeeper_License_RevenueCat::event_id( $payload );
	}

	public static function to_op( array $payload ): array {
		return Gatekeeper_License_RevenueCat::to_op( $payload, Gatekeeper_Xp_Pro_Stripe::product() );
	}
}
