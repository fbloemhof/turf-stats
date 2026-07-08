( function () {
	'use strict';

	if ( typeof turfImportLegacy === 'undefined' ) {
		return;
	}

	var form           = document.getElementById( 'turf-import-form' );
	var startBtn       = document.getElementById( 'turf-import-start' );
	var sourceSelect   = document.getElementById( 'turf-import-source' );
	var forceCheckbox  = document.getElementById( 'turf-import-force' );
	var dryRunCheckbox = document.getElementById( 'turf-import-dry-run' );
	var progressWrap   = document.getElementById( 'turf-import-progress' );
	var progressBar    = document.getElementById( 'turf-import-progress-bar' );
	var progressText   = document.getElementById( 'turf-import-progress-text' );
	var resultsEl      = document.getElementById( 'turf-import-results' );

	if ( ! form || ! startBtn ) {
		return;
	}

	// The Jetpack source calls out to Jetpack's API and sleeps 200ms per post
	// on purpose (see turf_legacy_get_views()), so small batches keep each
	// AJAX request quick. entry-views is a plain postmeta read and can move
	// through much larger batches.
	var BATCH_SIZE = { jetpack: 10, 'entry-views': 100 };

	function chunk( list, size ) {
		var out = [];
		for ( var i = 0; i < list.length; i += size ) {
			out.push( list.slice( i, i + size ) );
		}
		return out;
	}

	function format( template, values ) {
		return template.replace( /%(\d)\$[ds]/g, function ( match, index ) {
			return values[ index - 1 ];
		} );
	}

	function runBatch( source, ids, force, dryRun ) {
		var body = new URLSearchParams();
		body.set( 'action', 'turf_import_legacy_batch' );
		body.set( 'nonce', turfImportLegacy.nonce );
		body.set( 'source', source );
		body.set( 'force', force ? '1' : '' );
		body.set( 'dry_run', dryRun ? '1' : '' );
		ids.forEach( function ( id ) { body.append( 'post_ids[]', id ); } );

		return fetch( turfImportLegacy.ajaxUrl, { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } );
	}

	/**
	 * Runs one source's batches strictly one at a time (not in parallel -
	 * that would defeat the point of the deliberate Jetpack rate-limit
	 * delay), updating the progress bar after each, and resolves with the
	 * summed totals for that source.
	 */
	function runSource( source, force, dryRun ) {
		var batches = chunk( turfImportLegacy.postIds, BATCH_SIZE[ source ] || 20 );
		var totals  = { imported: 0, skipped: 0, empty: 0 };
		var done    = 0;

		function next( i ) {
			if ( i >= batches.length ) {
				return Promise.resolve( totals );
			}

			return runBatch( source, batches[ i ], force, dryRun ).then( function ( response ) {
				if ( response && response.success ) {
					totals.imported += response.data.imported;
					totals.skipped  += response.data.skipped;
					totals.empty    += response.data.empty;
				}

				done += batches[ i ].length;
				progressBar.value = done;
				progressText.textContent = format( turfImportLegacy.i18n.progress, [ source, done, turfImportLegacy.postIds.length ] );

				return next( i + 1 );
			} );
		}

		return next( 0 );
	}

	function runSources( sources, force, dryRun ) {
		var results = {};

		function next( i ) {
			if ( i >= sources.length ) {
				return Promise.resolve( results );
			}

			return runSource( sources[ i ], force, dryRun ).then( function ( totals ) {
				results[ sources[ i ] ] = totals;
				return next( i + 1 );
			} );
		}

		return next( 0 );
	}

	startBtn.addEventListener( 'click', function () {
		var source = sourceSelect.value;
		var force  = forceCheckbox.checked;
		var dryRun = dryRunCheckbox.checked;

		var sources = ( 'all' === source ? [ 'jetpack', 'entry-views' ] : [ source ] ).filter( function ( s ) {
			return 'jetpack' !== s || turfImportLegacy.jetpackActive;
		} );

		if ( ! sources.length || ! turfImportLegacy.postIds.length ) {
			return;
		}

		startBtn.disabled = true;
		resultsEl.innerHTML = '';
		progressWrap.style.display = '';
		progressBar.max = turfImportLegacy.postIds.length;
		progressBar.value = 0;
		progressText.textContent = turfImportLegacy.i18n.running;

		runSources( sources, force, dryRun ).then( function ( results ) {
			progressWrap.style.display = 'none';

			var summary = document.createElement( 'p' );
			summary.textContent = turfImportLegacy.i18n.done;
			resultsEl.appendChild( summary );

			var list = document.createElement( 'ul' );

			sources.forEach( function ( s ) {
				var totals = results[ s ];
				var li     = document.createElement( 'li' );

				li.textContent = format( turfImportLegacy.i18n.result, [ s, totals.imported, totals.skipped, totals.empty ] ) +
					( dryRun ? turfImportLegacy.i18n.dryRun : '' );

				list.appendChild( li );
			} );

			resultsEl.appendChild( list );
			startBtn.disabled = false;
		} ).catch( function () {
			progressWrap.style.display = 'none';
			resultsEl.textContent = turfImportLegacy.i18n.error;
			startBtn.disabled = false;
		} );
	} );
}() );
