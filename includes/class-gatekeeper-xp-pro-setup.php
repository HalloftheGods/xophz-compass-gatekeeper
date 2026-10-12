<?php
/**
 * XP Pro setup API for administrators: wires the Stripe webhook end to end and reads or writes
 * the XP Pro settings, so the whole setup can be done over REST.
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

class Gatekeeper_Xp_Pro_Setup {

	const OPTION_STRIPE_SETUP = 'gatekeeper_xp_pro_stripe_setup';

	const STRIPE_EVENTS = array(
		'checkout.session.completed',
		'checkout.session.async_payment_succeeded',
		'checkout.session.async_payment_failed',
		'invoice.paid',
		'customer.subscription.deleted',
		'charge.refunded',
		'charge.dispute.created',
	);

	public static function register( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/xp-pro/setup/stripe-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'stripe_webhook' ),
				'permission_callback' => array( __CLASS__, 'is_admin' ),
			)
		);
		register_rest_route(
			$namespace,
			'/xp-pro/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => array( __CLASS__, 'is_admin' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_settings' ),
					'permission_callback' => array( __CLASS__, 'is_admin' ),
				),
			)
		);
	}

	public static function is_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * POST /xp-pro/setup/stripe-webhook {mode: 'test'|'live', rotate?: bool}
	 *
	 * Creates an incoming Hookshot webhook, registers it as a Stripe webhook endpoint for the
	 * XP Pro events, stores Stripe's signing secret in Hookshot, and records the webhook id in
	 * gatekeeper_xp_pro_webhooks (slot stripe_test or stripe). Calling it again returns the
	 * existing setup unless rotate is true.
	 */
	public static function stripe_webhook( WP_REST_Request $request ): WP_REST_Response {
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

		$setups   = self::stripe_setups();
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
				'post_title'  => sprintf( 'XP Pro Stripe (%s)', $mode ),
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
			'description' => 'Do It For The XP: XP Pro (Gatekeeper via Hookshot)',
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

		$record           = array(
			'webhook_id'         => (int) $webhook_id,
			'stripe_endpoint_id' => (string) $created['id'],
			'livemode'           => ! empty( $created['livemode'] ),
			'created_at'         => gmdate( 'c' ),
		);
		$setups[ $mode ] = $record;
		update_option( self::OPTION_STRIPE_SETUP, $setups, false );

		$webhooks = self::webhook_option();
		$webhooks[ 'live' === $mode ? 'stripe' : 'stripe_test' ] = (int) $webhook_id;
		update_option( Gatekeeper_Xp_Pro_Intake::OPTION_WEBHOOKS, $webhooks, false );

		return new WP_REST_Response( self::describe( $mode, $record, true ), 201 );
	}

	/** GET /xp-pro/settings */
	public static function get_settings( $request = null ): WP_REST_Response {
		return new WP_REST_Response( self::settings_view(), 200 );
	}

	/**
	 * POST /xp-pro/settings with any of:
	 * - webhooks: {stripe?, stripe_test?, revenuecat?} positive ints (0 clears a slot)
	 * - ai_daily_limit: int 1..10000
	 * - lifetime_on_sale: bool
	 *
	 * Site-wide security settings (the redirect allowlist, the site URL) are not writable here;
	 * an administrator sets them in wp-admin.
	 */
	public static function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$errors = array();

		$webhooks = $request->get_param( 'webhooks' );
		if ( null !== $webhooks ) {
			if ( ! is_array( $webhooks ) ) {
				$errors[] = 'webhooks must be an object';
			} else {
				$current = self::webhook_option();
				foreach ( array( 'stripe', 'stripe_test', 'revenuecat' ) as $slot ) {
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
					update_option( Gatekeeper_Xp_Pro_Intake::OPTION_WEBHOOKS, $current, false );
				}
			}
		}

		$limit = $request->get_param( 'ai_daily_limit' );
		if ( null !== $limit ) {
			$limit = (int) $limit;
			if ( $limit < 1 || $limit > 10000 ) {
				$errors[] = 'ai_daily_limit must be between 1 and 10000';
			} else {
				update_option( 'xp_pro_ai_daily_limit', $limit );
			}
		}

		$on_sale = $request->get_param( 'lifetime_on_sale' );
		if ( null !== $on_sale ) {
			update_option( 'xp_pro_lifetime_on_sale', $on_sale ? '1' : '0' );
		}

		if ( ! empty( $errors ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_input', 'messages' => $errors, 'settings' => self::settings_view() ), 400 );
		}
		return new WP_REST_Response( self::settings_view(), 200 );
	}

	public static function settings_view(): array {
		return array(
			'webhooks'                 => self::webhook_option(),
			'stripe_setup'             => array_map(
				static function ( $r ) {
					return is_array( $r ) ? array_intersect_key( $r, array_flip( array( 'webhook_id', 'stripe_endpoint_id', 'livemode', 'created_at' ) ) ) : $r;
				},
				self::stripe_setups()
			),
			'ai_daily_limit'           => (int) get_option( 'xp_pro_ai_daily_limit', 100 ),
			'lifetime_on_sale'         => ! in_array( strtolower( (string) get_option( 'xp_pro_lifetime_on_sale', '1' ) ), array( '', '0', 'false', 'no', 'off' ), true ),
			'trusted_redirect_domains' => self::trusted_domains(),
			'siteurl'                  => (string) get_option( 'siteurl', '' ),
			'home'                     => (string) get_option( 'home', '' ),
		);
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
			'Xophz_Bazaar_Stripe_Key'      => 'Bazaar',
			'Hookshot_Webhook_Secrets'     => 'Hookshot',
			'Xophz_Compass_Hookshot_REST'  => 'Hookshot',
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

	private static function stripe_setups(): array {
		$setups = get_option( self::OPTION_STRIPE_SETUP, array() );
		return is_array( $setups ) ? $setups : array();
	}

	private static function webhook_option(): array {
		$value = get_option( Gatekeeper_Xp_Pro_Intake::OPTION_WEBHOOKS, array() );
		return is_array( $value ) ? $value : array();
	}
}
