<?php
/**
 * Gatekeeper API Key Authority
 *
 * Generates, hashes, stores, and validates machine-to-machine API keys
 * with customizable prefixes (defaulting to stargate_) and scope enforcement.
 *
 * @package    Xophz_Compass_Gatekeeper
 * @subpackage Xophz_Compass_Gatekeeper/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_Keys {

	const OPTION_KEYS = 'compass_gatekeeper_api_keys';
	const DEFAULT_PREFIX = 'stargate_';

	/**
	 * Generate a new machine API key.
	 *
	 * @param int         $user_id    Owner WordPress user ID.
	 * @param string      $name       Human-readable label/name for the key.
	 * @param array       $scopes     Allowed capability scopes (e.g. ['read:audits', 'verify:licenses']).
	 * @param string|null $prefix     Key prefix (defaults to 'stargate_').
	 * @param int|null    $expiry_days Days until expiration, or null for indefinite.
	 * @param string|null $owner_email Customer email recorded on the key, for keys minted without a WordPress owner (user_id 0).
	 * @return array{id: string, plain_key: string, name: string, prefix: string, display_key: string, scopes: array, created_at: string}
	 */
	public static function generate_key( int $user_id, string $name, array $scopes = array(), ?string $prefix = null, ?int $expiry_days = null, ?string $owner_email = null ): array {
		$effective_prefix = ! empty( $prefix ) ? sanitize_key( $prefix ) : self::DEFAULT_PREFIX;
		if ( substr( $effective_prefix, -1 ) !== '_' && substr( $effective_prefix, -1 ) !== '-' ) {
			$effective_prefix .= '_';
		}

		$random_entropy = bin2hex( random_bytes( 24 ) );
		$plain_key      = $effective_prefix . $random_entropy;
		$key_hash       = hash( 'sha256', $plain_key );
		$key_id         = 'key_' . substr( hash( 'sha256', $key_hash . microtime() ), 0, 16 );
		$display_key    = $effective_prefix . '...' . substr( $random_entropy, -4 );

		$now        = current_time( 'mysql' );
		$expires_at = $expiry_days ? date( 'Y-m-d H:i:s', strtotime( "+{$expiry_days} days" ) ) : null;

		$record = array(
			'id'           => $key_id,
			'user_id'      => $user_id,
			'owner_email'  => $owner_email ? sanitize_email( $owner_email ) : null,
			'name'         => sanitize_text_field( $name ),
			'prefix'       => $effective_prefix,
			'display_key'  => $display_key,
			'key_hash'     => $key_hash,
			'scopes'       => array_map( 'sanitize_key', $scopes ),
			'created_at'   => $now,
			'expires_at'   => $expires_at,
			'last_used_at' => null,
			'status'       => 'active',
		);

		$keys = self::get_all_keys();
		$keys[ $key_id ] = $record;
		update_option( self::OPTION_KEYS, $keys, false );

		return array(
			'id'          => $key_id,
			'plain_key'   => $plain_key,
			'name'        => $record['name'],
			'prefix'      => $effective_prefix,
			'display_key' => $display_key,
			'scopes'      => $record['scopes'],
			'created_at'  => $now,
			'expires_at'  => $expires_at,
		);
	}

	/**
	 * Validate a plain API key against stored cryptographic hashes.
	 *
	 * @param string      $plain_key      The plain secret key presented by caller.
	 * @param string|null $required_scope Optional scope required to execute the action.
	 * @return array{valid: bool, error?: string, key_id?: string, user_id?: int, name?: string, scopes?: array}
	 */
	public static function validate_key( string $plain_key, ?string $required_scope = null ): array {
		$trimmed = trim( $plain_key );
		if ( empty( $trimmed ) ) {
			return array( 'valid' => false, 'error' => 'API key is missing.' );
		}

		$hash = hash( 'sha256', $trimmed );
		$keys = self::get_all_keys();

		$matched_id = null;
		$matched_record = null;

		foreach ( $keys as $id => $record ) {
			if ( isset( $record['key_hash'] ) && hash_equals( $record['key_hash'], $hash ) ) {
				$matched_id     = $id;
				$matched_record = $record;
				break;
			}
		}

		if ( ! $matched_record ) {
			return array( 'valid' => false, 'error' => 'Invalid API key.' );
		}

		if ( ( $matched_record['status'] ?? '' ) !== 'active' ) {
			return array( 'valid' => false, 'error' => 'API key has been revoked.' );
		}

		if ( ! empty( $matched_record['expires_at'] ) && strtotime( $matched_record['expires_at'] ) < time() ) {
			return array( 'valid' => false, 'error' => 'API key has expired.' );
		}

		if ( $required_scope && ! empty( $matched_record['scopes'] ) ) {
			$has_admin_scope = in_array( 'admin:all', $matched_record['scopes'], true );
			$has_scope       = in_array( sanitize_key( $required_scope ), $matched_record['scopes'], true );
			if ( ! $has_admin_scope && ! $has_scope ) {
				return array( 'valid' => false, 'error' => "Missing required scope '{$required_scope}'." );
			}
		}

		// Update last used timestamp
		$keys[ $matched_id ]['last_used_at'] = current_time( 'mysql' );
		update_option( self::OPTION_KEYS, $keys, false );

		return array(
			'valid'   => true,
			'key_id'  => $matched_id,
			'user_id' => (int) $matched_record['user_id'],
			'name'    => $matched_record['name'],
			'scopes'  => $matched_record['scopes'],
		);
	}

	/**
	 * Revoke an API key by ID.
	 *
	 * @param string $key_id
	 * @return bool
	 */
	public static function revoke_key( string $key_id ): bool {
		$keys = self::get_all_keys();
		if ( ! isset( $keys[ $key_id ] ) ) {
			return false;
		}

		$keys[ $key_id ]['status']     = 'revoked';
		$keys[ $key_id ]['revoked_at'] = current_time( 'mysql' );
		return update_option( self::OPTION_KEYS, $keys, false );
	}

	/**
	 * List all keys without exposing hashes.
	 *
	 * @return array
	 */
	public static function list_keys(): array {
		$keys = self::get_all_keys();
		$safe_list = array();

		foreach ( $keys as $id => $record ) {
			$safe_list[] = array(
				'id'           => $id,
				'name'         => $record['name'] ?? 'Unnamed Key',
				'prefix'       => $record['prefix'] ?? self::DEFAULT_PREFIX,
				'display_key'  => $record['display_key'] ?? '',
				'scopes'       => $record['scopes'] ?? array(),
				'user_id'      => $record['user_id'] ?? 0,
				'owner_email'  => $record['owner_email'] ?? null,
				'created_at'   => $record['created_at'] ?? '',
				'expires_at'   => $record['expires_at'] ?? null,
				'last_used_at' => $record['last_used_at'] ?? null,
				'status'       => $record['status'] ?? 'active',
			);
		}

		return $safe_list;
	}

	/**
	 * Retrieve raw keys storage.
	 *
	 * @return array
	 */
	private static function get_all_keys(): array {
		$keys = get_option( self::OPTION_KEYS, array() );
		return is_array( $keys ) ? $keys : array();
	}
}
