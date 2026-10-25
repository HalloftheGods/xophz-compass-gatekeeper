<?php
/**
 * The license product registry: Gatekeeper is the license engine, and each product (XP Pro for
 * Do It For The XP, or any other plugin's licence) registers itself through the filter
 * gatekeeper_license_products:
 *
 *     add_filter( 'gatekeeper_license_products', function ( $products ) {
 *         $products['xp-pro'] = array( 'label' => 'XP Pro', 'app_name' => 'Do It For The XP', ... );
 *         return $products;
 *     } );
 *
 * Every config key has a default (see defaults()); a malformed product is logged and skipped, never
 * fatal. Until the XP plugin registers xp-pro itself, a compact built-in definition keeps the
 * shipped app served (the transition default, removed in the release after the XP plugin ships).
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_License_Products {

	const FILTER           = 'gatekeeper_license_products';
	const DEFAULT_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	const WEBHOOK_SLOTS    = array( 'stripe' => 'stripe', 'stripe_test' => 'stripe', 'revenuecat' => 'revenuecat' );
	const SETTING_TYPES    = array( 'int', 'bool', 'string' );
	/** Record fields the store writes; account_param and device_param may not shadow them. */
	const RECORD_FIELDS    = array( 'license_key', 'plan', 'status', 'expires_at', 'env', 'email', 'sources', 'devices', 'event_ids', 'created_at', 'updated_at', 'notes', 'period_source', 'lifetime_source', 'last_event', 'pro' );

	/** @var array|null id => validated config, built once per request. */
	private static $cache = null;
	/** @var bool whether the transition-default notice went to the log this request. */
	private static $warned = false;

	/** Every valid product keyed by id, defaults filled, the transition default included when needed. */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$registered = apply_filters( self::FILTER, array() );
		$registered = is_array( $registered ) ? $registered : array();
		$products   = array();
		$taken      = array( 'option_prefix' => array(), 'route_prefix' => array(), 'key_prefix' => array() );
		foreach ( $registered as $id => $config ) {
			$product = self::build( (string) $id, $config, $taken );
			if ( null !== $product ) {
				$products[ $product['id'] ] = $product;
			}
		}
		if ( ! isset( $products['xp-pro'] ) ) {
			$builtin = self::build( 'xp-pro', self::builtin_xp_pro(), $taken );
			if ( null !== $builtin ) {
				$products['xp-pro'] = $builtin;
				if ( ! self::$warned ) {
					self::$warned = true;
					error_log( 'Gatekeeper licenses: no plugin registered the xp-pro product, serving the built-in definition. Update the Do It For The XP plugin (xophz-compass-xp), which registers it on gatekeeper_license_products.' );
				}
			}
		}
		self::$cache = $products;
		return $products;
	}

	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/** The product whose routes live at /compass/v1/gatekeeper/{prefix}/..., or null. */
	public static function by_route_prefix( string $prefix ): ?array {
		foreach ( self::all() as $product ) {
			if ( $product['route_prefix'] === $prefix ) {
				return $product;
			}
		}
		return null;
	}

	/**
	 * The product and source ('stripe' or 'revenuecat') a Hookshot webhook id is configured for, read
	 * from each product's webhooks_option (slots stripe, stripe_test, revenuecat). Live and test
	 * Stripe webhooks both feed the Stripe adapter; the event's livemode sets env.
	 *
	 * @param int|string $webhook_id
	 * @return array{0: array, 1: string}|null [product config, source]
	 */
	public static function by_webhook_id( $webhook_id ): ?array {
		$id = (int) $webhook_id;
		if ( $id <= 0 ) {
			return null;
		}
		foreach ( self::all() as $product ) {
			$configured = get_option( $product['webhooks_option'], array() );
			if ( ! is_array( $configured ) ) {
				continue;
			}
			foreach ( self::WEBHOOK_SLOTS as $slot => $source ) {
				if ( (int) ( $configured[ $slot ] ?? 0 ) === $id ) {
					return array( $product, $source );
				}
			}
		}
		return null;
	}

	/** The plan catalog of a product (plan id => price, mode, interval, trial_days, store_product_id, on_sale). */
	public static function plans( string $id ): array {
		$product = self::get( $id );
		if ( null === $product ) {
			return array();
		}
		$plans = call_user_func( $product['plans'] );
		if ( ! is_array( $plans ) ) {
			return array();
		}
		$out = array();
		foreach ( $plans as $plan_id => $plan ) {
			if ( is_string( $plan_id ) && '' !== $plan_id && is_array( $plan ) ) {
				$out[ $plan_id ] = $plan;
			}
		}
		return $out;
	}

	/** Drop the per-request cache (after the registered products changed, mostly in tests). */
	public static function reset(): void {
		self::$cache  = null;
		self::$warned = false;
	}

	/* ---- Settings schema --------------------------------------------------------------------- */

	/** One setting's schema row (label, type, option, default), or null. */
	public static function setting_schema( string $id, string $key ): ?array {
		$product = self::get( $id );
		return null === $product ? null : ( $product['settings'][ $key ] ?? null );
	}

	/** A setting's value, typed per its schema, the default when unset or unreadable; null for an unknown setting. */
	public static function read_setting( string $id, string $key ) {
		$schema = self::setting_schema( $id, $key );
		if ( null === $schema ) {
			return null;
		}
		$marker = new stdClass();
		$stored = get_option( $schema['option'], $marker );
		return $stored === $marker ? $schema['default'] : self::cast( $stored, $schema['type'], $schema['default'] );
	}

	/** Store a setting after casting it to its type; false for an unknown setting. */
	public static function write_setting( string $id, string $key, $value ): bool {
		$schema = self::setting_schema( $id, $key );
		if ( null === $schema ) {
			return false;
		}
		$typed = self::cast( $value, $schema['type'], $schema['default'] );
		// A bool is stored as '1' or '0' (as the option always was): WordPress skips update_option( x, false ) on an option that does not exist yet.
		update_option( $schema['option'], is_bool( $typed ) ? ( $typed ? '1' : '0' ) : $typed, false );
		return true;
	}

	/** Every setting of a product, key => typed value. */
	public static function settings( string $id ): array {
		$product = self::get( $id );
		$out     = array();
		foreach ( null === $product ? array() : array_keys( $product['settings'] ) as $key ) {
			$out[ $key ] = self::read_setting( $id, $key );
		}
		return $out;
	}

	private static function cast( $value, string $type, $default ) {
		if ( 'int' === $type ) {
			$int = filter_var( $value, FILTER_VALIDATE_INT );
			return false === $int ? $default : (int) $int;
		}
		if ( 'bool' === $type ) {
			if ( is_bool( $value ) ) {
				return $value;
			}
			// '', '0', 'false', 'off' and 'no' are false; anything unreadable keeps the default.
			$bool = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			return null === $bool ? $default : $bool;
		}
		return is_scalar( $value ) ? (string) $value : $default;
	}

	/* ---- Default validators ------------------------------------------------------------------ */

	/** Lowercase UUID v4: the default account validator. */
	public static function is_uuid4( string $s ): bool {
		return 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $s );
	}

	/** 8 to 64 characters of letters, digits, underscore and dash: the default device validator. */
	public static function is_device_id( string $s ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9_-]{8,64}\z/', $s );
	}

	/* ---- Building a product ------------------------------------------------------------------ */

	/** Validate one registration; null (and one error_log line) when it is malformed or collides. */
	private static function build( string $id, $config, array &$taken ): ?array {
		if ( 1 !== preg_match( '/\A[a-z0-9][a-z0-9-]{0,31}\z/', $id ) ) {
			error_log( sprintf( 'Gatekeeper licenses: product "%s" skipped: the id must be 1 to 32 lowercase letters, digits and dashes.', $id ) );
			return null;
		}
		if ( ! is_array( $config ) ) {
			error_log( sprintf( 'Gatekeeper licenses: product "%s" skipped: the config is not an array.', $id ) );
			return null;
		}
		$product = self::defaults( $id, $config );
		$error   = self::validate( $product );
		if ( null === $error ) {
			foreach ( array_keys( $taken ) as $field ) {
				if ( isset( $taken[ $field ][ $product[ $field ] ] ) ) {
					$error = sprintf( '%s "%s" is already used by product "%s"', $field, $product[ $field ], $taken[ $field ][ $product[ $field ] ] );
					break;
				}
			}
		}
		if ( null !== $error ) {
			error_log( sprintf( 'Gatekeeper licenses: product "%s" skipped: %s.', $id, $error ) );
			return null;
		}
		foreach ( array_keys( $taken ) as $field ) {
			$taken[ $field ][ $product[ $field ] ] = $id;
		}
		return $product;
	}

	/** The config with every missing key filled from the product id, label and app name. */
	public static function defaults( string $id, array $config ): array {
		$slug     = str_replace( '-', '_', $id );
		$label    = isset( $config['label'] ) && is_string( $config['label'] ) ? $config['label'] : ucwords( str_replace( '-', ' ', $id ) );
		$app_name = isset( $config['app_name'] ) && is_string( $config['app_name'] ) ? $config['app_name'] : $label;
		$defaults = array(
			'id'                  => $id,
			'label'               => $label,
			'app_name'            => $app_name,
			'key_prefix'          => strtoupper( $id ) . '-',
			'key_groups'          => 3,
			'key_alphabet'        => self::DEFAULT_ALPHABET,
			'option_prefix'       => 'gk_lic_' . $slug . '_',
			'account_param'       => 'account_id',
			'account_validator'   => array( __CLASS__, 'is_uuid4' ),
			'device_param'        => 'device_id',
			'device_validator'    => array( __CLASS__, 'is_device_id' ),
			'max_devices'         => 10,
			'grace_seconds'       => 259200,
			'trial_seconds'       => 604800,
			'plans'               => static function () {
				return array();
			},
			'checkout_product'    => $id,
			'webhooks_option'     => 'gatekeeper_' . $slug . '_webhooks',
			'stripe_setup_option' => 'gatekeeper_' . $slug . '_stripe_setup',
			'route_prefix'        => $id,
			'settings'            => array(),
			'strings'             => array(),
			'builtin'             => false,
		);
		$product            = array_merge( $defaults, $config );
		$product['id']      = $id;
		$product['builtin'] = ! empty( $config['builtin'] );
		foreach ( array( 'key_groups', 'max_devices', 'grace_seconds', 'trial_seconds' ) as $int_field ) {
			$int = filter_var( $product[ $int_field ], FILTER_VALIDATE_INT );
			if ( false !== $int ) {
				$product[ $int_field ] = (int) $int;
			}
		}
		$strings            = is_array( $config['strings'] ?? null ) ? $config['strings'] : array();
		$product['strings'] = array_merge( self::default_strings( $product ), $strings );
		return $product;
	}

	/** The key email and support line built from the label, app name and device cap. */
	private static function default_strings( array $product ): array {
		$label = (string) $product['label'];
		$app   = (string) $product['app_name'];
		$max   = (int) $product['max_devices'];
		return array(
			'key_email_subject' => sprintf( 'Your %s key', $label ),
			'key_email_lines'   => static function ( array $record ) use ( $label, $app, $max ): array {
				return array(
					sprintf( 'Thank you for getting %s.', $label ),
					'',
					'Your key: ' . (string) ( $record['license_key'] ?? '' ),
					'',
					sprintf( '%s is already on the device you bought it from. To use it on another device:', $label ),
					sprintf( '1. Open %s.', $app ),
					sprintf( '2. Go to Settings > %s > Restore with key.', $label ),
					'3. Type the key above.',
					'',
					sprintf( 'Keep this email. The key works on up to %d devices.', $max ),
				);
			},
			// {support_email} is the site's admin_email at send time.
			'support_line'      => 'Questions? Write to {support_email}.',
		);
	}

	/** The first thing wrong with a defaults-filled config, or null when it is sound. */
	public static function validate( array $p ): ?string {
		foreach ( array( 'label', 'app_name', 'checkout_product' ) as $field ) {
			if ( ! is_string( $p[ $field ] ) || '' === trim( $p[ $field ] ) ) {
				return $field . ' must be a non-empty string';
			}
		}
		if ( ! is_string( $p['key_prefix'] ) || 1 !== preg_match( '/\A[A-Z0-9][A-Z0-9-]{0,14}-\z/', $p['key_prefix'] ) ) {
			return 'key_prefix must be uppercase letters, digits and dashes ending in a dash (XP-PRO-)';
		}
		if ( ! is_int( $p['key_groups'] ) || $p['key_groups'] < 1 || $p['key_groups'] > 8 ) {
			return 'key_groups must be an integer from 1 to 8';
		}
		$alphabet = $p['key_alphabet'];
		$isAlphabet = is_string( $alphabet ) && strlen( $alphabet ) >= 8 && 1 === preg_match( '/\A[A-Z0-9]+\z/', $alphabet ) && strlen( count_chars( $alphabet, 3 ) ) === strlen( $alphabet );
		if ( ! $isAlphabet ) {
			return 'key_alphabet must be at least 8 distinct uppercase letters and digits';
		}
		if ( ! is_string( $p['option_prefix'] ) || 1 !== preg_match( '/\A[a-z0-9][a-z0-9_]{1,30}_\z/', $p['option_prefix'] ) ) {
			return 'option_prefix must be lowercase letters, digits and underscores ending in an underscore (gk_xp_pro_)';
		}
		foreach ( array( 'account_param', 'device_param' ) as $field ) {
			if ( ! is_string( $p[ $field ] ) || 1 !== preg_match( '/\A[a-z][a-z0-9_]{1,31}\z/', $p[ $field ] ) ) {
				return $field . ' must be a lowercase snake_case name';
			}
			if ( in_array( $p[ $field ], self::RECORD_FIELDS, true ) ) {
				return $field . ' may not be a record field name (' . $p[ $field ] . ')';
			}
		}
		if ( $p['account_param'] === $p['device_param'] ) {
			return 'account_param and device_param must differ';
		}
		foreach ( array( 'account_validator', 'device_validator', 'plans' ) as $field ) {
			if ( ! is_callable( $p[ $field ] ) ) {
				return $field . ' must be callable';
			}
		}
		if ( ! is_int( $p['max_devices'] ) || $p['max_devices'] < 1 ) {
			return 'max_devices must be an integer of at least 1';
		}
		foreach ( array( 'grace_seconds', 'trial_seconds' ) as $field ) {
			if ( ! is_int( $p[ $field ] ) || $p[ $field ] < 0 ) {
				return $field . ' must be an integer of at least 0';
			}
		}
		foreach ( array( 'webhooks_option', 'stripe_setup_option' ) as $field ) {
			if ( ! is_string( $p[ $field ] ) || 1 !== preg_match( '/\A[a-z0-9_]{1,60}\z/', $p[ $field ] ) ) {
				return $field . ' must be an option name of lowercase letters, digits and underscores';
			}
		}
		if ( ! is_string( $p['route_prefix'] ) || 1 !== preg_match( '/\A[a-z0-9][a-z0-9-]{0,31}\z/', $p['route_prefix'] ) ) {
			return 'route_prefix must be lowercase letters, digits and dashes';
		}
		if ( ! is_array( $p['settings'] ) ) {
			return 'settings must be an array';
		}
		foreach ( $p['settings'] as $key => $setting ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,31}\z/', $key ) ) {
				return 'settings keys must be lowercase snake_case names';
			}
			$isRow = is_array( $setting )
				&& is_string( $setting['label'] ?? null )
				&& in_array( $setting['type'] ?? null, self::SETTING_TYPES, true )
				&& is_string( $setting['option'] ?? null ) && 1 === preg_match( '/\A[a-z0-9_]{1,60}\z/', $setting['option'] )
				&& array_key_exists( 'default', $setting );
			if ( ! $isRow ) {
				return sprintf( 'setting "%s" needs label, type (int|bool|string), option and default', $key );
			}
		}
		$strings = $p['strings'];
		if ( ! is_string( $strings['key_email_subject'] ?? null ) || ! is_string( $strings['support_line'] ?? null ) ) {
			return 'strings.key_email_subject and strings.support_line must be strings';
		}
		if ( ! is_callable( $strings['key_email_lines'] ?? null ) ) {
			return 'strings.key_email_lines must be callable(record): lines';
		}
		return null;
	}

	/* ---- Transition default ------------------------------------------------------------------ */

	/**
	 * XP Pro as the XP plugin registers it, for a Gatekeeper that updated before the XP plugin did.
	 * Plans come from the legacy filter gatekeeper_xp_pro_plans (the old XP plugin still fills it).
	 * Removed in the release after the XP plugin ships its registration.
	 */
	public static function builtin_xp_pro(): array {
		return array(
			'label'               => 'XP Pro',
			'app_name'            => 'Do It For The XP',
			'key_prefix'          => 'XP-PRO-',
			'key_groups'          => 3,
			'option_prefix'       => 'gk_xp_pro_',
			'account_param'       => 'family_id',
			'device_param'        => 'device_id',
			'max_devices'         => 10,
			'grace_seconds'       => 259200,
			'trial_seconds'       => 604800,
			'plans'               => array( __CLASS__, 'legacy_xp_pro_plans' ),
			'checkout_product'    => 'doitforthexp',
			'webhooks_option'     => 'gatekeeper_xp_pro_webhooks',
			'stripe_setup_option' => 'gatekeeper_xp_pro_stripe_setup',
			'route_prefix'        => 'xp-pro',
			'settings'            => array(
				'ai_daily_limit'   => array( 'label' => 'AI requests per family per day', 'type' => 'int', 'option' => 'xp_pro_ai_daily_limit', 'default' => 100 ),
				'lifetime_on_sale' => array( 'label' => 'Lifetime plan on sale', 'type' => 'bool', 'option' => 'xp_pro_lifetime_on_sale', 'default' => true ),
			),
			'builtin'             => true,
		);
	}

	/** The XP Pro catalog from the legacy filter, or the catalog as the XP plugin ships it when nothing answered. */
	public static function legacy_xp_pro_plans(): array {
		$plans = apply_filters( 'gatekeeper_xp_pro_plans', array() );
		if ( is_array( $plans ) && ! empty( $plans ) ) {
			return $plans;
		}
		$on_sale = self::cast( get_option( 'xp_pro_lifetime_on_sale', true ), 'bool', true );
		return array(
			'monthly'  => array( 'price' => 4.99, 'mode' => 'subscription', 'interval' => 'month', 'trial_days' => 7, 'store_product_id' => 'xp_pro_monthly', 'on_sale' => true ),
			'yearly'   => array( 'price' => 39.99, 'mode' => 'subscription', 'interval' => 'year', 'trial_days' => 7, 'store_product_id' => 'xp_pro_yearly', 'on_sale' => true ),
			'lifetime' => array( 'price' => 79.00, 'mode' => 'payment', 'interval' => null, 'trial_days' => 0, 'store_product_id' => 'xp_pro_lifetime', 'on_sale' => $on_sale ),
		);
	}
}
