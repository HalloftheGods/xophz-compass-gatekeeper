<?php
/**
 * XP Pro webhook intake: Hookshot delivers a Stripe or RevenueCat event, this turns it into a
 * license operation.
 *
 * A payload is processed only when its webhook id is one of the two configured in the option
 * gatekeeper_xp_pro_webhooks AND Hookshot verified the request's signature or bearer header in
 * this request. The webhook id picks the adapter; the payload's shape never does.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_Xp_Pro_Intake {

	const OPTION_WEBHOOKS = 'gatekeeper_xp_pro_webhooks';

	/**
	 * @param array|object $payload
	 * @param int|string   $webhook_id
	 */
	public static function handle( $payload, $webhook_id ): void {
		$source = self::source_for( $webhook_id );
		if ( null === $source ) {
			return;
		}

		$isVerified = class_exists( 'Hookshot_Signature' ) && Hookshot_Signature::was_verified( $webhook_id );
		if ( ! $isVerified ) {
			error_log( sprintf( 'Gatekeeper XP Pro: dropped an unverified delivery on webhook %d.', (int) $webhook_id ) );
			return;
		}

		if ( is_object( $payload ) ) {
			$payload = json_decode( wp_json_encode( $payload ), true );
		}
		if ( ! is_array( $payload ) ) {
			return;
		}

		$adapter  = 'stripe' === $source ? 'Gatekeeper_Xp_Pro_Stripe' : 'Gatekeeper_Xp_Pro_RevenueCat';
		$op       = $adapter::to_op( $payload );
		$event_id = $adapter::event_id( $payload );

		$result = Gatekeeper_Xp_Pro_Licenses::apply( $op, $event_id );

		$shouldEmail = $result['created_key'] && ! empty( $result['record']['email'] );
		if ( $shouldEmail ) {
			self::email_key( $result['record'] );
		}
	}

	/** 'stripe' or 'revenuecat' when the id is a configured XP Pro webhook (slots stripe, stripe_test, revenuecat), otherwise null. */
	public static function source_for( $webhook_id ): ?string {
		$id = (int) $webhook_id;
		if ( $id <= 0 ) {
			return null;
		}
		$configured = get_option( self::OPTION_WEBHOOKS, array() );
		if ( ! is_array( $configured ) ) {
			return null;
		}
		// Live and test Stripe webhooks both feed the Stripe adapter; the event's livemode sets env.
		foreach ( array( 'stripe' => 'stripe', 'stripe_test' => 'stripe', 'revenuecat' => 'revenuecat' ) as $slot => $source ) {
			if ( (int) ( $configured[ $slot ] ?? 0 ) === $id ) {
				return $source;
			}
		}
		return null;
	}

	public static function email_key( array $record ): void {
		$support = (string) get_option( 'admin_email', '' );
		$lines   = array(
			'Thank you for getting XP Pro.',
			'',
			'Your key: ' . $record['license_key'],
			'',
			'XP Pro is already on the device you bought it from. To use it on another device:',
			'1. Open Do It For The XP.',
			'2. Go to Settings > XP Pro > Restore with key.',
			'3. Type the key above.',
			'',
			'Keep this email. The key works on up to 10 devices.',
		);
		if ( '' !== $support ) {
			$lines[] = 'Questions? Write to ' . $support . '.';
		}

		wp_mail( $record['email'], 'Your XP Pro key', implode( "\n", $lines ) );
	}
}
