<?php
/**
 * License webhook intake: Hookshot delivers a Stripe or RevenueCat event, this turns it into a
 * license operation for the product that owns the webhook.
 *
 * A payload is processed only when its webhook id is one a registered product configured in its
 * webhooks_option (slots stripe, stripe_test, revenuecat) AND Hookshot verified the request's
 * signature or bearer header in this request. The webhook id picks the product and the adapter;
 * the payload's shape never does. A webhook id no product owns is ignored.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_License_Intake {

	/**
	 * @param array|object $payload
	 * @param int|string   $webhook_id
	 */
	public static function handle( $payload, $webhook_id ): void {
		$match = Gatekeeper_License_Products::by_webhook_id( $webhook_id );
		if ( null === $match ) {
			return;
		}
		list( $product, $source ) = $match;

		$isVerified = class_exists( 'Hookshot_Signature' ) && Hookshot_Signature::was_verified( $webhook_id );
		if ( ! $isVerified ) {
			error_log( sprintf( 'Gatekeeper %s: dropped an unverified delivery on webhook %d.', $product['label'], (int) $webhook_id ) );
			return;
		}

		if ( is_object( $payload ) ) {
			$payload = json_decode( wp_json_encode( $payload ), true );
		}
		if ( ! is_array( $payload ) ) {
			return;
		}

		$adapter  = 'stripe' === $source ? 'Gatekeeper_License_Stripe' : 'Gatekeeper_License_RevenueCat';
		$op       = self::name_account( $adapter::to_op( $payload, $product ), $product );
		$event_id = $adapter::event_id( $payload );

		$result = Gatekeeper_License_Store::apply( $product['id'], $op, $event_id );

		$shouldEmail = $result['created_key'] && ! empty( $result['record']['email'] );
		if ( $shouldEmail ) {
			self::email_key( $product, $result['record'] );
		}
	}

	/**
	 * The adapters name the account in the field family_id; the store reads the product's own
	 * account_param (family_id for xp-pro, so that product passes through unchanged).
	 */
	private static function name_account( array $op, array $product ): array {
		$field = (string) $product['account_param'];
		if ( 'family_id' !== $field && array_key_exists( 'family_id', $op ) ) {
			if ( null !== $op['family_id'] && ! array_key_exists( $field, $op ) ) {
				$op[ $field ] = $op['family_id'];
			}
			unset( $op['family_id'] );
		}
		return $op;
	}

	/**
	 * Mail the new key from the product's strings: key_email_subject, the lines key_email_lines
	 * builds from the record, and the support_line when the site has an admin email.
	 */
	public static function email_key( array $product, array $record ): void {
		$strings = (array) $product['strings'];
		$lines   = array();
		try {
			$built = call_user_func( $strings['key_email_lines'], $record );
			$lines = is_array( $built ) ? array_map( 'strval', array_values( $built ) ) : array();
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'Gatekeeper %s: the key email lines failed (%s); sending the key alone.', $product['label'], $e->getMessage() ) );
		}
		if ( empty( $lines ) ) {
			$lines = array( 'Your key: ' . (string) ( $record['license_key'] ?? '' ) );
		}

		$support = self::support_line( $product );
		if ( '' !== $support ) {
			$lines[] = $support;
		}

		wp_mail( $record['email'], (string) $strings['key_email_subject'], implode( "\n", $lines ) );
	}

	/** The product's support line with {support_email} replaced by the site's admin email; empty when there is none. */
	public static function support_line( array $product ): string {
		$support = (string) get_option( 'admin_email', '' );
		if ( '' === $support ) {
			return '';
		}
		return str_replace( '{support_email}', $support, (string) $product['strings']['support_line'] );
	}
}
