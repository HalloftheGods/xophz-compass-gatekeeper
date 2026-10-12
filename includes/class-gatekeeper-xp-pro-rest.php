<?php
/**
 * XP Pro REST endpoints for the Do It For The XP app. All routes are public: the caller proves
 * itself with the family id (a random UUID) or the license key, and every route is rate limited.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_Xp_Pro_REST {

	const PER_MINUTE = 30;

	public static function register( string $namespace ): void {
		$routes = array(
			'/xp-pro/plans'    => array( WP_REST_Server::READABLE, __CLASS__, 'plans' ),
			'/xp-pro/claim'    => array( WP_REST_Server::CREATABLE, __CLASS__, 'claim' ),
			'/xp-pro/validate' => array( WP_REST_Server::CREATABLE, __CLASS__, 'validate' ),
			'/xp-pro/recover'  => array( WP_REST_Server::CREATABLE, __CLASS__, 'recover' ),
			'/xp-pro/manage'   => array( WP_REST_Server::CREATABLE, __CLASS__, 'manage' ),
			'/xp-pro/ai'       => array( WP_REST_Server::CREATABLE, 'Gatekeeper_Xp_Pro_AI', 'handle' ),
		);

		foreach ( $routes as $path => $route ) {
			register_rest_route(
				$namespace,
				$path,
				array(
					array(
						'methods'             => $route[0],
						'callback'            => array( $route[1], $route[2] ),
						'permission_callback' => '__return_true',
					),
				)
			);
		}
	}

	/**
	 * The caller's address: Hookshot_Client_Ip::resolve() when Hookshot is active (it reads
	 * X-Forwarded-For only from a configured trusted proxy, XOPHZ_HOOKSHOT_TRUSTED_PROXIES), else
	 * REMOTE_ADDR. Behind Cloudflare without that constant every caller would share one bucket.
	 */
	public static function client_ip( ?WP_REST_Request $request = null ): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		if ( null !== $request && class_exists( 'Hookshot_Client_Ip' ) ) {
			$resolved = Hookshot_Client_Ip::resolve( $request );
			if ( is_string( $resolved ) && '' !== $resolved ) {
				return $resolved;
			}
		}
		return $remote;
	}

	/**
	 * Fixed-window counter per route and client address (claim and validate: per family and address),
	 * bumped atomically in the options table. Returns true when this request is over the limit. The
	 * window is part of the counter name, so a new minute (or hour) starts a fresh row.
	 */
	public static function rate_limited( string $bucket, int $max, int $window_seconds, ?WP_REST_Request $request = null, string $family_id = '' ): bool {
		$scope   = self::client_ip( $request ) . '|' . $family_id;
		$counter = Gatekeeper_Xp_Pro_Db::rate_counter( $bucket, $scope, $window_seconds );
		return Gatekeeper_Xp_Pro_Db::increment( $counter ) > $max;
	}

	/** The family to key a bucket on: only one that exists, so inventing ids cannot open fresh buckets. */
	private static function known_family( string $family_id ): string {
		return Gatekeeper_Xp_Pro_Licenses::is_uuid4( $family_id ) && null !== Gatekeeper_Xp_Pro_Licenses::get( $family_id ) ? $family_id : '';
	}

	public static function plans( $request = null ): WP_REST_Response {
		if ( self::rate_limited( 'plans', self::PER_MINUTE, 60, $request instanceof WP_REST_Request ? $request : null ) ) {
			return self::error( 'rate_limited', 429 );
		}
		$plans = apply_filters( 'gatekeeper_xp_pro_plans', array() );
		return new WP_REST_Response( is_array( $plans ) ? $plans : array(), 200 );
	}

	public static function claim( WP_REST_Request $request ): WP_REST_Response {
		$family_id = self::text( $request, 'family_id' );
		if ( self::rate_limited( 'claim', self::PER_MINUTE, 60, $request, self::known_family( $family_id ) ) ) {
			return self::error( 'rate_limited', 429 );
		}

		$device_id = self::text( $request, 'device_id' );
		if ( ! Gatekeeper_Xp_Pro_Licenses::is_uuid4( $family_id ) || ! Gatekeeper_Xp_Pro_Licenses::is_device_id( $device_id ) ) {
			return self::error( 'invalid_input', 400 );
		}

		if ( null === Gatekeeper_Xp_Pro_Licenses::get( $family_id ) ) {
			return new WP_REST_Response( array( 'pro' => false ), 404 );
		}

		return self::respond_with_device( $family_id, $device_id );
	}

	public static function validate( WP_REST_Request $request ): WP_REST_Response {
		$key       = strtoupper( trim( self::text( $request, 'license_key' ) ) );
		$keyed_to  = Gatekeeper_Xp_Pro_Licenses::find_by_key( $key );
		if ( self::rate_limited( 'validate', self::PER_MINUTE, 60, $request, null === $keyed_to ? '' : (string) $keyed_to['family_id'] ) ) {
			return self::error( 'rate_limited', 429 );
		}

		$device_id = self::text( $request, 'device_id' );
		if ( ! Gatekeeper_Xp_Pro_Licenses::is_license_key( $key ) || ! Gatekeeper_Xp_Pro_Licenses::is_device_id( $device_id ) ) {
			return self::error( 'invalid_input', 400 );
		}

		$record = Gatekeeper_Xp_Pro_Licenses::find_by_key( $key );
		if ( null === $record ) {
			return new WP_REST_Response( array( 'pro' => false ), 404 );
		}

		return self::respond_with_device( $record['family_id'], $device_id );
	}

	/** Always 202 for a well-formed address, so the answer never reveals whether keys exist. */
	public static function recover( WP_REST_Request $request ): WP_REST_Response {
		if ( self::rate_limited( 'recover', 5, 3600, $request ) ) {
			return self::error( 'rate_limited', 429 );
		}

		$email = sanitize_email( self::text( $request, 'email' ) );
		if ( '' === $email || false === is_email( $email ) ) {
			return self::error( 'invalid_input', 400 );
		}

		$keys = array();
		foreach ( Gatekeeper_Xp_Pro_Licenses::find_by_email( $email ) as $record ) {
			$keys[] = (string) $record['license_key'];
		}

		if ( ! empty( $keys ) ) {
			self::email_keys( $email, $keys );
		}

		return new WP_REST_Response( array( 'ok' => true ), 202 );
	}

	public static function manage( WP_REST_Request $request ): WP_REST_Response {
		if ( self::rate_limited( 'manage', self::PER_MINUTE, 60, $request ) ) {
			return self::error( 'rate_limited', 429 );
		}

		$key        = strtoupper( trim( self::text( $request, 'license_key' ) ) );
		$return_url = self::text( $request, 'return_url' );
		$isAllowedUrl = class_exists( 'Xophz_Compass_Security' ) && Xophz_Compass_Security::is_allowed_redirect_url( $return_url );
		if ( ! Gatekeeper_Xp_Pro_Licenses::is_license_key( $key ) || ! $isAllowedUrl ) {
			return self::error( 'invalid_input', 400 );
		}

		$record = Gatekeeper_Xp_Pro_Licenses::find_by_key( $key );
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

		$body  = is_wp_error( $response ) ? array() : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$url   = is_array( $body ) ? ( $body['url'] ?? null ) : null;
		$isOk  = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) && is_string( $url ) && '' !== $url;
		if ( ! $isOk ) {
			return self::error( 'billing_unavailable', 502 );
		}

		return new WP_REST_Response( array( 'url' => $url ), 200 );
	}

	/** Add the device, then answer with the family's public view; a full license answers 409. */
	private static function respond_with_device( string $family_id, string $device_id ): WP_REST_Response {
		$attached = Gatekeeper_Xp_Pro_Licenses::attach_device( $family_id, $device_id );
		if ( is_wp_error( $attached ) ) {
			$status = (int) ( $attached->get_error_data()['status'] ?? 400 );
			return self::error( $attached->get_error_code(), $status );
		}

		$record = Gatekeeper_Xp_Pro_Licenses::get( $family_id );
		if ( null === $record ) {
			return new WP_REST_Response( array( 'pro' => false ), 404 );
		}
		return new WP_REST_Response( Gatekeeper_Xp_Pro_Licenses::public_view( $record, time() ), 200 );
	}

	private static function email_keys( string $email, array $keys ): void {
		$support = (string) get_option( 'admin_email', '' );
		$lines   = array( 'Here is your XP Pro key' . ( count( $keys ) > 1 ? 's' : '' ) . ':', '' );
		foreach ( $keys as $key ) {
			$lines[] = $key;
		}
		$lines[] = '';
		$lines[] = 'To use it on a device: open Do It For The XP, go to Settings > XP Pro > Restore with key, and type the key.';
		if ( '' !== $support ) {
			$lines[] = 'Questions? Write to ' . $support . '.';
		}

		wp_mail( $email, 'Your XP Pro key', implode( "\n", $lines ) );
	}

	private static function text( WP_REST_Request $request, string $name ): string {
		$value = $request->get_param( $name );
		return is_string( $value ) ? $value : '';
	}

	private static function error( string $code, int $status ): WP_REST_Response {
		return new WP_REST_Response( array( 'error' => $code ), $status );
	}
}
