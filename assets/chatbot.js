/**
 * "Ask about Oman" (includes/class-chatbot.php). Rule-based: the visitor's question is matched against
 * data/oman-faq.json in the browser. Nothing typed here is sent to the server or saved.
 * Order of rules: rule-bypass attempt, safety-of-travel, politics, FAQ match, contact fallback.
 */
( function () {
	'use strict';

	var cfg = window.tsChat;
	var root = document.getElementById( 'ts-flyout' );
	var chat = document.getElementById( 'ts-chat' );
	if ( ! cfg || ! root || ! chat ) {
		return;
	}

	var log = document.getElementById( 'ts-chat-log' );
	var chips = document.getElementById( 'ts-chat-chips' );
	var form = document.getElementById( 'ts-chat-form' );
	var input = document.getElementById( 'ts-chat-input' );
	var head = root.querySelector( '.ts-flyout__head' );
	var menu = root.querySelector( '.ts-flyout__menu' );
	var openChat = root.querySelector( '[data-ts-flyout-chat]' );
	var backBtn = chat.querySelector( '[data-ts-chat-back]' );
	var openForm = root.querySelector( '[data-ts-flyout-form]' );

	var data = null;
	var loading = null;
	var lastVariant = -1;

	var STOP = 'the a an is are was were be been am do does did can could should would will shall i you we they he she it my me our your to of in on at for and or but with about from by this that these those what whats how when where which who whom there their any some please tell know want need like get have has had if so as into than then just also oman omani'.split( ' ' );

	/* ----- text helpers ------------------------------------------------ */

	function normalize( text ) {
		return ' ' + String( text )
			.toLowerCase()
			.replace( /[‘’']/g, '' )
			.replace( /[^a-z0-9؀-ۿ]+/g, ' ' )
			.replace( /\s+/g, ' ' )
			.trim() + ' ';
	}

	function hasPhrase( norm, phrase ) {
		var p = normalize( phrase ).trim();
		if ( ! p ) {
			return false;
		}
		// Whole words, but a trailing stem such as "politic" also matches "politics" / "political".
		var stem = p.length >= 6 && p.indexOf( ' ' ) === -1;
		var re = new RegExp( '(?:^|\\s)' + p.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + ( stem ? '' : '(?:\\s|$)' ) );
		return re.test( norm );
	}

	function hasAny( norm, list ) {
		for ( var i = 0; i < ( list || [] ).length; i++ ) {
			if ( hasPhrase( norm, list[ i ] ) ) {
				return true;
			}
		}
		return false;
	}

	function tokens( norm ) {
		return norm.trim().split( ' ' ).filter( function ( t ) {
			return t.length > 3 && STOP.indexOf( t ) === -1;
		} );
	}

	/* ----- matching ---------------------------------------------------- */

	function bestFaq( norm ) {
		var qTokens = tokens( norm );
		var best = null;
		var bestScore = 0;
		data.faqs.forEach( function ( faq ) {
			var score = 0;
			var kwTokens = {};
			( faq.keywords || [] ).forEach( function ( kw ) {
				var n = normalize( kw ).trim();
				if ( ! n ) {
					return;
				}
				if ( hasPhrase( norm, n ) ) {
					score = Math.max( score, n.split( ' ' ).length * 3 + Math.min( n.length, 24 ) / 24 );
				}
				tokens( ' ' + n + ' ' ).forEach( function ( t ) {
					kwTokens[ t ] = true;
				} );
			} );
			var overlap = qTokens.filter( function ( t ) {
				return kwTokens[ t ];
			} ).length;
			if ( overlap >= 2 ) {
				score = Math.max( score, overlap * 1.6 );
			}
			if ( score > bestScore ) {
				bestScore = score;
				best = faq;
			}
		} );
		return bestScore >= 3 ? best : null;
	}

	/* ----- output ------------------------------------------------------ */

	function scrollDown() {
		log.scrollTop = log.scrollHeight;
	}

	function bubble( who, text ) {
		var el = document.createElement( 'div' );
		el.className = 'ts-chat__msg ts-chat__msg--' + who;
		if ( text ) {
			var p = document.createElement( 'p' );
			p.textContent = text;
			el.appendChild( p );
		}
		log.appendChild( el );
		return el;
	}

	function link( parent, label, href, external ) {
		var a = document.createElement( 'a' );
		a.className = 'ts-chat__link';
		a.textContent = label;
		a.href = href;
		if ( external ) {
			a.target = '_blank';
			a.rel = 'noopener';
		}
		parent.appendChild( a );
		return a;
	}

	function addNote( el, text ) {
		var p = document.createElement( 'p' );
		p.className = 'ts-chat__small';
		p.textContent = text;
		el.appendChild( p );
	}

	function addContact( el ) {
		var c = data.contact || {};
		var wrap = document.createElement( 'div' );
		wrap.className = 'ts-chat__contact';
		if ( c.tel ) {
			link( wrap, 'Call ' + ( c.phone || c.tel ), 'tel:' + c.tel, false );
		}
		if ( c.whatsapp ) {
			link( wrap, 'WhatsApp us', c.whatsapp, true );
		}
		if ( c.email ) {
			link( wrap, 'Email ' + c.email, 'mailto:' + c.email, false );
		}
		if ( c.contactUrl ) {
			link( wrap, 'Open our contact page', c.contactUrl, false );
		}
		if ( openForm ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'ts-chat__link ts-chat__link--btn';
			b.textContent = 'Send us an enquiry';
			b.addEventListener( 'click', function () {
				openForm.click();
			} );
			wrap.appendChild( b );
		}
		el.appendChild( wrap );
	}

	function addVisaLinks( el ) {
		var links = ( data.settings && data.settings.visa_links ) || [];
		if ( ! links.length ) {
			return;
		}
		var wrap = document.createElement( 'div' );
		wrap.className = 'ts-chat__contact';
		links.forEach( function ( l ) {
			if ( l && /^https:\/\//.test( l.url || '' ) ) {
				link( wrap, l.label || l.url, l.url, true );
			}
		} );
		el.appendChild( wrap );
	}

	function pickPolitical() {
		var v = ( data.settings && data.settings.political_decline_variants ) || [];
		if ( ! v.length ) {
			return 'I\'m a travel assistant and can\'t comment on political topics. Is there something about your trip I can help you with?';
		}
		var i = Math.floor( Math.random() * v.length );
		if ( v.length > 1 && i === lastVariant ) {
			i = ( i + 1 ) % v.length;
		}
		lastVariant = i;
		return v[ i ];
	}

	function respond( question ) {
		var s = data.settings || {};
		var norm = normalize( question );
		var el;

		if ( hasAny( norm, s.bypass_trigger_phrases ) ) {
			el = bubble( 'bot', s.bypass_reply || 'I can only help with travel questions about Oman.' );
			return el;
		}
		if ( hasAny( norm, s.safety_trigger_keywords ) ) {
			el = bubble( 'bot', s.safety_advisory_text );
			addContact( el );
			return el;
		}
		var politicsNorm = norm;
		( s.political_allow_phrases || [] ).forEach( function ( phrase ) {
			var p = normalize( phrase );
			politicsNorm = politicsNorm.split( p.trim() ).join( ' ' );
		} );
		if ( hasAny( normalize( politicsNorm ), s.political_trigger_keywords ) ) {
			return bubble( 'bot', pickPolitical() );
		}

		var faq = bestFaq( norm );
		if ( ! faq ) {
			el = bubble( 'bot', s.fallback_text );
			addContact( el );
			return el;
		}
		el = bubble( 'bot', faq.answer );
		if ( faq.visa ) {
			addNote( el, s.visa_caveat );
			addVisaLinks( el );
		} else if ( faq.volatile ) {
			addNote( el, s.volatile_note );
		}
		if ( faq.contact ) {
			addContact( el );
		}
		return el;
	}

	function ask( text ) {
		text = String( text || '' ).trim().slice( 0, 200 );
		if ( ! text ) {
			return;
		}
		bubble( 'user', text );
		var reply = respond( text );
		scrollDown();
		// Keep keyboard focus in the input; the live region announces the reply.
		if ( reply ) {
			input.value = '';
		}
		input.focus();
	}

	/* ----- data loading ------------------------------------------------ */

	function load() {
		if ( data ) {
			return Promise.resolve( data );
		}
		if ( loading ) {
			return loading;
		}
		loading = fetch( cfg.ajaxUrl + '?action=' + encodeURIComponent( cfg.action ), { credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success || ! j.data || ! j.data.faqs ) {
					throw new Error( 'bad data' );
				}
				data = j.data;
				return data;
			} )
			.catch( function ( e ) {
				loading = null;
				throw e;
			} );
		return loading;
	}

	function buildChips() {
		chips.textContent = '';
		var list = ( data.settings && data.settings.suggestions ) || [];
		list.forEach( function ( label ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'ts-chat__chip';
			b.textContent = label;
			b.addEventListener( 'click', function () {
				ask( label );
			} );
			chips.appendChild( b );
		} );
	}

	function start() {
		if ( log.getAttribute( 'data-started' ) ) {
			return;
		}
		log.setAttribute( 'data-started', '1' );
		bubble( 'bot', 'Hello! Ask me a general question about Oman, such as the best time to visit, money, transport or what to wear.' );
		load().then( function () {
			buildChips();
		} ).catch( function () {
			bubble( 'bot', 'Sorry, I could not load the answers just now. Please go back and choose another way to reach us.' );
			log.removeAttribute( 'data-started' );
		} );
	}

	/* ----- open / close ------------------------------------------------ */

	function showChat() {
		head.hidden = true;
		menu.hidden = true;
		var form2 = root.querySelector( '.ts-flyout__form' );
		var done = root.querySelector( '.ts-flyout__done' );
		if ( form2 ) {
			form2.hidden = true;
		}
		if ( done ) {
			done.hidden = true;
		}
		chat.hidden = false;
		start();
		input.focus();
	}

	if ( openChat ) {
		openChat.addEventListener( 'click', showChat );
	}
	backBtn.addEventListener( 'click', function () {
		chat.hidden = true;
		head.hidden = false;
		menu.hidden = false;
		if ( openChat ) {
			openChat.focus();
		}
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var q = input.value;
		load().then( function () {
			ask( q );
		} ).catch( function () {
			bubble( 'bot', 'Sorry, I could not load the answers just now. Please use the Contact us option instead.' );
		} );
	} );
}() );
