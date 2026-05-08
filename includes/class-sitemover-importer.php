<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteMover_Importer {

	private $temp_dir = '';

	public function import( array $uploaded_file ) {
		set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		wp_raise_memory_limit( 'admin' );

		if ( $uploaded_file['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'upload', sprintf(
				/* translators: %d = PHP upload error code */
				__( 'File upload failed (code %d).', 'sitemover' ),
				$uploaded_file['error']
			) );
		}

		if ( ! $this->is_zip( $uploaded_file ) ) {
			return new WP_Error( 'filetype', __( 'Please upload a valid .zip file.', 'sitemover' ) );
		}

		$this->temp_dir = SITEMOVER_EXPORT_DIR . 'import_' . time() . '/';
		if ( ! wp_mkdir_p( $this->temp_dir ) ) {
			return new WP_Error( 'mkdir', __( 'Cannot create temporary directory.', 'sitemover' ) );
		}

		$result = $this->extract( $uploaded_file['tmp_name'] );
		if ( is_wp_error( $result ) ) {
			$this->cleanup();
			return $result;
		}

		$info = $this->read_site_info();
		if ( is_wp_error( $info ) ) {
			$this->cleanup();
			return $info;
		}

		$old_url    = rtrim( $info['site_url'], '/' );
		$new_url    = rtrim( get_site_url(), '/' );
		$old_prefix = $info['table_prefix'];
		$new_prefix = $GLOBALS['wpdb']->prefix;

		$result = $this->import_database( $old_prefix, $new_prefix );
		if ( is_wp_error( $result ) ) {
			$this->cleanup();
			return $result;
		}

		if ( $old_url !== $new_url ) {
			$this->replace_urls( $old_url, $new_url );
		}

		$result = $this->restore_wp_content();
		if ( is_wp_error( $result ) ) {
			$this->cleanup();
			return $result;
		}

		$this->cleanup();

		return sprintf(
			/* translators: %1$s = old site URL, %2$s = new site URL */
			__( 'Import completed successfully! Database imported, URLs updated (%1$s → %2$s), files restored.', 'sitemover' ),
			esc_html( $old_url ),
			esc_html( $new_url )
		);
	}

	// -------------------------------------------------------------------------
	// ZIP extraction
	// -------------------------------------------------------------------------

	private function is_zip( array $file ) {
		$name = strtolower( $file['name'] );
		return substr( $name, -4 ) === '.zip';
	}

	private function extract( $tmp_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', __( 'PHP ZipArchive extension is not available.', 'sitemover' ) );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $tmp_path ) !== true ) {
			return new WP_Error( 'zip_open', __( 'Cannot open the uploaded ZIP file.', 'sitemover' ) );
		}

		$zip->extractTo( $this->temp_dir );
		$zip->close();
		return true;
	}

	// -------------------------------------------------------------------------
	// Site info
	// -------------------------------------------------------------------------

	private function read_site_info() {
		$file = $this->temp_dir . 'site-info.json';
		if ( ! file_exists( $file ) ) {
			return new WP_Error( 'no_info', __( 'Invalid SiteMover archive: missing site-info.json.', 'sitemover' ) );
		}

		$fs      = $this->get_filesystem();
		$content = $fs->get_contents( $file );
		$info    = json_decode( $content, true );

		if ( ! $info || empty( $info['site_url'] ) || empty( $info['table_prefix'] ) ) {
			return new WP_Error( 'bad_info', __( 'site-info.json is malformed.', 'sitemover' ) );
		}

		return $info;
	}

	// -------------------------------------------------------------------------
	// Database import
	// -------------------------------------------------------------------------

	private function import_database( $old_prefix, $new_prefix ) {
		global $wpdb;

		$file = $this->temp_dir . 'database.sql';
		if ( ! file_exists( $file ) ) {
			return new WP_Error( 'no_sql', __( 'Invalid SiteMover archive: missing database.sql.', 'sitemover' ) );
		}

		$fs  = $this->get_filesystem();
		$sql = $fs->get_contents( $file );
		if ( $sql === false ) {
			return new WP_Error( 'read_sql', __( 'Cannot read database.sql.', 'sitemover' ) );
		}

		// Replace table prefix in table names (always wrapped in backticks in our dumps).
		if ( $old_prefix !== $new_prefix ) {
			$sql = str_replace( '`' . $old_prefix, '`' . $new_prefix, $sql );
		}

		$statements = $this->parse_sql( $sql );
		unset( $sql );

		$wpdb->hide_errors();
		foreach ( $statements as $stmt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( $stmt );
		}
		$wpdb->show_errors();

		return true;
	}

	/**
	 * Split a SQL dump into individual statements, respecting quoted strings.
	 */
	private function parse_sql( $sql ) {
		$statements  = array();
		$current     = '';
		$in_string   = false;
		$string_char = '';
		$escape_next = false;
		$len         = strlen( $sql );

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $sql[ $i ];

			if ( $escape_next ) {
				$current    .= $c;
				$escape_next = false;
				continue;
			}

			if ( $in_string ) {
				$current .= $c;
				if ( $c === '\\' ) {
					$escape_next = true;
				} elseif ( $c === $string_char ) {
					$in_string = false;
				}
				continue;
			}

			// Line comment: skip to end of line.
			if ( $c === '-' && $i + 1 < $len && $sql[ $i + 1 ] === '-' ) {
				while ( $i < $len && $sql[ $i ] !== "\n" ) {
					$i++;
				}
				continue;
			}

			if ( $c === "'" || $c === '"' || $c === '`' ) {
				$in_string   = true;
				$string_char = $c;
				$current    .= $c;
				continue;
			}

			if ( $c === ';' ) {
				$stmt = trim( $current );
				if ( $stmt !== '' ) {
					$statements[] = $stmt;
				}
				$current = '';
				continue;
			}

			$current .= $c;
		}

		$stmt = trim( $current );
		if ( $stmt !== '' ) {
			$statements[] = $stmt;
		}

		return $statements;
	}

	// -------------------------------------------------------------------------
	// URL search & replace (serialisation-aware)
	// -------------------------------------------------------------------------

	private function replace_urls( $old_url, $new_url ) {
		global $wpdb;

		$tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $tables as $row ) {
			$table      = $row[0];
			$table_safe = esc_sql( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$columns = $wpdb->get_results( "DESCRIBE `{$table_safe}`", ARRAY_A );
			$pk      = $this->get_primary_key( $table );

			foreach ( $columns as $col ) {
				if ( ! $this->is_text_type( $col['Type'] ) ) {
					continue;
				}

				$field      = $col['Field'];
				$field_safe = esc_sql( $field );

				if ( ! $pk ) {
					// No PK: use a simple SQL REPLACE (won't fix serialised data, but best we can do).
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE `{$table_safe}` SET `{$field_safe}` = REPLACE(`{$field_safe}`, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							$old_url,
							$new_url
						)
					);
					continue;
				}

				$pk_safe = esc_sql( $pk );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT `{$pk_safe}`, `{$field_safe}` FROM `{$table_safe}` WHERE `{$field_safe}` LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						'%' . $wpdb->esc_like( $old_url ) . '%'
					),
					ARRAY_A
				);

				foreach ( $rows as $r ) {
					$new_val = $this->deep_replace( $r[ $field ], $old_url, $new_url );
					if ( $new_val !== $r[ $field ] ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update( $table, array( $field => $new_val ), array( $pk => $r[ $pk ] ) );
					}
				}
			}
		}
	}

	private function get_primary_key( $table ) {
		global $wpdb;
		$table_safe = esc_sql( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table_safe}` WHERE Key_name = 'PRIMARY'", ARRAY_A );
		return ! empty( $keys ) ? $keys[0]['Column_name'] : null;
	}

	private function is_text_type( $type ) {
		$type = strtolower( $type );
		return strpos( $type, 'char' ) !== false
			|| strpos( $type, 'text' ) !== false
			|| strpos( $type, 'blob' ) !== false;
	}

	/**
	 * Recursively replace strings, handling PHP serialised data.
	 *
	 * @param mixed  $data
	 * @param string $old
	 * @param string $new
	 * @return mixed
	 */
	private function deep_replace( $data, $old, $new ) {
		if ( is_string( $data ) && $this->is_serialised( $data ) ) {
			$val = @unserialize( $data );
			if ( $val !== false || $data === serialize( false ) ) {
				return serialize( $this->deep_replace( $val, $old, $new ) );
			}
		}

		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				$out[ $this->deep_replace( $k, $old, $new ) ] = $this->deep_replace( $v, $old, $new );
			}
			return $out;
		}

		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$data->$k = $this->deep_replace( $v, $old, $new );
			}
			return $data;
		}

		if ( is_string( $data ) ) {
			return str_replace( $old, $new, $data );
		}

		return $data;
	}

	private function is_serialised( $data ) {
		if ( ! is_string( $data ) ) {
			return false;
		}
		$data = trim( $data );
		if ( $data === 'N;' ) {
			return true;
		}
		$len = strlen( $data );
		if ( $len < 4 || $data[1] !== ':' ) {
			return false;
		}
		$last = $data[ $len - 1 ];
		if ( $last !== ';' && $last !== '}' ) {
			return false;
		}
		switch ( $data[0] ) {
			case 's':
				return $data[ $len - 2 ] === '"';
			case 'a':
			case 'O':
				return (bool) preg_match( '/^[aO]:[0-9]+:/s', $data );
			case 'b':
			case 'i':
			case 'd':
				return (bool) preg_match( '/^[bid]:[0-9.E+-]+;$/', $data );
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// wp-content restore
	// -------------------------------------------------------------------------

	private function restore_wp_content() {
		$source = $this->temp_dir . 'wp-content' . DIRECTORY_SEPARATOR;
		if ( ! is_dir( $source ) ) {
			return true; // Nothing to restore.
		}

		$real_source = realpath( $source );
		$dest        = WP_CONTENT_DIR . DIRECTORY_SEPARATOR;

		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iter as $item ) {
			$real_item = $item->getRealPath();

			// Prevent path traversal.
			if ( strpos( $real_item, $real_source ) !== 0 ) {
				continue;
			}

			$relative = substr( $real_item, strlen( $real_source ) );
			$target   = $dest . ltrim( $relative, DIRECTORY_SEPARATOR );

			if ( $item->isDir() ) {
				wp_mkdir_p( $target );
			} else {
				wp_mkdir_p( dirname( $target ) );
				if ( ! copy( $real_item, $target ) ) {
					return new WP_Error( 'copy', sprintf(
						/* translators: %s = relative file path */
						__( 'Cannot copy file: %s', 'sitemover' ),
						$relative
					) );
				}
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Cleanup
	// -------------------------------------------------------------------------

	private function cleanup() {
		if ( $this->temp_dir && is_dir( $this->temp_dir ) ) {
			$this->remove_dir( $this->temp_dir );
		}
	}

	private function remove_dir( $dir ) {
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
