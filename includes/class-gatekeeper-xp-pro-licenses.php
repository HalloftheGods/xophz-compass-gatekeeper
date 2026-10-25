<?php
/**
 * XP Pro license facade, kept for one release: every call delegates to Gatekeeper_License_Store
 * with the xp-pro product, so the XP plugin's family gate and the code that has not moved yet keep
 * working. New code calls the store (product id first) or the gatekeeper_license_* helper functions
 * in gatekeeper-license-functions.php. Removed in the release after the XP plugin ships its own
 * registration.
 *
 * @deprecated Use Gatekeeper_License_Store with 'xp-pro' or the gatekeeper_license_* functions.
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gatekeeper-license-db.php';
require_once __DIR__ . '/class-gatekeeper-license-products.php';
require_once __DIR__ . '/class-gatekeeper-license-store.php';
require_once __DIR__ . '/gatekeeper-license-functions.php';

class Gatekeeper_Xp_Pro_Licenses {

	const PRODUCT       = 'xp-pro';
	const OPTION_PREFIX = 'gk_xp_pro_';
	const KEY_PREFIX    = 'XP-PRO-';
	const ALPHABET      = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	const GRACE_SECONDS = 259200;
	const MAX_DEVICES   = 10;
	const MAX_EVENTS    = Gatekeeper_License_Store::MAX_EVENTS;
	const REF_TYPES     = Gatekeeper_License_Store::REF_TYPES;

	public static function is_uuid4( string $s ): bool {
		return Gatekeeper_License_Products::is_uuid4( $s );
	}

	public static function is_device_id( string $s ): bool {
		return Gatekeeper_License_Products::is_device_id( $s );
	}

	public static function is_license_key( string $s ): bool {
		return Gatekeeper_License_Store::is_license_key( self::PRODUCT, $s );
	}

	public static function get( string $family_id ): ?array {
		return Gatekeeper_License_Store::get( self::PRODUCT, $family_id );
	}

	public static function find_by_key( string $key ): ?array {
		return Gatekeeper_License_Store::find_by_key( self::PRODUCT, $key );
	}

	public static function find_by_ref( string $type, string $id ): ?array {
		return Gatekeeper_License_Store::find_by_ref( self::PRODUCT, $type, $id );
	}

	public static function apply( array $op, string $event_id ): array {
		return Gatekeeper_License_Store::apply( self::PRODUCT, $op, $event_id );
	}

	public static function is_entitled( array $record, int $now ): bool {
		return Gatekeeper_License_Store::is_entitled( self::PRODUCT, $record, $now );
	}

	/** @return true|WP_Error */
	public static function attach_device( string $family_id, string $device_id ) {
		return Gatekeeper_License_Store::attach_device( self::PRODUCT, $family_id, $device_id );
	}

	public static function public_view( array $record, int $now ): array {
		return Gatekeeper_License_Store::public_view( self::PRODUCT, $record, $now );
	}

	public static function reset_devices( string $family_id ): bool {
		return Gatekeeper_License_Store::reset_devices( self::PRODUCT, $family_id );
	}

	public static function restore( string $family_id, int $user_id ): bool {
		return Gatekeeper_License_Store::restore( self::PRODUCT, $family_id, $user_id );
	}

	public static function update_admin( string $family_id, array $fields, int $user_id ): bool {
		return Gatekeeper_License_Store::update_admin( self::PRODUCT, $family_id, $fields, $user_id );
	}

	public static function find_by_email( string $email ): array {
		return Gatekeeper_License_Store::find_by_email( self::PRODUCT, $email );
	}

	public static function all(): array {
		return Gatekeeper_License_Store::all( self::PRODUCT );
	}
}
