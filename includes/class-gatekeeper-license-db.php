<?php
/**
 * License database primitives shared by every license product: MySQL named locks, atomic counters
 * in the options table, fixed-window rate limiting and the daily counter cleanup.
 *
 * Counters are bumped with one INSERT ... ON DUPLICATE KEY UPDATE statement, so two requests at the
 * same moment both count. A counter's name carries its window (a unix window start for rate limits,
 * Ymd for daily counters, a unix window end for the generic gatekeeper_counter_bump() counters), so
 * a new window is a new row; a daily cron event deletes rows whose window is over.
 *
 * The option prefixes keep their pre-product names (gk_xppro_rl_, gk_xppro_ai_, the cleanup hook):
 * live rows and the scheduled event rely on them, and they hold nothing worth migrating.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_License_Db {

	const LOCK_WAIT        = 10;
	const RATE_PREFIX      = 'gk_xppro_rl_';
	const AI_PREFIX        = 'gk_xppro_ai_';
	const COUNTER_PREFIX   = 'gk_lic_ctr_';
	const CLEANUP_HOOK     = 'gk_xp_pro_cleanup_counters';
	const COUNTER_LIFETIME = 172800; // 2 days.

	/** Unix time; tests set it to move between windows. */
	public static $now = null;

	public static function now(): int {
		return null === self::$now ? time() : (int) self::$now;
	}

	/* ---- Named locks ------------------------------------------------------------------------- */

	/** Take the named lock; false when it could not be taken within $wait seconds. */
	public static function lock( string $name, int $wait = self::LOCK_WAIT ): bool {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $wait ) );
		return '1' === (string) $got;
	}

	public static function unlock( string $name ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/* ---- Counters ---------------------------------------------------------------------------- */

	/** Add one to a counter and return its value after the add. */
	public static function increment( string $name ): int {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, 1, 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$name
			)
		);
		return self::read( $name );
	}

	/** Take one off a counter, never below zero. */
	public static function decrement( string $name ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = GREATEST(CAST(option_value AS SIGNED) - 1, 0) WHERE option_name = %s",
				$name
			)
		);
	}

	/** A counter's value, read straight from the table so no object cache can serve a stale one. */
	public static function read( string $name ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/** Counter name of a fixed window: the window start is a unix time, so one row per client per window. */
	public static function rate_counter( string $bucket, string $scope, int $window_seconds ): string {
		$start = intdiv( self::now(), max( 1, $window_seconds ) ) * max( 1, $window_seconds );
		return self::RATE_PREFIX . preg_replace( '/[^a-z0-9_]/', '', strtolower( $bucket ) ) . '_' . md5( $scope ) . '_' . $start;
	}

	/**
	 * Counter name of one account's calls for the current UTC day. Nothing in Gatekeeper bumps it any
	 * more (the XP plugin's AI route counts through gatekeeper_counter_bump()); the prefix stays so
	 * cleanup_counters() still sweeps the rows the old in-Gatekeeper AI route left behind.
	 */
	public static function daily_counter( string $scope ): string {
		return self::AI_PREFIX . $scope . '_' . gmdate( 'Ymd', self::now() );
	}

	/**
	 * Name of a generic named counter living in a fixed window of $ttl seconds. The window END is
	 * part of the name, so every bump inside the window hits the same row and the cleanup can drop
	 * the row once the window is over, whatever its length.
	 */
	public static function counter( string $name, int $ttl ): string {
		$ttl = max( 1, $ttl );
		$end = ( intdiv( self::now(), $ttl ) + 1 ) * $ttl;
		return self::COUNTER_PREFIX . preg_replace( '/[^a-z0-9_-]/', '', strtolower( $name ) ) . '_' . $end;
	}

	/**
	 * Bump the named counter for the current $ttl window by $delta (1 by default, -1 takes one back,
	 * never below zero) and return its value after the bump. This is what gatekeeper_counter_bump()
	 * calls.
	 */
	public static function bump( string $name, int $ttl, int $delta = 1 ): int {
		$row = self::counter( $name, $ttl );
		if ( $delta > 0 ) {
			$value = 0;
			for ( $i = 0; $i < $delta; $i++ ) {
				$value = self::increment( $row );
			}
			return $value;
		}
		for ( $i = 0; $i < -$delta; $i++ ) {
			self::decrement( $row );
		}
		return self::read( $row );
	}

	/** Delete rate limit and daily counters older than two days and generic counters whose window is over. @return int rows deleted */
	public static function cleanup_counters(): int {
		global $wpdb;
		$cutoff  = self::now() - self::COUNTER_LIFETIME;
		$rate    = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_name, '_', -1) AS UNSIGNED) < %d",
				$wpdb->esc_like( self::RATE_PREFIX ) . '%',
				$cutoff
			)
		);
		$daily   = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND SUBSTRING_INDEX(option_name, '_', -1) < %s",
				$wpdb->esc_like( self::AI_PREFIX ) . '%',
				gmdate( 'Ymd', $cutoff )
			)
		);
		$generic = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_name, '_', -1) AS UNSIGNED) < %d",
				$wpdb->esc_like( self::COUNTER_PREFIX ) . '%',
				self::now()
			)
		);
		return $rate + $daily + $generic;
	}

	/** Hook the daily cleanup. Called once per request from the plugin. */
	public static function register_cleanup(): void {
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup_counters' ) );
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::CLEANUP_HOOK );
		}
	}

	/** Drop any cached copy of an option so the next get_option reads the table (used after taking a lock). */
	public static function forget_option( string $name ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( $name, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/* ---- Rate limiting ----------------------------------------------------------------------- */

	/**
	 * The caller's address: Hookshot_Client_Ip::resolve() when Hookshot is active (it reads
	 * X-Forwarded-For only from a configured trusted proxy, XOPHZ_HOOKSHOT_TRUSTED_PROXIES), else
	 * REMOTE_ADDR. Behind Cloudflare without that constant every caller would share one bucket.
	 *
	 * @param WP_REST_Request|null $request
	 */
	public static function client_ip( $request = null ): string {
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
	 * Fixed-window counter per bucket, client address and scope (a product's claim and validate
	 * routes pass the account id as the scope), bumped atomically in the options table. Returns true
	 * when this request is over the limit. The window is part of the counter name, so a new minute
	 * (or hour) starts a fresh row. Buckets are per product: pass "{product}_{route}".
	 *
	 * @param WP_REST_Request|null $request
	 */
	public static function rate_limited( string $bucket, int $max, int $window_seconds, $request = null, string $scope = '' ): bool {
		$key     = self::client_ip( $request ) . '|' . $scope;
		$counter = self::rate_counter( $bucket, $key, $window_seconds );
		return self::increment( $counter ) > $max;
	}
}
