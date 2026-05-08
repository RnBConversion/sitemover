/* global SiteMover, jQuery */
jQuery( function ( $ ) {
	'use strict';

	var selectedFile  = null;
	var currentJobId  = null;
	var exportAborted = false;
	var exportStart   = 0;
	var i18n          = SiteMover.i18n;

	// ── Helpers ────────────────────────────────────────────────────────────

	function fmtSize( bytes ) {
		if ( bytes < 1048576 )    { return ( bytes / 1024 ).toFixed( 0 ) + ' KB'; }
		if ( bytes < 1073741824 ) { return ( bytes / 1048576 ).toFixed( 1 ) + ' MB'; }
		return ( bytes / 1073741824 ).toFixed( 2 ) + ' GB';
	}

	function fmtTime( secs ) {
		secs = Math.round( secs );
		if ( secs < 60 )   { return secs + 's'; }
		if ( secs < 3600 ) { return Math.round( secs / 60 ) + ' min'; }
		var h = Math.floor( secs / 3600 );
		var m = Math.round( ( secs % 3600 ) / 60 );
		return h + 'h ' + m + 'min';
	}

	function calcEta( processed, total ) {
		if ( processed <= 0 || ! exportStart || total <= 0 ) { return ''; }
		var elapsed = ( Date.now() - exportStart ) / 1000;
		if ( elapsed < 3 ) { return ''; }
		var rate      = processed / elapsed; // files per second
		var remaining = ( total - processed ) / rate;
		if ( remaining < 5 ) { return ''; }
		return '~' + fmtTime( remaining ) + ' remaining';
	}

	function setExportProgress( pct, statusMsg, etaMsg ) {
		$( '#sitemover-export-fill' ).css( 'width', pct + '%' );
		$( '#sitemover-export-pct' ).text( pct + '%' );
		if ( statusMsg !== undefined ) { $( '#sitemover-export-status' ).text( statusMsg ); }
		if ( etaMsg    !== undefined ) { $( '#sitemover-export-eta' ).text( etaMsg ); }
	}

	function showExportResult( type, html ) {
		$( '#sitemover-export-result' ).removeClass( 'is-success is-error' ).addClass( type ).html( html );
	}

	// ── Drop zone ──────────────────────────────────────────────────────────

	var $zone  = $( '#sitemover-drop-zone' );
	var $input = $( '#sitemover-file-input' );

	$zone.on( 'dragover dragenter', function ( e ) {
		e.preventDefault();
		$zone.addClass( 'is-over' );
	} );

	$zone.on( 'dragleave drop', function () {
		$zone.removeClass( 'is-over' );
	} );

	$zone.on( 'drop', function ( e ) {
		e.preventDefault();
		var files = e.originalEvent.dataTransfer.files;
		if ( files.length ) { handleFile( files[0] ); }
	} );

	$input.on( 'change', function () {
		if ( this.files.length ) { handleFile( this.files[0] ); }
	} );

	$zone.on( 'keydown', function ( e ) {
		if ( e.key === 'Enter' || e.key === ' ' ) { $input.trigger( 'click' ); }
	} );

	function handleFile( file ) {
		if ( file.name.slice( -4 ).toLowerCase() !== '.zip' ) {
			alert( i18n.selectZip );
			return;
		}
		if ( SiteMover.maxUpload && file.size > SiteMover.maxUpload ) {
			alert( i18n.fileTooBig.replace( '%s', fmtSize( SiteMover.maxUpload ) ) );
			return;
		}
		selectedFile = file;
		$( '#sitemover-file-info' )
			.html( '<span class="dashicons dashicons-media-archive"></span>' + file.name + ' (' + fmtSize( file.size ) + ')' )
			.prop( 'hidden', false );
		$( '#sitemover-import-btn' ).prop( 'disabled', false );
	}

	// ── Export ─────────────────────────────────────────────────────────────

	$( '#sitemover-export-btn' ).on( 'click', function () {
		var mode = $( 'input[name="sitemover-export-mode"]:checked' ).val() || 'all';

		$( this ).prop( 'disabled', true );
		$( '#sitemover-export-progress' ).prop( 'hidden', false );
		$( '#sitemover-export-result' ).removeClass( 'is-success is-error' ).empty();

		exportAborted = false;
		exportStart   = Date.now();
		currentJobId  = null;

		setExportProgress( 2, i18n.initializing, '' );

		$.ajax( {
			url:     SiteMover.ajaxUrl,
			type:    'POST',
			data:    { action: 'sitemover_export_init', nonce: SiteMover.nonce, mode: mode },
			timeout: 300000,
		} ).done( function ( r ) {
			if ( exportAborted ) { return; }
			if ( ! r.success ) {
				resetExportUI();
				showExportResult( 'is-error', '<strong>' + i18n.error + ':</strong> ' + r.data.message );
				return;
			}
			if ( r.data.done ) {
				// Database-only: completed during init
				finishExportOk( r.data.filename, r.data.dl_url );
			} else {
				currentJobId = r.data.job_id;
				processChunk( 0, r.data.total );
			}
		} ).fail( function () {
			if ( exportAborted ) { return; }
			resetExportUI();
			showExportResult( 'is-error', i18n.requestFailed );
		} );
	} );

	function processChunk( offset, total ) {
		if ( exportAborted ) { return; }

		var pct = total > 0 ? Math.max( 3, Math.round( offset / total * 100 ) ) : 100;
		setExportProgress(
			pct,
			i18n.exportingFiles + ' ' + offset + ' / ' + total,
			calcEta( offset, total )
		);

		$.ajax( {
			url:     SiteMover.ajaxUrl,
			type:    'POST',
			data:    {
				action:  'sitemover_export_chunk',
				nonce:   SiteMover.nonce,
				job_id:  currentJobId,
				offset:  offset,
			},
			timeout: 300000,
		} ).done( function ( r ) {
			if ( exportAborted ) { return; }
			if ( ! r.success ) {
				resetExportUI();
				showExportResult( 'is-error', '<strong>' + i18n.error + ':</strong> ' + r.data.message );
				return;
			}
			if ( r.data.done ) {
				finishExportOk( r.data.filename, r.data.dl_url );
			} else {
				processChunk( r.data.offset, total );
			}
		} ).fail( function () {
			if ( exportAborted ) { return; }
			resetExportUI();
			showExportResult( 'is-error', i18n.exportFailed );
		} );
	}

	function finishExportOk( filename, dlUrl ) {
		setExportProgress( 100, i18n.done, '' );
		showExportResult( 'is-success',
			'<strong>' + i18n.exportCompleted + '</strong> ' + filename +
			'<br><a class="sitemover-dl-link" href="' + dlUrl + '" download="' + filename + '">' +
			'<span class="dashicons dashicons-download"></span>' + i18n.downloadZip + '</a>'
		);
		setTimeout( function () {
			$( '#sitemover-export-progress' ).prop( 'hidden', true );
			$( '#sitemover-export-btn' ).prop( 'disabled', false );
		}, 1200 );
	}

	function resetExportUI() {
		$( '#sitemover-export-progress' ).prop( 'hidden', true );
		$( '#sitemover-export-btn' ).prop( 'disabled', false );
		setExportProgress( 0, '', '' );
	}

	// Cancel button
	$( '#sitemover-export-cancel' ).on( 'click', function () {
		exportAborted = true;
		if ( currentJobId ) {
			$.post( SiteMover.ajaxUrl, {
				action:  'sitemover_export_cancel',
				nonce:   SiteMover.nonce,
				job_id:  currentJobId,
			} );
			currentJobId = null;
		}
		resetExportUI();
	} );

	// Clean up orphaned job if user navigates away mid-export
	$( window ).on( 'beforeunload', function () {
		if ( currentJobId && ! exportAborted ) {
			var data = 'action=sitemover_export_cancel&nonce=' + encodeURIComponent( SiteMover.nonce ) + '&job_id=' + encodeURIComponent( currentJobId );
			if ( navigator.sendBeacon ) {
				navigator.sendBeacon( SiteMover.ajaxUrl, data );
			}
		}
	} );

	// ── Import ─────────────────────────────────────────────────────────────

	$( '#sitemover-import-btn' ).on( 'click', function () {
		if ( ! selectedFile ) { return; }

		var confirmed = window.confirm( i18n.importWarning );
		if ( ! confirmed ) { return; }

		var $btn    = $( this ).prop( 'disabled', true );
		var $prog   = $( '#sitemover-import-progress' ).prop( 'hidden', false );
		var $fill   = $( '#sitemover-import-fill' );
		var $pct    = $( '#sitemover-import-pct' );
		var $status = $( '#sitemover-import-status' );
		var $result = $( '#sitemover-import-result' ).removeClass( 'is-success is-error' ).empty();

		$fill.css( 'width', '2%' );
		$pct.text( '2%' );
		$status.text( i18n.uploading );

		var form = new FormData();
		form.append( 'action',   'sitemover_import' );
		form.append( 'nonce',    SiteMover.nonce );
		form.append( 'zip_file', selectedFile );

		$.ajax( {
			url:         SiteMover.ajaxUrl,
			type:        'POST',
			data:        form,
			processData: false,
			contentType: false,
			timeout:     600000,
			xhr: function () {
				var xhr = new XMLHttpRequest();
				xhr.upload.addEventListener( 'progress', function ( e ) {
					if ( e.lengthComputable ) {
						var p = Math.max( 2, Math.round( ( e.loaded / e.total ) * 60 ) );
						$fill.css( 'width', p + '%' );
						$pct.text( p + '%' );
						$status.text( i18n.uploading + ' ' + p + '%' );
					}
				} );
				return xhr;
			},
		} ).done( function ( r ) {
			$fill.css( 'width', '100%' );
			$pct.text( '100%' );
			$status.text( i18n.done );
			if ( r.success ) {
				$result.addClass( 'is-success' ).html(
					'<strong>' + i18n.importSuccess + '</strong><br>' + r.data.message +
					'<br><br><a href="' + window.location.origin + '" target="_blank">' + i18n.openSite + ' &rarr;</a>'
				);
			} else {
				$result.addClass( 'is-error' ).html( '<strong>' + i18n.error + ':</strong> ' + r.data.message );
			}
		} ).fail( function () {
			$result.addClass( 'is-error' ).html( i18n.importFailed );
		} ).always( function () {
			$prog.prop( 'hidden', true );
			$btn.prop( 'disabled', false );
		} );
	} );
} );
