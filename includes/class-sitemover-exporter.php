<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteMover_Exporter {

	const CHUNK_SIZE      = 50;
	const JOB_TTL         = 3600; // seconds
	const FREE_SIZE_LIMIT = 524288000; // 500 MB in bytes

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Initialise a new export job.
	 *
	 * @param  string $mode 'all' | 'database' | 'files'
	 * @return array|WP_Error  On success returns job data array.
	 */
	public function init( $mode = 'all' ) {
		set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		wp_raise_memory_limit( 'admin' );

		$job_id    = wp_generate_password( 20, false );
		$timestamp = gmdate( 'Y-m-d_H-i-s' );
		$temp_dir  = SITEMOVER_EXPORT_DIR . 'tmp_' . $timestamp . '_' . substr( $job_id, 0, 8 ) . '/';
		$zip_path  = SITEMOVER_EXPORT_DIR . 'sitemover-' . $timestamp . '.zip';
		$list_file = SITEMOVER_EXPORT_DIR . 'list_' . $job_id . '.jsonl';

		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error( 'mkdir', __( 'Could not create temporary directory.', 'sitemover' ) );
		}

		// Database dump
		if ( $mode === 'all' || $mode === 'database' ) {
			$r = $this->dump_database( $temp_dir );
			if ( is_wp_error( $r ) ) {
				$this->remove_dir( $temp_dir );
				return $r;
			}
		}

		// Metadata (always written so imports stay compatible)
		$this->write_site_info( $temp_dir );

		// Create ZIP and add metadata/DB files
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->remove_dir( $temp_dir );
			return new WP_Error( 'no_zip', __( 'PHP ZipArchive extension is not available.', 'sitemover' ) );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			$this->remove_dir( $temp_dir );
			return new WP_Error( 'zip_open', __( 'Cannot create ZIP file.', 'sitemover' ) );
		}
		foreach ( glob( $temp_dir . '*' ) as $f ) {
			$zip->addFile( $f, basename( $f ) );
		}
		$zip->close();
		$this->remove_dir( $temp_dir );

		// Build file list for file-based modes
		$total_files = 0;
		if ( $mode === 'all' || $mode === 'files' ) {
			$r = $this->write_file_list( $list_file );
			if ( is_wp_error( $r ) ) {
				wp_delete_file( $zip_path );
				return $r;
			}
			$total_files = $r;
		}

		// Nothing to chunk → done already
		if ( $total_files === 0 ) {
			wp_delete_file( $list_file );
			return array(
				'done'     => true,
				'zip_path' => $zip_path,
				'filename' => basename( $zip_path ),
			);
		}

		set_transient( 'sitemover_job_' . $job_id, array(
			'zip_path'  => $zip_path,
			'list_file' => $list_file,
			'total'     => $total_files,
		), self::JOB_TTL );

		return array(
			'done'    => false,
			'job_id'  => $job_id,
			'total'   => $total_files,
		);
	}

	/**
	 * Add one batch of files to the in-progress ZIP.
	 *
	 * @param  string $job_id
	 * @param  int    $offset
	 * @return array|WP_Error
	 */
	public function process_chunk( $job_id, $offset ) {
		set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		wp_raise_memory_limit( 'admin' );

		$state = get_transient( 'sitemover_job_' . $job_id );
		if ( ! $state ) {
			return new WP_Error( 'no_job', __( 'Export job not found or expired. Please start a new export.', 'sitemover' ) );
		}

		$zip_path  = $state['zip_path'];
		$list_file = $state['list_file'];
		$total     = $state['total'];

		$chunk = $this->read_chunk( $list_file, $offset, self::CHUNK_SIZE );

		if ( ! empty( $chunk ) ) {
			$zip = new ZipArchive();
			// ZipArchive::CREATE (without OVERWRITE) opens an existing archive for appending.
			if ( $zip->open( $zip_path, ZipArchive::CREATE ) !== true ) {
				return new WP_Error( 'zip_open', __( 'Cannot open ZIP for writing.', 'sitemover' ) );
			}
			foreach ( $chunk as $item ) {
				if ( is_file( $item['r'] ) ) {
					$zip->addFile( $item['r'], $item['p'] );
				}
			}
			$zip->close();
		}

		$new_offset = $offset + count( $chunk );
		$percent    = $total > 0 ? min( 100, (int) round( $new_offset / $total * 100 ) ) : 100;
		$done       = $new_offset >= $total;

		if ( $done ) {
			wp_delete_file( $list_file );
			delete_transient( 'sitemover_job_' . $job_id );
		}

		return array(
			'done'     => $done,
			'offset'   => $new_offset,
			'total'    => $total,
			'percent'  => $percent,
			'zip_path' => $done ? $zip_path : null,
			'filename' => $done ? basename( $zip_path ) : null,
		);
	}

	/**
	 * Cancel an in-progress export job and clean up its files.
	 *
	 * @param string $job_id
	 */
	public function cancel( $job_id ) {
		$state = get_transient( 'sitemover_job_' . $job_id );
		if ( $state ) {
			if ( ! empty( $state['zip_path'] ) ) {
				wp_delete_file( $state['zip_path'] );
			}
			if ( ! empty( $state['list_file'] ) ) {
				wp_delete_file( $state['list_file'] );
			}
			delete_transient( 'sitemover_job_' . $job_id );
		}
	}

	// -------------------------------------------------------------------------
	// Database dump
	// -------------------------------------------------------------------------

	private function dump_database( $temp_dir ) {
		global $wpdb;

		$file    = $temp_dir . 'database.sql';
		$content = "-- SiteMover Database Dump\n";
		$content .= '-- Date:       ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
		$content .= '-- Source URL: ' . get_site_url() . "\n\n";
		$content .= "SET FOREIGN_KEY_CHECKS=0;\n";
		$content .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
		$content .= "SET NAMES utf8mb4;\n\n";

		$tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $tables as $row ) {
			$table_sql = $this->dump_table( $row[0] );
			if ( is_wp_error( $table_sql ) ) {
				return $table_sql;
			}
			$content .= $table_sql;
		}

		$content .= "\nSET FOREIGN_KEY_CHECKS=1;\n";

		$fs = $this->get_filesystem();
		if ( ! $fs->put_contents( $file, $content, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'write_sql', __( 'Cannot create database.sql.', 'sitemover' ) );
		}

		return true;
	}

	private function dump_table( $table ) {
		global $wpdb;

		$table_safe = esc_sql( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table_safe}`", ARRAY_N );
		if ( ! $create ) {
			return new WP_Error( 'show_create', sprintf(
				/* translators: %s = database table name */
				__( 'Cannot get CREATE TABLE for %s.', 'sitemover' ),
				$table
			) );
		}

		$sql = "\n-- Table: {$table}\n";
		$sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
		$sql .= $create[1] . ";\n\n";

		$offset = 0;
		$chunk  = 500;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM `{$table_safe}` LIMIT %d OFFSET %d", $chunk, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			$cols = '`' . implode( '`, `', array_keys( $rows[0] ) ) . '`';

			foreach ( $rows as $row ) {
				$vals = array_map( function ( $v ) use ( $wpdb ) {
					return $v === null ? 'NULL' : "'" . $wpdb->_real_escape( $v ) . "'";
				}, array_values( $row ) );

				$sql .= "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode( ', ', $vals ) . ");\n";
			}

			$offset += $chunk;
		} while ( count( $rows ) === $chunk );

		$sql .= "\n";
		return $sql;
	}

	// -------------------------------------------------------------------------
	// Site info metadata
	// -------------------------------------------------------------------------

	private function write_site_info( $temp_dir ) {
		global $wpdb;
		$info = array(
			'sitemover_version' => SITEMOVER_VERSION,
			'wp_version'        => get_bloginfo( 'version' ),
			'site_url'          => get_site_url(),
			'home_url'          => get_home_url(),
			'table_prefix'      => $wpdb->prefix,
			'export_date'       => gmdate( 'Y-m-d H:i:s' ),
			'php_version'       => PHP_VERSION,
		);

		$fs = $this->get_filesystem();
		$fs->put_contents( $temp_dir . 'site-info.json', wp_json_encode( $info, JSON_PRETTY_PRINT ), FS_CHMOD_FILE );
	}

	// -------------------------------------------------------------------------
	// File list (JSONL — one entry per line)
	// -------------------------------------------------------------------------

	private function write_file_list( $list_file ) {
		$skip_segments = array( 'cache', 'sitemover-exports' );
		$base_dir      = WP_CONTENT_DIR;
		$base_real     = realpath( $base_dir );

		$lines      = array();
		$count      = 0;
		$total_size = 0;
		$iter  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iter as $file ) {
			if ( $file->isDir() ) {
				continue;
			}

			$real = $file->getRealPath();
			if ( strpos( $real, $base_real ) !== 0 ) {
				continue;
			}

			$relative = 'wp-content/' . ltrim( substr( $real, strlen( $base_real ) ), DIRECTORY_SEPARATOR );
			$relative = str_replace( '\\', '/', $relative );

			$skip = false;
			foreach ( $skip_segments as $seg ) {
				if ( strpos( $relative, '/' . $seg . '/' ) !== false ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			$total_size += $file->getSize();
			if ( $total_size > self::FREE_SIZE_LIMIT ) {
				return new WP_Error(
					'size_limit',
					__( '⚠️ Your site exceeds the 500 MB free plan limit. To export larger sites, upgrade to SiteMover Pro.', 'sitemover' )
				);
			}

			// Short keys ('r', 'p') reduce file size for large sites.
			$lines[] = wp_json_encode( array( 'r' => $real, 'p' => $relative ) );
			$count++;
		}

		$fs = $this->get_filesystem();
		if ( ! $fs->put_contents( $list_file, implode( "\n", $lines ), FS_CHMOD_FILE ) ) {
			return new WP_Error( 'write_list', __( 'Cannot create file list.', 'sitemover' ) );
		}

		return $count;
	}

	/**
	 * Read $limit entries starting at $offset from a JSONL file.
	 */
	private function read_chunk( $list_file, $offset, $limit ) {
		$fs      = $this->get_filesystem();
		$content = $fs->get_contents( $list_file );
		if ( $content === false || $content === '' ) {
			return array();
		}

		$lines = explode( "\n", $content );
		$slice = array_slice( $lines, $offset, $limit );

		$items = array();
		foreach ( $slice as $line ) {
			$item = json_decode( trim( $line ), true );
			if ( $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	// -------------------------------------------------------------------------
	// Cleanup
	// -------------------------------------------------------------------------

	private function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$fs   = $this->get_filesystem();
		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iter as $f ) {
			if ( $f->isDir() ) {
				$fs->rmdir( $f->getRealPath() );
			} else {
				wp_delete_file( $f->getRealPath() );
			}
		}
		$fs->rmdir( $dir );
	}

	// -------------------------------------------------------------------------
	// WP_Filesystem helper
	// -------------------------------------------------------------------------

	private function get_filesystem() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		return $wp_filesystem;
	}
}
