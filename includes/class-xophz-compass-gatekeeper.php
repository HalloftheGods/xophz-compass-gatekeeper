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
}
