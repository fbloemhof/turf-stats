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

	// How many batches of a source run at once. entry-views is already
	// near-instant serially, so there's nothing to gain from parallelizing
	// it. jetpack's batches are each slow (per-post Jetpack API calls) but
	// independent - running two at once roughly halves wall-clock time.
	// Kept deliberately low: the server-side 200ms/post delay exists to stay
	// under Jetpack's API rate limits, and a throttled stats_get_csv() call
	// returns empty (indistinguishable from "post has no views"), so pushing
	// the request rate higher risks silently importing nothing for whole
	// batches rather than failing loudly.
	var CONCURRENCY = { jetpack: 2, 'entry-views': 1 };

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
	 * One-shot "top posts" pre-pass for the jetpack source - see
	 * turf_legacy_import_jetpack_top_posts() (includes/legacy-import.php).
	 * Covers up to ~100 posts in a single request, before the per-post
	 * batches below run for the rest.
	 */
	function runBulkPrepass( force, dryRun ) {
		var body = new URLSearchParams();
		body.set( 'action', 'turf_import_legacy_bulk_prepass' );
		body.set( 'nonce', turfImportLegacy.nonce );
		body.set( 'force', force ? '1' : '' );
		body.set( 'dry_run', dryRun ? '1' : '' );

		return fetch( turfImportLegacy.ajaxUrl, { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } );
	}

	/**
	 * Runs one source's batches through a small worker pool (see
	 * CONCURRENCY above) instead of strictly one at a time, updating the
	 * progress bar as each batch completes (order doesn't matter for a
	 * running total), and resolves with the summed totals for that source.
	 *
	 * @param ids        The post IDs to process for this source (may be
	 *                   fewer than the site's full eligible list - see the
	 *                   jetpack bulk-prepass exclusion in runSources()).
	 * @param seedTotals Counts already tallied before this call (e.g. by the
	 *                   bulk prepass) - added to, not replaced.
	 * @param seedDone   Posts already accounted for before this call, so the
	 *                   progress bar reflects them immediately.
	 */
	function runSource( source, ids, force, dryRun, seedTotals, seedDone ) {
		var batches = chunk( ids, BATCH_SIZE[ source ] || 20 );
		var totals  = {
			imported: ( seedTotals && seedTotals.imported ) || 0,
			skipped:  ( seedTotals && seedTotals.skipped )  || 0,
			empty:    ( seedTotals && seedTotals.empty )    || 0,
			// Posts whose batch request errored (network failure, non-success
			// response) - tallied and reported instead of silently dropped, so
			// the user knows to re-run the import for them.
			failed:   0
		};
		var done      = seedDone || 0;
		var cursor    = 0;
		var poolSize  = Math.max( 1, Math.min( CONCURRENCY[ source ] || 1, batches.length ) );

		progressBar.value = done;
		progressText.textContent = format( turfImportLegacy.i18n.progress, [ source, done, turfImportLegacy.postIds.length ] );

		function runNext() {
			if ( cursor >= batches.length ) {
				return Promise.resolve();
			}

			var i = cursor++;

			// A failed batch must not abort the whole pool (the other posts
			// are still importable) - convert any rejection into a null
			// response and count the batch under `failed` below.
			return runBatch( source, batches[ i ], force, dryRun )
				.catch( function () { return null; } )
				.then( function ( response ) {
					if ( response && response.success ) {
						totals.imported += response.data.imported;
						totals.skipped  += response.data.skipped;
						totals.empty    += response.data.empty;
					} else {
						totals.failed += batches[ i ].length;
					}

					done += batches[ i ].length;
					progressBar.value = done;
					progressText.textContent = format( turfImportLegacy.i18n.progress, [ source, done, turfImportLegacy.postIds.length ] );

					return runNext();
				} );
		}

		if ( ! batches.length ) {
			return Promise.resolve( totals );
		}

		var workers = [];
		for ( var w = 0; w < poolSize; w++ ) {
			workers.push( runNext() );
		}

		return Promise.all( workers ).then( function () {
			return totals;
		} );
	}

	function runSources( sources, force, dryRun ) {
		var results = {};

		function next( i ) {
			if ( i >= sources.length ) {
				return Promise.resolve( results );
			}

			var source = sources[ i ];

			if ( 'jetpack' === source ) {
				// A failed prepass isn't fatal - fall through with an empty
				// handled list and let the normal per-post batches cover
				// everything (the prepass is purely a speed optimization).
				return runBulkPrepass( force, dryRun ).catch( function () { return null; } ).then( function ( response ) {
					var seedTotals = { imported: 0, skipped: 0, empty: 0 };
					var handledIds = [];

					if ( response && response.success ) {
						seedTotals = response.data.counts;
						handledIds = response.data.handledPostIds || [];
					}

					var handled = new Set( handledIds );
					var remainingIds = turfImportLegacy.postIds.filter( function ( id ) {
						return ! handled.has( id );
					} );

					return runSource( source, remainingIds, force, dryRun, seedTotals, handledIds.length ).then( function ( totals ) {
						results[ source ] = totals;
						return next( i + 1 );
					} );
				} );
			}

			return runSource( source, turfImportLegacy.postIds, force, dryRun ).then( function ( totals ) {
				results[ source ] = totals;
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
					( dryRun ? turfImportLegacy.i18n.dryRun : '' ) +
					( totals.failed ? ' ' + format( turfImportLegacy.i18n.failed, [ totals.failed ] ) : '' );

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
