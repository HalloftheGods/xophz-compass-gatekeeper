<?php
/**
 * Gatekeeper_Xp_Pro_Db is now Gatekeeper_License_Db, the primitives every license product shares
 * (class-gatekeeper-license-db.php). This alias keeps the old name working for one release.
 *
 * @deprecated Use Gatekeeper_License_Db.
 * @package Xophz_Compass_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gatekeeper-license-db.php';

if ( ! class_exists( 'Gatekeeper_Xp_Pro_Db', false ) ) {
	class_alias( 'Gatekeeper_License_Db', 'Gatekeeper_Xp_Pro_Db' );
}
