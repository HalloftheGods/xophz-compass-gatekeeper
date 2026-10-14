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

	/** A record read straight from the table, for use inside the family lock. */
	private static function get_fresh( string $family_id ): ?array {
		if ( self::is_uuid4( $family_id ) ) {
			Gatekeeper_Xp_Pro_Db::forget_option( self::OPTION_PREFIX . $family_id );
		}
		return self::get( $family_id );
	}

	/**
	 * Run $fn while holding the family's named lock. Returns the callback's result, or $on_busy when
	 * the lock was not available in LOCK_WAIT seconds (nothing is written then).
	 *
	 * @param mixed $on_busy
	 * @return mixed
	 */
	private static function with_family_lock( string $family_id, callable $fn, $on_busy ) {
		$name = self::OPTION_PREFIX . $family_id;
		if ( ! Gatekeeper_Xp_Pro_Db::lock( $name ) ) {
			return $on_busy;
		}
		try {
			return $fn();
		} finally {
			Gatekeeper_Xp_Pro_Db::unlock( $name );
		}
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
	 * Apply one adapter operation to the right family's record, under that family's lock.
	 *
	 * @return array{changed: bool, created_key: bool, record: ?array, lock_failed?: bool}
	 */
	public static function apply( array $op, string $event_id ): array {
		$none   = array( 'changed' => false, 'created_key' => false, 'record' => null );
		$action = (string) ( $op['action'] ?? 'none' );
		if ( ! in_array( $action, array( 'grant', 'extend', 'expire', 'revoke' ), true ) ) {
			return $none;
		}

		$family_id = self::family_of( $op );
		if ( null === $family_id ) {
			return $none;
		}

		$busy = array_merge( $none, array( 'lock_failed' => true ) );
		$result = self::with_family_lock(
			$family_id,
			static function () use ( $op, $event_id ) {
				return self::apply_locked( $op, $event_id );
			},
			null
		);
		if ( null === $result ) {
			error_log( sprintf( 'Gatekeeper XP Pro: could not lock family %s within %d s, event %s was not applied.', $family_id, Gatekeeper_Xp_Pro_Db::LOCK_WAIT, '' === $event_id ? '(none)' : $event_id ) );
			return $busy;
		}
		return $result;
	}

	/** The family an op is about: its own valid family_id, or the family a ref points to. */
	private static function family_of( array $op ): ?string {
		$family_id = $op['family_id'] ?? null;
		if ( is_string( $family_id ) && '' !== $family_id ) {
			return self::is_uuid4( $family_id ) ? $family_id : null;
		}
		$found = 'grant' === ( $op['action'] ?? '' ) ? null : self::resolve_record( $op );
		return null === $found ? null : (string) $found['family_id'];
	}

	private static function apply_locked( array $op, string $event_id ): array {
		$none   = array( 'changed' => false, 'created_key' => false, 'record' => null );
		$action = (string) ( $op['action'] ?? 'none' );

		// Read again now that the lock is ours: another request may have written since the first look.
		$record = self::resolve_record( $op );
		if ( null !== $record ) {
			$record = self::get_fresh( (string) $record['family_id'] );
		}

		$isGrant = 'grant' === $action;
		if ( $isGrant ) {
			$family_id = (string) ( $op['family_id'] ?? '' );
			if ( ! self::is_uuid4( $family_id ) ) {
				return $none;
			}
			if ( null === $record ) {
				$record = self::get_fresh( $family_id );
			}
		} elseif ( null === $record ) {
			return $none;
		}

		// Production wins: a sandbox event never touches a production record.
		$opEnv        = in_array( $op['env'] ?? null, array( 'production', 'sandbox' ), true ) ? $op['env'] : null;
		$isSandboxHit = null !== $record && 'sandbox' === $opEnv && ( $record['env'] ?? 'production' ) === 'production';
		if ( $isSandboxHit ) {
			return array( 'changed' => false, 'created_key' => false, 'record' => $record );
		}

		$isReplay = null !== $record && '' !== $event_id && in_array( $event_id, (array) ( $record['event_ids'] ?? array() ), true );
		if ( $isReplay ) {
			return array( 'changed' => false, 'created_key' => false, 'record' => $record );
		}

		$created_key = false;
		if ( $isGrant ) {
			if ( null === $record ) {
				$record        = self::new_record( $family_id );
				$record['env'] = $opEnv ?? 'production';
			}
			$created_key = self::grant( $record, $op );
		} else {
			self::change( $record, $action, $op );
		}

		$isProductionOnSandbox = 'production' === $opEnv && ( $record['env'] ?? 'production' ) === 'sandbox';
		if ( $isProductionOnSandbox ) {
			$record['env'] = 'production';
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
		$busy = new WP_Error( 'busy', 'Try again in a moment.', array( 'status' => 503 ) );
		return self::with_family_lock(
			$family_id,
			static function () use ( $family_id, $device_id ) {
				$record = self::get_fresh( $family_id );
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
			},
			$busy
		);
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
		if ( ! self::is_uuid4( $family_id ) ) {
			return false;
		}
		return true === self::with_family_lock(
			$family_id,
			static function () use ( $family_id ) {
				$record = self::get_fresh( $family_id );
				if ( null === $record ) {
					return false;
				}
				$record['devices'] = array();
				self::save( $record );
				return true;
			},
			false
		);
	}

	/** Admin action: set the record active again and note who did it and when. */
	public static function restore( string $family_id, int $user_id ): bool {
		if ( ! self::is_uuid4( $family_id ) ) {
			return false;
		}
		return true === self::with_family_lock(
			$family_id,
			static function () use ( $family_id, $user_id ) {
				$record = self::get_fresh( $family_id );
				if ( null === $record ) {
					return false;
				}
				$now                  = gmdate( 'Y-m-d\TH:i:s\Z' );
				$record['status']     = 'active';
				$notes                = (array) ( $record['notes'] ?? array() );
				$notes[]              = array( 'at' => $now, 'user_id' => $user_id, 'note' => 'Restored by admin.' );
				$record['notes']      = array_slice( $notes, -self::MAX_EVENTS );
				$record['updated_at'] = $now;
				self::save( $record );
				return true;
			},
			false
		);
	}

	/** Admin action: update editable fields on an XP Pro license record. */
	public static function update_admin( string $family_id, array $fields, int $user_id ): bool {
		if ( ! self::is_uuid4( $family_id ) ) {
			return false;
		}
		return true === self::with_family_lock(
			$family_id,
			static function () use ( $family_id, $fields, $user_id ) {
				$record = self::get_fresh( $family_id );
				if ( null === $record ) {
					return false;
				}
				$plan = (string) ( $fields['plan'] ?? '' );
				if ( in_array( $plan, array( 'monthly', 'yearly', 'lifetime' ), true ) ) {
					$record['plan'] = $plan;
					if ( 'lifetime' === $plan ) {
						$record['expires_at'] = null;
					}
				}
				$status = (string) ( $fields['status'] ?? '' );
				if ( in_array( $status, array( 'active', 'expired', 'revoked' ), true ) ) {
					$record['status'] = $status;
				}
				$env = (string) ( $fields['env'] ?? '' );
				if ( in_array( $env, array( 'production', 'sandbox' ), true ) ) {
					$record['env'] = $env;
				}
				if ( array_key_exists( 'expires_at', $fields ) ) {
					$exp = trim( (string) $fields['expires_at'] );
					if ( '' === $exp || 'never' === strtolower( $exp ) ) {
						$record['expires_at'] = null;
					} else {
						$ts = strtotime( $exp );
						if ( false !== $ts ) {
							$record['expires_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $ts );
						}
					}
				}
				if ( array_key_exists( 'email', $fields ) ) {
					$email = sanitize_email( trim( (string) $fields['email'] ) );
					$record['email'] = '' !== $email && is_email( $email ) ? $email : null;
				}
				$now                  = gmdate( 'Y-m-d\TH:i:s\Z' );
				$notes                = (array) ( $record['notes'] ?? array() );
				$notes[]              = array( 'at' => $now, 'user_id' => $user_id, 'note' => 'Updated by admin.' );
				$record['notes']      = array_slice( $notes, -self::MAX_EVENTS );
				$record['updated_at'] = $now;
				self::save( $record );
				if ( ! empty( $record['email'] ) ) {
					self::index_email( $record );
				}
				return true;
			},
			false
		);
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

	/** The source of a paid period: "stripe:<sub id>" or "revenuecat:<product id>", or null when the op names none. */
	private static function period_source_of( array $op ): ?string {
		$source = (string) ( $op['source'] ?? '' );
		$refs   = is_array( $op['refs'] ?? null ) ? $op['refs'] : array();
		$ref    = is_array( $op['ref'] ?? null ) ? $op['ref'] : array();
		if ( 'stripe' === $source ) {
			$sub = $refs['sub'] ?? ( ( $ref['type'] ?? '' ) === 'sub' ? ( $ref['id'] ?? null ) : null );
			return is_string( $sub ) && '' !== $sub ? 'stripe:' . $sub : null;
		}
		if ( 'revenuecat' === $source ) {
			$product = $refs['product_id'] ?? null;
			return is_string( $product ) && '' !== $product ? 'revenuecat:' . $product : null;
		}
		return null;
	}

	/** The source of a purchase being refunded: "stripe:<payment intent>" or "revenuecat:<product id>". */
	private static function purchase_source_of( array $op ): ?string {
		$source = (string) ( $op['source'] ?? '' );
		if ( 'stripe' === $source ) {
			$refs = is_array( $op['refs'] ?? null ) ? $op['refs'] : array();
			$ref  = is_array( $op['ref'] ?? null ) ? $op['ref'] : array();
			$pi   = $refs['pi'] ?? ( ( $ref['type'] ?? '' ) === 'pi' ? ( $ref['id'] ?? null ) : null );
			return is_string( $pi ) && '' !== $pi ? 'stripe:' . $pi : null;
		}
		return self::period_source_of( $op );
	}

	/** A later expiry wins; no current expiry means anything usable wins. Equal values go to the newer event. */
	private static function new_expiry_wins( ?string $current, ?string $new ): bool {
		if ( null === $new ) {
			return false;
		}
		if ( null === $current ) {
			return true;
		}
		$currentTs = strtotime( $current );
		$newTs     = strtotime( $new );
		return false === $currentTs || ( false !== $newTs && $newTs >= $currentTs );
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
			// A grant is a new purchase, so a revoked record starts a fresh period.
			if ( ( $record['status'] ?? '' ) === 'revoked' ) {
				$record['expires_at']    = null;
				$record['period_source'] = null;
			}
			if ( 'lifetime' === $plan ) {
				$record['plan']            = 'lifetime';
				$record['expires_at']      = null;
				$record['lifetime_source'] = self::purchase_source_of( array_merge( $op, array( 'ref' => null ) ) );
			} else {
				$new = self::iso( $op['expires_at'] ?? null );
				if ( self::new_expiry_wins( $record['expires_at'] ?? null, $new ) ) {
					$record['plan']          = $plan;
					$record['expires_at']    = $new;
					$record['period_source'] = self::period_source_of( $op );
				}
			}
			$record['status'] = 'active';
		}

		$email = $op['email'] ?? null;
		$needsEmail = empty( $record['email'] ) && is_string( $email ) && false !== is_email( $email );
		if ( $needsEmail ) {
			$record['email'] = $email;
		}

		self::merge_sources( $record, $op );

		return $created_key;
	}

	private static function change( array &$record, string $action, array $op ): void {
		$status = (string) ( $record['status'] ?? '' );
		switch ( $action ) {
			case 'extend':
				if ( 'revoked' === $status || self::is_lifetime_active( $record ) ) {
					return;
				}
				$expires = self::iso( $op['expires_at'] ?? null );
				if ( self::new_expiry_wins( $record['expires_at'] ?? null, $expires ) ) {
					$record['expires_at']    = $expires;
					$record['period_source'] = self::period_source_of( $op );
				}
				if ( 'expired' === $status ) {
					$record['status'] = 'active';
				}
				return;
			case 'expire':
				$source    = self::period_source_of( $op );
				$isCurrent = null !== $source && ( $record['period_source'] ?? null ) === $source;
				if ( $isCurrent && ! self::is_lifetime_active( $record ) && 'revoked' !== $status ) {
					$record['status'] = 'expired';
				}
				return;
			case 'revoke':
				$purchase   = self::purchase_source_of( $op );
				$isLifetime = null !== $purchase && ( $record['lifetime_source'] ?? null ) === $purchase;
				if ( $isLifetime ) {
					$record['status'] = 'revoked';
				} elseif ( 'active' === $status && ! self::is_lifetime_active( $record ) ) {
					// Any other refund ends the paid period; the next invoice.paid or RENEWAL brings it back.
					$record['status'] = 'expired';
				}
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
