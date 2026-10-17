/**
 * Contact flyout (includes/class-flyout.php): opens and closes the panel, switches between the three
 * options and the short form, and sends the form in the background to admin-ajax.php.
 */
( function () {
	'use strict';

	var cfg = window.tsFlyout;
	var root = document.getElementById( 'ts-flyout' );
	if ( ! cfg || ! root ) {
		return;
	}

	var toggle = root.querySelector( '.ts-flyout__toggle' );
	var panel = root.querySelector( '.ts-flyout__panel' );
	var head = root.querySelector( '.ts-flyout__head' );
	var menu = root.querySelector( '.ts-flyout__menu' );
	var form = root.querySelector( '.ts-flyout__form' );
	var done = root.querySelector( '.ts-flyout__done' );
	var openForm = root.querySelector( '[data-ts-flyout-form]' );
	var back = root.querySelector( '[data-ts-flyout-back]' );
	var submit = form.querySelector( '.ts-flyout__submit' );
	var nonce = root.getAttribute( 'data-nonce' );
	var chat = root.querySelector( '.ts-chat' ); // "Ask about Oman" (assets/chatbot.js); hidden whenever another view shows.

	root.classList.add( 'ts-flyout--js' );

	function setOpen( open ) {
		root.classList.toggle( 'is-open', open );
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) {
			showMenu();
		}
	}

	function announce( view ) {
		// Extension point: other views (Alia) listen and hide themselves when another view shows.
		root.dispatchEvent( new CustomEvent( 'ts-flyout:view', { detail: { view: view } } ) );
	}

	function showMenu() {
		head.hidden = false;
		menu.hidden = false;
		form.hidden = true;
		done.hidden = true;
		if ( chat ) {
			chat.hidden = true;
		}
		announce( 'menu' );
	}

	function showForm() {
		head.hidden = true;
		menu.hidden = true;
		done.hidden = true;
		if ( chat ) {
			chat.hidden = true;
		}
		form.hidden = false;
		announce( 'form' );
		var first = form.querySelector( 'input[name="full_name"]' );
		if ( first ) {
			first.focus();
		}
	}

	function clearErrors() {
		var errs = form.querySelectorAll( '.ts-flyout__err' );
		Array.prototype.forEach.call( errs, function ( el ) {
			el.hidden = true;
			el.textContent = '';
		} );
		Array.prototype.forEach.call( form.querySelectorAll( '.has-error' ), function ( el ) {
			el.classList.remove( 'has-error' );
		} );
	}

	function showError( key, text ) {
		var el = form.querySelector( '[data-err="' + key + '"]' );
		if ( ! el ) {
			return;
		}
		el.textContent = text;
		el.hidden = false;
		var field = el.closest( '.ts-flyout__field' );
		if ( field ) {
			field.classList.add( 'has-error' );
		}
	}

	function validate() {
		var bad = null;
		var name = form.elements.full_name.value.trim();
		var email = form.elements.email.value.trim();
		var message = form.elements.message.value.trim();
		if ( ! name ) {
			showError( 'name', cfg.errors.name );
			bad = bad || form.elements.full_name;
		}
		if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
			showError( 'email', cfg.errors.email );
			bad = bad || form.elements.email;
		}
		if ( ! message ) {
			showError( 'message', cfg.errors.message );
			bad = bad || form.elements.message;
		}
		if ( ! form.elements.consent.checked ) {
			showError( 'consent', cfg.errors.consent );
			bad = bad || form.elements.consent;
		}
		if ( bad ) {
			bad.focus();
		}
		return ! bad;
	}

	function post( retried ) {
		var body = new FormData( form );
		body.set( 'action', cfg.action );
		body.set( 'nonce', nonce );
		body.set( 'post_id', root.getAttribute( 'data-post' ) || '0' );
		return fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( res ) {
				return res.json().catch( function () {
					return { success: false, data: { code: 'error' } };
				} );
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					return { ok: true };
				}
				var data = ( json && json.data ) || { code: 'error' };
				// A page kept in a cache can carry an old security code: ask for a new one, retry once.
				if ( 'expired' === data.code && ! retried ) {
					var ask = new FormData();
					ask.set( 'action', 'ts_flyout_nonce' );
					return fetch( cfg.ajaxUrl, { method: 'POST', body: ask, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( j ) {
							if ( j && j.success && j.data && j.data.nonce ) {
								nonce = j.data.nonce;
								return post( true );
							}
							return { ok: false, data: data };
						} );
				}
				return { ok: false, data: data };
			} );
	}

	toggle.addEventListener( 'click', function () {
		setOpen( ! root.classList.contains( 'is-open' ) );
	} );
	openForm.addEventListener( 'click', showForm );
	back.addEventListener( 'click', function () {
		showMenu();
		openForm.focus();
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && root.classList.contains( 'is-open' ) ) {
			setOpen( false );
			toggle.focus();
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( root.classList.contains( 'is-open' ) && ! root.contains( e.target ) ) {
			setOpen( false );
		}
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		clearErrors();
		if ( ! validate() ) {
			return;
		}
		submit.disabled = true;
		submit.textContent = cfg.sending;
		post( false )
			.then( function ( result ) {
				if ( result.ok ) {
					form.reset();
					form.hidden = true;
					done.hidden = false;
					done.focus();
					return;
				}
				var data = result.data || {};
				if ( 'invalid' === data.code && data.errors ) {
					Object.keys( data.errors ).forEach( function ( key ) {
						showError( key, cfg.errors[ key ] || cfg.errors.send );
					} );
				} else if ( 'wait' === data.code ) {
					showError( 'form', cfg.errors.wait );
				} else {
					showError( 'form', cfg.errors.send );
				}
			} )
			.catch( function () {
				showError( 'form', cfg.errors.send );
			} )
			.then( function () {
				submit.disabled = false;
				submit.textContent = cfg.send;
			} );
	} );
}() );
