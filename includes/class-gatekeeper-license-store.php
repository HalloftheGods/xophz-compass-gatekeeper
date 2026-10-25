<?php
/**
 * The license store: one record per account per product, kept in its own option.
 *
 * Every public method takes the product id first; the product (Gatekeeper_License_Products) supplies
 * the option prefix, the key shape, the account and device field names and validators, the device
 * cap, the grace window and the plan catalog. For xp-pro that is gk_xp_pro_{family_id} (autoload
 * off) with lookup options gk_xp_pro_key_{KEY}, gk_xp_pro_sub_{id}, gk_xp_pro_pi_{id},
 * gk_xp_pro_cus_{id} and gk_xp_pro_email_{md5} pointing back to the family id, exactly as before.
 * These records never pass through Gatekeeper_Licenses::validate() and never touch its option.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_License_Store {

	const MAX_EVENTS = 50;
	const REF_TYPES  = array( 'sub', 'pi', 'cus' );

	/* ---- Validators -------------------------------------------------------------------------- */

	/** The product's account validator (lowercase uuid4 for xp-pro's family_id). */
	public static function is_account( string $product, string $s ): bool {
		$p = self::product( $product );
		return null !== $p && self::valid_account( $p, $s );
	}

	/** The product's device validator (8 to 64 letters, digits, underscore and dash by default). */
	public static function is_device( string $product, string $s ): bool {
		$p = self::product( $product );
		return null !== $p && true === (bool) call_user_func( $p['device_validator'], $s );
	}

	/** A license key as handed out: {key_prefix}XXXX-XXXX-XXXX ({key_groups} groups) from the product's alphabet. */
	public static function is_license_key( string $product, string $s ): bool {
		$p = self::product( $product );
		return null !== $p && 1 === preg_match( self::key_pattern( $p ), $s );
	}

	/** Provider ids become part of an option name, so they are held to a safe shape. */
	private static function is_ref_id( string $s ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9_-]{1,100}\z/', $s );
	}

	/* ---- Plans ------------------------------------------------------------------------------- */

	public static function plan_ids( string $product ): array {
		return array_keys( Gatekeeper_License_Products::plans( $product ) );
	}

	/** The plan sold as a one-time payment, which later events never shorten; null when the product has none. */
	public static function lifetime_plan( string $product ): ?string {
		foreach ( Gatekeeper_License_Products::plans( $product ) as $id => $plan ) {
			if ( ( $plan['mode'] ?? '' ) === 'payment' ) {
				return (string) $id;
			}
		}
		return null;
	}

	/** The plan a grant falls back to: the first subscription plan, else the first plan, else ''. */
	public static function default_plan( string $product ): string {
		$plans = Gatekeeper_License_Products::plans( $product );
		foreach ( $plans as $id => $plan ) {
			if ( ( $plan['mode'] ?? '' ) !== 'payment' ) {
				return (string) $id;
			}
		}
		$ids = array_keys( $plans );
		return empty( $ids ) ? '' : (string) $ids[0];
	}

	/* ---- Reading records --------------------------------------------------------------------- */

	public static function get( string $product, string $account ): ?array {
		$p = self::product( $product );
		if ( null === $p || ! self::valid_account( $p, $account ) ) {
			return null;
		}
		$record = get_option( $p['option_prefix'] . $account, null );
		return is_array( $record ) ? $record : null;
	}

	/** A record read straight from the table, for use inside the account's lock. */
	public static function get_fresh( string $product, string $account ): ?array {
		$p = self::product( $product );
		if ( null !== $p && self::valid_account( $p, $account ) ) {
			Gatekeeper_License_Db::forget_option( $p['option_prefix'] . $account );
		}
		return self::get( $product, $account );
	}

	public static function find_by_key( string $product, string $key ): ?array {
		$p   = self::product( $product );
		$key = strtoupper( trim( $key ) );
		if ( null === $p || 1 !== preg_match( self::key_pattern( $p ), $key ) ) {
			return null;
		}
		$account = get_option( $p['option_prefix'] . 'key_' . $key, null );
		if ( ! is_string( $account ) ) {
			return null;
		}
		$record = self::get( $product, $account );
		$isSame = null !== $record && ( $record['license_key'] ?? '' ) === $key;
		return $isSame ? $record : null;
	}

	public static function find_by_ref( string $product, string $type, string $id ): ?array {
		$p = self::product( $product );
		if ( null === $p || ! in_array( $type, self::REF_TYPES, true ) || ! self::is_ref_id( $id ) ) {
			return null;
		}
		$account = get_option( $p['option_prefix'] . $type . '_' . $id, null );
		return is_string( $account ) ? self::get( $product, $account ) : null;
	}

	/** Every record whose purchase email is this address (case-insensitive), for the key recovery mail. */
	public static function find_by_email( string $product, string $email ): array {
		$p     = self::product( $product );
		$email = strtolower( trim( $email ) );
		if ( null === $p || '' === $email ) {
			return array();
		}
		$accounts = get_option( self::email_index_name( $p, $email ), array() );
		$records  = array();
		foreach ( is_array( $accounts ) ? $accounts : array() as $account ) {
			$record = is_string( $account ) ? self::get( $product, $account ) : null;
			if ( null !== $record ) {
				$records[] = $record;
			}
		}
		return $records;
	}

	/** Every record of a product, for the admin list. Reads straight from the options table. */
	public static function all( string $product ): array {
		global $wpdb;
		$p = self::product( $product );
		if ( null === $p || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$prefix = $p['option_prefix'];
		$names  = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		$out    = array();
		foreach ( (array) $names as $name ) {
			$account = substr( (string) $name, strlen( $prefix ) );
			// Index options (key_, sub_, pi_, cus_, email_) fail the account validator and are skipped.
			$record = self::valid_account( $p, $account ) ? self::get( $product, $account ) : null;
			if ( null !== $record ) {
				$out[] = $record;
			}
		}
		return $out;
	}

	/* ---- Applying events --------------------------------------------------------------------- */

	/**
	 * Apply one adapter operation to the right account's record, under that account's lock. The op
	 * names the account in the product's account_param field (family_id for xp-pro) or by a ref.
	 * Fires gatekeeper_license_applied(product, op, result) when the record changed.
	 *
	 * @return array{changed: bool, created_key: bool, record: ?array, lock_failed?: bool}
	 */
	public static function apply( string $product, array $op, string $event_id ): array {
		$none   = array( 'changed' => false, 'created_key' => false, 'record' => null );
		$p      = self::product( $product );
		$action = (string) ( $op['action'] ?? 'none' );
		if ( null === $p || ! in_array( $action, array( 'grant', 'extend', 'expire', 'revoke' ), true ) ) {
			return $none;
		}

		$account = self::account_of( $p, $op );
		if ( null === $account ) {
			return $none;
		}

		$busy   = array_merge( $none, array( 'lock_failed' => true ) );
		$result = self::with_lock(
			$p,
			$account,
			static function () use ( $p, $op, $event_id ) {
				return self::apply_locked( $p, $op, $event_id );
			},
			null
		);
		if ( null === $result ) {
			error_log( sprintf( 'Gatekeeper licenses (%s): could not lock %s %s within %d s, event %s was not applied.', $product, $p['account_param'], $account, Gatekeeper_License_Db::LOCK_WAIT, '' === $event_id ? '(none)' : $event_id ) );
			return $busy;
		}
		if ( $result['changed'] ) {
			do_action( 'gatekeeper_license_applied', $product, $op, $result );
		}
		return $result;
	}

	/** The account an op is about: its own valid account id, or the account a ref points to. */
	private static function account_of( array $p, array $op ): ?string {
		$field   = $p['account_param'];
		$account = $op[ $field ] ?? null;
		if ( is_string( $account ) && '' !== $account ) {
			return self::valid_account( $p, $account ) ? $account : null;
		}
		$found = 'grant' === ( $op['action'] ?? '' ) ? null : self::resolve_record( $p, $op );
		return null === $found ? null : (string) $found[ $field ];
	}

	private static function apply_locked( array $p, array $op, string $event_id ): array {
		$none    = array( 'changed' => false, 'created_key' => false, 'record' => null );
		$product = $p['id'];
		$field   = $p['account_param'];
		$action  = (string) ( $op['action'] ?? 'none' );

		// Read again now that the lock is ours: another request may have written since the first look.
		$record = self::resolve_record( $p, $op );
		if ( null !== $record ) {
			$record = self::get_fresh( $product, (string) $record[ $field ] );
		}

		$isGrant = 'grant' === $action;
		if ( $isGrant ) {
			$account = (string) ( $op[ $field ] ?? '' );
			if ( ! self::valid_account( $p, $account ) ) {
				return $none;
			}
			if ( null === $record ) {
				$record = self::get_fresh( $product, $account );
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
				$record        = self::new_record( $p, $account );
				$record['env'] = $opEnv ?? 'production';
			}
			$created_key = self::grant( $p, $record, $op );
		} else {
			self::change( $p, $record, $action, $op );
		}

		$isProductionOnSandbox = 'production' === $opEnv && ( $record['env'] ?? 'production' ) === 'sandbox';
		if ( $isProductionOnSandbox ) {
			$record['env'] = 'production';
		}

		$now                  = gmdate( 'Y-m-d\TH:i:s\Z' );
		$record['event_ids']  = self::remember_event( (array) ( $record['event_ids'] ?? array() ), $event_id );
		$record['last_event'] = array( 'id' => $event_id, 'action' => $action, 'source' => (string) ( $op['source'] ?? '' ), 'at' => $now );
		$record['updated_at'] = $now;

		self::save( $p, $record );
		if ( $isGrant ) {
			self::index_refs( $p, $record, $op );
			self::index_email( $p, $record );
		}

		return array( 'changed' => true, 'created_key' => $created_key, 'record' => $record );
	}

	/* ---- Entitlement and the app's view ------------------------------------------------------ */

	/** Entitled: active, and either no expiry or inside expires_at plus the product's grace window. */
	public static function is_entitled( string $product, array $record, int $now ): bool {
		$p = self::product( $product );
		if ( null === $p || ( $record['status'] ?? '' ) !== 'active' ) {
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
		return $now < $ts + (int) $p['grace_seconds'];
	}

	/** The shape the app sees; the account field carries the product's account_param name. */
	public static function public_view( string $product, array $record, int $now ): array {
		$p       = self::product( $product );
		$field   = null === $p ? 'account_id' : $p['account_param'];
		$expires = $record['expires_at'] ?? null;
		$ts      = is_string( $expires ) ? strtotime( $expires ) : false;
		$view    = array(
			'pro'         => self::is_entitled( $product, $record, $now ),
			'license_key' => (string) ( $record['license_key'] ?? '' ),
			'plan'        => (string) ( $record['plan'] ?? '' ),
			'expires_at'  => false !== $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : null,
			'env'         => (string) ( $record['env'] ?? 'production' ),
			$field        => (string) ( $record[ $field ] ?? '' ),
		);
		$filtered = apply_filters( 'gatekeeper_license_public_view', $view, $record, $product );
		return is_array( $filtered ) ? $filtered : $view;
	}

	/* ---- Devices ----------------------------------------------------------------------------- */

	/** @return true|WP_Error invalid_input 400, not_found 404, device_limit 409, busy 503 */
	public static function attach_device( string $product, string $account, string $device_id ) {
		$p = self::product( $product );
		if ( null === $p ) {
			return new WP_Error( 'invalid_input', 'Unknown license product.', array( 'status' => 400 ) );
		}
		if ( ! self::valid_account( $p, $account ) || ! self::is_device( $product, $device_id ) ) {
			return new WP_Error( 'invalid_input', sprintf( 'Invalid %s or %s.', $p['account_param'], $p['device_param'] ), array( 'status' => 400 ) );
		}
		$busy = new WP_Error( 'busy', 'Try again in a moment.', array( 'status' => 503 ) );
		return self::with_lock(
			$p,
			$account,
			static function () use ( $p, $account, $device_id ) {
				$record = self::get_fresh( $p['id'], $account );
				if ( null === $record ) {
					return new WP_Error( 'not_found', 'No license for this account.', array( 'status' => 404 ) );
				}
				$devices = (array) ( $record['devices'] ?? array() );
				if ( in_array( $device_id, $devices, true ) ) {
					return true;
				}
				$max = (int) $p['max_devices'];
				if ( count( $devices ) >= $max ) {
					return new WP_Error( 'device_limit', sprintf( 'This license is on %d devices already.', $max ), array( 'status' => 409 ) );
				}
				$devices[]         = $device_id;
				$record['devices'] = $devices;
				self::save( $p, $record );
				return true;
			},
			$busy
		);
	}

	public static function reset_devices( string $product, string $account ): bool {
		$p = self::product( $product );
		if ( null === $p || ! self::valid_account( $p, $account ) ) {
			return false;
		}
		return true === self::with_lock(
			$p,
			$account,
			static function () use ( $p, $account ) {
				$record = self::get_fresh( $p['id'], $account );
				if ( null === $record ) {
					return false;
				}
				$record['devices'] = array();
				self::save( $p, $record );
				return true;
			},
			false
		);
	}

	/* ---- Admin actions ----------------------------------------------------------------------- */

	/** Admin action: set the record active again and note who did it and when. */
	public static function restore( string $product, string $account, int $user_id ): bool {
		$p = self::product( $product );
		if ( null === $p || ! self::valid_account( $p, $account ) ) {
			return false;
		}
		return true === self::with_lock(
			$p,
			$account,
			static function () use ( $p, $account, $user_id ) {
				$record = self::get_fresh( $p['id'], $account );
				if ( null === $record ) {
					return false;
				}
				$now                  = gmdate( 'Y-m-d\TH:i:s\Z' );
				$record['status']     = 'active';
				$notes                = (array) ( $record['notes'] ?? array() );
				$notes[]              = array( 'at' => $now, 'user_id' => $user_id, 'note' => 'Restored by admin.' );
				$record['notes']      = array_slice( $notes, -self::MAX_EVENTS );
				$record['updated_at'] = $now;
				self::save( $p, $record );
				return true;
			},
			false
		);
	}

	/** Admin action: update the editable fields (plan, status, env, expires_at, email) of a record. */
	public static function update_admin( string $product, string $account, array $fields, int $user_id ): bool {
		$p = self::product( $product );
		if ( null === $p || ! self::valid_account( $p, $account ) ) {
			return false;
		}
		return true === self::with_lock(
			$p,
			$account,
			static function () use ( $p, $account, $fields, $user_id ) {
				$product = $p['id'];
				$record  = self::get_fresh( $product, $account );
				if ( null === $record ) {
					return false;
				}
				$plan = (string) ( $fields['plan'] ?? '' );
				if ( in_array( $plan, self::plan_ids( $product ), true ) ) {
					$record['plan'] = $plan;
					if ( $plan === self::lifetime_plan( $product ) ) {
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
					$email           = sanitize_email( trim( (string) $fields['email'] ) );
					$record['email'] = '' !== $email && is_email( $email ) ? $email : null;
				}
				$now                  = gmdate( 'Y-m-d\TH:i:s\Z' );
				$notes                = (array) ( $record['notes'] ?? array() );
				$notes[]              = array( 'at' => $now, 'user_id' => $user_id, 'note' => 'Updated by admin.' );
				$record['notes']      = array_slice( $notes, -self::MAX_EVENTS );
				$record['updated_at'] = $now;
				self::save( $p, $record );
				if ( ! empty( $record['email'] ) ) {
					self::index_email( $p, $record );
				}
				return true;
			},
			false
		);
	}

	/* ---- Internals --------------------------------------------------------------------------- */

	private static function product( string $product ): ?array {
		return Gatekeeper_License_Products::get( $product );
	}

	/** The product's validator, plus a shape guard because the account id becomes part of an option name. */
	private static function valid_account( array $p, string $account ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9_.:@-]{1,100}\z/', $account ) && true === (bool) call_user_func( $p['account_validator'], $account );
	}

	private static function key_pattern( array $p ): string {
		$group = '[' . preg_quote( (string) $p['key_alphabet'], '/' ) . ']{4}';
		return '/\A' . preg_quote( (string) $p['key_prefix'], '/' ) . $group . str_repeat( '-' . $group, max( 0, (int) $p['key_groups'] - 1 ) ) . '\z/';
	}

	/**
	 * Run $fn while holding the account's named lock. Returns the callback's result, or $on_busy when
	 * the lock was not available in LOCK_WAIT seconds (nothing is written then).
	 *
	 * @param mixed $on_busy
	 * @return mixed
	 */
	private static function with_lock( array $p, string $account, callable $fn, $on_busy ) {
		$name = $p['option_prefix'] . $account;
		if ( ! Gatekeeper_License_Db::lock( $name ) ) {
			return $on_busy;
		}
		try {
			return $fn();
		} finally {
			Gatekeeper_License_Db::unlock( $name );
		}
	}

	private static function resolve_record( array $p, array $op ): ?array {
		$account = $op[ $p['account_param'] ] ?? null;
		if ( is_string( $account ) && '' !== $account ) {
			return self::get( $p['id'], $account );
		}
		// A fallback ref covers an id we never stored, such as the payment intent of a renewal invoice.
		foreach ( array( 'ref', 'fallback_ref' ) as $field ) {
			$ref = $op[ $field ] ?? null;
			if ( is_array( $ref ) && isset( $ref['type'], $ref['id'] ) ) {
				$found = self::find_by_ref( $p['id'], (string) $ref['type'], (string) $ref['id'] );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	private static function new_record( array $p, string $account ): array {
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		return array(
			'license_key'       => '',
			$p['account_param'] => $account,
			'plan'              => self::default_plan( $p['id'] ),
			'status'            => 'active',
			'expires_at'        => null,
			'env'               => 'production',
			'email'             => null,
			'sources'           => array(),
			'devices'           => array(),
			'event_ids'         => array(),
			'created_at'        => $now,
			'updated_at'        => $now,
		);
	}

	/** True when the record already holds an active lifetime plan, which later events never shorten. */
	private static function is_lifetime_active( array $p, array $record ): bool {
		$lifetime = self::lifetime_plan( $p['id'] );
		return null !== $lifetime
			&& ( $record['plan'] ?? '' ) === $lifetime
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
	private static function grant( array $p, array &$record, array $op ): bool {
		$product     = $p['id'];
		$created_key = false;
		if ( '' === ( $record['license_key'] ?? '' ) ) {
			$record['license_key'] = self::generate_key( $p );
			$created_key           = true;
		}

		$plan = (string) ( $op['plan'] ?? '' );
		if ( ! in_array( $plan, self::plan_ids( $product ), true ) ) {
			$plan = self::default_plan( $product );
		}
		$lifetime = self::lifetime_plan( $product );

		if ( ! self::is_lifetime_active( $p, $record ) ) {
			// A grant is a new purchase, so a revoked record starts a fresh period.
			if ( ( $record['status'] ?? '' ) === 'revoked' ) {
				$record['expires_at']    = null;
				$record['period_source'] = null;
			}
			if ( null !== $lifetime && $plan === $lifetime ) {
				$record['plan']            = $lifetime;
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

		$email      = $op['email'] ?? null;
		$needsEmail = empty( $record['email'] ) && is_string( $email ) && false !== is_email( $email );
		if ( $needsEmail ) {
			$record['email'] = $email;
		}

		self::merge_sources( $record, $op );

		return $created_key;
	}

	private static function change( array $p, array &$record, string $action, array $op ): void {
		$status = (string) ( $record['status'] ?? '' );
		switch ( $action ) {
			case 'extend':
				if ( 'revoked' === $status || self::is_lifetime_active( $p, $record ) ) {
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
				if ( $isCurrent && ! self::is_lifetime_active( $p, $record ) && 'revoked' !== $status ) {
					$record['status'] = 'expired';
				}
				return;
			case 'revoke':
				$purchase   = self::purchase_source_of( $op );
				$isLifetime = null !== $purchase && ( $record['lifetime_source'] ?? null ) === $purchase;
				if ( $isLifetime ) {
					$record['status'] = 'revoked';
				} elseif ( 'active' === $status && ! self::is_lifetime_active( $p, $record ) ) {
					// Any other refund ends the paid period; the next invoice.paid or RENEWAL brings it back.
					$record['status'] = 'expired';
				}
				return;
		}
	}

	private static function merge_sources( array &$record, array $op ): void {
		$source  = (string) ( $op['source'] ?? '' );
		$refs    = is_array( $op['refs'] ?? null ) ? $op['refs'] : array();
		$sources = (array) ( $record['sources'] ?? array() );

		if ( 'stripe' === $source ) {
			$map = array( 'customer' => 'cus', 'subscription' => 'sub', 'payment_intent' => 'pi' );
			$cur = (array) ( $sources['stripe'] ?? array() );
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

	/** Point sub, pi and cus ids at the account, never taking one over from another account. */
	private static function index_refs( array $p, array $record, array $op ): void {
		if ( ( $op['source'] ?? '' ) !== 'stripe' || ! is_array( $op['refs'] ?? null ) ) {
			return;
		}
		$account = $record[ $p['account_param'] ];
		foreach ( self::REF_TYPES as $type ) {
			$id = $op['refs'][ $type ] ?? null;
			if ( ! is_string( $id ) || ! self::is_ref_id( $id ) ) {
				continue;
			}
			$name     = $p['option_prefix'] . $type . '_' . $id;
			$existing = get_option( $name, null );
			$isTaken  = is_string( $existing ) && $existing !== $account;
			if ( ! $isTaken ) {
				update_option( $name, $account, false );
			}
		}
	}

	private static function email_index_name( array $p, string $email ): string {
		return $p['option_prefix'] . 'email_' . md5( strtolower( trim( $email ) ) );
	}

	/** Email to account ids, so recovery never scans every license. */
	private static function index_email( array $p, array $record ): void {
		$email = $record['email'] ?? null;
		if ( ! is_string( $email ) || '' === $email ) {
			return;
		}
		$account  = $record[ $p['account_param'] ];
		$name     = self::email_index_name( $p, $email );
		$accounts = get_option( $name, array() );
		$accounts = is_array( $accounts ) ? $accounts : array();
		if ( ! in_array( $account, $accounts, true ) ) {
			$accounts[] = $account;
			update_option( $name, $accounts, false );
		}
	}

	private static function save( array $p, array $record ): void {
		$account = $record[ $p['account_param'] ];
		update_option( $p['option_prefix'] . $account, $record, false );
		if ( '' !== ( $record['license_key'] ?? '' ) ) {
			update_option( $p['option_prefix'] . 'key_' . $record['license_key'], $account, false );
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

	private static function generate_key( array $p ): string {
		$alphabet = (string) $p['key_alphabet'];
		$groups   = (int) $p['key_groups'];
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$parts = array();
			for ( $g = 0; $g < $groups; $g++ ) {
				$s = '';
				for ( $i = 0; $i < 4; $i++ ) {
					$s .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
				}
				$parts[] = $s;
			}
			$key = $p['key_prefix'] . implode( '-', $parts );
			if ( null === get_option( $p['option_prefix'] . 'key_' . $key, null ) ) {
				return $key;
			}
		}
		throw new RuntimeException( sprintf( 'Could not generate a unique %s license key.', $p['label'] ) );
	}
}
