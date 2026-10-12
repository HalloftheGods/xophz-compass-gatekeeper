<?php
/**
 * Bootstrap for the XP Pro specs: the Gatekeeper bootstrap plus the extra WordPress
 * functions and classes the XP Pro code touches (transients, mail, HTTP, REST, errors),
 * all in memory, and every includes/class-gatekeeper-xp-pro-*.php file that exists.
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

foreach ( glob( dirname( __DIR__ ) . '/includes/class-gatekeeper-xp-pro-*.php' ) as $gk_xp_pro_file ) {
	require_once $gk_xp_pro_file;
}
