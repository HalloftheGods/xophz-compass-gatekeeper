<?php
/**
 * XP Pro license store: one record per family, kept in its own option.
 *
 * Records live at gk_xp_pro_{family_id} (autoload off), with lookup options
 * gk_xp_pro_key_{KEY}, gk_xp_pro_sub_{id}, gk_xp_pro_pi_{id} and gk_xp_pro_cus_{id}
 * that point back to the family id. These licenses never pass through
 * Gatekeeper_Licenses::validate() and never touch its option.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_Xp_Pro_Licenses {

	const OPTION_PREFIX = 'gk_xp_pro_';
	const KEY_PREFIX    = 'XP-PRO-';
	const ALPHABET      = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	const GRACE_SECONDS = 259200; // 3 days, covers store billing retries.
	const MAX_DEVICES   = 10;
	const MAX_EVENTS    = 50;
	const REF_TYPES     = array( 'sub', 'pi', 'cus' );

	/** Lowercase UUID v4. */
	public static function is_uuid4( string $s ): bool {
		return 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $s );
	}

	/** 8 to 64 characters of letters, digits, underscore and dash. */
	public static function is_device_id( string $s ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9_-]{8,64}\z/', $s );
	}

	/** A license key as handed out: XP-PRO-XXXX-XXXX-XXXX from the unambiguous alphabet. */
	public static function is_license_key( string $s ): bool {
		return 1 === preg_match( '/\AXP-PRO-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}\z/', $s );
	}

	/** Provider ids become part of an option name, so they are held to a safe shape. */
	private static function is_ref_id( string $s ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9_-]{1,100}\z/', $s );
	}

	public static function get( string $family_id ): ?array {
		if ( ! self::is_uuid4( $family_id ) ) {
			return null;
		}
		$record = get_option( self::OPTION_PREFIX . $family_id, null );
		return is_array( $record ) ? $record : null;
	}

	public static function find_by_key( string $key ): ?array {
		$key = strtoupper( trim( $key ) );
		if ( ! self::is_license_key( $key ) ) {
			return null;
		}
		$family_id = get_option( self::OPTION_PREFIX . 'key_' . $key, null );
		if ( ! is_string( $family_id ) ) {
			return null;
		}
		$record = self::get( $family_id );
		$isSame = null !== $record && ( $record['license_key'] ?? '' ) === $key;
		return $isSame ? $record : null;
	}

	public static function find_by_ref( string $type, string $id ): ?array {
		if ( ! in_array( $type, self::REF_TYPES, true ) || ! self::is_ref_id( $id ) ) {
			return null;
		}
		$family_id = get_option( self::OPTION_PREFIX . $type . '_' . $id, null );
		return is_string( $family_id ) ? self::get( $family_id ) : null;
	}

	/**
	 * Apply one adapter operation to the right family's record.
	 *
	 * @return array{changed: bool, created_key: bool, record: ?array}
	 */
	public static function apply( array $op, string $event_id ): array {
		$none   = array( 'changed' => false, 'created_key' => false, 'record' => null );
		$action = (string) ( $op['action'] ?? 'none' );
		if ( ! in_array( $action, array( 'grant', 'extend', 'expire', 'revoke' ), true ) ) {
			return $none;
		}

		$record = self::resolve_record( $op );

		$isGrant = 'grant' === $action;
		if ( $isGrant ) {
			$family_id = (string) ( $op['family_id'] ?? '' );
			if ( ! self::is_uuid4( $family_id ) ) {
				return $none;
			}
		} elseif ( null === $record ) {
			return $none;
		}

		$isReplay = null !== $record && '' !== $event_id && in_array( $event_id, (array) ( $record['event_ids'] ?? array() ), true );
		if ( $isReplay ) {
			return array( 'changed' => false, 'created_key' => false, 'record' => $record );
		}

		$created_key = false;
		if ( $isGrant ) {
			if ( null === $record ) {
				$record = self::new_record( $family_id );
			}
			$created_key = self::grant( $record, $op );
		} else {
			self::change( $record, $action, $op );
		}

		$record['event_ids'] = self::remember_event( (array) ( $record['event_ids'] ?? array() ), $event_id );
		$record['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );

		self::save( $record );
		if ( $isGrant ) {
			self::index_refs( $record, $op );
			self::index_email( $record );
		}

		return array( 'changed' => true, 'created_key' => $created_key, 'record' => $record );
	}

	/** Entitled: active, and either no expiry or inside expires_at plus the grace days. */
	public static function is_entitled( array $record, int $now ): bool {
		if ( ( $record['status'] ?? '' ) !== 'active' ) {
			return false;
		}
		$expires = $record['expires_at'] ?? null;
		if ( null === $expires ) {
			return true;
		}
		$ts = is_string( $expires ) ? strtotime( $expires ) : false;
		if ( false === $ts ) {
			return false;
		}
		return $now < $ts + self::GRACE_SECONDS;
	}

	/** @return true|WP_Error */
	public static function attach_device( string $family_id, string $device_id ) {
		if ( ! self::is_uuid4( $family_id ) || ! self::is_device_id( $device_id ) ) {
			return new WP_Error( 'invalid_input', 'Invalid family or device id.', array( 'status' => 400 ) );
		}
		$record = self::get( $family_id );
		if ( null === $record ) {
			return new WP_Error( 'not_found', 'No license for this family.', array( 'status' => 404 ) );
		}
		$devices = (array) ( $record['devices'] ?? array() );
		if ( in_array( $device_id, $devices, true ) ) {
			return true;
		}
		if ( count( $devices ) >= self::MAX_DEVICES ) {
			return new WP_Error( 'device_limit', 'This license is on 10 devices already.', array( 'status' => 409 ) );
		}
		$devices[]         = $device_id;
		$record['devices'] = $devices;
		self::save( $record );
		return true;
	}

	/** The shape the app sees. */
	public static function public_view( array $record, int $now ): array {
		$expires = $record['expires_at'] ?? null;
		$ts      = is_string( $expires ) ? strtotime( $expires ) : false;
		return array(
			'pro'         => self::is_entitled( $record, $now ),
			'license_key' => (string) ( $record['license_key'] ?? '' ),
			'plan'        => (string) ( $record['plan'] ?? '' ),
			'expires_at'  => false !== $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : null,
			'env'         => (string) ( $record['env'] ?? 'production' ),
			'family_id'   => (string) ( $record['family_id'] ?? '' ),
		);
	}

	public static function reset_devices( string $family_id ): bool {
		$record = self::get( $family_id );
		if ( null === $record ) {
			return false;
		}
		$record['devices'] = array();
		self::save( $record );
		return true;
	}

	/** Every record whose purchase email is this address (case-insensitive), for the key recovery mail. */
	public static function find_by_email( string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		$families = get_option( self::email_index_name( $email ), array() );
		$records  = array();
		foreach ( is_array( $families ) ? $families : array() as $family_id ) {
			$record = is_string( $family_id ) ? self::get( $family_id ) : null;
			if ( null !== $record ) {
				$records[] = $record;
			}
		}
		return $records;
	}

	/** Every record, for the admin list. Reads straight from the options table. */
	public static function all(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$like = $wpdb->esc_like( self::OPTION_PREFIX ) . '________-____-4___-____-____________';
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		$out   = array();
		foreach ( (array) $names as $name ) {
			$record = self::get( substr( (string) $name, strlen( self::OPTION_PREFIX ) ) );
			if ( null !== $record ) {
				$out[] = $record;
			}
		}
		return $out;
	}

	private static function resolve_record( array $op ): ?array {
		$family_id = $op['family_id'] ?? null;
		if ( is_string( $family_id ) && '' !== $family_id ) {
			return self::get( $family_id );
		}
		// A fallback ref covers an id we never stored, such as the payment intent of a renewal invoice.
		foreach ( array( 'ref', 'fallback_ref' ) as $field ) {
			$ref = $op[ $field ] ?? null;
			if ( is_array( $ref ) && isset( $ref['type'], $ref['id'] ) ) {
				$found = self::find_by_ref( (string) $ref['type'], (string) $ref['id'] );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	private static function new_record( string $family_id ): array {
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		return array(
			'license_key' => '',
			'family_id'   => $family_id,
			'plan'        => 'monthly',
			'status'      => 'active',
			'expires_at'  => null,
			'env'         => 'production',
			'email'       => null,
			'sources'     => array(),
			'devices'     => array(),
			'event_ids'   => array(),
			'created_at'  => $now,
			'updated_at'  => $now,
		);
	}

	/** True when the record already holds an active lifetime plan, which later events never shorten. */
	private static function is_lifetime_active( array $record ): bool {
		return ( $record['plan'] ?? '' ) === 'lifetime'
			&& ( $record['status'] ?? '' ) === 'active'
			&& null === ( $record['expires_at'] ?? null );
	}

	/** @return bool whether a new key was created */
	private static function grant( array &$record, array $op ): bool {
		$created_key = false;
		if ( '' === ( $record['license_key'] ?? '' ) ) {
			$record['license_key'] = self::generate_key();
			$created_key           = true;
		}

		$plan = (string) ( $op['plan'] ?? '' );
		if ( ! in_array( $plan, array( 'monthly', 'yearly', 'lifetime' ), true ) ) {
			$plan = 'monthly';
		}

		if ( ! self::is_lifetime_active( $record ) ) {
			$record['plan']       = $plan;
			$record['expires_at'] = 'lifetime' === $plan ? null : self::iso( $op['expires_at'] ?? null );
		}
		$isLifetimeNow = 'lifetime' === ( $record['plan'] ?? '' );
		if ( $isLifetimeNow ) {
			$record['expires_at'] = null;
		}

		$record['status'] = 'active';
		$record['env']    = in_array( $op['env'] ?? null, array( 'production', 'sandbox' ), true ) ? $op['env'] : ( $record['env'] ?? 'production' );

		$email = $op['email'] ?? null;
		$needsEmail = empty( $record['email'] ) && is_string( $email ) && false !== is_email( $email );
		if ( $needsEmail ) {
			$record['email'] = $email;
		}

		self::merge_sources( $record, $op );

		return $created_key;
	}

	private static function change( array &$record, string $action, array $op ): void {
		switch ( $action ) {
			case 'extend':
				$isRevoked = ( $record['status'] ?? '' ) === 'revoked';
				if ( $isRevoked || self::is_lifetime_active( $record ) ) {
					return;
				}
				$expires = self::iso( $op['expires_at'] ?? null );
				if ( null !== $expires ) {
					$record['expires_at'] = $expires;
					$record['status']     = 'active';
				}
				return;
			case 'expire':
				if ( ! self::is_lifetime_active( $record ) && ( $record['status'] ?? '' ) !== 'revoked' ) {
					$record['status'] = 'expired';
				}
				return;
			case 'revoke':
				$record['status'] = 'revoked';
				return;
		}
	}

	private static function merge_sources( array &$record, array $op ): void {
		$source = (string) ( $op['source'] ?? '' );
		$refs   = is_array( $op['refs'] ?? null ) ? $op['refs'] : array();
		$sources = (array) ( $record['sources'] ?? array() );

		if ( 'stripe' === $source ) {
			$map  = array( 'customer' => 'cus', 'subscription' => 'sub', 'payment_intent' => 'pi' );
			$cur  = (array) ( $sources['stripe'] ?? array() );
			foreach ( $map as $field => $ref_key ) {
				$id = $refs[ $ref_key ] ?? null;
				if ( is_string( $id ) && self::is_ref_id( $id ) ) {
					$cur[ $field ] = $id;
				}
			}
			$sources['stripe'] = $cur;
		} elseif ( 'revenuecat' === $source ) {
			$cur = (array) ( $sources['revenuecat'] ?? array() );
			foreach ( array( 'product_id', 'store' ) as $field ) {
				$value = $refs[ $field ] ?? null;
				if ( is_string( $value ) && '' !== $value ) {
					$cur[ $field ] = sanitize_text_field( $value );
				}
			}
			$sources['revenuecat'] = $cur;
		}

		$record['sources'] = $sources;
	}

	/** Point sub, pi and cus ids at the family, never taking one over from another family. */
	private static function index_refs( array $record, array $op ): void {
		if ( ( $op['source'] ?? '' ) !== 'stripe' || ! is_array( $op['refs'] ?? null ) ) {
			return;
		}
		foreach ( self::REF_TYPES as $type ) {
			$id = $op['refs'][ $type ] ?? null;
			if ( ! is_string( $id ) || ! self::is_ref_id( $id ) ) {
				continue;
			}
			$name     = self::OPTION_PREFIX . $type . '_' . $id;
			$existing = get_option( $name, null );
			$isTaken  = is_string( $existing ) && $existing !== $record['family_id'];
			if ( ! $isTaken ) {
				update_option( $name, $record['family_id'], false );
			}
		}
	}

	private static function email_index_name( string $email ): string {
		return self::OPTION_PREFIX . 'email_' . md5( strtolower( trim( $email ) ) );
	}

	/** Email to family ids, so recovery never scans every license. */
	private static function index_email( array $record ): void {
		$email = $record['email'] ?? null;
		if ( ! is_string( $email ) || '' === $email ) {
			return;
		}
		$name     = self::email_index_name( $email );
		$families = get_option( $name, array() );
		$families = is_array( $families ) ? $families : array();
		if ( ! in_array( $record['family_id'], $families, true ) ) {
			$families[] = $record['family_id'];
			update_option( $name, $families, false );
		}
	}

	private static function save( array $record ): void {
		update_option( self::OPTION_PREFIX . $record['family_id'], $record, false );
		if ( '' !== ( $record['license_key'] ?? '' ) ) {
			update_option( self::OPTION_PREFIX . 'key_' . $record['license_key'], $record['family_id'], false );
		}
	}

	private static function remember_event( array $ids, string $event_id ): array {
		if ( '' !== $event_id ) {
			$ids[] = $event_id;
		}
		return array_slice( array_values( $ids ), -self::MAX_EVENTS );
	}

	private static function iso( $unix ): ?string {
		$isUsable = is_int( $unix ) || ( is_string( $unix ) && ctype_digit( $unix ) );
		return $isUsable && (int) $unix > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $unix ) : null;
	}

	private static function generate_key(): string {
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$groups = array();
			for ( $g = 0; $g < 3; $g++ ) {
				$s = '';
				for ( $i = 0; $i < 4; $i++ ) {
					$s .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
				}
				$groups[] = $s;
			}
			$key = self::KEY_PREFIX . implode( '-', $groups );
			if ( null === get_option( self::OPTION_PREFIX . 'key_' . $key, null ) ) {
				return $key;
			}
		}
		throw new RuntimeException( 'Could not generate a unique XP Pro license key.' );
	}
}
