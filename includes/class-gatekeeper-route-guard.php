<?php
/**
 * Gatekeeper Route Guard
 *
 * A REST permission_callback factory that companion plugins wrap their routes in. It reads a
 * machine API key from the X-API-Key header or Authorization: Bearer only, checks it (and the
 * route's scope) against Gatekeeper_Keys, and runs the request as the key's owner.
 *
 * A key in the query string (`?api_key=`) is refused with 400 `gatekeeper_api_key_in_url`,
 * valid or not (#5992): URLs land in server, proxy and browser logs, so the caller is told to
 * move the key to a header and rotate it. An `api_key` body field is ignored.
 *
 * @package    Xophz_Compass_Gatekeeper
 * @subpackage Xophz_Compass_Gatekeeper/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_Route_Guard {

	/**
	 * Build a permission_callback that requires an API key with $required_scope.
	 *
	 * @param string        $required_scope    Required capability scope (e.g. 'xp:write', 'xp:read').
	 * @param callable|null $fallback_callback Optional callback when no key is presented (e.g. cookie session).
	 * @return callable
	 */
	public static function guard( string $required_scope = '', $fallback_callback = null ): callable {
		return function( WP_REST_Request $request ) use ( $required_scope, $fallback_callback ) {
			// 1. A key in the URL is already in server and proxy logs: refuse it so the caller notices.
			if ( self::has_query_key( $request ) ) {
				return new WP_Error(
					'gatekeeper_api_key_in_url',
					'Send the API key in the X-API-Key header or as Authorization: Bearer, not in the URL. Treat a key that was sent in a URL as exposed and rotate it.',
					array( 'status' => 400 )
				);
			}

			// 2. Read the key from the X-API-Key or Authorization: Bearer header only.
			$api_key = self::header_key( $request );

			// 3. Validate presented API key
			if ( '' !== $api_key ) {
				return self::authorize( $request, $api_key, $required_scope );
			}

			// 4. Fall back to secondary callback if key is omitted (e.g. cookie session)
			if ( is_callable( $fallback_callback ) ) {
				return call_user_func( $fallback_callback, $request );
			}

			return new WP_Error(
				'gatekeeper_missing_key',
				'Missing machine API key. Please provide an X-API-Key or Authorization Bearer header.',
				array( 'status' => 401 )
			);
		};
	}

	/** True when the query string carries a non-empty `api_key`. */
	private static function has_query_key( WP_REST_Request $request ): bool {
		$query = $request->get_query_params();
		return isset( $query['api_key'] ) && '' !== $query['api_key'];
	}

	/** The key from X-API-Key, else from Authorization: Bearer, else ''. */
	private static function header_key( WP_REST_Request $request ): string {
		$api_key = $request->get_header( 'x-api-key' ) ?: $request->get_header( 'x_api_key' );
		if ( ! empty( $api_key ) ) {
			return (string) $api_key;
		}
		$auth_header = $request->get_header( 'authorization' );
		if ( ! empty( $auth_header ) && preg_match( '/Bearer\s+([a-zA-Z0-9_\-]+)/i', $auth_header, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * Check a presented key and its scope, then run the request as the key's owner.
	 *
	 * @return true|WP_Error
	 */
	private static function authorize( WP_REST_Request $request, string $api_key, string $required_scope ) {
		$validation = Gatekeeper_Keys::validate_key( $api_key, $required_scope ?: null );
		if ( ! $validation['valid'] ) {
			$error_code = 'gatekeeper_unauthorized';
			$status     = 401;
			if ( strpos( $validation['error'] ?? '', 'Missing required scope' ) !== false ) {
				$error_code = 'gatekeeper_forbidden_scope';
				$status     = 403;
			}
			return new WP_Error( $error_code, $validation['error'] ?? 'Unauthorized.', array( 'status' => $status ) );
		}

		// Set active WordPress user context to key owner
		if ( ! empty( $validation['user_id'] ) ) {
			wp_set_current_user( (int) $validation['user_id'] );
		}

		// Store key details in request params for handler consumption
		$request->set_param( '_gatekeeper_key_id', $validation['key_id'] ?? null );
		$request->set_param( '_gatekeeper_scopes', $validation['scopes'] ?? array() );

		// Allow external filters to inspect or augment authorization
		$is_allowed = apply_filters( 'gatekeeper_authorize_route', true, $request, $required_scope, $validation );
		if ( is_wp_error( $is_allowed ) ) {
			return $is_allowed;
		}
		if ( false === $is_allowed ) {
			return new WP_Error( 'gatekeeper_filter_rejected', 'Route access denied by filter.', array( 'status' => 403 ) );
		}

		return true;
	}
}

if ( ! function_exists( 'gatekeeper_guard' ) ) {
	/**
	 * Helper function to wrap REST route permission_callback with Gatekeeper route guard.
	 *
	 * @param string        $required_scope   Required capability scope.
	 * @param callable|null $fallback_callback Optional fallback callback.
	 * @return callable
	 */
	function gatekeeper_guard( string $required_scope = '', $fallback_callback = null ): callable {
		return Gatekeeper_Route_Guard::guard( $required_scope, $fallback_callback );
	}
}

// Register global filter hook for plugins checking Gatekeeper availability dynamically
add_filter( 'gatekeeper_route_guard', function( $default_callback, string $required_scope = '', $fallback = null ) {
	return Gatekeeper_Route_Guard::guard( $required_scope, $fallback );
}, 10, 3 );
