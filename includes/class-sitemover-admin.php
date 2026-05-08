<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteMover_Admin {

	public function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sitemover_export_init',   array( $this, 'ajax_export_init' ) );
		add_action( 'wp_ajax_sitemover_export_chunk',  array( $this, 'ajax_export_chunk' ) );
		add_action( 'wp_ajax_sitemover_export_cancel', array( $this, 'ajax_export_cancel' ) );
		add_action( 'wp_ajax_sitemover_import', array( $this, 'ajax_import' ) );
		add_action( 'wp_ajax_sitemover_dl',     array( $this, 'ajax_download' ) );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public function register_menu() {
		add_management_page(
			'SiteMover',
			'SiteMover',
			'manage_options',
			'sitemover',
			array( $this, 'render_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets( $hook ) {
		if ( $hook !== 'tools_page_sitemover' ) {
			return;
		}

		wp_enqueue_style(
			'sitemover',
			SITEMOVER_URL . 'assets/sitemover-admin.css',
			array(),
			SITEMOVER_VERSION
		);

		wp_enqueue_script(
			'sitemover',
			SITEMOVER_URL . 'assets/sitemover-admin.js',
			array( 'jquery' ),
			SITEMOVER_VERSION,
			true
		);

		wp_localize_script( 'sitemover', 'SiteMover', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'sitemover' ),
			'maxUpload' => wp_max_upload_size(),
			'chunkSize' => 50,
			'i18n'      => array(
				'selectZip'       => __( 'Please select a .zip file.', 'sitemover' ),
				/* translators: %s = maximum upload size (e.g. "128 MB") */
				'fileTooBig'      => __( 'File exceeds server upload limit (%s).', 'sitemover' ),
				'preparing'       => __( 'Preparing…', 'sitemover' ),
				'initializing'    => __( 'Initializing…', 'sitemover' ),
				'requestFailed'   => __( 'Request failed or timed out. Try again.', 'sitemover' ),
				'exportingFiles'  => __( 'Exporting files…', 'sitemover' ),
				'exportFailed'    => __( 'Export failed or timed out. Try again.', 'sitemover' ),
				'done'            => __( 'Done!', 'sitemover' ),
				'exportCompleted' => __( 'Export complete!', 'sitemover' ),
				'downloadZip'     => __( 'Download ZIP', 'sitemover' ),
				'uploading'       => __( 'Uploading…', 'sitemover' ),
				'importSuccess'   => __( 'Import successful!', 'sitemover' ),
				'openSite'        => __( 'Open site', 'sitemover' ),
				'importFailed'    => __( 'Import failed or timed out. Check server PHP limits.', 'sitemover' ),
				'importWarning'   => __( 'WARNING: Importing will OVERWRITE the current database and merge files. This cannot be undone. Make sure you have a backup. Continue?', 'sitemover' ),
				'error'           => __( 'Error', 'sitemover' ),
			),
		) );
	}

	// -------------------------------------------------------------------------
	// Admin page
	// -------------------------------------------------------------------------

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'sitemover' ) );
		}

		$max_upload = size_format( wp_max_upload_size() );
		?>
		<div class="wrap sitemover-wrap">

			<div class="sitemover-header">
				<span class="sitemover-logo">Site<span>Mover</span></span>
				<p><?php esc_html_e( 'Migrate your WordPress site — export to ZIP, import anywhere.', 'sitemover' ); ?></p>
			</div>

			<div class="sitemover-grid">

				<!-- ====== EXPORT ====== -->
				<div class="sitemover-card">
					<div class="sitemover-card-head sitemover-export-head">
						<span class="dashicons dashicons-download"></span>
						<h2><?php esc_html_e( 'Export Site', 'sitemover' ); ?></h2>
					</div>
					<div class="sitemover-card-body">
						<p><?php echo wp_kses(
							__( 'Creates a <strong>ZIP file</strong> with your database and/or <code>wp-content</code> folder. Supports archives up to 2&nbsp;GB.', 'sitemover' ),
							array( 'strong' => array(), 'code' => array() )
						); ?></p>

						<!-- Export scope selector -->
						<div class="sitemover-modes" role="group" aria-label="<?php esc_attr_e( 'Export scope', 'sitemover' ); ?>">
							<div class="sitemover-mode-opt">
								<input type="radio" name="sitemover-export-mode" id="mode-all" value="all" checked>
								<label for="mode-all">
									<span class="dashicons dashicons-networking"></span>
									<strong><?php esc_html_e( 'Full site', 'sitemover' ); ?></strong>
									<span><?php esc_html_e( 'Database + files', 'sitemover' ); ?></span>
								</label>
							</div>
							<div class="sitemover-mode-opt">
								<input type="radio" name="sitemover-export-mode" id="mode-database" value="database">
								<label for="mode-database">
									<span class="dashicons dashicons-database"></span>
									<strong><?php esc_html_e( 'Database only', 'sitemover' ); ?></strong>
									<span><?php esc_html_e( 'SQL dump', 'sitemover' ); ?></span>
								</label>
							</div>
							<div class="sitemover-mode-opt">
								<input type="radio" name="sitemover-export-mode" id="mode-files" value="files">
								<label for="mode-files">
									<span class="dashicons dashicons-media-archive"></span>
									<strong><?php esc_html_e( 'Files only', 'sitemover' ); ?></strong>
									<span>wp-content</span>
								</label>
							</div>
						</div>

						<button id="sitemover-export-btn" class="sitemover-btn sitemover-btn--export">
							<span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export to ZIP', 'sitemover' ); ?>
						</button>

						<div id="sitemover-export-progress" class="sitemover-progress" hidden>
							<div class="sitemover-bar-row">
								<div class="sitemover-bar">
									<div class="sitemover-fill" id="sitemover-export-fill"></div>
								</div>
								<span class="sitemover-pct" id="sitemover-export-pct">0%</span>
							</div>
							<div class="sitemover-progress-footer">
								<p class="sitemover-status" id="sitemover-export-status"></p>
								<span class="sitemover-eta" id="sitemover-export-eta"></span>
							</div>
							<button type="button" id="sitemover-export-cancel" class="sitemover-cancel-btn"><?php esc_html_e( 'Cancel', 'sitemover' ); ?></button>
						</div>

						<div id="sitemover-export-result" class="sitemover-result"></div>
					</div>
				</div>

				<!-- ====== IMPORT ====== -->
				<div class="sitemover-card">
					<div class="sitemover-card-head sitemover-import-head">
						<span class="dashicons dashicons-upload"></span>
						<h2><?php esc_html_e( 'Import Site', 'sitemover' ); ?></h2>
					</div>
					<div class="sitemover-card-body">
						<p><?php echo wp_kses(
							__( 'Upload a <strong>SiteMover ZIP</strong> — the database will be imported and all URLs updated automatically.', 'sitemover' ),
							array( 'strong' => array() )
						); ?></p>
						<ul class="sitemover-checklist">
							<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Automatic URL replacement', 'sitemover' ); ?></li>
							<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Serialisation-safe search &amp; replace', 'sitemover' ); ?></li>
							<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Files &amp; database restored', 'sitemover' ); ?></li>
						</ul>

						<div class="sitemover-drop-zone" id="sitemover-drop-zone" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Upload ZIP file', 'sitemover' ); ?>">
							<span class="dashicons dashicons-media-archive"></span>
							<p><?php echo wp_kses( __( 'Drop ZIP here or <u>browse</u>', 'sitemover' ), array( 'u' => array() ) ); ?></p>
							<small><?php
								printf(
									/* translators: %s = maximum upload size (e.g. "128 MB") */
									esc_html__( 'Max upload: %s', 'sitemover' ),
									esc_html( $max_upload )
								);
							?></small>
							<input type="file" id="sitemover-file-input" accept=".zip" aria-hidden="true">
						</div>

						<div id="sitemover-file-info" class="sitemover-file-info" hidden></div>

						<button id="sitemover-import-btn" class="sitemover-btn sitemover-btn--import" disabled>
							<span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Import from ZIP', 'sitemover' ); ?>
						</button>

						<div id="sitemover-import-progress" class="sitemover-progress" hidden>
							<div class="sitemover-bar-row">
								<div class="sitemover-bar">
									<div class="sitemover-fill sitemover-fill--import" id="sitemover-import-fill"></div>
								</div>
								<span class="sitemover-pct sitemover-pct--import" id="sitemover-import-pct">0%</span>
							</div>
							<div class="sitemover-progress-footer">
								<p class="sitemover-status" id="sitemover-import-status"></p>
							</div>
						</div>

						<div id="sitemover-import-result" class="sitemover-result"></div>
					</div>
				</div>

			</div><!-- .sitemover-grid -->
		</div><!-- .sitemover-wrap -->
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX: export — init
	// -------------------------------------------------------------------------

	public function ajax_export_init() {
		check_ajax_referer( 'sitemover', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sitemover' ) ), 403 );
		}

		$mode = sanitize_text_field( wp_unslash( $_POST['mode'] ?? 'all' ) );
		if ( ! in_array( $mode, array( 'all', 'database', 'files' ), true ) ) {
			$mode = 'all';
		}

		$exporter = new SiteMover_Exporter();
		$result   = $exporter->init( $mode );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( $result['done'] ) {
			$result['dl_url'] = $this->make_dl_url( $result['filename'] );
			unset( $result['zip_path'] );
		}

		wp_send_json_success( $result );
	}

	// -------------------------------------------------------------------------
	// AJAX: export — chunk
	// -------------------------------------------------------------------------

	public function ajax_export_chunk() {
		check_ajax_referer( 'sitemover', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sitemover' ) ), 403 );
		}

		$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
		$offset = max( 0, intval( $_POST['offset'] ?? 0 ) );

		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing job_id.', 'sitemover' ) ) );
		}

		$exporter = new SiteMover_Exporter();
		$result   = $exporter->process_chunk( $job_id, $offset );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( $result['done'] ) {
			$result['dl_url'] = $this->make_dl_url( $result['filename'] );
			unset( $result['zip_path'] );
		}

		wp_send_json_success( $result );
	}

	// -------------------------------------------------------------------------
	// AJAX: export — cancel
	// -------------------------------------------------------------------------

	public function ajax_export_cancel() {
		check_ajax_referer( 'sitemover', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sitemover' ) ), 403 );
		}

		$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
		if ( $job_id ) {
			$exporter = new SiteMover_Exporter();
			$exporter->cancel( $job_id );
		}

		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// AJAX: import
	// -------------------------------------------------------------------------

	public function ajax_import() {
		check_ajax_referer( 'sitemover', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sitemover' ) ), 403 );
		}

		if ( empty( $_FILES['zip_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file received.', 'sitemover' ) ) );
		}

		$zip_file = array(
			'name'     => isset( $_FILES['zip_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['zip_file']['name'] ) ) : '',
			'type'     => isset( $_FILES['zip_file']['type'] ) ? sanitize_text_field( wp_unslash( $_FILES['zip_file']['type'] ) ) : '',
			'tmp_name' => isset( $_FILES['zip_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['zip_file']['tmp_name'] ) ) : '',
			'error'    => isset( $_FILES['zip_file']['error'] ) ? absint( $_FILES['zip_file']['error'] ) : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $_FILES['zip_file']['size'] ) ? absint( $_FILES['zip_file']['size'] ) : 0,
		);

		$importer = new SiteMover_Importer();
		$result   = $importer->import( $zip_file );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => $result ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: file download
	// -------------------------------------------------------------------------

	public function ajax_download() {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'sitemover_dl' ) ) {
			wp_die( esc_html__( 'Invalid or expired link.', 'sitemover' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'sitemover' ) );
		}

		$filename = sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) );

		if ( ! $filename || substr( $filename, -4 ) !== '.zip' || strpos( $filename, '/' ) !== false ) {
			wp_die( esc_html__( 'Invalid filename.', 'sitemover' ) );
		}

		$filepath  = SITEMOVER_EXPORT_DIR . $filename;
		$real_file = realpath( $filepath );
		$real_dir  = realpath( SITEMOVER_EXPORT_DIR );

		if ( ! $real_file || strpos( $real_file, $real_dir ) !== 0 || ! is_file( $real_file ) ) {
			wp_die( esc_html__( 'File not found.', 'sitemover' ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $real_file ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		echo $wp_filesystem->get_contents( $real_file ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary ZIP file download, escaping would corrupt data.

		wp_delete_file( $real_file );

		exit;
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	private function make_dl_url( $filename ) {
		return add_query_arg(
			array(
				'action'   => 'sitemover_dl',
				'file'     => urlencode( $filename ),
				'_wpnonce' => wp_create_nonce( 'sitemover_dl' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}
}
