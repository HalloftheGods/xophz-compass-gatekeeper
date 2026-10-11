<?php
/**
 * Gatekeeper REST Controller
 *
 * Exposes standardized REST endpoints for machine API key provisioning,
 * software license validation, and GitHub sponsor entitlement checks.
 *
 * @package    Xophz_Compass_Gatekeeper
 * @subpackage Xophz_Compass_Gatekeeper/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Xophz_Compass_REST_Controller' ) ) {
	$core_controller = dirname( dirname( __DIR__ ) ) . '/xophz-compass/includes/core/class-compass-rest-controller.php';
	if ( file_exists( $core_controller ) ) {
		require_once $core_controller;
	}
}

class Gatekeeper_REST extends Xophz_Compass_REST_Controller {

	public function __construct() {
		parent::__construct( 'gatekeeper', 'v1' );
	}

	/**
	 * Register all Gatekeeper REST routes.
	 */
	public function register_routes(): void {
		// Health & Status
		register_rest_route(
			$this->namespace,
			'/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'permissions_public' ),
				),
			)
		);

		// Machine API Keys
		register_rest_route(
			$this->namespace,
			'/keys',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_keys' ),
					'permission_callback' => array( $this, 'permissions_admin' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_key' ),
					'permission_callback' => array( $this, 'permissions_admin' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/keys/(?P<id>[a-zA-Z0-9-_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke_key' ),
					'permission_callback' => array( $this, 'permissions_admin' ),
				),
			)
		);

		// Software & Sponsor License Verification (Client / CLI Gateway)
		register_rest_route(
			$this->namespace,
			'/licenses/validate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'validate_license' ),
					'permission_callback' => array( $this, 'permissions_public' ),
					'args'                => array(
						'key' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'deviceId' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Sponsor Entitlement Sync (Called by Hookshot GitHub Sponsors Bridge)
		register_rest_route(
			$this->namespace,
			'/sponsors/sync',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sync_sponsor' ),
					'permission_callback' => array( $this, 'permissions_admin' ),
				),
			)
		);

		// Client Software Licenses Management
		register_rest_route(
			$this->namespace,
			'/licenses',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_licenses' ),
					'permission_callback' => array( $this, 'permissions_admin' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'mint_license' ),
					'permission_callback' => array( $this, 'permissions_admin' ),
					'args'                => array(
						'tier'        => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'standard',
							'sanitize_callback' => 'sanitize_key',
						),
						'github_user' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'email'       => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'max_devices' => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 3,
						),
					),
				),
			)
		);

		// Sponsor Entitlements Listing
		register_rest_route(
			$this->namespace,
			'/sponsors',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_sponsors' ),
					'permission_callback' => array( $this, 'permissions_admin' ),
				),
			)
		);

		// Machine Schema / AI Abilities Declaration
		register_rest_route(
			$this->namespace,
			'/schema',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_schema' ),
					'permission_callback' => array( $this, 'permissions_public' ),
				),
			)
		);
	}

	/**
	 * Get module health and metrics.
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		$keys     = Gatekeeper_Keys::list_keys();
		$sponsors = Gatekeeper_Entitlements::list_sponsors();

		$active_keys     = count( array_filter( $keys, fn( $k ) => ( $k['status'] ?? '' ) === 'active' ) );
		$active_sponsors = count( array_filter( $sponsors, fn( $s ) => ( $s['status'] ?? '' ) === 'active' ) );

		return rest_ensure_response(
			array(
				'status'          => 'healthy',
				'engine'          => 'Xophz Gatekeeper Auth & SaaS Gateway',
				'version'         => XOPHZ_COMPASS_GATEKEEPER_VERSION,
				'active_keys'     => $active_keys,
				'active_sponsors' => $active_sponsors,
				'default_prefix'  => Gatekeeper_Keys::DEFAULT_PREFIX,
				'timestamp'       => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Create a new machine API key with custom prefix support.
	 */
	public function create_key( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id() ?: 1;
		$name    = sanitize_text_field( (string) $request->get_param( 'name' ) ?: 'CLI Token' );
		$scopes  = (array) ( $request->get_param( 'scopes' ) ?: array( 'read:audits', 'verify:licenses' ) );
		$prefix  = sanitize_key( (string) ( $request->get_param( 'prefix' ) ?: Gatekeeper_Keys::DEFAULT_PREFIX ) );
		$expiry  = $request->get_param( 'expiry_days' ) ? (int) $request->get_param( 'expiry_days' ) : null;

		$key_data = Gatekeeper_Keys::generate_key( $user_id, $name, $scopes, $prefix, $expiry );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'API Key created successfully. Store plain_key securely; it will not be shown again.',
				'key'     => $key_data,
			)
		);
	}

	/**
	 * List all machine API keys (display format).
	 */
	public function list_keys( WP_REST_Request $request ): WP_REST_Response {
		$keys = Gatekeeper_Keys::list_keys();
		return rest_ensure_response(
			array(
				'success' => true,
				'count'   => count( $keys ),
				'keys'    => $keys,
			)
		);
	}

	/**
	 * Revoke an API key.
	 */
	public function revoke_key( WP_REST_Request $request ): WP_REST_Response {
		$key_id  = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$success = Gatekeeper_Keys::revoke_key( $key_id );

		if ( ! $success ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Key not found.' ), 404 );
		}

		return rest_ensure_response( array( 'success' => true, 'message' => "Key '{$key_id}' revoked." ) );
	}

	/**
	 * Validate a software license or GitHub sponsor handle.
	 */
	public function validate_license( WP_REST_Request $request ): WP_REST_Response {
		$key_or_user = (string) ( $request->get_param( 'key' ) ?: $request->get_param( 'licenseKey' ) ?: '' );
		$device_id   = (string) ( $request->get_param( 'deviceId' ) ?: '' );

		$result = Gatekeeper_Licenses::validate( $key_or_user, $device_id );

		if ( ! $result['valid'] ) {
			return new WP_REST_Response(
				array(
					'valid' => false,
					'error' => $result['error'] ?? 'Invalid license key or inactive sponsorship.',
				),
				401
			);
		}

		return rest_ensure_response(
			array(
				'valid'        => true,
				'tier'         => $result['tier'] ?? 'standard',
				'is_sponsor'   => $result['is_sponsor'] ?? false,
				'github_user'  => $result['github_user'] ?? null,
				'nag_silenced' => true,
				'device_count' => $result['device_count'] ?? 1,
				'max_devices'  => $result['max_devices'] ?? 3,
			)
		);
	}

	/**
	 * Sync GitHub Sponsor from Hookshot bridge.
	 */
	public function sync_sponsor( WP_REST_Request $request ): WP_REST_Response {
		$user     = sanitize_text_field( (string) $request->get_param( 'github_user' ) );
		$tier     = sanitize_key( (string) ( $request->get_param( 'tier' ) ?: 'solo_sponsor' ) );
		$action   = sanitize_key( (string) ( $request->get_param( 'action' ) ?: 'created' ) );
		$metadata = (array) ( $request->get_param( 'metadata' ) ?: array() );

		if ( empty( $user ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'github_user is required.' ), 400 );
		}

		if ( $action === 'cancelled' ) {
			Gatekeeper_Entitlements::cancel_sponsor( $user );
			return rest_ensure_response( array( 'success' => true, 'action' => 'cancelled', 'github_user' => $user ) );
		}

		$record = Gatekeeper_Entitlements::record_sponsor( $user, $tier, $metadata );

		return rest_ensure_response(
			array(
				'success' => true,
				'action'  => $action,
				'sponsor' => $record,
			)
		);
	}

	/**
	 * List all stored client software licenses.
	 */
	public function list_licenses( WP_REST_Request $request ): WP_REST_Response {
		$licenses = Gatekeeper_Licenses::get_all_licenses();
		return rest_ensure_response(
			array(
				'success'  => true,
				'count'    => count( $licenses ),
				'licenses' => array_values( $licenses ),
			)
		);
	}

	/**
	 * Mint a new client software license.
	 */
	public function mint_license( WP_REST_Request $request ): WP_REST_Response {
		$tier        = (string) ( $request->get_param( 'tier' ) ?: 'standard' );
		$github_user = $request->get_param( 'github_user' ) ? (string) $request->get_param( 'github_user' ) : null;
		$email       = $request->get_param( 'email' ) ? (string) $request->get_param( 'email' ) : null;
		$max_devices = $request->get_param( 'max_devices' ) !== null ? (int) $request->get_param( 'max_devices' ) : 3;

		$record = Gatekeeper_Licenses::mint_license( $tier, $github_user, $email, $max_devices );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'License minted successfully.',
				'license' => $record,
			)
		);
	}

	/**
	 * List all GitHub sponsor entitlements.
	 */
	public function list_sponsors( WP_REST_Request $request ): WP_REST_Response {
		$sponsors = Gatekeeper_Entitlements::list_sponsors();
		return rest_ensure_response(
			array(
				'success'  => true,
				'count'    => count( $sponsors ),
				'sponsors' => $sponsors,
			)
		);
	}

	/**
	 * Expose machine-readable schema and abilities for AI agents.
	 */
	public function get_schema( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			array(
				'namespace' => $this->namespace,
				'abilities' => array(
					array(
						'action'      => 'validate_license',
						'endpoint'    => "/{$this->namespace}/licenses/validate",
						'method'      => 'POST',
						'description' => 'Validates client license keys or GitHub sponsor handles to unlock CLI and web tools.',
						'parameters'  => array( 'key' => 'string (required)', 'deviceId' => 'string (optional)' ),
					),
					array(
						'action'      => 'create_key',
						'endpoint'    => "/{$this->namespace}/keys",
						'method'      => 'POST',
						'description' => 'Mints a machine-to-machine API key with custom prefix (default stargate_).',
						'parameters'  => array( 'name' => 'string', 'prefix' => 'string', 'scopes' => 'array' ),
					),
					array(
						'action'      => 'list_keys',
						'endpoint'    => "/{$this->namespace}/keys",
						'method'      => 'GET',
						'description' => 'Lists active machine-to-machine API keys (masked tokens).',
						'parameters'  => array(),
					),
					array(
						'action'      => 'list_licenses',
						'endpoint'    => "/{$this->namespace}/licenses",
						'method'      => 'GET',
						'description' => 'Lists all stored client software licenses and activation records.',
						'parameters'  => array(),
					),
					array(
						'action'      => 'mint_license',
						'endpoint'    => "/{$this->namespace}/licenses",
						'method'      => 'POST',
						'description' => 'Mints a client software license for Chemical X or COMPASS companion tools.',
						'parameters'  => array( 'tier' => 'string', 'github_user' => 'string', 'email' => 'string' ),
					),
					array(
						'action'      => 'list_sponsors',
						'endpoint'    => "/{$this->namespace}/sponsors",
						'method'      => 'GET',
						'description' => 'Lists all active GitHub sponsor entitlements.',
						'parameters'  => array(),
					),
					array(
						'action'      => 'sync_sponsor',
						'endpoint'    => "/{$this->namespace}/sponsors/sync",
						'method'      => 'POST',
						'description' => 'Internal sync hook for Hookshot GitHub Sponsors webhooks.',
						'parameters'  => array( 'github_user' => 'string', 'tier' => 'string', 'action' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * Universal Route Guard for companion plugins (kept for callers of Gatekeeper_REST::guard()).
	 *
	 * The guard lives in Gatekeeper_Route_Guard (`class-gatekeeper-route-guard.php`). It reads
	 * the key from the X-API-Key or Authorization: Bearer header only; `?api_key=` gets a 400.
	 *
	 * @param string        $required_scope   Required capability scope (e.g. 'xp:write', 'xp:read').
	 * @param callable|null $fallback_callback Optional callback if no machine key is presented.
	 * @return callable
	 */
	public static function guard( string $required_scope = '', $fallback_callback = null ): callable {
		return Gatekeeper_Route_Guard::guard( $required_scope, $fallback_callback );
	}
}
