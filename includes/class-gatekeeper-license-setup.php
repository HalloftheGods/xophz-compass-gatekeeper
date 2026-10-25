<?php
/**
 * License setup API for administrators: wires a product's Stripe webhook end to end and reads or
 * writes the product's settings, so the whole setup can be done over REST.
 *
 * For each product in Gatekeeper_License_Products the routes live at
 * /compass/v1/gatekeeper/{route_prefix}/setup/stripe-webhook and {route_prefix}/settings. The
 * option names come from the product (webhooks_option, stripe_setup_option); the settings are the
 * product's settings schema, read and written through Gatekeeper_License_Products.
 *
 * The Stripe secret key and the webhook signing secret stay on the server: the key is resolved
 * through Bazaar (Settings > Connectors), the signing secret Stripe returns goes straight into
 * the Hookshot webhook's write-only meta, and no response ever contains either.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_License_Setup {

	const STRIPE_EVENTS = array(
		'checkout.session.completed',
		'checkout.session.async_payment_succeeded',
		'checkout.session.async_payment_failed',
		'invoice.paid',
		'customer.subscription.deleted',
		'charge.refunded',
		'charge.dispute.created',
	);

	/** The webhook slots a settings write may set (0 clears one). */
	const WEBHOOK_SLOTS = array( 'stripe', 'stripe_test', 'revenuecat' );

	/** Response and request names a product setting may not shadow. */
	const RESERVED = array( 'webhooks', 'stripe_setup', 'trusted_redirect_domains', 'siteurl', 'home' );

	/** The range an int setting must stay inside unless its schema row names its own min and max. */
	const INT_MIN = 1;
	const INT_MAX = 10000;

	public static function register( string $namespace ): void {
		foreach ( Gatekeeper_License_Products::all() as $product ) {
			$id     = $product['id'];
			$prefix = '/' . $product['route_prefix'];

			register_rest_route(
				$namespace,
				$prefix . '/setup/stripe-webhook',
				array(
					'methods'             => 'POST',
					'callback'            => static function ( $request ) use ( $id ) {
						return Gatekeeper_License_Setup::stripe_webhook( $id, $request );
					},
					'permission_callback' => array( __CLASS__, 'is_admin' ),
				)
			);
			register_rest_route(
				$namespace,
				$prefix . '/settings',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => static function ( $request = null ) use ( $id ) {
							return Gatekeeper_License_Setup::get_settings( $id, $request );
						},
						'permission_callback' => array( __CLASS__, 'is_admin' ),
					),
					array(
						'methods'             => 'POST',
						'callback'            => static function ( $request ) use ( $id ) {
							return Gatekeeper_License_Setup::update_settings( $id, $request );
						},
						'permission_callback' => array( __CLASS__, 'is_admin' ),
					),
				)
			);
		}
	}

	public static function is_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * POST /{route_prefix}/setup/stripe-webhook {mode: 'test'|'live', rotate?: bool}
	 *
	 * Creates an incoming Hookshot webhook, registers it as a Stripe webhook endpoint for the
	 * license events, stores Stripe's signing secret in Hookshot, and records the webhook id in
	 * the product's webhooks_option (slot stripe_test or stripe). Calling it again returns the
	 * existing setup unless rotate is true.
	 */
	public static function stripe_webhook( string $product_id, WP_REST_Request $request ): WP_REST_Response {
		$product = Gatekeeper_License_Products::get( $product_id );
		if ( null === $product ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}
		$mode   = 'live' === $request->get_param( 'mode' ) ? 'live' : 'test';
		$rotate = (bool) $request->get_param( 'rotate' );

		$missing = self::missing_dependencies();
		if ( '' !== $missing ) {
			return new WP_REST_Response( array( 'error' => 'missing_dependency', 'message' => $missing ), 424 );
		}

		$key = (string) Xophz_Bazaar_Stripe_Key::resolve( 'test' === $mode );
		if ( ! self::key_matches_mode( $key, $mode ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'stripe_key_missing',
					'message' => sprintf( 'No Stripe %s secret key is set in Settings > Connectors.', $mode ),
				),
				424
			);
		}

		$setups   = self::stripe_setups( $product );
		$existing = $setups[ $mode ] ?? null;
		$hasLive  = is_array( $existing ) && ! empty( $existing['webhook_id'] ) && get_post( (int) $existing['webhook_id'] );
		if ( $hasLive && ! $rotate ) {
			return new WP_REST_Response( self::describe( $mode, $existing, false ), 200 );
		}
		if ( $hasLive && $rotate ) {
			self::stripe_request( 'DELETE', 'webhook_endpoints/' . rawurlencode( (string) $existing['stripe_endpoint_id'] ), array(), $key );
			wp_delete_post( (int) $existing['webhook_id'], true );
		}

		$webhook_id = wp_insert_post(
			array(
				'post_title'  => sprintf( '%s Stripe (%s)', $product['label'], $mode ),
				'post_type'   => 'compass_webhook',
				'post_status' => 'publish',
			)
		);
		if ( is_wp_error( $webhook_id ) || ! $webhook_id ) {
			return new WP_REST_Response( array( 'error' => 'webhook_create_failed' ), 500 );
		}
		update_post_meta( $webhook_id, 'hookshot_type', 'incoming' );
		update_post_meta( $webhook_id, 'hookshot_status', 'active' );
		update_post_meta( $webhook_id, 'hookshot_sig_header', 'Stripe-Signature' );
		update_post_meta( $webhook_id, 'hookshot_verify_mode', 'hmac' );

		$endpoint_url = Xophz_Compass_Hookshot_REST::receive_url( $webhook_id );
		$body         = array(
			'url'         => $endpoint_url,
			'description' => sprintf( '%s: %s (Gatekeeper via Hookshot)', $product['app_name'], $product['label'] ),
		);
		foreach ( self::STRIPE_EVENTS as $i => $event ) {
			$body[ "enabled_events[{$i}]" ] = $event;
		}
		$created = self::stripe_request( 'POST', 'webhook_endpoints', $body, $key );

		if ( empty( $created['id'] ) || empty( $created['secret'] ) ) {
			wp_delete_post( $webhook_id, true );
			return new WP_REST_Response(
				array(
					'error'   => 'stripe_error',
					'message' => (string) ( $created['error']['message'] ?? 'Stripe did not create the webhook endpoint.' ),
				),
				502
			);
		}

		Hookshot_Webhook_Secrets::save( $webhook_id, array( 'signing_secret' => (string) $created['secret'] ) );

		$record          = array(
			'webhook_id'         => (int) $webhook_id,
			'stripe_endpoint_id' => (string) $created['id'],
			'livemode'           => ! empty( $created['livemode'] ),
			'created_at'         => gmdate( 'c' ),
		);
		$setups[ $mode ] = $record;
		update_option( $product['stripe_setup_option'], $setups, false );

		$webhooks = self::webhook_option( $product );
		$webhooks[ 'live' === $mode ? 'stripe' : 'stripe_test' ] = (int) $webhook_id;
		update_option( $product['webhooks_option'], $webhooks, false );

		return new WP_REST_Response( self::describe( $mode, $record, true ), 201 );
	}

	/** GET /{route_prefix}/settings */
	public static function get_settings( string $product_id, $request = null ): WP_REST_Response {
		if ( null === Gatekeeper_License_Products::get( $product_id ) ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}
		return new WP_REST_Response( self::settings_view( $product_id ), 200 );
	}

	/**
	 * POST /{route_prefix}/settings with any of:
	 * - webhooks: {stripe?, stripe_test?, revenuecat?} positive ints (0 clears a slot)
	 * - one field per setting of the product's schema (xp-pro: ai_daily_limit int 1..10000,
	 *   lifetime_on_sale bool). An int setting stays inside its row's min and max, 1..10000 when
	 *   the row names none.
	 *
	 * Site-wide security settings (the redirect allowlist, the site URL) are not writable here;
	 * an administrator sets them in wp-admin.
	 */
	public static function update_settings( string $product_id, WP_REST_Request $request ): WP_REST_Response {
		$product = Gatekeeper_License_Products::get( $product_id );
		if ( null === $product ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}
		$errors = array();

		$webhooks = $request->get_param( 'webhooks' );
		if ( null !== $webhooks ) {
			if ( ! is_array( $webhooks ) ) {
				$errors[] = 'webhooks must be an object';
			} else {
				$current = self::webhook_option( $product );
				foreach ( self::WEBHOOK_SLOTS as $slot ) {
					if ( array_key_exists( $slot, $webhooks ) ) {
						$id = (int) $webhooks[ $slot ];
						if ( $id < 0 ) {
							$errors[] = "webhooks.{$slot} must be a webhook id";
							continue;
						}
						if ( 0 === $id ) {
							unset( $current[ $slot ] );
						} else {
							$current[ $slot ] = $id;
						}
					}
				}
				if ( empty( $errors ) ) {
					update_option( $product['webhooks_option'], $current, false );
				}
			}
		}

		foreach ( $product['settings'] as $key => $schema ) {
			$value = in_array( $key, self::RESERVED, true ) ? null : $request->get_param( $key );
			if ( null === $value ) {
				continue;
			}
			$problem = self::setting_problem( $key, $schema, $value );
			if ( null !== $problem ) {
				$errors[] = $problem;
				continue;
			}
			Gatekeeper_License_Products::write_setting( $product_id, $key, $value );
		}

		if ( ! empty( $errors ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_input', 'messages' => $errors, 'settings' => self::settings_view( $product_id ) ), 400 );
		}
		return new WP_REST_Response( self::settings_view( $product_id ), 200 );
	}

	/** Why a posted value cannot be stored for this schema row, or null when it can. */
	private static function setting_problem( string $key, array $schema, $value ): ?string {
		if ( 'int' === $schema['type'] ) {
			$min = isset( $schema['min'] ) ? (int) $schema['min'] : self::INT_MIN;
			$max = isset( $schema['max'] ) ? (int) $schema['max'] : self::INT_MAX;
			$int = (int) $value;
			return $int < $min || $int > $max ? sprintf( '%s must be between %d and %d', $key, $min, $max ) : null;
		}
		if ( 'bool' === $schema['type'] ) {
			$isBool = is_bool( $value ) || null !== filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			return $isBool ? null : sprintf( '%s must be true or false', $key );
		}
		return is_scalar( $value ) ? null : sprintf( '%s must be text', $key );
	}

	/** The settings response: webhooks, the Stripe setup, the product's own settings, then the read-only site values. */
	public static function settings_view( string $product_id ): array {
		$product = Gatekeeper_License_Products::get( $product_id );
		if ( null === $product ) {
			return array();
		}
		$view = array(
			'webhooks'     => self::webhook_option( $product ),
			'stripe_setup' => array_map(
				static function ( $r ) {
					return is_array( $r ) ? array_intersect_key( $r, array_flip( array( 'webhook_id', 'stripe_endpoint_id', 'livemode', 'created_at' ) ) ) : $r;
				},
				self::stripe_setups( $product )
			),
		);
		foreach ( Gatekeeper_License_Products::settings( $product_id ) as $key => $value ) {
			if ( ! in_array( $key, self::RESERVED, true ) ) {
				$view[ $key ] = $value;
			}
		}
		$view['trusted_redirect_domains'] = self::trusted_domains();
		$view['siteurl']                  = (string) get_option( 'siteurl', '' );
		$view['home']                     = (string) get_option( 'home', '' );
		return $view;
	}

	/** Read-only: shown so an administrator can see whether the app's host is allowed. */
	public static function trusted_domains(): array {
		$raw = get_option( 'compass_trusted_redirect_domains', array() );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\r\n|\r|\n/', $raw );
		}
		return array_values( array_filter( array_map( 'trim', (array) $raw ), 'strlen' ) );
	}

	private static function describe( string $mode, array $record, bool $created ): array {
		return array(
			'created'            => $created,
			'mode'               => $mode,
			'webhook_id'         => (int) $record['webhook_id'],
			'endpoint_url'       => Xophz_Compass_Hookshot_REST::receive_url( (int) $record['webhook_id'] ),
			'stripe_endpoint_id' => (string) $record['stripe_endpoint_id'],
			'livemode'           => (bool) ( $record['livemode'] ?? false ),
			'events'             => self::STRIPE_EVENTS,
		);
	}

	private static function missing_dependencies(): string {
		$needed = array(
			'Xophz_Bazaar_Stripe_Key'     => 'Bazaar',
			'Hookshot_Webhook_Secrets'    => 'Hookshot',
			'Xophz_Compass_Hookshot_REST' => 'Hookshot',
		);
		foreach ( $needed as $class => $plugin ) {
			if ( ! class_exists( $class ) ) {
				return "{$plugin} must be active ({$class} is missing).";
			}
		}
		return '';
	}

	private static function key_matches_mode( string $key, string $mode ): bool {
		$prefixes = 'live' === $mode ? array( 'sk_live_', 'rk_live_' ) : array( 'sk_test_', 'rk_test_' );
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) && 0 !== strpos( $key, 'sk_test_Mock' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Calls the Stripe API; the key goes only in the Authorization header. Returns the decoded body or an error shape. */
	private static function stripe_request( string $method, string $path, array $body, string $key ): array {
		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => $body ? http_build_query( $body ) : null,
			'timeout' => 20,
		);
		$url      = 'https://api.stripe.com/v1/' . $path;
		$response = 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array( 'error' => array( 'message' => 'Could not reach Stripe.' ) );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : array( 'error' => array( 'message' => 'Stripe returned an unreadable response.' ) );
	}

	private static function stripe_setups( array $product ): array {
		$setups = get_option( $product['stripe_setup_option'], array() );
		return is_array( $setups ) ? $setups : array();
	}

	private static function webhook_option( array $product ): array {
		$value = get_option( $product['webhooks_option'], array() );
		return is_array( $value ) ? $value : array();
	}
}
