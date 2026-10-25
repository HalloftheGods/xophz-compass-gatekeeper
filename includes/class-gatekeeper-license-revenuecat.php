<?php
/**
 * RevenueCat adapter for Gatekeeper license products: turns a webhook v1 payload into one license
 * operation.
 *
 * Pure: a payload and a product config go in, an operation comes out. app_user_id is the account id.
 * Everything product specific comes from the product array (see Gatekeeper_License_Products):
 *
 *   plans              the plan catalog keyed by plan id (a callable returning it, or the array);
 *                      a RevenueCat product_id is mapped to a plan id through each plan's
 *                      store_product_id, and the plan whose mode is payment is the lifetime plan
 *   account_validator  callable(string): bool for app_user_id (default: lowercase uuid4)
 *
 * A purchase of a product_id outside the catalog is none, and so is an ending (expiration, support
 * refund) that names one, so a store product that belongs to another product never touches this
 * product's records. An ending that names no product still reaches the account.
 *
 * The operation shape is the one Gatekeeper_Xp_Pro_Licenses::apply() takes; it still names the
 * account `family_id`.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_License_RevenueCat {

	const SUBSCRIPTION_EVENTS = array( 'INITIAL_PURCHASE', 'RENEWAL', 'UNCANCELLATION', 'PRODUCT_CHANGE' );
	const UUID4_PATTERN       = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

	public static function event_id( array $payload ): string {
		$id = $payload['event']['id'] ?? '';
		return is_string( $id ) ? $id : '';
	}

	/**
	 * @param array $product The product config (plans, account_validator).
	 */
	public static function to_op( array $payload, array $product ): array {
		$event = is_array( $payload['event'] ?? null ) ? $payload['event'] : array();
		$type  = is_string( $event['type'] ?? null ) ? $event['type'] : '';

		$account = $event['app_user_id'] ?? null;
		if ( ! is_string( $account ) || ! self::is_account( $account, $product ) ) {
			return self::none();
		}

		$env   = ( $event['environment'] ?? '' ) === 'SANDBOX' ? 'sandbox' : 'production';
		$store = self::store_plans( $product );

		if ( in_array( $type, self::SUBSCRIPTION_EVENTS, true ) ) {
			return self::subscription_grant( $event, $store, $account, $env, $type );
		}

		switch ( $type ) {
			case 'NON_RENEWING_PURCHASE':
				return self::lifetime_grant( $event, $store, $account, $env );
			case 'CANCELLATION':
				// Auto-renew off: the paid period still runs out. Only a support refund revokes.
				return ( $event['cancel_reason'] ?? '' ) === 'CUSTOMER_SUPPORT'
					? self::ending( 'revoke', $event, $store, $account, $env )
					: self::none();
			case 'EXPIRATION':
				return self::ending( 'expire', $event, $store, $account, $env );
		}

		return self::none();
	}

	private static function subscription_grant( array $event, array $store, string $account, string $env, string $type ): array {
		// RevenueCat reports the plan the account moved to in new_product_id; product_id is the old one.
		$product = 'PRODUCT_CHANGE' === $type && is_string( $event['new_product_id'] ?? null )
			? $event['new_product_id']
			: ( $event['product_id'] ?? null );
		$match = is_string( $product ) ? ( $store[ $product ] ?? null ) : null;

		$isSubscription = null !== $match && ! $match['lifetime'];
		$ms             = $event['expiration_at_ms'] ?? null;
		if ( ! $isSubscription || ! is_numeric( $ms ) || $ms <= 0 ) {
			return self::none();
		}

		return self::op(
			'grant',
			$account,
			$env,
			array(
				'plan'       => $match['plan'],
				'expires_at' => (int) floor( $ms / 1000 ),
				'refs'       => self::refs( $event, $product ),
			)
		);
	}

	private static function lifetime_grant( array $event, array $store, string $account, string $env ): array {
		$product = $event['product_id'] ?? null;
		$match   = is_string( $product ) ? ( $store[ $product ] ?? null ) : null;
		if ( null === $match || ! $match['lifetime'] ) {
			return self::none();
		}
		return self::op( 'grant', $account, $env, array( 'plan' => $match['plan'], 'expires_at' => null, 'refs' => self::refs( $event, $product ) ) );
	}

	/**
	 * An expiration or a support refund. It names the product it is about, so the store can tell a
	 * lifetime refund from a subscription one; a product outside the catalog belongs to someone else.
	 */
	private static function ending( string $action, array $event, array $store, string $account, string $env ): array {
		$product = $event['product_id'] ?? null;
		if ( ! is_string( $product ) || '' === $product ) {
			return self::op( $action, $account, $env );
		}
		if ( ! isset( $store[ $product ] ) ) {
			return self::none();
		}
		return self::op( $action, $account, $env, array( 'refs' => array( 'product_id' => $product ) ) );
	}

	private static function refs( array $event, string $product ): array {
		$refs  = array( 'product_id' => $product );
		$store = $event['store'] ?? null;
		if ( is_string( $store ) && '' !== $store ) {
			$refs['store'] = $store;
		}
		return $refs;
	}

	/** app_user_id is usable when the product's validator (default: lowercase uuid4) accepts it. */
	private static function is_account( string $account, array $product ): bool {
		$validator = $product['account_validator'] ?? null;
		return is_callable( $validator ) ? (bool) call_user_func( $validator, $account ) : 1 === preg_match( self::UUID4_PATTERN, $account );
	}

	/**
	 * The catalog indexed by store product id: [ product_id => [ 'plan' => plan id, 'lifetime' => bool ] ].
	 * The plans are a callable returning the catalog keyed by plan id, or that array; a plan without a
	 * store_product_id is not sold in a store, and the first plan wins a store id two plans share.
	 */
	private static function store_plans( array $product ): array {
		$plans = $product['plans'] ?? array();
		if ( is_callable( $plans ) ) {
			$plans = call_user_func( $plans );
		}
		if ( ! is_array( $plans ) ) {
			return array();
		}

		$store = array();
		foreach ( $plans as $id => $plan ) {
			$store_id = is_array( $plan ) ? ( $plan['store_product_id'] ?? null ) : null;
			if ( ! is_string( $id ) || '' === $id || ! is_string( $store_id ) || '' === $store_id || isset( $store[ $store_id ] ) ) {
				continue;
			}
			$store[ $store_id ] = array(
				'plan'     => $id,
				'lifetime' => 'payment' === ( $plan['mode'] ?? null ),
			);
		}
		return $store;
	}

	private static function none(): array {
		return array( 'action' => 'none', 'source' => 'revenuecat' );
	}

	private static function op( string $action, string $account, string $env, array $fields = array() ): array {
		return array_merge(
			array(
				'action'     => $action,
				'source'     => 'revenuecat',
				'family_id'  => $account,
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
