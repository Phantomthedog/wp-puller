<?php
/**
 * Static Deployer class for WP Puller.
 *
 * Dry-run only — previews what static files would be deployed.
 * Does NOT write to ABSPATH.
 *
 * @package WP_Puller
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_Puller_Static_Deployer Class.
 */
class WP_Puller_Static_Deployer {

	/**
	 * GitHub API instance.
	 *
	 * @var WP_Puller_GitHub_API
	 */
	private $github_api;

	/**
	 * Logger instance.
	 *
	 * @var WP_Puller_Logger
	 */
	private $logger;

	/**
	 * Allowed file extensions.
	 *
	 * @var array
	 */
	private $allowed_extensions = array(
		'html', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp',
		'ico', 'webmanifest', 'xml', 'woff', 'woff2', 'ttf', 'css',
	);

	/**
	 * Allowed JS filenames (explicit allowlist).
	 *
	 * @var array
	 */
	private $allowed_js_files = array(
		'screenshot-cta.js',
		'screenshot-cta-scroll.js',
		'screenshot-mobile-cta.js',
	);

	/**
	 * Blocked extensions.
	 *
	 * @var array
	 */
	private $blocked_extensions = array(
		'php', 'htaccess', 'sql', 'zip', 'wpress', 'tar', 'gz',
	);

	/**
	 * Blocked path prefixes (relative to ABSPATH).
	 *
	 * @var array
	 */
	private $blocked_prefixes = array(
		'wp-admin', 'wp-content', 'wp-includes',
		'.git', 'node_modules', 'vendor',
	);

	/**
	 * Temporary extraction directory.
	 *
	 * @var string
	 */
	private $temp_dir = '';

	/**
	 * Constructor.
	 *
	 * @param WP_Puller_GitHub_API $github_api GitHub API instance.
	 * @param WP_Puller_Logger     $logger     Logger instance.
	 */
	public function __construct( $github_api, $logger ) {
		$this->github_api = $github_api;
		$this->logger     = $logger;
	}

	/**
	 * Perform a dry run of static file deployment.
	 *
	 * @param string $source_path Optional source path override.
	 * @return array|WP_Error Dry-run result array, or WP_Error on failure.
	 */
	public function dry_run( $source_path = '' ) {
		$repo_url = get_option( 'wp_puller_repo_url', '' );
		$branch   = get_option( 'wp_puller_branch', 'main' );

		if ( empty( $repo_url ) ) {
			return new WP_Error(
				'no_repo',
				__( 'No GitHub repository configured.', 'wp-puller' )
			);
		}

		$parsed = $this->github_api->parse_repo_url( $repo_url );

		if ( ! $parsed ) {
			return new WP_Error(
				'invalid_repo',
				__( 'Invalid GitHub repository URL.', 'wp-puller' )
			);
		}

		$zip_file = $this->github_api->download_archive( $parsed['owner'], $parsed['repo'], $branch );

		if ( is_wp_error( $zip_file ) ) {
			return $zip_file;
		}

		$extracted_dir = $this->download_and_extract_repo( $zip_file, $parsed['repo'], $branch );

		@unlink( $zip_file );

		if ( is_wp_error( $extracted_dir ) ) {
			return $extracted_dir;
		}

		$source_dir = $this->get_static_source_path( $extracted_dir, $source_path );

		if ( is_wp_error( $source_dir ) ) {
			$this->cleanup_temp_files();
			return $source_dir;
		}

		$result = $this->scan_static_files( $source_dir );

		$this->cleanup_temp_files();

		return $result;
	}

	/**
	 * Download and extract the repository ZIP.
	 *
	 * @param string $zip_file ZIP file path.
	 * @param string $repo     Repository name.
	 * @param string $branch   Branch name.
	 * @return string|WP_Error Extracted directory path, or error.
	 */
	private function download_and_extract_repo( $zip_file, $repo, $branch ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$this->temp_dir = get_temp_dir() . 'wp-puller-static-' . uniqid();

		$result = unzip_file( $zip_file, $this->temp_dir );

		if ( is_wp_error( $result ) ) {
			$wp_filesystem->delete( $this->temp_dir, true );
			return new WP_Error(
				'unzip_failed',
				__( 'Failed to extract repository archive.', 'wp-puller' )
			);
		}

		$extracted_dir = $this->temp_dir . '/' . $repo . '-' . $branch;

		if ( ! is_dir( $extracted_dir ) ) {
			$dirs = glob( $this->temp_dir . '/*', GLOB_ONLYDIR );
			if ( ! empty( $dirs ) ) {
				$extracted_dir = $dirs[0];
			} else {
				$this->cleanup_temp_files();
				return new WP_Error(
					'invalid_archive',
					__( 'Invalid archive structure.', 'wp-puller' )
				);
			}
		}

		return $extracted_dir;
	}

	/**
	 * Get the static source path inside the extracted repo.
	 *
	 * @param string $extracted_dir Extracted repository directory.
	 * @param string $source_path   Optional source path override.
	 * @return string|WP_Error Source directory path, or error.
	 */
	private function get_static_source_path( $extracted_dir, $source_path = '' ) {
		if ( empty( $source_path ) ) {
			$source_path = get_option( 'wp_puller_static_source_path', 'static-root-pages' );
		}

		$source_path = $this->sanitize_source_path( $source_path );
		if ( is_wp_error( $source_path ) ) {
			return $source_path;
		}

		$extracted_real = realpath( $extracted_dir );
		if ( false === $extracted_real ) {
			return new WP_Error(
				'invalid_extracted_dir',
				__( 'Extracted repository directory is invalid.', 'wp-puller' )
			);
		}

		$source_dir = $extracted_real . '/' . $source_path;
		$source_real = realpath( $source_dir );

		if ( false === $source_real ) {
			return new WP_Error(
				'source_not_found',
				sprintf(
					/* translators: %s: source path */
					__( 'Static source path "%s" not found in repository.', 'wp-puller' ),
					$source_path
				)
			);
		}

		// Jail check: source must be strictly inside the extracted directory.
		if ( strpos( $source_real, $extracted_real . '/' ) !== 0 ) {
			return new WP_Error(
				'source_path_jail',
				__( 'Source path is outside the repository directory.', 'wp-puller' )
			);
		}

		if ( ! is_dir( $source_real ) ) {
			return new WP_Error(
				'source_not_dir',
				sprintf(
					/* translators: %s: source path */
					__( 'Static source path "%s" is not a directory.', 'wp-puller' ),
					$source_path
				)
			);
		}

		return $source_real;
	}

	/**
	 * Scan static files and classify each one.
	 *
	 * @param string $source_dir Source directory inside extracted repo.
	 * @return array Dry-run result.
	 */
	private function scan_static_files( $source_dir ) {
		$allowed = array();
		$blocked = array();
		$skipped = array();
		$warnings = array();

		$dir_iterator = new RecursiveDirectoryIterator(
			$source_dir,
			RecursiveDirectoryIterator::SKIP_DOTS
		);

		// Filter out hidden directories and symlink directories at the iterator level.
		// Hidden files and symlink files are allowed through so they can be reported.
		$filter_iterator = new RecursiveCallbackFilterIterator(
			$dir_iterator,
			function( $current, $key, $iterator ) {
				// Skip hidden directories (and their children).
				if ( $current->isDir() && substr( $current->getBasename(), 0, 1 ) === '.' ) {
					return false;
				}
				// Skip symlink directories (and their children).
				if ( $current->isDir() && $current->isLink() ) {
					return false;
				}
				return true;
			}
		);

		$iterator = new RecursiveIteratorIterator(
			$filter_iterator,
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			// Skip directories.
			if ( ! $file->isFile() ) {
				continue;
			}

			$pathname = $file->getPathname();
			$prefix   = $source_dir . '/';

			if ( strpos( $pathname, $prefix ) !== 0 ) {
				$skipped[] = array(
					'source' => $pathname,
					'target' => $pathname,
					'action' => 'skipped',
					'size'   => 0,
					'reason' => __( 'Path prefix mismatch during scanning.', 'wp-puller' ),
				);
				continue;
			}

			$relative_source = substr( $pathname, strlen( $prefix ) );
			$relative_source = $this->normalize_relative_path( $relative_source );

			// Report symlinks as skipped (defense in depth for symlink files).
			if ( $file->isLink() ) {
				$skipped[] = array(
					'source' => $relative_source,
					'target' => $relative_source,
					'action' => 'skipped',
					'size'   => 0,
					'reason' => __( 'Symlinks are not allowed.', 'wp-puller' ),
				);
				continue;
			}

			if ( $this->is_blocked_path( $relative_source ) ) {
				$blocked[] = array(
					'source' => $relative_source,
					'target' => $relative_source,
					'action' => 'blocked',
					'size'   => $file->getSize(),
					'reason' => __( 'Path is in blocklist (WordPress core or protected directory).', 'wp-puller' ),
				);
				continue;
			}

			$check = $this->is_allowed_file( $file->getPathname(), $relative_source );

			if ( true !== $check ) {
				$blocked[] = array(
					'source' => $relative_source,
					'target' => $relative_source,
					'action' => 'blocked',
					'size'   => $file->getSize(),
					'reason' => $check,
				);
				continue;
			}

			$target_exists = file_exists( ABSPATH . $relative_source );
			$action        = $target_exists ? 'replace' : 'add';

			$allowed[] = array(
				'source'   => $relative_source,
				'target'   => $relative_source,
				'action'   => $action,
				'size'     => $file->getSize(),
				'size_fmt' => $this->format_file_size( $file->getSize() ),
				'reason'   => '',
			);
		}

		$total_size = array_sum( array_column( $allowed, 'size' ) );

		return array(
			'allowed'  => $allowed,
			'blocked'  => $blocked,
			'skipped'  => $skipped,
			'warnings' => $warnings,
			'summary'  => array(
				'allowed_count'  => count( $allowed ),
				'blocked_count'  => count( $blocked ),
				'skipped_count'  => count( $skipped ),
				'warning_count'  => count( $warnings ),
				'total_size'     => $total_size,
				'total_size_fmt' => $this->format_file_size( $total_size ),
			),
		);
	}

	/**
	 * Normalize a relative path.
	 *
	 * @param string $path Relative path.
	 * @return string Normalized path.
	 */
	private function normalize_relative_path( $path ) {
		$path = str_replace( '\\', '/', $path );
		$path = ltrim( $path, '/' );
		$path = preg_replace( '#/+#', '/', $path );
		return $path;
	}

	/**
	 * Sanitize and validate a source path.
	 *
	 * @param string $source_path Raw source path.
	 * @return string|WP_Error Sanitized path, or error.
	 */
	private function sanitize_source_path( $source_path ) {
		if ( empty( $source_path ) ) {
			return 'static-root-pages';
		}

		// Reject absolute paths (Unix or Windows).
		if ( substr( $source_path, 0, 1 ) === '/' || substr( $source_path, 0, 1 ) === '\\' ) {
			return new WP_Error(
				'invalid_source_path',
				__( 'Source path must be relative.', 'wp-puller' )
			);
		}

		// Reject Windows drive letters (e.g., C:\ or C:/).
		if ( preg_match( '/^[a-zA-Z]:[\\\\\\/]/', $source_path ) ) {
			return new WP_Error(
				'invalid_source_path',
				__( 'Source path must be relative.', 'wp-puller' )
			);
		}

		// Normalize slashes.
		$source_path = str_replace( '\\', '/', $source_path );

		// Reject parent-directory traversal.
		if ( strpos( $source_path, '..' ) !== false ) {
			return new WP_Error(
				'invalid_source_path',
				__( 'Source path cannot contain parent directory references.', 'wp-puller' )
			);
		}

		// Trim leading/trailing slashes.
		$source_path = trim( $source_path, '/' );

		if ( empty( $source_path ) ) {
			return 'static-root-pages';
		}

		return $source_path;
	}

	/**
	 * Check if a relative path is blocked.
	 *
	 * @param string $relative_path Path relative to ABSPATH.
	 * @return bool True if blocked.
	 */
	private function is_blocked_path( $relative_path ) {
		$lower = strtolower( $relative_path );

		if ( strpos( $lower, '..' ) !== false ) {
			return true;
		}

		$basename = basename( $lower );
		if ( $basename === 'index.php' || $basename === '.htaccess' ) {
			return true;
		}

		foreach ( $this->blocked_prefixes as $prefix ) {
			if ( strpos( $lower, $prefix . '/' ) === 0 || $lower === $prefix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a file is allowed.
	 *
	 * @param string $file_path     Full file path.
	 * @param string $relative_path Relative path.
	 * @return true|string True if allowed, error message if blocked.
	 */
	private function is_allowed_file( $file_path, $relative_path ) {
		$filename = basename( $relative_path );
		$ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( substr( $filename, 0, 1 ) === '.' ) {
			return __( 'Hidden files are not allowed.', 'wp-puller' );
		}

		if ( in_array( $ext, $this->blocked_extensions, true ) ) {
			return sprintf(
				/* translators: %s: file extension */
				__( 'Extension "%s" is in blocklist.', 'wp-puller' ),
				$ext
			);
		}

		if ( $ext === 'js' ) {
			if ( ! in_array( $filename, $this->allowed_js_files, true ) ) {
				return sprintf(
					/* translators: %s: filename */
					__( 'JS file "%s" is not in the explicit allowlist.', 'wp-puller' ),
					$filename
				);
			}
			return true;
		}

		if ( ! in_array( $ext, $this->allowed_extensions, true ) ) {
			return sprintf(
				/* translators: %s: file extension */
				__( 'Extension "%s" is not in the allowlist.', 'wp-puller' ),
				$ext
			);
		}

		return true;
	}

	/**
	 * Format file size to human-readable string.
	 *
	 * @param int $bytes Size in bytes.
	 * @return string
	 */
	private function format_file_size( $bytes ) {
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}

		$units = array( 'B', 'KB', 'MB', 'GB' );
		$size  = (float) $bytes;

		for ( $i = 0; $size >= 1024 && $i < count( $units ) - 1; $i++ ) {
			$size /= 1024;
		}

		return round( $size, 2 ) . ' ' . $units[ $i ];
	}

	/**
	 * Perform preflight checks before deploying.
	 *
	 * @param string $source_dir    Source directory inside extracted repo.
	 * @param array  $allowed_files Files from dry-run allowed list.
	 * @return true|WP_Error True if all checks pass, WP_Error on failure.
	 */
	private function preflight( $source_dir, $allowed_files ) {
		// 1. Verify backup directory is writable.
		$backup_base = trailingslashit( WP_CONTENT_DIR ) . 'wp-puller-static-backups';
		if ( ! is_dir( $backup_base ) ) {
			$created = wp_mkdir_p( $backup_base );
			if ( ! $created ) {
				return new WP_Error(
					'preflight_backup_dir',
					__( 'Preflight failed: cannot create backup directory.', 'wp-puller' )
				);
			}
		}
		if ( ! wp_is_writable( $backup_base ) ) {
			return new WP_Error(
				'preflight_backup_writable',
				__( 'Preflight failed: backup directory is not writable.', 'wp-puller' )
			);
		}

		// 2. Verify every source file is readable and not forbidden.
		foreach ( $allowed_files as $file ) {
			$relative = $file['source'];
			$source   = $source_dir . '/' . $relative;
			$target   = ABSPATH . $relative;

			// Source must exist and be readable.
			if ( ! is_readable( $source ) ) {
				return new WP_Error(
					'preflight_source_unreadable',
					sprintf(
						/* translators: %s: file path */
						__( 'Preflight failed: source file unreadable: %s', 'wp-puller' ),
						$relative
					)
				);
			}

			// No symlinks.
			if ( is_link( $source ) ) {
				return new WP_Error(
					'preflight_source_symlink',
					sprintf(
						/* translators: %s: file path */
						__( 'Preflight failed: source is a symlink: %s', 'wp-puller' ),
						$relative
					)
				);
			}

			// No hidden files.
			if ( substr( basename( $relative ), 0, 1 ) === '.' ) {
				return new WP_Error(
					'preflight_hidden_file',
					sprintf(
						/* translators: %s: file path */
						__( 'Preflight failed: hidden file detected: %s', 'wp-puller' ),
						$relative
					)
				);
			}

			// No forbidden paths.
			if ( $this->is_blocked_path( $relative ) ) {
				return new WP_Error(
					'preflight_blocked_path',
					sprintf(
						/* translators: %s: file path */
						__( 'Preflight failed: blocked path: %s', 'wp-puller' ),
						$relative
					)
				);
			}

			// Target must stay within ABSPATH.
			$target_dir = dirname( $target );
			$target_dir_real = realpath( $target_dir );
			$abspath_real    = realpath( ABSPATH );
			if ( false === $target_dir_real || strpos( $target_dir_real, $abspath_real ) !== 0 ) {
				return new WP_Error(
					'preflight_target_jail',
					sprintf(
						/* translators: %s: file path */
						__( 'Preflight failed: target escapes ABSPATH: %s', 'wp-puller' ),
						$relative
					)
				);
			}

			// Target directory must be writable or creatable.
			if ( is_dir( $target_dir ) ) {
				if ( ! wp_is_writable( $target_dir ) ) {
					return new WP_Error(
						'preflight_target_not_writable',
						sprintf(
							/* translators: %s: directory path */
							__( 'Preflight failed: target directory not writable: %s', 'wp-puller' ),
							$target_dir
						)
					);
				}
			} else {
				// Directory will be created — check parent is writable.
				$parent_dir = dirname( $target_dir );
				$parent_real = realpath( $parent_dir );
				if ( false === $parent_real || ! wp_is_writable( $parent_real ) ) {
					return new WP_Error(
						'preflight_parent_not_writable',
						sprintf(
							/* translators: %s: directory path */
							__( 'Preflight failed: cannot create target directory: %s', 'wp-puller' ),
							$target_dir
						)
					);
				}
			}

			// If target exists, it must be backupable (readable for copy).
			if ( file_exists( $target ) ) {
				if ( ! is_readable( $target ) ) {
					return new WP_Error(
						'preflight_target_unreadable',
						sprintf(
							/* translators: %s: file path */
							__( 'Preflight failed: existing target unreadable: %s', 'wp-puller' ),
							$relative
						)
					);
				}
			}
		}

		return true;
	}

	/**
	 * Deploy static files from extracted repo to ABSPATH.
	 *
	 * Server recomputes dry_run() immediately before copying.
	 * Does NOT trust a client-submitted manifest.
	 *
	 * @param string             $source_path    Optional source path override.
	 * @param WP_Puller_Static_Backup $backup_manager Backup manager instance.
	 * @return array|WP_Error Deploy result array, or WP_Error on failure.
	 */
	public function deploy( $source_path = '', $backup_manager ) {
		// Rate limit check.
		$rate_check = $backup_manager->check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		$backup_manager->record_deploy_time();

		// Recompute dry_run server-side. Do NOT trust client manifest.
		$dry_run_result = $this->dry_run( $source_path );

		if ( is_wp_error( $dry_run_result ) ) {
			return $dry_run_result;
		}

		if ( empty( $dry_run_result['allowed'] ) || ! is_array( $dry_run_result['allowed'] ) ) {
			return new WP_Error(
				'no_files_to_deploy',
				__( 'No files to deploy.', 'wp-puller' )
			);
		}

		$allowed_files = $dry_run_result['allowed'];

		// Re-download and extract repo for deploy (do not reuse temp files from dry_run).
		$repo_url = get_option( 'wp_puller_repo_url', '' );
		$branch   = get_option( 'wp_puller_branch', 'main' );
		$parsed   = $this->github_api->parse_repo_url( $repo_url );

		if ( ! $parsed ) {
			return new WP_Error(
				'invalid_repo',
				__( 'Invalid GitHub repository URL.', 'wp-puller' )
			);
		}

		$zip_file = $this->github_api->download_archive( $parsed['owner'], $parsed['repo'], $branch );

		if ( is_wp_error( $zip_file ) ) {
			return $zip_file;
		}

		$extracted_dir = $this->download_and_extract_repo( $zip_file, $parsed['repo'], $branch );
		@unlink( $zip_file );

		if ( is_wp_error( $extracted_dir ) ) {
			return $extracted_dir;
		}

		$source_dir = $this->get_static_source_path( $extracted_dir, $source_path );

		if ( is_wp_error( $source_dir ) ) {
			$this->cleanup_temp_files();
			return $source_dir;
		}

		// Preflight checks.
		$preflight = $this->preflight( $source_dir, $allowed_files );
		if ( is_wp_error( $preflight ) ) {
			$this->cleanup_temp_files();
			return $preflight;
		}

		// Start backup point.
		$backup_id = $backup_manager->start_backup();
		if ( is_wp_error( $backup_id ) ) {
			$this->cleanup_temp_files();
			return $backup_id;
		}

		$added      = array();
		$replaced   = array();
		$failed     = array();
		$total_size = 0;

		foreach ( $allowed_files as $file ) {
			$relative = $file['source'];
			$source   = $source_dir . '/' . $relative;
			$target   = ABSPATH . $relative;

			$action = file_exists( $target ) ? 'replace' : 'add';

			// Backup existing file before overwrite.
			if ( 'replace' === $action ) {
				$backup_result = $backup_manager->backup_file( $target );
				if ( is_wp_error( $backup_result ) ) {
					// Backup failed — abort and rollback.
					$failed[] = array(
						'file'   => $relative,
						'reason' => $backup_result->get_error_message(),
					);

					$rollback = $this->rollback_deploy( $backup_manager, $added, $replaced );
					$this->cleanup_temp_files();

					return new WP_Error(
						'deploy_aborted',
						__( 'Deploy aborted: backup failed. Rollback executed.', 'wp-puller' ),
						array(
							'failed_file' => $relative,
							'rollback'    => $rollback,
							'added'       => $added,
							'replaced'    => $replaced,
						)
					);
				}
			}

			// Ensure target directory exists.
			$target_dir = dirname( $target );
			if ( ! is_dir( $target_dir ) ) {
				wp_mkdir_p( $target_dir );
			}

			// Copy file.
			if ( ! @copy( $source, $target ) ) {
				// Copy failed — abort and rollback.
				$failed[] = array(
					'file'   => $relative,
					'reason' => __( 'Copy failed.', 'wp-puller' ),
				);

				$rollback = $this->rollback_deploy( $backup_manager, $added, $replaced );
				$this->cleanup_temp_files();

				return new WP_Error(
					'deploy_aborted',
					__( 'Deploy aborted: file copy failed. Rollback executed.', 'wp-puller' ),
					array(
						'failed_file' => $relative,
						'rollback'    => $rollback,
						'added'       => $added,
						'replaced'    => $replaced,
					)
				);
			}

			$total_size += $file['size'];

			if ( 'add' === $action ) {
				$added[] = $relative;
			} else {
				$replaced[] = $relative;
			}
		}

		// Build and save manifest.
		$manifest = array(
			'id'         => $backup_id,
			'timestamp'  => current_time( 'mysql' ),
			'source_path'=> empty( $source_path ) ? get_option( 'wp_puller_static_source_path', 'static-root-pages' ) : $source_path,
			'branch'     => $branch,
			'repo'       => $parsed['owner'] . '/' . $parsed['repo'],
			'deployed_by'=> wp_get_current_user()->user_login,
			'added'      => $added,
			'replaced'   => $replaced,
			'backed_up'  => $backup_manager->get_current_backed_up(),
			'failed'     => $failed,
			'total_size' => $total_size,
		);

		$backup_manager->write_manifest( $manifest );

		$this->cleanup_temp_files();

		return array(
			'success'    => true,
			'backup_id'  => $backup_id,
			'manifest'   => $manifest,
			'added'      => $added,
			'replaced'   => $replaced,
			'failed'     => $failed,
			'total_size' => $total_size,
			'total_size_fmt' => $this->format_file_size( $total_size ),
		);
	}

	/**
	 * Rollback files that were already deployed.
	 *
	 * @param WP_Puller_Static_Backup $backup_manager Backup manager instance.
	 * @param array                  $added          Files that were added.
	 * @param array                  $replaced       Files that were replaced.
	 * @return array Rollback result.
	 */
	private function rollback_deploy( $backup_manager, $added, $replaced ) {
		$restored = array();
		$removed  = array();

		// Restore replaced files from backup.
		$backed_up = $backup_manager->get_current_backed_up();
		foreach ( $backed_up as $entry ) {
			$backup_file   = $backup_manager->get_backup_path() . '/' . $entry['backup'];
			$original_path = ABSPATH . $entry['original'];

			if ( file_exists( $backup_file ) ) {
				if ( @copy( $backup_file, $original_path ) ) {
					$restored[] = $entry['original'];
				}
			}
		}

		// Remove files that were added (they didn't exist before).
		$abspath_real = realpath( ABSPATH );
		foreach ( $added as $relative ) {
			$target = realpath( ABSPATH . $relative );
			if ( false === $target || strpos( $target, $abspath_real . '/' ) !== 0 ) {
				continue; // Skip invalid paths.
			}
			if ( file_exists( $target ) ) {
				if ( @unlink( $target ) ) {
					$removed[] = $relative;
				}
			}
		}

		return array(
			'restored' => $restored,
			'removed'  => $removed,
		);
	}

	/**
	 * Clean up temporary files.
	 */
	private function cleanup_temp_files() {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! empty( $this->temp_dir ) && is_dir( $this->temp_dir ) ) {
			$wp_filesystem->delete( $this->temp_dir, true );
		}
	}
}
