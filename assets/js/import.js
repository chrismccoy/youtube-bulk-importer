/**
 * YouTube Bulk Importer admin page.
 *
 * Handles the search (with Load more paging), the result cards in grid or list
 * view, the edit dialog with its embedded player, and the import queue (two
 * videos at a time).
 */
( function () {
	'use strict';

	const configEl = document.getElementById( 'ytbi-config' );
	if ( ! configEl ) {
		return;
	}

	const config = JSON.parse( configEl.textContent );
	const i18n = config.i18n;
	const byId = ( id ) => document.getElementById( id );

	const form = byId( 'ytbi-search' );
	const submitButton = form.querySelector( 'button[type="submit"]' );
	const notice = byId( 'ytbi-notice' );
	const toolbar = byId( 'ytbi-toolbar' );
	const results = byId( 'ytbi-results' );
	const template = byId( 'ytbi-card' );
	const selectAll = byId( 'ytbi-select-all' );
	const categorySelect = byId( 'ytbi-category' );
	const statusSelect = byId( 'ytbi-status' );
	const importButton = byId( 'ytbi-import' );
	const progress = byId( 'ytbi-progress' );
	const moreButton = byId( 'ytbi-more' );
	const clearButton = byId( 'ytbi-clear' );
	const hideImportedButton = byId( 'ytbi-hide-imported' );
	const dialog = byId( 'ytbi-dialog' );
	const viewButtons = toolbar.querySelectorAll( '.ytbi-view' );

	const FILTERS = [ 'order', 'published' ];
	const CONCURRENCY = 2;
	const VIEW_KEY = 'ytbi-view';
	const VIDEO_ID = /^[A-Za-z0-9_-]{11}$/;

	const state = {
		boot: null,
		query: null,
		next: null,
		rows: new Map(),
		busy: false,
		queue: [],
		workers: 0,
		total: 0,
		done: 0,
		editing: null,
	};

	/**
	 * Call a REST route with the nonce header.
	 */
	async function api( path, { method = 'GET', params = null, body = null } = {} ) {
		const url = new URL( config.root + path, window.location.href );
		if ( params ) {
			Object.entries( params ).forEach( ( [ key, value ] ) => {
				if ( value !== '' && value !== null && value !== undefined ) {
					url.searchParams.set( key, value );
				}
			} );
		}

		const response = await fetch( url.toString(), {
			method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce,
				'Content-Type': 'application/json',
			},
			body: body ? JSON.stringify( body ) : null,
		} );

		let data = null;
		try {
			data = await response.json();
		} catch ( e ) {
			data = null;
		}

		if ( ! response.ok ) {
			const error = new Error( ( data && data.message ) || response.statusText || 'Request failed' );
			error.status = response.status;
			error.data = ( data && data.data ) || {};
			throw error;
		}
		return data;
	}

	/**
	 * An import is running
	 */
	function isImporting() {
		return state.workers > 0;
	}

	/**
	 * Show a message in the page notice.
	 */
	function showNotice( message ) {
		notice.querySelector( 'p' ).textContent = message;
		notice.hidden = false;
	}

	/**
	 * Hide the page notice.
	 */
	function hideNotice() {
		notice.hidden = true;
	}

	/**
	 * Enable or disable the controls for the current state. A search or an
	 * import locks the buttons. Select all, category, and status lock only
	 * during an import
	 */
	function updateControls() {
		const locked = state.busy || isImporting();
		submitButton.disabled = locked || ! state.boot || ! state.boot.has_api_key;
		moreButton.hidden = ! state.next;
		moreButton.disabled = locked;
		importButton.disabled = locked;
		clearButton.disabled = locked;
		hideImportedButton.disabled = locked;
		hideImportedButton.hidden = ! results.querySelector( '.ytbi-card[data-state="done"]' );
		selectAll.disabled = isImporting();
		categorySelect.disabled = isImporting();
		statusSelect.disabled = isImporting();
	}

	/**
	 * The "done / total" counter for the current import run.
	 */
	function updateProgress() {
		progress.textContent = state.total ? `${ state.done } / ${ state.total }` : '';
	}

	/**
	 * Seconds as m:ss, or h:mm:ss for an hour or more.
	 */
	function formatDuration( seconds ) {
		const h = Math.floor( seconds / 3600 );
		const m = Math.floor( ( seconds % 3600 ) / 60 );
		const s = seconds % 60;
		const pad = ( n ) => String( n ).padStart( 2, '0' );
		return h ? `${ h }:${ pad( m ) }:${ pad( s ) }` : `${ m }:${ pad( s ) }`;
	}

	/**
	 * Compact view counts (1.2K, 3.4M)
	 */
	const viewFormat = new Intl.NumberFormat( document.documentElement.lang || undefined, {
		notation: 'compact',
		maximumFractionDigits: 1,
	} );

	/**
	 * The translated "N views" label.
	 */
	function formatViews( views ) {
		return i18n.views.replace( '%s', viewFormat.format( views ) );
	}

	/**
	 * Switch between grid and list and remember the choice.
	 */
	function setView( view ) {
		results.classList.toggle( 'is-list', view === 'list' );
		viewButtons.forEach( ( button ) => {
			button.setAttribute( 'aria-pressed', String( button.dataset.view === view ) );
		} );
		try {
			window.localStorage.setItem( VIEW_KEY, view );
		} catch ( e ) {}
	}

	/**
	 * The remembered view. localStorage can throw when storage is blocked, so
	 * a failure falls back to grid.
	 */
	function savedView() {
		try {
			return window.localStorage.getItem( VIEW_KEY ) === 'list' ? 'list' : 'grid';
		} catch ( e ) {
			return 'grid';
		}
	}

	/**
	 * Select or deselect a card. The checkbox, the card class, and the
	 * thumbnail's aria-pressed are kept. Imported cards can't be
	 * selected.
	 */
	function setSelected( card, selected ) {
		if ( card.dataset.state === 'done' ) {
			return;
		}
		card.querySelector( '.ytbi-select' ).checked = selected;
		card.classList.toggle( 'is-selected', selected );
		card.querySelector( '.ytbi-thumb' ).setAttribute( 'aria-pressed', String( selected ) );
	}

	/**
	 * Set a card's state (queued, importing, done, error) and its status text.
	 */
	function setCardState( card, name, text ) {
		card.dataset.state = name;
		card.querySelector( '.ytbi-state' ).textContent = text;
		card.querySelector( '.ytbi-retry' ).hidden = name !== 'error';
	}

	/**
	 * Lock an imported card's fields and point its link at the new post's edit screen.
	 */
	function markImported( card, editUrl ) {
		setSelected( card, false );
		card.classList.remove( 'is-selected' );
		setCardState( card, 'done', i18n.imported );
		card.querySelectorAll( 'input, textarea, .ytbi-edit, .ytbi-thumb' ).forEach( ( el ) => {
			el.disabled = true;
		} );
		const link = card.querySelector( '.ytbi-link' );
		if ( editUrl ) {
			link.href = editUrl;
			link.textContent = i18n.editPost;
			link.hidden = false;
		}
	}

	/**
	 * Copy the title field into the card heading.
	 */
	function syncTitle( card ) {
		card.querySelector( '.ytbi-card-title' ).textContent = card.querySelector( '.ytbi-title' ).value;
	}

	/**
	 * One result card, cloned from the template. The description and tags
	 * fields show only when the settings import them. A video that is already
	 * imported starts locked.
	 */
	function buildCard( video ) {
		const card = template.content.firstElementChild.cloneNode( true );
		card.dataset.id = video.id;

		const img = card.querySelector( 'img' );
		if ( video.thumb_url ) {
			img.src = video.thumb_url;
		}
		img.alt = video.title;
		card.querySelector( '.ytbi-duration' ).textContent = formatDuration( video.duration_s );
		const meta = [ video.published.slice( 0, 10 ) ];
		if ( typeof video.views === 'number' ) {
			meta.unshift( formatViews( video.views ) );
		}
		card.querySelector( '.ytbi-meta' ).textContent = meta.join( ' · ' );

		card.querySelector( '.ytbi-title' ).value = video.title;
		card.querySelector( '.ytbi-desc' ).value = video.description;
		card.querySelector( '.ytbi-desc-field' ).hidden = ! state.boot.import_description;
		card.querySelector( '.ytbi-tags' ).value = video.tags.join( ', ' );
		card.querySelector( '.ytbi-tags-field' ).hidden = ! state.boot.import_tags;
		syncTitle( card );

		card.querySelector( '.ytbi-title' ).addEventListener( 'input', () => syncTitle( card ) );
		card.querySelector( '.ytbi-thumb' ).addEventListener( 'click', () => {
			setSelected( card, ! card.querySelector( '.ytbi-select' ).checked );
		} );
		card.querySelector( '.ytbi-select' ).addEventListener( 'change', ( event ) => {
			setSelected( card, event.target.checked );
		} );
		card.querySelector( '.ytbi-edit' ).addEventListener( 'click', () => openEditor( card, video ) );
		card.querySelector( '.ytbi-retry' ).addEventListener( 'click', () => enqueue( [ video.id ] ) );

		if ( video.imported ) {
			markImported( card, video.edit_url );
		}
		return card;
	}

	/**
	 * Show search results. A new search replaces the grid. Load more appends,
	 * skips videos already shown, and selects the new cards when Select all is
	 * checked.
	 */
	function render( videos, append ) {
		if ( ! append ) {
			results.replaceChildren();
			state.rows.clear();
			selectAll.checked = false;
			state.total = 0;
			state.done = 0;
			updateProgress();
		}

		const fresh = videos.filter( ( video ) => ! state.rows.has( video.id ) );
		if ( ! append && ! fresh.length ) {
			toolbar.hidden = true;
			showNotice( i18n.noResults );
			return;
		}

		fresh.forEach( ( video ) => {
			const card = buildCard( video );
			if ( append && selectAll.checked ) {
				setSelected( card, true );
			}
			state.rows.set( video.id, card );
			results.append( card );
		} );
		toolbar.hidden = false;
	}

	/**
	 * Open the edit dialog for a card.
	 */
	function openEditor( card, video ) {
		state.editing = card;
		dialog.querySelector( '.ytbi-dialog-fields' ).append( card.querySelector( '.ytbi-fields' ) );
		dialog.querySelector( '.ytbi-yt-link' ).href = video.url;

		const player = dialog.querySelector( '.ytbi-player' );
		player.replaceChildren();
		if ( VIDEO_ID.test( video.id ) ) {
			const frame = document.createElement( 'iframe' );
			frame.src = 'https://www.youtube-nocookie.com/embed/' + video.id;
			frame.title = video.title;
			frame.allow = 'encrypted-media; picture-in-picture';
			frame.allowFullscreen = true;
			player.append( frame );
		}
		dialog.showModal();
	}

	/**
	 * Move the fields back to their card and empty the player, which stops the video.
	 */
	dialog.addEventListener( 'close', () => {
		const card = state.editing;
		state.editing = null;
		dialog.querySelector( '.ytbi-player' ).replaceChildren();
		if ( card ) {
			card.querySelector( '.ytbi-actions' ).before( dialog.querySelector( '.ytbi-fields' ) );
			syncTitle( card );
		}
	} );

	/**
	 * Run a search, one at a time. The query is kept for Load more.
	 */
	async function search( params, append ) {
		if ( state.busy || isImporting() ) {
			return;
		}
		state.busy = true;
		updateControls();
		hideNotice();

		try {
			const data = await api( 'search', { params } );
			state.query = params;
			state.next = data.next_page_token;
			render( data.videos, append );
		} catch ( error ) {
			showNotice( error.message );
		} finally {
			state.busy = false;
			updateControls();
		}
	}

	/**
	 * The search form as a plain object.
	 */
	function formParams() {
		const params = {};
		new FormData( form ).forEach( ( value, key ) => {
			params[ key ] = value;
		} );
		return params;
	}

	/**
	 * The import request for one card. Description and tags are sent only when
	 * the settings import them, and tags are split on commas.
	 */
	function collectBody( id, card ) {
		const body = {
			video_id: id,
			title: card.querySelector( '.ytbi-title' ).value,
			status: statusSelect.value,
		};
		if ( state.boot.import_description ) {
			body.description = card.querySelector( '.ytbi-desc' ).value;
		}
		if ( categorySelect.value ) {
			body.category = parseInt( categorySelect.value, 10 );
		}
		if ( state.boot.import_tags ) {
			body.tags = card
				.querySelector( '.ytbi-tags' )
				.value.split( ',' )
				.map( ( tag ) => tag.trim() )
				.filter( Boolean );
		}
		return body;
	}

	/**
	 * Import one video and update its card. A 409 means the video is already
	 * imported, or is being imported right now, so the card is marked done,
	 * with the existing post's edit link
	 */
	async function importOne( id ) {
		const card = state.rows.get( id );
		if ( ! card ) {
			return;
		}
		setCardState( card, 'importing', i18n.importing );

		try {
			const result = await api( 'import', { method: 'POST', body: collectBody( id, card ) } );
			markImported( card, result.edit_url );
			if ( result.warnings && result.warnings.length ) {
				card.querySelector( '.ytbi-state' ).textContent += ' — ' + result.warnings.join( ' ' );
			}
		} catch ( error ) {
			if ( error.status === 409 ) {
				markImported( card, error.data.edit_url || null );
			} else {
				setCardState( card, 'error', error.message );
			}
		}
	}

	/**
	 * Take videos off the shared queue until it is empty
	 */
	async function worker() {
		state.workers++;
		updateControls();
		while ( state.queue.length ) {
			await importOne( state.queue.shift() );
			state.done++;
			updateProgress();
		}
		state.workers--;
		updateControls();
	}

	/**
	 * Queue videos for import
	 */
	function enqueue( ids ) {
		const fresh = ids.filter( ( id ) => ! state.queue.includes( id ) );
		if ( ! fresh.length ) {
			return;
		}
		if ( ! isImporting() ) {
			state.total = 0;
			state.done = 0;
		}
		fresh.forEach( ( id ) => setCardState( state.rows.get( id ), 'queued', i18n.queued ) );
		state.queue.push( ...fresh );
		state.total += fresh.length;
		updateProgress();

		const needed = Math.min( CONCURRENCY - state.workers, state.queue.length );
		for ( let i = 0; i < needed; i++ ) {
			worker();
		}
	}

	/**
	 * Load the API key present, categories, statuses, default status, import toggles
	 */
	async function boot() {
		setView( savedView() );

		try {
			state.boot = await api( 'bootstrap' );
		} catch ( error ) {
			showNotice( error.message );
			return;
		}

		categorySelect.append( new Option( i18n.noCategory, '' ) );
		state.boot.categories.forEach( ( category ) => {
			categorySelect.append( new Option( category.name, String( category.id ) ) );
		} );

		state.boot.statuses.forEach( ( status ) => {
			const selected = status.value === state.boot.post_status;
			statusSelect.append( new Option( status.label, status.value, selected, selected ) );
		} );

		updateControls();
	}

	/**
	 * The search box clears only after a successful search
	 */
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const input = byId( 'ytbi-q' );
		const term = input.value;
		await search( formParams(), false );
		if ( notice.hidden && state.query && state.query.q === term && input.value === term ) {
			input.value = '';
		}
	} );

	/**
	 * Order and published date filters don't apply to playlists, so they're
	 * disabled.
	 */
	form.elements.namedItem( 'type' ).addEventListener( 'change', ( event ) => {
		const isPlaylist = event.target.value === 'playlist';
		FILTERS.forEach( ( name ) => {
			form.elements.namedItem( name ).disabled = isPlaylist;
		} );
	} );

	/**
	 * Empty the results and reset the search, unless an import is running.
	 */
	function clearResults() {
		if ( isImporting() ) {
			return;
		}
		results.replaceChildren();
		state.rows.clear();
		state.query = null;
		state.next = null;
		state.total = 0;
		state.done = 0;
		selectAll.checked = false;
		toolbar.hidden = true;
		hideNotice();
		updateProgress();
		updateControls();
		byId( 'ytbi-q' ).focus();
	}

	/**
	 * Remove imported cards from the grid.
	 */
	function hideImported() {
		if ( isImporting() ) {
			return;
		}
		state.rows.forEach( ( card, id ) => {
			if ( card.dataset.state === 'done' ) {
				card.remove();
				state.rows.delete( id );
			}
		} );
		updateControls();
	}

	clearButton.addEventListener( 'click', clearResults );
	hideImportedButton.addEventListener( 'click', hideImported );

	moreButton.addEventListener( 'click', () => search( { ...state.query, page_token: state.next }, true ) );

	viewButtons.forEach( ( button ) => {
		button.addEventListener( 'click', () => setView( button.dataset.view ) );
	} );

	selectAll.addEventListener( 'change', () => {
		state.rows.forEach( ( card ) => setSelected( card, selectAll.checked ) );
	} );

	importButton.addEventListener( 'click', () => {
		const ids = [];
		state.rows.forEach( ( card, id ) => {
			if ( card.dataset.state !== 'done' && card.querySelector( '.ytbi-select' ).checked ) {
				ids.push( id );
			}
		} );
		enqueue( ids );
	} );

	/**
	 * Warn before leaving the page while an import is running.
	 */
	window.addEventListener( 'beforeunload', ( event ) => {
		if ( isImporting() ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	boot();
} )();
