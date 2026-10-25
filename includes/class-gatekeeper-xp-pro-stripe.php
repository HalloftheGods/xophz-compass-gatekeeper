<?php
/**
 * Stripe adapter for XP Pro, kept for the XP Pro intake until it moves to the generic one.
 *
 * Thin shim over Gatekeeper_License_Stripe with the old signature and the fixed xp-pro product
 * (the built-in definition below is the one the adapter always used). Delete this file, and the
 * RevenueCat shim beside it, once Gatekeeper_License_Intake takes the events by product.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gatekeeper-license-stripe.php';

class Gatekeeper_Xp_Pro_Stripe {

	public static function event_id( array $event ): string {
		return Gatekeeper_License_Stripe::event_id( $event );
	}

	/**
	 * @param int|null $now Unix time, for the trial window; defaults to time().
	 */
	public static function to_op( array $event, ?int $now = null ): array {
		return Gatekeeper_License_Stripe::to_op( $event, self::product(), $now );
	}

	/** The xp-pro product as the adapter always read it: the doitforthexp checkout, three plans, a 7 day trial. */
	public static function product(): array {
		return array(
			'checkout_product' => 'doitforthexp',
			'account_param'    => 'family_id',
			'trial_seconds'    => 604800,
			'plans'            => array(
				'monthly'  => array( 'mode' => 'subscription', 'store_product_id' => 'xp_pro_monthly' ),
				'yearly'   => array( 'mode' => 'subscription', 'store_product_id' => 'xp_pro_yearly' ),
				'lifetime' => array( 'mode' => 'payment', 'store_product_id' => 'xp_pro_lifetime' ),
			),
		);
	}
}
