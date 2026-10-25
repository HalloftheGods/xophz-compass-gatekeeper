<?php
/**
 * Deprecated: the XP Pro webhook intake is Gatekeeper_License_Intake now, which serves every
 * registered product. This shim keeps the old class name and its webhook option constant for one
 * release (the admin page read the constant); nothing in Gatekeeper calls it.
 *
 * @deprecated Use Gatekeeper_License_Intake and the product's webhooks_option.
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gatekeeper-license-intake.php';

class Gatekeeper_Xp_Pro_Intake {

	const OPTION_WEBHOOKS = 'gatekeeper_xp_pro_webhooks';

	/**
	 * @param array|object $payload
	 * @param int|string   $webhook_id
	 */
	public static function handle( $payload, $webhook_id ): void {
		Gatekeeper_License_Intake::handle( $payload, $webhook_id );
	}
}
