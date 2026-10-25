<?php
/**
 * Stripe adapter for Gatekeeper license products: turns a Stripe event into one license operation.
 *
 * Pure: an event and a product config go in, an operation comes out, and nothing is read or
 * written. Everything product specific comes from the product array (see Gatekeeper_License_Products):
 *
 *   checkout_product   the Stripe metadata.product a checkout must carry; any other checkout is none
 *   plans              the plan catalog keyed by plan id (a callable returning it, or the array);
 *                      metadata.plan must be one of its keys, and the plan whose mode is payment
 *                      is the lifetime plan (no expiry)
 *   account_param      the metadata field that carries the account id (default family_id)
 *   account_validator  callable(string): bool for that id (default: lowercase uuid4)
 *   trial_seconds      the trial window when the session carries no subscription period
 *
 * Invoices, subscription endings, refunds and disputes carry no product: they name a Stripe
 * reference, and the store looks it up among that product's own records, so a reference that
 * belongs to another product matches nothing.
 *
 * The operation shape is the one Gatekeeper_Xp_Pro_Licenses::apply() takes; it still names the
 * account `family_id`.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_License_Stripe {

	const DEFAULT_ACCOUNT_PARAM  = 'family_id';
	const DEFAULT_TRIAL_SECONDS  = 604800; // 7 days.
	const UUID4_PATTERN          = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

	public static function event_id( array $event ): string {
		$id = $event['id'] ?? '';
		return is_string( $id ) ? $id : '';
	}

	/**
	 * @param array    $product The product config (checkout_product, plans, account_param, account_validator, trial_seconds).
	 * @param int|null $now     Unix time, for the trial window; defaults to time().
	 */
	public static function to_op( array $event, array $product, ?int $now = null ): array {
		$now    = $now ?? time();
		$type   = is_string( $event['type'] ?? null ) ? $event['type'] : '';
		$object = is_array( $event['data']['object'] ?? null ) ? $event['data']['object'] : array();
		$env    = ! empty( $event['livemode'] ) ? 'production' : 'sandbox';

		switch ( $type ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				return self::from_checkout( $object, $product, $env, $now );
			case 'checkout.session.async_payment_failed':
				return self::from_failed_payment( $object, $product, $env );
			case 'invoice.paid':
				return self::from_invoice( $object, $env );
			case 'customer.subscription.deleted':
				$sub = self::id_of( $object['id'] ?? null );
				return null === $sub ? self::none() : self::op( 'expire', $env, array( 'ref' => array( 'type' => 'sub', 'id' => $sub ) ) );
			case 'charge.refunded':
				return self::from_refund( $object, $env );
			case 'charge.dispute.created':
				$pi = self::id_of( $object['payment_intent'] ?? null );
				return null === $pi ? self::none() : self::op( 'revoke', $env, array( 'ref' => array( 'type' => 'pi', 'id' => $pi ) ) );
		}

		return self::none();
	}

	private static function from_checkout( array $session, array $product, string $env, int $now ): array {
		$meta = is_array( $session['metadata'] ?? null ) ? $session['metadata'] : array();
		if ( ! self::is_product_checkout( $meta, $product ) ) {
			return self::none();
		}

		// A delayed payment method completes the session unpaid; the grant waits for async_payment_succeeded.
		$isPaid = in_array( $session['payment_status'] ?? null, array( 'paid', 'no_payment_required' ), true );
		if ( ! $isPaid ) {
			return self::none();
		}

		$plan_id = $meta['plan'] ?? null;
		$plans   = self::plans( $product );
		$plan    = is_string( $plan_id ) ? ( $plans[ $plan_id ] ?? null ) : null;
		$account = self::account( $meta, $product );
		if ( null === $plan || null === $account ) {
			return self::none();
		}

		$refs = array();
		foreach ( array( 'sub' => 'subscription', 'pi' => 'payment_intent', 'cus' => 'customer' ) as $key => $field ) {
			$id = self::id_of( $session[ $field ] ?? null );
			if ( null !== $id ) {
				$refs[ $key ] = $id;
			}
		}

		$email = $session['customer_details']['email'] ?? ( $session['customer_email'] ?? null );

		return self::op(
			'grant',
			$env,
			array(
				'family_id'  => $account,
				'plan'       => $plan_id,
				'expires_at' => self::is_lifetime( $plan ) ? null : self::subscription_end( $session['subscription'] ?? null, $product, $now ),
				'email'      => is_string( $email ) && '' !== $email ? $email : null,
				'refs'       => $refs,
			)
		);
	}

	/**
	 * A delayed payment that failed: revoke what that session bought, by payment intent (or by
	 * subscription for a subscription session). No customer fallback, so a failed attempt never
	 * touches another purchase, and a purchase that was never granted matches no record.
	 */
	private static function from_failed_payment( array $session, array $product, string $env ): array {
		$meta = is_array( $session['metadata'] ?? null ) ? $session['metadata'] : array();
		if ( ! self::is_product_checkout( $meta, $product ) ) {
			return self::none();
		}

		$pi  = self::id_of( $session['payment_intent'] ?? null );
		$sub = self::id_of( $session['subscription'] ?? null );
		if ( null !== $pi ) {
			return self::op( 'revoke', $env, array( 'ref' => array( 'type' => 'pi', 'id' => $pi ) ) );
		}
		if ( null !== $sub ) {
			return self::op( 'revoke', $env, array( 'ref' => array( 'type' => 'sub', 'id' => $sub ) ) );
		}
		return self::none();
	}

	/** trial_end, else current_period_end, from an expanded subscription; else the product's trial window. */
	private static function subscription_end( $subscription, array $product, int $now ): int {
		if ( is_array( $subscription ) ) {
			$item = $subscription['items']['data'][0] ?? array();
			foreach ( array( $subscription['trial_end'] ?? null, $subscription['current_period_end'] ?? null, $item['current_period_end'] ?? null ) as $candidate ) {
				if ( is_int( $candidate ) && $candidate > 0 ) {
					return $candidate;
				}
			}
		}
		$trial = $product['trial_seconds'] ?? null;
		return $now + ( is_int( $trial ) && $trial >= 0 ? $trial : self::DEFAULT_TRIAL_SECONDS );
	}

	private static function from_invoice( array $invoice, string $env ): array {
		$sub = self::id_of( $invoice['subscription'] ?? null )
			?? self::id_of( $invoice['parent']['subscription_details']['subscription'] ?? null );
		if ( null === $sub ) {
			return self::none();
		}

		$end = 0;
		foreach ( (array) ( $invoice['lines']['data'] ?? array() ) as $line ) {
			$candidate = $line['period']['end'] ?? null;
			if ( is_int( $candidate ) && $candidate > $end ) {
				$end = $candidate;
			}
		}
		if ( $end <= 0 ) {
			return self::none();
		}

		return self::op( 'extend', $env, array( 'ref' => array( 'type' => 'sub', 'id' => $sub ), 'expires_at' => $end ) );
	}

	/**
	 * A full refund revokes by payment intent; a charge with none (or one we never stored, such as a
	 * renewal invoice's intent) falls back to the customer, so a refunded subscription loses Pro.
	 */
	private static function from_refund( array $charge, string $env ): array {
		if ( true !== ( $charge['refunded'] ?? false ) ) {
			return self::none();
		}

		$pi      = self::id_of( $charge['payment_intent'] ?? null );
		$cus     = self::id_of( $charge['customer'] ?? null );
		$cus_ref = null === $cus ? null : array( 'type' => 'cus', 'id' => $cus );

		if ( null !== $pi ) {
			return self::op( 'revoke', $env, array( 'ref' => array( 'type' => 'pi', 'id' => $pi ), 'fallback_ref' => $cus_ref ) );
		}
		if ( null !== $cus_ref ) {
			return self::op( 'revoke', $env, array( 'ref' => $cus_ref ) );
		}
		return self::none();
	}

	/** The checkout belongs to this product: its metadata.product equals a non-empty checkout_product. */
	private static function is_product_checkout( array $meta, array $product ): bool {
		$checkout = $product['checkout_product'] ?? null;
		return is_string( $checkout ) && '' !== $checkout && ( $meta['product'] ?? null ) === $checkout;
	}

	/** The plan catalog keyed by plan id: the product's plans callable or array, minus anything that is not a plan. */
	private static function plans( array $product ): array {
		$plans = $product['plans'] ?? array();
		if ( is_callable( $plans ) ) {
			$plans = call_user_func( $plans );
		}
		if ( ! is_array( $plans ) ) {
			return array();
		}
		$catalog = array();
		foreach ( $plans as $id => $plan ) {
			if ( is_string( $id ) && '' !== $id && is_array( $plan ) ) {
				$catalog[ $id ] = $plan;
			}
		}
		return $catalog;
	}

	/** The plan whose mode is payment is the lifetime plan. */
	private static function is_lifetime( array $plan ): bool {
		return 'payment' === ( $plan['mode'] ?? null );
	}

	/** metadata.{account_param} when the product's validator (default: lowercase uuid4) accepts it. */
	private static function account( array $meta, array $product ): ?string {
		$param = $product['account_param'] ?? null;
		$param = is_string( $param ) && '' !== $param ? $param : self::DEFAULT_ACCOUNT_PARAM;

		$account = $meta[ $param ] ?? null;
		if ( ! is_string( $account ) ) {
			return null;
		}

		$validator = $product['account_validator'] ?? null;
		$isValid   = is_callable( $validator ) ? (bool) call_user_func( $validator, $account ) : 1 === preg_match( self::UUID4_PATTERN, $account );
		return $isValid ? $account : null;
	}

	/** A Stripe reference is a string id, or an expanded object that carries one. */
	private static function id_of( $value ): ?string {
		if ( is_array( $value ) ) {
			$value = $value['id'] ?? null;
		}
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	private static function none(): array {
		return array( 'action' => 'none', 'source' => 'stripe' );
	}

	private static function op( string $action, string $env, array $fields ): array {
		return array_merge(
			array(
				'action'     => $action,
				'source'     => 'stripe',
				'family_id'  => null,
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
