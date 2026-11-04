/**
 * YouTube Bulk Importer settings page: API key tester.
 */
( function () {
	'use strict';

	const configEl = document.getElementById( 'ytbi-settings-config' );
	const input = document.getElementById( 'ytbi_api_key' );
	const button = document.getElementById( 'ytbi_test_key' );
	const result = document.getElementById( 'ytbi_test_key_result' );
	if ( ! configEl || ! input || ! button || ! result ) {
		return;
	}

	const config = JSON.parse( configEl.textContent );
	const i18n = config.i18n;
	let busy = false;

	/**
	 * Test is available when a key is saved or one is typed.
	 */
	function syncButton() {
		button.disabled = busy || ( ! config.hasKey && input.value.trim() === '' );
	}

	/**
	 * Show the test result, styled by state (valid, warning, error).
	 */
	function show( message, state ) {
		result.textContent = message;
		result.className = 'ytbi-key-result' + ( state ? ' is-' + state : '' );
	}

	/**
	 * Test the key. A warning means the key works but its quota is used up.
	 */
	async function testKey() {
		const typed = input.value.trim();
		const label = button.textContent;
		busy = true;
		syncButton();
		button.textContent = i18n.testing;
		show( '', '' );

		try {
			const response = await fetch( config.root + 'test-key', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': config.nonce,
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( { api_key: typed } ),
			} );
			const data = await response.json().catch( () => null );

			if ( ! response.ok || ! data ) {
				show( ( data && data.message ) || i18n.failed, 'error' );
			} else if ( data.warning ) {
				show( data.warning, 'warning' );
			} else {
				show( data.source === 'typed' ? i18n.validTyped : i18n.validSaved, 'valid' );
			}
		} catch ( e ) {
			show( i18n.failed, 'error' );
		} finally {
			busy = false;
			button.textContent = label;
			syncButton();
		}
	}

	input.addEventListener( 'input', () => {
		show( '', '' );
		syncButton();
	} );
	button.addEventListener( 'click', testKey );
	syncButton();
} )();
