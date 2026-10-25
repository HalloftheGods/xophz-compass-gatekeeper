<?php
/**
 * License REST endpoints for the apps that sell a registered product. All routes are public: the
 * caller proves itself with the account id (the product's account_param, a random UUID for xp-pro)
 * or the license key, and every route is rate limited.
 *
 * For each product in Gatekeeper_License_Products the routes live at
 * /compass/v1/gatekeeper/{route_prefix}/plans|claim|validate|recover|manage. The request and JSON
 * field names come from the product's account_param and device_param. Rate limit buckets are
 * per product: "{product}_{route}".
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_License_REST {

	const PER_MINUTE = 30;

	/** route name => HTTP methods. Each name is also the handler: Gatekeeper_License_REST::claim( $product_id, $request ). */
	const ROUTES = array(
		'plans'    => 'GET',
		'claim'    => 'POST',
		'validate' => 'POST',
		'recover'  => 'POST',
		'manage'   => 'POST',
	);

	public static function register( string $namespace ): void {
		foreach ( Gatekeeper_License_Products::all() as $product ) {
			self::register_product( $namespace, $product );
		}
		Gatekeeper_License_Setup::register( $namespace );
	}

	private static function register_product( string $namespace, array $product ): void {
		$id = $product['id'];
		foreach ( self::ROUTES as $name => $methods ) {
			register_rest_route(
				$namespace,
				'/' . $product['route_prefix'] . '/' . $name,
				array(
					array(
						'methods'             => 'GET' === $methods ? WP_REST_Server::READABLE : WP_REST_Server::CREATABLE,
						'callback'            => static function ( $request = null ) use ( $id, $name ) {
							return call_user_func( array( __CLASS__, $name ), $id, $request );
						},
						'permission_callback' => '__return_true',
					),
				)
			);
		}
		self::register_transitional_ai( $namespace, $product );
	}

	/**
	 * The AI helpers route is moving to the XP plugin (Xophz_Compass_Xp_Pro_AI serves
	 * /xp-pro/ai there). Until it does, Gatekeeper's copy keeps the route alive; it steps aside the
	 * moment the XP plugin's class exists, and goes away with includes/class-gatekeeper-xp-pro-ai.php.
	 */
	private static function register_transitional_ai( string $namespace, array $product ): void {
		if ( 'xp-pro' !== $product['id'] || ! class_exists( 'Gatekeeper_Xp_Pro_AI' ) || class_exists( 'Xophz_Compass_Xp_Pro_AI' ) ) {
			return;
		}
		register_rest_route(
			$namespace,
			'/' . $product['route_prefix'] . '/ai',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( 'Gatekeeper_Xp_Pro_AI', 'handle' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/** The rate limit bucket of one route of one product: "{product}_{route}", dashes as underscores. */
	public static function bucket( array $product, string $route ): string {
		return str_replace( '-', '_', $product['id'] ) . '_' . $route;
	}

	private static function limited( array $product, string $route, int $max, int $window, ?WP_REST_Request $request, string $scope = '' ): bool {
		return gatekeeper_rate_limited( self::bucket( $product, $route ), $max, $window, $request, $scope );
	}

	/** The account to key a bucket on: only one that exists, so inventing ids cannot open fresh buckets. */
	private static function known_account( array $product, string $account ): string {
		$id = $product['id'];
		return Gatekeeper_License_Store::is_account( $id, $account ) && null !== Gatekeeper_License_Store::get( $id, $account ) ? $account : '';
	}

	public static function plans( string $product_id, $request = null ): WP_REST_Response {
		$product = Gatekeeper_License_Products::get( $product_id );
		if ( null === $product ) {
			return self::error( 'not_found', 404 );
		}
		if ( self::limited( $product, 'plans', self::PER_MINUTE, 60, $request instanceof WP_REST_Request ? $request : null ) ) {
			return self::error( 'rate_limited', 429 );
		}
		return new WP_REST_Response( Gatekeeper_License_Products::plans( $product_id ), 200 );
	}

	public static function claim( string $product_id, WP_REST_Request $request ): WP_REST_Response {
		$product = Gatekeeper_License_Products::get( $product_id );
		if ( null === $product ) {
			return self::error( 'not_found', 404 );
		}
		$account = self::text( $request, $product['account_param'] );
		if ( self::limited( $product, 'claim', self::PER_MINUTE, 60, $request, self::known_account( $product, $account ) ) ) {
			return self::error( 'rate_limited', 429 );
		}

		$device = self::text( $request, $product['device_param'] );
		if ( ! Gatekeeper_License_Store::is_account( $product_id, $account ) || ! Gatekeeper_License_Store::is_device( $product_id, $device ) ) {
			return self::error( 'invalid_input', 400 );
		}

		if ( null === Gatekeeper_License_Store::get( $product_id, $account ) ) {
			return new WP_REST_Response( array( 'pro' => false ), 404 );
		}

		return self::respond_with_device( $product, $account, $device );
	}

	public static function validate( string $product_id, WP_REST_Request $request ): WP_REST_Response {
		$product = Gatekeeper_License_Products::get( $product_id );
		if ( null === $product ) {
			return self::error( 'not_found', 404 );
		}
		$field    = $product['account_param'];
		$key      = strtoupper( trim( self::text( $request, 'license_key' ) ) );
		$keyed_to = Gatekeeper_License_Store::find_by_key( $product_id, $key );
		if ( self::limited( $product, 'validate', self::PER_MINUTE, 60, $request, null === $keyed_to ? '' : (string) $keyed_to[ $field ] ) ) {
			return self::error( 'rate_limited', 429 );
		}

		$device = self::text( $request, $product['device_param'] );
		if ( ! Gatekeeper_License_Store::is_license_key( $product_id, $key ) || ! Gatekeeper_License_Store::is_device( $product_id, $device ) ) {
			return self::error( 'invalid_input', 400 );
		}

		$record = Gatekeeper_License_Store::find_by_key( $product_id, $key );
		if ( null === $record ) {
			return new WP_REST_Response( array( 'pro' => false ), 404 );
		}

		return self::respond_with_device( $product, (string) $record[ $field ], $device );
	}

	/** Always 202 for a well-formed address, so the answer never reveals whether keys exist. */
	public static function recover( string $product_id, WP_REST_Request $request ): WP_REST_Response {
		$product = Gatekeeper_License_Products::get( $product_id );
		if ( null === $product ) {
			return self::error( 'not_found', 404 );
		}
		if ( self::limited( $product, 'recover', 5, 3600, $request ) ) {
			return self::error( 'rate_limited', 429 );
		}

		$email = sanitize_email( self::text( $request, 'email' ) );
		if ( '' === $email || false === is_email( $email ) ) {
			return self::error( 'invalid_input', 400 );
		}

		$keys = array();
		foreach ( Gatekeeper_License_Store::find_by_email( $product_id, $email ) as $record ) {
			$keys[] = (string) $record['license_key'];
		}

		if ( ! empty( $keys ) ) {
			self::email_keys( $product, $email, $keys );
		}

		return new WP_REST_Response( array( 'ok' => true ), 202 );
	}

	public static function manage( string $product_id, WP_REST_Request $request ): WP_REST_Response {
		$product = Gatekeeper_License_Products::get( $product_id );
		if ( null === $product ) {
			return self::error( 'not_found', 404 );
		}
		if ( self::limited( $product, 'manage', self::PER_MINUTE, 60, $request ) ) {
			return self::error( 'rate_limited', 429 );
		}

		$key          = strtoupper( trim( self::text( $request, 'license_key' ) ) );
		$return_url   = self::text( $request, 'return_url' );
		$isAllowedUrl = class_exists( 'Xophz_Compass_Security' ) && Xophz_Compass_Security::is_allowed_redirect_url( $return_url );
		if ( ! Gatekeeper_License_Store::is_license_key( $product_id, $key ) || ! $isAllowedUrl ) {
			return self::error( 'invalid_input', 400 );
		}

		$record = Gatekeeper_License_Store::find_by_key( $product_id, $key );
		if ( null === $record ) {
			return new WP_REST_Response( array( 'pro' => false ), 404 );
		}

		$customer = $record['sources']['stripe']['customer'] ?? null;
		if ( is_string( $customer ) && '' !== $customer ) {
			return self::billing_portal( $customer, $return_url, ( $record['env'] ?? '' ) === 'sandbox' );
		}

		$store = $record['sources']['revenuecat']['store'] ?? null;
		if ( 'PLAY_STORE' === $store ) {
			return new WP_REST_Response( array( 'store' => 'PLAY_STORE' ), 200 );
		}
		if ( 'APP_STORE' === $store || 'MAC_APP_STORE' === $store ) {
			return new WP_REST_Response( array( 'store' => 'APP_STORE' ), 200 );
		}

		return self::error( 'no_billing', 404 );
	}

	private static function billing_portal( string $customer, string $return_url, bool $sandbox ): WP_REST_Response {
		$secret = class_exists( 'Xophz_Bazaar_Stripe_Key' ) ? Xophz_Bazaar_Stripe_Key::resolve( $sandbox ) : '';
		if ( '' === $secret ) {
			return self::error( 'billing_unavailable', 503 );
		}

		// The Stripe secret goes in the Authorization header only, never in the URL.
		$response = wp_remote_post(
			'https://api.stripe.com/v1/billing_portal/sessions',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
				'body'    => array(
					'customer'   => $customer,
					'return_url' => $return_url,
				),
			)
		);

		$body = is_wp_error( $response ) ? array() : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$url  = is_array( $body ) ? ( $body['url'] ?? null ) : null;
		$isOk = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) && is_string( $url ) && '' !== $url;
		if ( ! $isOk ) {
			return self::error( 'billing_unavailable', 502 );
		}

		return new WP_REST_Response( array( 'url' => $url ), 200 );
	}

	/** Add the device, then answer with the account's public view; a full license answers 409. */
	private static function respond_with_device( array $product, string $account, string $device ): WP_REST_Response {
		$id       = $product['id'];
		$attached = Gatekeeper_License_Store::attach_device( $id, $account, $device );
		if ( is_wp_error( $attached ) ) {
			$status = (int) ( $attached->get_error_data()['status'] ?? 400 );
			return self::error( $attached->get_error_code(), $status );
		}

		$record = Gatekeeper_License_Store::get( $id, $account );
		if ( null === $record ) {
			return new WP_REST_Response( array( 'pro' => false ), 404 );
		}
		return new WP_REST_Response( Gatekeeper_License_Store::public_view( $id, $record, time() ), 200 );
	}

	private static function email_keys( array $product, string $email, array $keys ): void {
		$label = (string) $product['label'];
		$lines = array( 'Here is your ' . $label . ' key' . ( count( $keys ) > 1 ? 's' : '' ) . ':', '' );
		foreach ( $keys as $key ) {
			$lines[] = $key;
		}
		$lines[] = '';
		$lines[] = sprintf( 'To use it on a device: open %s, go to Settings > %s > Restore with key, and type the key.', $product['app_name'], $label );
		$support = Gatekeeper_License_Intake::support_line( $product );
		if ( '' !== $support ) {
			$lines[] = $support;
		}

		wp_mail( $email, (string) $product['strings']['key_email_subject'], implode( "\n", $lines ) );
	}

	private static function text( WP_REST_Request $request, string $name ): string {
		$value = $request->get_param( $name );
		return is_string( $value ) ? $value : '';
	}

	private static function error( string $code, int $status ): WP_REST_Response {
		return new WP_REST_Response( array( 'error' => $code ), $status );
	}
}
