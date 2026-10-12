<?php
/**
 * Stripe adapter for XP Pro: turns a Stripe event into one license operation.
 *
 * Pure: an event goes in, an operation comes out, and nothing is read or written.
 * The operation shape is the one Gatekeeper_Xp_Pro_Licenses::apply() takes.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_Xp_Pro_Stripe {

	const PRODUCT      = 'doitforthexp';
	const PLANS        = array( 'monthly', 'yearly', 'lifetime' );
	const TRIAL_SECONDS = 604800; // 7 days.

	public static function event_id( array $event ): string {
		$id = $event['id'] ?? '';
		return is_string( $id ) ? $id : '';
	}

	/**
	 * @param int|null $now Unix time, for the trial window; defaults to time().
	 */
	public static function to_op( array $event, ?int $now = null ): array {
		$now    = $now ?? time();
		$type   = is_string( $event['type'] ?? null ) ? $event['type'] : '';
		$object = is_array( $event['data']['object'] ?? null ) ? $event['data']['object'] : array();
		$env    = ! empty( $event['livemode'] ) ? 'production' : 'sandbox';

		switch ( $type ) {
			case 'checkout.session.completed':
				return self::from_checkout( $object, $env, $now );
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

	private static function from_checkout( array $session, string $env, int $now ): array {
		$meta = is_array( $session['metadata'] ?? null ) ? $session['metadata'] : array();
		if ( ( $meta['product'] ?? null ) !== self::PRODUCT ) {
			return self::none();
		}

		$plan      = $meta['plan'] ?? null;
		$family_id = $meta['family_id'] ?? null;
		$isUsable  = is_string( $plan ) && in_array( $plan, self::PLANS, true )
			&& is_string( $family_id ) && Gatekeeper_Xp_Pro_Licenses::is_uuid4( $family_id );
		if ( ! $isUsable ) {
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
				'family_id'  => $family_id,
				'plan'       => $plan,
				'expires_at' => 'lifetime' === $plan ? null : self::subscription_end( $session['subscription'] ?? null, $now ),
				'email'      => is_string( $email ) && '' !== $email ? $email : null,
				'refs'       => $refs,
			)
		);
	}

	/** trial_end, else current_period_end, from an expanded subscription; else a 7 day trial window. */
	private static function subscription_end( $subscription, int $now ): int {
		if ( is_array( $subscription ) ) {
			$item = $subscription['items']['data'][0] ?? array();
			foreach ( array( $subscription['trial_end'] ?? null, $subscription['current_period_end'] ?? null, $item['current_period_end'] ?? null ) as $candidate ) {
				if ( is_int( $candidate ) && $candidate > 0 ) {
					return $candidate;
				}
			}
		}
		return $now + self::TRIAL_SECONDS;
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

		$pi  = self::id_of( $charge['payment_intent'] ?? null );
		$cus = self::id_of( $charge['customer'] ?? null );
		$cus_ref = null === $cus ? null : array( 'type' => 'cus', 'id' => $cus );

		if ( null !== $pi ) {
			return self::op( 'revoke', $env, array( 'ref' => array( 'type' => 'pi', 'id' => $pi ), 'fallback_ref' => $cus_ref ) );
		}
		if ( null !== $cus_ref ) {
			return self::op( 'revoke', $env, array( 'ref' => $cus_ref ) );
		}
		return self::none();
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
