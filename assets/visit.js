/**
 * TS Enquiries 1.3.0: remember how this visit began (for this browser tab only, no cookie) and send
 * it with any enquiry form on the page as the hidden field "ts_visit". Kept: first page, referring
 * site, campaign tags, whether an ad click code was present (yes/no only), device, browser language.
 */
( function () {
	'use strict';
	var KEY = 'tsEnqVisit';
	var store;
	try {
		store = window.sessionStorage;
		store.setItem( KEY + '_t', '1' );
		store.removeItem( KEY + '_t' );
	} catch ( e ) {
		store = null;
	}

	function read() {
		if ( ! store ) {
			return null;
		}
		try {
			return JSON.parse( store.getItem( KEY ) || 'null' );
		} catch ( e ) {
			return null;
		}
	}

	function begin() {
		var p = new URLSearchParams( window.location.search );
		var v = { landing: window.location.pathname.slice( 0, 191 ), t: String( Date.now() ) };
		var ref = '';
		try {
			ref = document.referrer ? new URL( document.referrer ).hostname : '';
		} catch ( e ) {
			ref = '';
		}
		if ( ref && ref !== window.location.hostname ) {
			v.ref = ref;
		}
		[ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ].forEach( function ( k ) {
			if ( p.get( k ) ) {
				v[ k ] = p.get( k ).slice( 0, 150 );
			}
		} );
		if ( p.get( 'gclid' ) || p.get( 'gbraid' ) || p.get( 'wbraid' ) ) {
			v.gads = '1';
		}
		if ( p.get( 'fbclid' ) ) {
			v.meta = '1';
		}
		var w = window.innerWidth || 0;
		v.device = /Mobi|Android.+Mobile|iPhone/.test( navigator.userAgent ) || w < 600 ? 'mobile' : ( w < 1024 ? 'tablet' : 'desktop' );
		v.lang = ( navigator.language || '' ).slice( 0, 10 );
		return v;
	}

	var visit = read();
	if ( ! visit ) {
		visit = begin();
		if ( store ) {
			store.setItem( KEY, JSON.stringify( visit ) );
		}
	}
	window.tsEnqVisit = visit;

	// Add the field just before any form on the page is sent (runs before the forms' own handlers).
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form || 'FORM' !== form.tagName ) {
			return;
		}
		var field = form.querySelector( 'input[name="ts_visit"]' );
		if ( ! field ) {
			field = document.createElement( 'input' );
			field.type = 'hidden';
			field.name = 'ts_visit';
			form.appendChild( field );
		}
		field.value = JSON.stringify( visit );
	}, true );
}() );
