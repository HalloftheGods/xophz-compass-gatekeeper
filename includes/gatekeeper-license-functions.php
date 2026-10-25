<?php
/**
 * Helper functions other plugins call to read a license (like gatekeeper_guard). Consumers guard
 * with function_exists(), since Gatekeeper may be inactive.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gatekeeper_license_record' ) ) {
	/** The product's record for this account (family_id for xp-pro), or null. */
	function gatekeeper_license_record( string $product, string $account ): ?array {
		return Gatekeeper_License_Store::get( $product, $account );
	}
}

if ( ! function_exists( 'gatekeeper_license_entitled' ) ) {
	/** Whether the account holds an entitled license right now (or at $now). */
	function gatekeeper_license_entitled( string $product, string $account, ?int $now = null ): bool {
		$record = Gatekeeper_License_Store::get( $product, $account );
		return null !== $record && Gatekeeper_License_Store::is_entitled( $product, $record, null === $now ? time() : $now );
	}
}

if ( ! function_exists( 'gatekeeper_license_for_key' ) ) {
	/**
	 * The entitled record behind a license key on a device, attaching the device when it is new and
	 * there is room. Entitlement is checked before the device is attached, so a dead license never
	 * takes a slot. The caller decides about sandbox records (the AI route allows them only on local
	 * or development sites).
	 *
	 * @return array|WP_Error invalid_input 400, not_found 404, device_limit 409, pro_required 402, busy 503
	 */
	function gatekeeper_license_for_key( string $product, string $key, string $device ) {
		$config = Gatekeeper_License_Products::get( $product );
		if ( null === $config ) {
			return new WP_Error( 'invalid_input', 'Unknown license product.', array( 'status' => 400 ) );
		}
		$key = strtoupper( trim( $key ) );
		if ( ! Gatekeeper_License_Store::is_license_key( $product, $key ) || ! Gatekeeper_License_Store::is_device( $product, $device ) ) {
			return new WP_Error( 'invalid_input', 'Invalid license key or device id.', array( 'status' => 400 ) );
		}
		$record = Gatekeeper_License_Store::find_by_key( $product, $key );
		if ( null === $record ) {
			return new WP_Error( 'not_found', 'No license with this key.', array( 'status' => 404 ) );
		}
		if ( ! Gatekeeper_License_Store::is_entitled( $product, $record, time() ) ) {
			return new WP_Error( 'pro_required', 'This license is not active.', array( 'status' => 402 ) );
		}
		if ( ! in_array( $device, (array) ( $record['devices'] ?? array() ), true ) ) {
			$account  = (string) ( $record[ $config['account_param'] ] ?? '' );
			$attached = Gatekeeper_License_Store::attach_device( $product, $account, $device );
			if ( is_wp_error( $attached ) ) {
				return $attached;
			}
			$record = Gatekeeper_License_Store::get( $product, $account ) ?? $record;
		}
		return $record;
	}
}

if ( ! function_exists( 'gatekeeper_counter_bump' ) ) {
	/**
	 * Bump a named counter for the current fixed window of $ttl seconds (86400 for a UTC day) and
	 * return its value after the bump; $delta -1 takes one back, never below zero. Counters live in
	 * the options table and are deleted once their window is over.
	 */
	function gatekeeper_counter_bump( string $name, int $ttl, int $delta = 1 ): int {
		return Gatekeeper_License_Db::bump( $name, $ttl, $delta );
	}
}

if ( ! function_exists( 'gatekeeper_rate_limited' ) ) {
	/**
	 * True when the caller (client address, plus $scope such as the account id) has made more than
	 * $max requests in the current $window seconds for this bucket. Buckets are per product: pass
	 * "{product}_{route}".
	 *
	 * @param WP_REST_Request|null $request
	 */
	function gatekeeper_rate_limited( string $bucket, int $max, int $window, $request = null, string $scope = '' ): bool {
		return Gatekeeper_License_Db::rate_limited( $bucket, $max, $window, $request, $scope );
	}
}
