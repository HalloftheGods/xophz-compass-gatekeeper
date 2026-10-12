<?php
/**
 * RevenueCat adapter for XP Pro: turns a webhook v1 payload into one license operation.
 *
 * Pure: a payload goes in, an operation comes out. app_user_id is the family id.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_Xp_Pro_RevenueCat {

	const PRODUCT_PLANS = array(
		'xp_pro_monthly'  => 'monthly',
		'xp_pro_yearly'   => 'yearly',
		'xp_pro_lifetime' => 'lifetime',
	);

	const SUBSCRIPTION_EVENTS = array( 'INITIAL_PURCHASE', 'RENEWAL', 'UNCANCELLATION', 'PRODUCT_CHANGE' );

	public static function event_id( array $payload ): string {
		$id = $payload['event']['id'] ?? '';
		return is_string( $id ) ? $id : '';
	}

	public static function to_op( array $payload ): array {
		$event = is_array( $payload['event'] ?? null ) ? $payload['event'] : array();
		$type  = is_string( $event['type'] ?? null ) ? $event['type'] : '';

		$family_id = $event['app_user_id'] ?? null;
		$isFamily  = is_string( $family_id ) && Gatekeeper_Xp_Pro_Licenses::is_uuid4( $family_id );
		if ( ! $isFamily ) {
			return self::none();
		}

		$env = ( $event['environment'] ?? '' ) === 'SANDBOX' ? 'sandbox' : 'production';

		if ( in_array( $type, self::SUBSCRIPTION_EVENTS, true ) ) {
			return self::subscription_grant( $event, $family_id, $env, $type );
		}

		switch ( $type ) {
			case 'NON_RENEWING_PURCHASE':
				return self::lifetime_grant( $event, $family_id, $env );
			case 'CANCELLATION':
				// Auto-renew off: the paid period still runs out. Only a support refund revokes.
				return ( $event['cancel_reason'] ?? '' ) === 'CUSTOMER_SUPPORT'
					? self::op( 'revoke', $family_id, $env )
					: self::none();
			case 'EXPIRATION':
				return self::op( 'expire', $family_id, $env );
		}

		return self::none();
	}

	private static function subscription_grant( array $event, string $family_id, string $env, string $type ): array {
		// RevenueCat reports the plan the family moved to in new_product_id; product_id is the old one.
		$product = 'PRODUCT_CHANGE' === $type && is_string( $event['new_product_id'] ?? null )
			? $event['new_product_id']
			: ( $event['product_id'] ?? null );
		$plan = is_string( $product ) ? ( self::PRODUCT_PLANS[ $product ] ?? null ) : null;

		$isSubscription = in_array( $plan, array( 'monthly', 'yearly' ), true );
		$ms             = $event['expiration_at_ms'] ?? null;
		if ( ! $isSubscription || ! is_numeric( $ms ) || $ms <= 0 ) {
			return self::none();
		}

		return self::op(
			'grant',
			$family_id,
			$env,
			array(
				'plan'       => $plan,
				'expires_at' => (int) floor( $ms / 1000 ),
				'refs'       => self::refs( $event, $product ),
			)
		);
	}

	private static function lifetime_grant( array $event, string $family_id, string $env ): array {
		$product = $event['product_id'] ?? null;
		if ( 'xp_pro_lifetime' !== $product ) {
			return self::none();
		}
		return self::op( 'grant', $family_id, $env, array( 'plan' => 'lifetime', 'expires_at' => null, 'refs' => self::refs( $event, $product ) ) );
	}

	private static function refs( array $event, string $product ): array {
		$refs  = array( 'product_id' => $product );
		$store = $event['store'] ?? null;
		if ( is_string( $store ) && '' !== $store ) {
			$refs['store'] = $store;
		}
		return $refs;
	}

	private static function none(): array {
		return array( 'action' => 'none', 'source' => 'revenuecat' );
	}

	private static function op( string $action, string $family_id, string $env, array $fields = array() ): array {
		return array_merge(
			array(
				'action'     => $action,
				'source'     => 'revenuecat',
				'family_id'  => $family_id,
				'ref'        => null,
				'plan'       => null,
				'expires_at' => null,
				'env'        => $env,
				'email'      => null,
				'refs'       => array(),
			),
			$fields
		);
	}
}
