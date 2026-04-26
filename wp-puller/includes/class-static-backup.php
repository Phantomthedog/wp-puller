<?php
/**
 * Static Backup class for WP Puller.
 *
 * Manages file-level backups for static deployments.
 * Each deploy creates a backup point containing only replaced files.
 *
 * @package WP_Puller
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_Puller_Static_Backup Class.
 */
class WP_Puller_Static_Backup {

	/**
	 * Base backup directory name (relative to WP_CONTENT_DIR).
	 *
	 * @var string
	 */
	const BACKUP_DIR = 'wp-puller-static-backups';

	/**
	 * Maximum number of backup points to retain.
	 *
	 * @var int
	 */
	const MAX_BACKUPS = 10;

	/**
	 * Logger instance.
	 *
	 * @var WP_Puller_Logger
	 */
	private $logger;

	/**
	 * Current backup ID for an in-progress deploy.
	 *
	 * @var string
	 */
	private $current_backup_id = '';

	/**
	 * List of files backed up in current deploy.
	 *
	 * @var array
	 */
	private $current_backed_up = array();

	/**
	 * Constructor.
	 *
	 * @param WP_Puller_Logger $logger Logger instance.
	 */
	public function __construct( $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Get the absolute path to the backup base directory.
	 *
	 * @return string
	 */
	public function get_backup_base_dir() {
		return trailingslashit( WP_CONTENT_DIR ) . self::BACKUP_DIR;
	}

	/**
	 * Initialize the backup directory with protection files.
	 *
	 * Creates the directory, index.php, and .htaccess if they don't exist.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function init_backup_dir() {
		$base_dir = $this->get_backup_base_dir();

		if ( ! is_dir( $base_dir ) ) {
			$created = wp_mkdir_p( $base_dir );
			if ( ! $created ) {
				return new WP_Error(
					'backup_dir_create_failed',
					__( 'Failed to create static backup directory.', 'wp-puller' )
				);
			}
		}

		// Ensure directory is writable.
		if ( ! wp_is_writable( $base_dir ) ) {
			return new WP_Error(
				'backup_dir_not_writable',
				__( 'Static backup directory is not writable.', 'wp-puller' )
			);
		}

		// Create index.php protection file.
		$index_file = $base_dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents(
				$index_file,
				"<?php\n// Silence is golden.\n",
				LOCK_EX
			);
		}

		// Create .htaccess protection file.
		$htaccess_file = $base_dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			@file_put_contents(
				$htaccess_file,
				"# Protect backup directory\n" .
				"<IfModule mod_authz_core.c>\n" .
				"    Require all denied\n" .
				"</IfModule>\n" .
				"<IfModule !mod_authz_core.c>\n" .
				"    Order deny,allow\n" .
				"    Deny from all\n" .
				"</IfModule>\n",
				LOCK_EX
			);
		}

		return true;
	}

	/**
	 * Start a new backup point for a deploy.
	 *
	 * @return string|WP_Error Backup ID on success, WP_Error on failure.
	 */
	public function start_backup() {
		$init = $this->init_backup_dir();
		if ( is_wp_error( $init ) ) {
			return $init;
		}

		$this->cleanup_old_backups();

		$this->current_backup_id = 'static-' . current_time( 'Ymd-His' ) . '-' . wp_rand( 1000, 9999 );
		$this->current_backed_up = array();

		$backup_dir = $this->get_backup_path();
		if ( ! wp_mkdir_p( $backup_dir . '/files' ) ) {
			return new WP_Error(
				'backup_point_create_failed',
				__( 'Failed to create backup point directory.', 'wp-puller' )
			);
		}

		return $this->current_backup_id;
	}

	/**
	 * Get the full backup directory path for the current backup ID.
	 *
	 * @param string $backup_id Optional backup ID. Defaults to current.
	 * @return string
	 */
	public function get_backup_path( $backup_id = '' ) {
		if ( empty( $backup_id ) ) {
			$backup_id = $this->current_backup_id;
		}
		return trailingslashit( $this->get_backup_base_dir() ) . $backup_id;
	}

	/**
	 * Backup a single target file before it is overwritten.
	 *
	 * @param string $target_path Absolute path to the file being replaced.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function backup_file( $target_path ) {
		if ( empty( $this->current_backup_id ) ) {
			return new WP_Error(
				'no_backup_active',
				__( 'No backup point is active.', 'wp-puller' )
			);
		}

		$target_real = realpath( $target_path );
		if ( false === $target_real ) {
			// Target doesn't exist yet — nothing to back up (add action).
			return true;
		}

		// Jail check: target must be inside ABSPATH.
		$abspath_real = realpath( ABSPATH );
		if ( strpos( $target_real, $abspath_real . '/' ) !== 0 && $target_real !== $abspath_real ) {
			return new WP_Error(
				'backup_jail_violation',
				__( 'Target file is outside ABSPATH.', 'wp-puller' )
			);
		}

		// Compute relative path from ABSPATH.
		$relative = str_replace( $abspath_real . '/', '', $target_real );
		$relative = str_replace( '\\', '/', $relative );

		$backup_dir  = $this->get_backup_path() . '/files';
		$backup_file = $backup_dir . '/' . $relative;

		// Create subdirectories in backup mirror.
		$backup_subdir = dirname( $backup_file );
		if ( ! wp_mkdir_p( $backup_subdir ) ) {
			return new WP_Error(
				'backup_subdir_failed',
				sprintf(
					/* translators: %s: subdirectory path */
					__( 'Failed to create backup subdirectory: %s', 'wp-puller' ),
					$backup_subdir
				)
			);
		}

		// Copy the original file to backup.
		if ( ! @copy( $target_real, $backup_file ) ) {
			return new WP_Error(
				'backup_copy_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Failed to backup file: %s', 'wp-puller' ),
					$relative
				)
			);
		}

		// Verify backup integrity.
		if ( ! file_exists( $backup_file ) || filesize( $backup_file ) !== filesize( $target_real ) ) {
			@unlink( $backup_file );
			return new WP_Error(
				'backup_integrity_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Backup integrity check failed for: %s', 'wp-puller' ),
					$relative
				)
			);
		}

		$this->current_backed_up[] = array(
			'original' => $relative,
			'backup'   => 'files/' . $relative,
			'size'     => filesize( $target_real ),
		);

		return true;
	}

	/**
	 * Write the deploy manifest to the backup point.
	 *
	 * @param array $manifest Manifest data.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function write_manifest( $manifest ) {
		if ( empty( $this->current_backup_id ) ) {
			return new WP_Error(
				'no_backup_active',
				__( 'No backup point is active.', 'wp-puller' )
			);
		}

		$manifest_file = $this->get_backup_path() . '/manifest.json';
		$json          = wp_json_encode( $manifest, JSON_PRETTY_PRINT );

		if ( false === $json ) {
			return new WP_Error(
				'manifest_encode_failed',
				__( 'Failed to encode deploy manifest.', 'wp-puller' )
			);
		}

		$written = @file_put_contents( $manifest_file, $json, LOCK_EX );

		if ( false === $written ) {
			return new WP_Error(
				'manifest_write_failed',
				__( 'Failed to write deploy manifest.', 'wp-puller' )
			);
		}

		return true;
	}

	/**
	 * Rollback a deploy by restoring files from a backup point.
	 *
	 * @param string $backup_id Backup ID to restore.
	 * @return array|WP_Error Rollback result on success, WP_Error on failure.
	 */
	public function rollback( $backup_id ) {
		$backup_id = sanitize_file_name( $backup_id );

		if ( empty( $backup_id ) || strpos( $backup_id, 'static-' ) !== 0 ) {
			return new WP_Error(
				'invalid_backup_id',
				__( 'Invalid backup ID.', 'wp-puller' )
			);
		}

		$backup_path = $this->get_backup_path( $backup_id );
		$manifest_file = $backup_path . '/manifest.json';

		if ( ! file_exists( $manifest_file ) ) {
			return new WP_Error(
				'manifest_not_found',
				__( 'Backup manifest not found.', 'wp-puller' )
			);
		}

		$manifest = json_decode( file_get_contents( $manifest_file ), true );
		if ( empty( $manifest ) || ! is_array( $manifest ) || empty( $manifest['backed_up'] ) ) {
			return new WP_Error(
				'invalid_manifest',
				__( 'Backup manifest is invalid or empty.', 'wp-puller' )
			);
		}

		$restored = array();
		$failed   = array();

		foreach ( $manifest['backed_up'] as $entry ) {
			if ( empty( $entry['original'] ) || empty( $entry['backup'] ) ) {
				continue;
			}

			$backup_file = realpath( $backup_path . '/' . $entry['backup'] );
			if ( false === $backup_file || strpos( $backup_file, realpath( $backup_path ) . '/' ) !== 0 ) {
				$failed[] = array(
					'file'   => $entry['original'],
					'reason' => __( 'Backup file path invalid.', 'wp-puller' ),
				);
				continue;
			}
			$original_path = ABSPATH . $entry['original'];

			// Verify backup file exists.
			if ( ! file_exists( $backup_file ) ) {
				$failed[] = array(
					'file'   => $entry['original'],
					'reason' => __( 'Backup file missing.', 'wp-puller' ),
				);
				continue;
			}

			// Jail check on restore target.
			$original_real = realpath( dirname( $original_path ) );
			$abspath_real  = realpath( ABSPATH );
			if ( false === $original_real || strpos( $original_real, $abspath_real ) !== 0 ) {
				$failed[] = array(
					'file'   => $entry['original'],
					'reason' => __( 'Restore target outside ABSPATH.', 'wp-puller' ),
				);
				continue;
			}

			// Ensure target directory exists.
			$target_dir = dirname( $original_path );
			if ( ! is_dir( $target_dir ) ) {
				wp_mkdir_p( $target_dir );
			}

			if ( @copy( $backup_file, $original_path ) ) {
				$restored[] = $entry['original'];
			} else {
				$failed[] = array(
					'file'   => $entry['original'],
					'reason' => __( 'Copy failed during rollback.', 'wp-puller' ),
				);
			}
		}

		return array(
			'restored' => $restored,
			'failed'   => $failed,
			'count'    => count( $restored ),
		);
	}

	/**
	 * List all available static backup points.
	 *
	 * @return array Array of backup metadata.
	 */
	public function list_backups() {
		$base_dir = $this->get_backup_base_dir();

		if ( ! is_dir( $base_dir ) ) {
			return array();
		}

		$backups = array();
		$dirs    = glob( $base_dir . '/static-*', GLOB_ONLYDIR );

		if ( empty( $dirs ) ) {
			return array();
		}

		// Sort newest first.
		rsort( $dirs );

		foreach ( $dirs as $dir ) {
			$backup_id = basename( $dir );
			$manifest_file = $dir . '/manifest.json';
			$manifest = array();

			if ( file_exists( $manifest_file ) ) {
				$manifest = json_decode( file_get_contents( $manifest_file ), true );
			}

			if ( empty( $manifest ) ) {
				continue;
			}

			$backups[] = array(
				'id'         => $backup_id,
				'timestamp'  => isset( $manifest['timestamp'] ) ? $manifest['timestamp'] : '',
				'source_path'=> isset( $manifest['source_path'] ) ? $manifest['source_path'] : '',
				'replaced'   => isset( $manifest['replaced'] ) ? count( $manifest['replaced'] ) : 0,
				'added'      => isset( $manifest['added'] ) ? count( $manifest['added'] ) : 0,
				'total_size' => isset( $manifest['total_size'] ) ? $manifest['total_size'] : 0,
			);
		}

		return $backups;
	}

	/**
	 * Get a single backup manifest.
	 *
	 * @param string $backup_id Backup ID.
	 * @return array|null Manifest array or null.
	 */
	public function get_backup_manifest( $backup_id ) {
		$backup_id     = sanitize_file_name( $backup_id );
		$manifest_file = $this->get_backup_path( $backup_id ) . '/manifest.json';

		if ( ! file_exists( $manifest_file ) ) {
			return null;
		}

		$manifest = json_decode( file_get_contents( $manifest_file ), true );
		return is_array( $manifest ) ? $manifest : null;
	}

	/**
	 * Delete a backup point.
	 *
	 * @param string $backup_id Backup ID.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function delete_backup( $backup_id ) {
		$backup_id   = sanitize_file_name( $backup_id );
		$backup_path = $this->get_backup_path( $backup_id );

		if ( ! is_dir( $backup_path ) ) {
			return new WP_Error(
				'backup_not_found',
				__( 'Backup point not found.', 'wp-puller' )
			);
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$wp_filesystem->delete( $backup_path, true );

		return true;
	}

	/**
	 * Clean up old backups beyond MAX_BACKUPS.
	 */
	public function cleanup_old_backups() {
		$backups = $this->list_backups();

		if ( count( $backups ) <= self::MAX_BACKUPS ) {
			return;
		}

		$to_delete = array_slice( $backups, self::MAX_BACKUPS );

		foreach ( $to_delete as $backup ) {
			$this->delete_backup( $backup['id'] );
		}
	}

	/**
	 * Check if the current user is within the rate limit.
	 *
	 * @return true|WP_Error True if allowed, WP_Error if rate limited.
	 */
	public function check_rate_limit() {
		$last_deploy = get_option( 'wp_puller_static_last_deploy', 0 );
		$min_interval = 60; // 60 seconds.

		if ( $last_deploy && ( time() - $last_deploy ) < $min_interval ) {
			$wait = $min_interval - ( time() - $last_deploy );
			return new WP_Error(
				'rate_limited',
				sprintf(
					/* translators: %d: seconds remaining */
					__( 'Please wait %d seconds before deploying again.', 'wp-puller' ),
					$wait
				)
			);
		}

		return true;
	}

	/**
	 * Record the timestamp of a deploy for rate limiting.
	 */
	public function record_deploy_time() {
		update_option( 'wp_puller_static_last_deploy', time() );
	}

	/**
	 * Get the list of files backed up in the current deploy.
	 *
	 * @return array
	 */
	public function get_current_backed_up() {
		return $this->current_backed_up;
	}
}
