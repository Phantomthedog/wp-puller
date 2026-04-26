<?php
/**
 * CLI manual test for WP_Puller_Static_Backup
 *
 * Run: php tests/test-static-backup.php
 */

// Mock WordPress functions.
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $str ) { return rtrim( $str, '/\\' ) . '/'; }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) { return @mkdir( $dir, 0755, true ); }
}
if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( $path ) { return is_writable( $path ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $fmt ) { return date( $fmt ); }
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min, $max ) { return rand( $min, $max ); }
}
if ( ! function_exists( 'get_option' ) ) {
	$_test_options = array();
	function get_option( $key, $default = false ) {
		global $_test_options;
		return isset( $_test_options[ $key ] ) ? $_test_options[ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $val ) {
		global $_test_options;
		$_test_options[ $key ] = $val;
		return true;
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $name ) { return preg_replace( '/[^a-zA-Z0-9._-]/', '', $name ); }
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'sprintf' ) ) {
	// sprintf is built-in, no need to mock.
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code, $message, $data;
		public function __construct( $code, $message, $data = '' ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
		public function get_error_code() { return $this->code; }
	}
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/test-abspath/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

// Create mock logger.
class MockLogger {
	public function log( $msg, $status = 'info', $source = 'system', $meta = array() ) {
		// Silent.
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-backup.php';

$backup = new WP_Puller_Static_Backup( new MockLogger() );
$pass = 0;
$fail = 0;

function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		echo "  PASS: $msg\n";
		$pass++;
	} else {
		echo "  FAIL: $msg\n";
		$fail++;
	}
}

echo "=== Test 1: init_backup_dir ===\n";
$result = $backup->init_backup_dir();
ok( $result === true, 'init_backup_dir returns true' );
ok( is_dir( WP_CONTENT_DIR . '/wp-puller-static-backups' ), 'backup base dir exists' );
ok( file_exists( WP_CONTENT_DIR . '/wp-puller-static-backups/index.php' ), 'index.php protection exists' );
ok( file_exists( WP_CONTENT_DIR . '/wp-puller-static-backups/.htaccess' ), '.htaccess protection exists' );

echo "\n=== Test 2: start_backup ===\n";
$bid = $backup->start_backup();
ok( ! is_wp_error( $bid ), 'start_backup returns ID not error' );
ok( is_string( $bid ) && strpos( $bid, 'static-' ) === 0, 'backup ID starts with static-' );
ok( is_dir( $backup->get_backup_path( $bid ) . '/files' ), 'backup files dir created' );

echo "\n=== Test 3: backup_file (target exists) ===\n";
$target_dir = ABSPATH . 'test-targets';
wp_mkdir_p( $target_dir );
$target_file = $target_dir . '/hello.html';
file_put_contents( $target_file, '<html>hello</html>' );

$backup2 = new WP_Puller_Static_Backup( new MockLogger() );
$bid2 = $backup2->start_backup();
$result = $backup2->backup_file( $target_file );
ok( $result === true, 'backup_file returns true' );
ok( file_exists( $backup2->get_backup_path( $bid2 ) . '/files/test-targets/hello.html' ), 'backup mirror exists' );
ok( file_get_contents( $backup2->get_backup_path( $bid2 ) . '/files/test-targets/hello.html' ) === '<html>hello</html>', 'backup content matches' );

echo "\n=== Test 4: backup_file (target missing = add action) ===\n";
$missing = ABSPATH . 'nonexistent.html';
$result = $backup2->backup_file( $missing );
ok( $result === true, 'backup_file returns true for missing target (add action)' );

echo "\n=== Test 5: backup_file (jail violation) ===\n";
$result = $backup2->backup_file( '/etc/passwd' );
ok( is_wp_error( $result ), 'backup_file rejects target outside ABSPATH' );

echo "\n=== Test 6: write_manifest ===\n";
$manifest = array(
	'id'        => $bid2,
	'timestamp' => date( 'c' ),
	'added'     => array(),
	'replaced'  => array( array( 'source' => 'test-targets/hello.html', 'target' => 'test-targets/hello.html' ) ),
	'backed_up' => $backup2->get_current_backed_up(),
);
$result = $backup2->write_manifest( $manifest );
ok( $result === true, 'write_manifest returns true' );
ok( file_exists( $backup2->get_backup_path( $bid2 ) . '/manifest.json' ), 'manifest.json written' );

echo "\n=== Test 7: rollback ===\n";
// Overwrite the original file.
file_put_contents( $target_file, '<html>modified</html>' );
$result = $backup2->rollback( $bid2 );
ok( ! is_wp_error( $result ), 'rollback returns array not error' );
ok( $result['count'] === 1, 'rollback restored 1 file' );
ok( file_get_contents( $target_file ) === '<html>hello</html>', 'rollback restored original content' );

echo "\n=== Test 8: list_backups ===\n";
$list = $backup2->list_backups();
ok( is_array( $list ), 'list_backups returns array' );
ok( count( $list ) >= 1, 'list_backups has at least 1 entry' );

echo "\n=== Test 9: rate limit ===\n";
$rl = $backup2->check_rate_limit();
ok( $rl === true, 'rate limit passes on first check' );
$backup2->record_deploy_time();
$rl2 = $backup2->check_rate_limit();
ok( is_wp_error( $rl2 ), 'rate limit blocks within 60 seconds' );

echo "\n=== Test 10: rollback rejects manifest path escape ===\n";
$backup3 = new WP_Puller_Static_Backup( new MockLogger() );
$bid3 = $backup3->start_backup();
// Create a tampered manifest with a backup path that escapes the backup directory.
$tampered_manifest = array(
	'id'        => $bid3,
	'timestamp' => date( 'c' ),
	'backed_up' => array(
		array(
			'original' => 'safe-file.html',
			'backup'   => '../../../etc/passwd',
			'size'     => 0,
		),
	),
);
$backup3->write_manifest( $tampered_manifest );
$result = $backup3->rollback( $bid3 );
ok( ! is_wp_error( $result ), 'rollback with tampered manifest returns array (not fatal error)' );
ok( $result['count'] === 0, 'rollback restored 0 files from tampered manifest' );
ok( count( $result['failed'] ) === 1, 'rollback reports 1 failed file from tampered manifest' );

echo "\n=== Test 11: cleanup ===\n";
// Remove test dirs.
function rrmdir( $dir ) {
	if ( is_dir( $dir ) ) {
		$objects = scandir( $dir );
		foreach ( $objects as $object ) {
			if ( $object != "." && $object != ".." ) {
				if ( is_dir( $dir . "/" . $object ) ) {
					rrmdir( $dir . "/" . $object );
				} else {
					unlink( $dir . "/" . $object );
				}
			}
		}
		rmdir( $dir );
	}
}
rrmdir( ABSPATH );
rrmdir( WP_CONTENT_DIR );
ok( ! is_dir( ABSPATH ), 'test ABSPATH cleaned up' );
ok( ! is_dir( WP_CONTENT_DIR ), 'test WP_CONTENT_DIR cleaned up' );

echo "\n==============================\n";
echo "Results: $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
