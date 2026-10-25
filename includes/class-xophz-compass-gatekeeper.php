<?php
/**
 * The main Gatekeeper plugin class.
 * Extends Xophz_Compass_Plugin_Base to integrate with COMPASS Core.
 *
 * @package    Xophz_Compass_Gatekeeper
 * @subpackage Xophz_Compass_Gatekeeper/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Xophz_Compass_Gatekeeper extends Xophz_Compass_Plugin_Base {

	/**
	 * REST Controller instance.
	 */
	protected Gatekeeper_REST $rest;

	/**
	 * Admin class instance.
	 */
	protected Xophz_Compass_Gatekeeper_Admin $admin;

	/**
	 * Initialize plugin components and hooks.
	 */
	public function init(): void {
		$this->rest  = new Gatekeeper_REST();
		$this->admin = new Xophz_Compass_Gatekeeper_Admin( $this->slug, $this->version );

		// Register abilities into central COMPASS registry
		add_filter( 'compass_abilities_registry', array( $this, 'register_abilities' ) );

		// Ingest Hookshot webhooks for SaaS subscription fulfillment
		add_action( 'xophz_hookshot_incoming', array( $this, 'handle_hookshot_incoming' ), 10, 2 );

		// License products (XP Pro for Do It For The XP): grants, extensions and revocations from Stripe and RevenueCat
		add_action( 'xophz_hookshot_incoming', array( 'Gatekeeper_License_Intake', 'handle' ), 20, 2 );

		// Daily cleanup of the license rate limit and daily counters
		Gatekeeper_License_Db::register_cleanup();
	}

	/**
	 * Register REST routes.
	 */
	public function register_rest_routes(): void {
		if ( isset( $this->rest ) ) {
			$this->rest->register_routes();
		}
	}

	/**
	 * Register abilities with COMPASS core.
	 */
	public function register_abilities( array $abilities ): array {
		$abilities[] = array(
			'id'          => 'compass/validate_license',
			'name'        => 'Validate Client License / Sponsor',
			'plugin'      => 'xophz-compass-gatekeeper',
			'category'    => 'Security',
			'description' => 'Validates Chemical X software license or GitHub Sponsor entitlement.',
			'parameters'  => array(
				'key'      => array( 'type' => 'string', 'required' => true ),
				'deviceId' => array( 'type' => 'string', 'required' => false ),
			),
		);

		$abilities[] = array(
			'id'          => 'compass/create_api_key',
			'name'        => 'Mint Machine API Key',
			'plugin'      => 'xophz-compass-gatekeeper',
			'category'    => 'Security',
			'description' => 'Mints a machine-to-machine API key with custom prefix (default stargate_).',
			'parameters'  => array(
				'name'   => array( 'type' => 'string', 'required' => false ),
				'prefix' => array( 'type' => 'string', 'required' => false ),
				'scopes' => array( 'type' => 'array', 'required' => false ),
			),
		);

		return $abilities;
	}

	/**
	 * YouMeOS Spark Definition for Gatekeeper.
	 */
	public function get_spark_definition(): ?array {
		return array(
			'id'          => 'gatekeeper',
			'title'       => 'Xophz Gatekeeper',
			'description' => 'Central authentication, machine keys, and SaaS sponsor entitlements',
			'icon'        => 'fal fa-hat-wizard',
			'color'       => '#9333EA',
			'url'         => '/gatekeeper',
			'category'    => 'security',
			'type'        => 'core-guard',
			'status'      => 'stable',
			'weight'      => 95,
			'active'      => true,
			'version'     => $this->version,
		);
	}

	/**
	 * Handle inbound webhook events from Hookshot to fulfill SaaS subscriptions.
	 *
	 * @param array|object $payload
	 * @param int          $webhook_id
	 */
	public function handle_hookshot_incoming( $payload, $webhook_id ): void {
		if ( is_object( $payload ) ) {
			$payload = (array) $payload;
		}
		if ( ! is_array( $payload ) ) {
			return;
		}

		$event_type = $payload['type'] ?? ( $payload['event'] ?? '' );
		if ( empty( $event_type ) && isset( $payload['data']['object'] ) ) {
			$event_type = 'checkout.session.completed';
		}

		if ( $event_type !== 'checkout.session.completed' && $event_type !== 'customer.subscription.created' ) {
			return;
		}

		$session_obj = $payload['data']['object'] ?? $payload;
		$metadata    = $session_obj['metadata'] ?? array();

		if ( ( $metadata['product'] ?? '' ) !== 'forthexp' ) {
			return;
		}

		$customer_email = (string) ( $session_obj['customer_email'] ?? ( $session_obj['customer_details']['email'] ?? '' ) );

		$user_id = intval( $metadata['customer_uid'] ?? 0 );
		if ( empty( $user_id ) && $customer_email !== '' ) {
			$found_user = get_user_by( 'email', $customer_email );
			if ( $found_user ) {
				$user_id = $found_user->ID;
			}
		}

		$tier   = sanitize_key( $metadata['tier'] ?? 'developer' );
		$scopes = ! empty( $metadata['scopes'] ) ? array_filter( explode( ',', (string) $metadata['scopes'] ) ) : array( 'xp:read', 'xp:write' );
		$label  = sprintf( 'forthexp.com SaaS Key (%s)', ucfirst( $tier ) );

		// No matching WordPress user: mint the key unowned (user_id 0) and record the
		// customer email on it. Never fall back to another account, because the route
		// guard authenticates a key's requests as its owner.
		$key_record = Gatekeeper_Keys::generate_key( $user_id, $label, $scopes, 'xp_live', null, $customer_email ?: null );

		if ( empty( $user_id ) ) {
			error_log(
				sprintf(
					'Gatekeeper: no WordPress user matched the forthexp checkout from webhook %s; minted key %s unowned%s.',
					$webhook_id,
					$key_record['id'],
					$customer_email !== '' ? ' with the customer email recorded' : ' and no customer email'
				)
			);
		}

		do_action( 'xp_subscription_activated', $user_id, $tier, $key_record, $payload );
	}
}
