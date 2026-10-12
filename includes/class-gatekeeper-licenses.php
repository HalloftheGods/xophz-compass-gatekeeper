<?php
/**
 * Gatekeeper Software License Authority
 *
 * Mints, activates, and cryptographically validates client software licenses
 * for Chemical X and COMPASS client tools (CX-ST-, CX-SP-, CX-VIP-).
 *
 * @package    Xophz_Compass_Gatekeeper
 * @subpackage Xophz_Compass_Gatekeeper/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_Licenses {

	const OPTION_LICENSES = 'compass_gatekeeper_licenses';

	/**
	 * Preview keys that unlock the VIP tier without a database record.
	 * Honoured only when is_dev_preview_enabled() is true.
	 */
	const DEV_PREVIEW_KEYS = array( 'CX-DEV-PREVIEW', 'QUANTUM-VIP' );

	/**
	 * Whether the developer preview keys are honoured on this site.
	 *
	 * True only when the WordPress environment type is 'local'
	 * (WP_ENVIRONMENT_TYPE constant or environment variable). WordPress
	 * reports 'production' when nothing is set, so the keys are off by default.
	 */
	public static function is_dev_preview_enabled(): bool {
		return function_exists( 'wp_get_environment_type' ) && wp_get_environment_type() === 'local';
	}

	/**
	 * Mint a new license key.
	 *
	 * @param string      $tier        Tier slug ('standard', 'solo_sponsor', 'team_power_puff', 'vip_bundle').
	 * @param string|null $github_user Linked GitHub username.
	 * @param string|null $email       Customer email address.
	 * @param int         $max_devices Maximum authorized devices (0 for unlimited).
	 * @return array
	 */
	public static function mint_license( string $tier, ?string $github_user = null, ?string $email = null, int $max_devices = 3 ): array {
		$prefix = 'CX-';
		if ( $tier === 'solo_sponsor' || $tier === 'sponsor' ) {
			$prefix = 'CX-SP-';
		} elseif ( $tier === 'team_power_puff' || $tier === 'vip_bundle' ) {
			$prefix = 'CX-VIP-';
			$max_devices = 0; // Unlimited for team
		} else {
			$prefix = 'CX-ST-';
		}

		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$seg   = function() use ( $chars ) {
			$s = '';
			for ( $i = 0; $i < 4; $i++ ) {
				$s .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
			}
			return $s;
		};

		$license_key = $prefix . $seg() . '-' . $seg() . '-' . $seg();
		$now         = current_time( 'mysql' );

		$record = array(
			'license_key'  => $license_key,
			'tier'         => sanitize_key( $tier ),
			'github_user'  => $github_user ? sanitize_text_field( $github_user ) : null,
			'email'        => $email ? sanitize_email( $email ) : null,
			'max_devices'  => $max_devices,
			'devices'      => array(),
			'status'       => 'active',
			'created_at'   => $now,
			'last_used_at' => null,
		);

		$all = self::get_all_licenses();
		$all[ $license_key ] = $record;
		update_option( self::OPTION_LICENSES, $all, false );

		return $record;
	}

	/**
	 * Validate a license key or GitHub sponsor handle.
	 *
	 * @param string      $key_or_user License key string or GitHub handle.
	 * @param string|null $device_id   Device identifier signature.
	 * @return array{valid: bool, tier?: string, is_sponsor?: bool, github_user?: string, error?: string, max_devices?: int, device_count?: int}
	 */
	public static function validate( string $key_or_user, ?string $device_id = null ): array {
		$raw = trim( $key_or_user );
		if ( empty( $raw ) ) {
			return array( 'valid' => false, 'error' => 'License key or GitHub username is required.' );
		}

		// 1. Check if checking by GitHub handle directly
		if ( strpos( $raw, 'CX-' ) !== 0 ) {
			$clean_handle = ltrim( sanitize_text_field( $raw ), '@' );
			$sponsor = Gatekeeper_Entitlements::get_sponsor( $clean_handle );
			if ( $sponsor && ( $sponsor['status'] ?? '' ) === 'active' ) {
				return array(
					'valid'       => true,
					'tier'        => $sponsor['tier'],
					'is_sponsor'  => true,
					'github_user' => $clean_handle,
					'nag_silenced'=> true,
				);
			}
		}

		// 2. Developer / preview keys, honoured only on a local development install
		$upper = strtoupper( $raw );
		if ( in_array( $upper, self::DEV_PREVIEW_KEYS, true ) && self::is_dev_preview_enabled() ) {
			return array(
				'valid'        => true,
				'tier'         => 'vip_bundle',
				'is_sponsor'   => false,
				'github_user'  => 'architect',
				'nag_silenced' => true,
				'max_devices'  => 0,
			);
		}

		// 3. Database lookup
		$all = self::get_all_licenses();
		$record = $all[ $upper ] ?? null;

		// Only keys minted into this database are valid. License keys carry no
		// signature, so a key's format proves nothing about whether it was sold.
		if ( ! $record ) {
			return array( 'valid' => false, 'error' => 'Unrecognized license key or inactive sponsorship.' );
		}

		if ( ( $record['status'] ?? '' ) !== 'active' ) {
			return array( 'valid' => false, 'error' => 'License key has been suspended or revoked.' );
		}

		// Check device limits
		$devices = (array) ( $record['devices'] ?? array() );
		if ( $device_id && ! in_array( $device_id, $devices, true ) ) {
			$max = (int) ( $record['max_devices'] ?? 3 );
			if ( $max > 0 && count( $devices ) >= $max ) {
				return array(
					'valid' => false,
					'error' => "Device limit reached ({$max} devices). Contact support to reset activations.",
				);
			}
			$devices[] = $device_id;
			$all[ $upper ]['devices'] = $devices;
		}

		$all[ $upper ]['last_used_at'] = current_time( 'mysql' );
		update_option( self::OPTION_LICENSES, $all, false );

		return array(
			'valid'        => true,
			'tier'         => $record['tier'],
			'is_sponsor'   => strpos( $upper, 'CX-SP-' ) === 0,
			'github_user'  => $record['github_user'],
			'nag_silenced' => true,
			'max_devices'  => (int) $record['max_devices'],
			'device_count' => count( $devices ),
		);
	}

	/**
	 * Retrieve all stored licenses.
	 */
	public static function get_all_licenses(): array {
		$licenses = get_option( self::OPTION_LICENSES, array() );
		return is_array( $licenses ) ? $licenses : array();
	}
}
