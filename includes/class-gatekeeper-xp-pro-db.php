<?php
/**
 * XP Pro database primitives: a per-family MySQL named lock and atomic counters.
 *
 * Counters live in the options table (autoload off) and are bumped with one INSERT ... ON DUPLICATE
 * KEY UPDATE statement, so two requests at the same moment both count. The counter names carry
 * their window (a unix window start for rate limits, Ymd for the daily AI cap), so a new window is
 * a new row; a daily cron event deletes rows older than two days.
 *
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gatekeeper_Xp_Pro_Db {

	const LOCK_PREFIX      = 'gk_xp_pro_';
	const LOCK_WAIT        = 10;
	const RATE_PREFIX      = 'gk_xppro_rl_';
	const AI_PREFIX        = 'gk_xppro_ai_';
	const CLEANUP_HOOK     = 'gk_xp_pro_cleanup_counters';
	const COUNTER_LIFETIME = 172800; // 2 days.

	/** Unix time; tests set it to move between windows. */
	public static $now = null;

	public static function now(): int {
		return null === self::$now ? time() : (int) self::$now;
	}

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

	/** Counter name of one family's AI calls for the current UTC day. */
	public static function ai_counter( string $family_id ): string {
		return self::AI_PREFIX . $family_id . '_' . gmdate( 'Ymd', self::now() );
	}

	/** Delete rate limit and AI counters older than two days. @return int rows deleted */
	public static function cleanup_counters(): int {
		global $wpdb;
		$cutoff = self::now() - self::COUNTER_LIFETIME;
		$rate   = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_name, '_', -1) AS UNSIGNED) < %d",
				$wpdb->esc_like( self::RATE_PREFIX ) . '%',
				$cutoff
			)
		);
		$ai     = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND SUBSTRING_INDEX(option_name, '_', -1) < %s",
				$wpdb->esc_like( self::AI_PREFIX ) . '%',
				gmdate( 'Ymd', $cutoff )
			)
		);
		return $rate + $ai;
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
}
