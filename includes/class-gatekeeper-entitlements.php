<?php
/**
 * Gatekeeper Entitlements & Sponsorship Registry
 *
 * Tracks active subscriber and GitHub Sponsor entitlements across tiers:
 * community, solo_sponsor ($9/mo), team_power_puff ($49/mo), and enterprise.
 *
 * @package    Xophz_Compass_Gatekeeper
 * @subpackage Xophz_Compass_Gatekeeper/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_Entitlements {

	const OPTION_SPONSORS = 'compass_gatekeeper_sponsors';

	/**
	 * Record or update a GitHub Sponsor entitlement.
	 *
	 * @param string $github_user GitHub username handle.
	 * @param string $tier        Tier slug ('solo_sponsor', 'team_power_puff', 'enterprise').
	 * @param array  $metadata    Additional metadata from GitHub webhook.
	 * @return array
	 */
	public static function record_sponsor( string $github_user, string $tier = 'solo_sponsor', array $metadata = array() ): array {
		$clean_user = strtolower( ltrim( sanitize_text_field( $github_user ), '@' ) );
		$sponsors   = self::get_all_sponsors();
		$now        = current_time( 'mysql' );

		$existing = $sponsors[ $clean_user ] ?? null;
		$license_key = $existing['license_key'] ?? null;

		if ( ! $license_key ) {
			$license_record = Gatekeeper_Licenses::mint_license(
				$tier,
				$clean_user,
				$metadata['email'] ?? null,
				$tier === 'team_power_puff' ? 0 : 3
			);
			$license_key = $license_record['license_key'];
		}

		$record = array(
			'github_user' => $clean_user,
			'tier'        => sanitize_key( $tier ),
			'license_key' => $license_key,
			'status'      => 'active',
			'metadata'    => $metadata,
			'created_at'  => $existing['created_at'] ?? $now,
			'updated_at'  => $now,
		);

		$sponsors[ $clean_user ] = $record;
		update_option( self::OPTION_SPONSORS, $sponsors, false );

		return $record;
	}

	/**
	 * Deactivate or cancel a sponsor entitlement.
	 *
	 * @param string $github_user
	 * @return bool
	 */
	public static function cancel_sponsor( string $github_user ): bool {
		$clean_user = strtolower( ltrim( sanitize_text_field( $github_user ), '@' ) );
		$sponsors   = self::get_all_sponsors();

		if ( ! isset( $sponsors[ $clean_user ] ) ) {
			return false;
		}

		$sponsors[ $clean_user ]['status']     = 'cancelled';
		$sponsors[ $clean_user ]['updated_at'] = current_time( 'mysql' );
		return update_option( self::OPTION_SPONSORS, $sponsors, false );
	}

	/**
	 * Retrieve a single sponsor record.
	 *
	 * @param string $github_user
	 * @return array|null
	 */
	public static function get_sponsor( string $github_user ): ?array {
		$clean_user = strtolower( ltrim( sanitize_text_field( $github_user ), '@' ) );
		$sponsors   = self::get_all_sponsors();
		return $sponsors[ $clean_user ] ?? null;
	}

	/**
	 * List all sponsors.
	 *
	 * @return array
	 */
	public static function list_sponsors(): array {
		$sponsors = self::get_all_sponsors();
		return array_values( $sponsors );
	}

	/**
	 * Retrieve raw sponsors storage.
	 *
	 * @return array
	 */
	private static function get_all_sponsors(): array {
		$sponsors = get_option( self::OPTION_SPONSORS, array() );
		return is_array( $sponsors ) ? $sponsors : array();
	}
}
