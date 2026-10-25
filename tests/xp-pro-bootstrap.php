<?php
/**
 * Bootstrap for the XP Pro specs: the Gatekeeper bootstrap plus the extra WordPress
 * functions and classes the XP Pro code touches (transients, mail, HTTP, REST, errors),
 * all in memory, the license engine, every includes/class-gatekeeper-xp-pro-*.php file that exists,
 * and the xp-pro product registered as the XP plugin registers it.
 */

require __DIR__ . '/bootstrap.php';

$GLOBALS['gk_transients'] = array();
$GLOBALS['gk_mail']       = array();
$GLOBALS['gk_http']       = array();
$GLOBALS['gk_http_reply'] = null;
$GLOBALS['gk_log']        = array();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		protected $code;
		protected $message;
		protected $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['gk_options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['gk_transients'] ) ? $GLOBALS['gk_transients'][ $key ] : false;
	}
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['gk_transients'][ $key ] = $value;
		return true;
	}
	function delete_transient( $key ) {
		unset( $GLOBALS['gk_transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '' ) {
		$GLOBALS['gk_mail'][] = array( 'to' => $to, 'subject' => $subject, 'message' => $message );
		return true;
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['gk_http'][] = array( 'url' => $url, 'args' => $args );
		return $GLOBALS['gk_http_reply'] ?? new WP_Error( 'offline', 'No reply stubbed' );
	}
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
	}
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		return isset( $GLOBALS['gk_filters'][ $hook ] ) ? call_user_func( $GLOBALS['gk_filters'][ $hook ], $value, ...$args ) : $value;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'error_log_gk' ) ) {
	function error_log_gk( $message ) {
		$GLOBALS['gk_log'][] = $message;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		protected $params  = array();
		protected $headers = array();
		protected $body    = '';
		public function __construct( $method = 'GET', $route = '' ) {}
		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}
		public function set_header( $key, $value ) {
			$this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] = $value;
		}
		public function get_header( $key ) {
			return $this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] ?? null;
		}
		public function set_body( $body ) {
			$this->body = $body;
		}
		public function get_body() {
			return $this->body;
		}
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		protected $data;
		protected $status;
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_data() {
			return $this->data;
		}
		public function get_status() {
			return $this->status;
		}
	}
}

/**
 * A $wpdb stand-in for the SQL the XP Pro code runs: named locks, the atomic counters and nothing
 * else. Counters live in $this->counters (never in the in-memory options). Set
 * $GLOBALS['gk_lock_result'] to '0' to make every GET_LOCK time out, and
 * $GLOBALS['gk_lock_hook'] to a callable that runs when a lock is taken (to simulate a writer
 * that got in first). The real SQL is exercised in the loadWp specs (tests/xp-pro-db.test.mjs).
 */
class Gk_Fake_Wpdb {
	public $options  = 'wp_options';
	public $counters = array();
	public $locks    = array();
	public $log      = array();
	public function prepare( $sql, ...$args ) {
		$args = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		$i    = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$sql
		);
	}
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}
	public function get_var( $sql ) {
		$this->log[] = $sql;
		if ( preg_match( "/GET_LOCK\\('(.*)', (\\d+)\\)/", $sql, $m ) ) {
			$result = $GLOBALS['gk_lock_result'] ?? '1';
			if ( '1' === $result ) {
				$this->locks[ stripslashes( $m[1] ) ] = true;
				if ( isset( $GLOBALS['gk_lock_hook'] ) ) {
					call_user_func( $GLOBALS['gk_lock_hook'], stripslashes( $m[1] ) );
				}
			}
			return $result;
		}
		if ( preg_match( "/RELEASE_LOCK\\('(.*)'\\)/", $sql, $m ) ) {
			unset( $this->locks[ stripslashes( $m[1] ) ] );
			return '1';
		}
		if ( preg_match( "/SELECT option_value FROM \\S+ WHERE option_name = '(.*)'/", $sql, $m ) ) {
			$name = stripslashes( $m[1] );
			return array_key_exists( $name, $this->counters ) ? (string) $this->counters[ $name ] : null;
		}
		return null;
	}
	public function get_col( $sql ) {
		return array();
	}
	public function query( $sql ) {
		$this->log[] = $sql;
		if ( preg_match( "/^INSERT INTO \\S+ \\(option_name, option_value, autoload\\) VALUES \\('(.*)', 1, 'no'\\) ON DUPLICATE KEY UPDATE option_value = option_value \\+ 1$/", $sql, $m ) ) {
			$name                     = stripslashes( $m[1] );
			$this->counters[ $name ] = ( $this->counters[ $name ] ?? 0 ) + 1;
			return 1;
		}
		if ( preg_match( "/^UPDATE \\S+ SET option_value = GREATEST\\(CAST\\(option_value AS SIGNED\\) - 1, 0\\) WHERE option_name = '(.*)'$/", $sql, $m ) ) {
			$name = stripslashes( $m[1] );
			if ( isset( $this->counters[ $name ] ) ) {
				$this->counters[ $name ] = max( 0, $this->counters[ $name ] - 1 );
			}
			return 1;
		}
		return 0;
	}
}
$GLOBALS['wpdb'] = new Gk_Fake_Wpdb();

// The license engine first, then every XP Pro file (the facade and alias require the engine too).
require_once dirname( __DIR__ ) . '/includes/class-gatekeeper-license-db.php';
require_once dirname( __DIR__ ) . '/includes/class-gatekeeper-license-products.php';
require_once dirname( __DIR__ ) . '/includes/class-gatekeeper-license-store.php';
require_once dirname( __DIR__ ) . '/includes/gatekeeper-license-functions.php';
foreach ( glob( dirname( __DIR__ ) . '/includes/class-gatekeeper-xp-pro-*.php' ) as $gk_xp_pro_file ) {
	require_once $gk_xp_pro_file;
}

/*
 * License products for the specs. A plugin registers its product on the gatekeeper_license_products
 * filter; here gk_register_license_product() does the same through the single-callback filter stub,
 * and $GLOBALS['gk_license_products'] = array() unregisters everything (to see the transition default).
 */
$GLOBALS['gk_filters']          = $GLOBALS['gk_filters'] ?? array();
$GLOBALS['gk_license_products'] = array();
$GLOBALS['gk_filters']['gatekeeper_license_products'] = function ( $products ) {
	return array_merge( is_array( $products ) ? $products : array(), $GLOBALS['gk_license_products'] );
};
function gk_register_license_product( string $id, array $config ): void {
	$GLOBALS['gk_license_products'][ $id ] = $config;
	Gatekeeper_License_Products::reset();
}

/**
 * The xp-pro product exactly as the XP plugin registers it (board epic #1552 carries this array).
 * The XP plugin passes 'plans' => array( 'Xophz_Compass_Xp_Pro', 'get_plans' ); here that catalog is
 * gk_xp_pro_plans(), which a spec may override through the legacy gatekeeper_xp_pro_plans filter stub.
 */
function gk_xp_pro_product(): array {
	return array(
		'label'               => 'XP Pro',
		'app_name'            => 'Do It For The XP',
		'key_prefix'          => 'XP-PRO-',
		'key_groups'          => 3,
		'option_prefix'       => 'gk_xp_pro_',
		'account_param'       => 'family_id',
		'device_param'        => 'device_id',
		'max_devices'         => 10,
		'grace_seconds'       => 259200,
		'trial_seconds'       => 604800,
		'plans'               => 'gk_xp_pro_plans',
		'checkout_product'    => 'doitforthexp',
		'webhooks_option'     => 'gatekeeper_xp_pro_webhooks',
		'stripe_setup_option' => 'gatekeeper_xp_pro_stripe_setup',
		'route_prefix'        => 'xp-pro',
		'settings'            => array(
			'ai_daily_limit'   => array( 'label' => 'AI requests per family per day', 'type' => 'int', 'option' => 'xp_pro_ai_daily_limit', 'default' => 100 ),
			'lifetime_on_sale' => array( 'label' => 'Lifetime plan on sale', 'type' => 'bool', 'option' => 'xp_pro_lifetime_on_sale', 'default' => true ),
		),
		'strings'             => array(
			'key_email_subject' => 'Your XP Pro key',
			'support_line'      => 'Questions? Write to {support_email}.',
		),
	);
}
/** Xophz_Compass_Xp_Pro::get_plans() as the XP plugin ships it; the legacy filter stub wins when a spec sets it. */
function gk_xp_pro_plans(): array {
	$override = apply_filters( 'gatekeeper_xp_pro_plans', array() );
	if ( is_array( $override ) && ! empty( $override ) ) {
		return $override;
	}
	$stored  = get_option( 'xp_pro_lifetime_on_sale', true );
	$parsed  = is_bool( $stored ) ? $stored : filter_var( $stored, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	$on_sale = null === $parsed ? true : $parsed;
	return array(
		'monthly'  => array( 'price' => 4.99, 'mode' => 'subscription', 'interval' => 'month', 'trial_days' => 7, 'store_product_id' => 'xp_pro_monthly', 'on_sale' => true ),
		'yearly'   => array( 'price' => 39.99, 'mode' => 'subscription', 'interval' => 'year', 'trial_days' => 7, 'store_product_id' => 'xp_pro_yearly', 'on_sale' => true ),
		'lifetime' => array( 'price' => 79.00, 'mode' => 'payment', 'interval' => null, 'trial_days' => 0, 'store_product_id' => 'xp_pro_lifetime', 'on_sale' => (bool) $on_sale ),
	);
}
gk_register_license_product( 'xp-pro', gk_xp_pro_product() );
