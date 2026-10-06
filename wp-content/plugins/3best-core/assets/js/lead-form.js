/**
 * 3Best — lead form, consent and phone verification.
 *
 * Ported from the React LeadForm and OTPVerification components. Three stages
 * live in the DOM and are switched here; nothing is fetched to move between
 * them except the two verification calls and the final submission.
 *
 * The server is authoritative throughout. This file gives immediate feedback,
 * but every rule it applies is applied again server-side, and the verified
 * state is a token the server issued — never a flag this code can set.
 */
( function () {
	'use strict';

	var settings = window.threebestLead;

	if ( ! settings || ! settings.restUrl ) {
		return;
	}

	var S = settings.strings || {};
	var STORAGE_KEY = ( settings.config && settings.config.storageKey ) || '3best:selected-location';
	var OTP_LENGTH = ( settings.config && settings.config.otpLength ) || 6;

	function post( path, body ) {
		return window.fetch( settings.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				'X-WP-Nonce': settings.nonce
			},
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return null;
			} ).then( function ( data ) {
				return { ok: response.ok, status: response.status, body: data };
			} );
		} );
	}

	function LeadForm( root ) {
		var stages = {
			form: root.querySelector( '[data-tb-stage="form"]' ),
			otp: root.querySelector( '[data-tb-stage="otp"]' ),
			success: root.querySelector( '[data-tb-stage="success"]' )
		};

		var form = root.querySelector( '[data-tb-lead]' );
		var otpForm = root.querySelector( '[data-tb-otp-form]' );
		var submit = root.querySelector( '[data-tb-submit]' );
		var submitLabel = root.querySelector( '[data-tb-submit-label]' );
		var note = root.querySelector( '[data-tb-note]' );
		var formError = root.querySelector( '[data-tb-form-error]' );
		var verifiedBadge = root.querySelector( '[data-tb-verified]' );

		var otpInputs = [].slice.call( root.querySelectorAll( '[data-tb-otp-digit]' ) );
		var otpSubmit = root.querySelector( '[data-tb-otp-submit]' );
		var otpError = root.querySelector( '[data-tb-otp-error]' );
		var otpNotice = root.querySelector( '[data-tb-otp-notice]' );
		var maskedEl = root.querySelector( '[data-tb-masked]' );
		var devNotice = root.querySelector( '[data-tb-dev-notice]' );
		var resendBtn = root.querySelector( '[data-tb-resend]' );
		var changeBtn = root.querySelector( '[data-tb-change-number]' );

		var state = {
			otpId: null,
			token: null,
			verifiedPhone: null,
			busy: false,
			cooldown: 0,
			timer: null
		};

		/* -------------------------------------------------------------- */

		function field( name ) {
			return root.querySelector( '[data-tb-field="' + name + '"]' );
		}

		function setFieldError( name, message ) {
			var target = root.querySelector( '[data-tb-error="' + name + '"]' );
			var input = field( name );
			var hint = root.querySelector( '[data-tb-hint="' + name + '"]' );

			if ( target ) {
				target.textContent = message || '';
				target.hidden = ! message;
			}
			if ( input ) {
				if ( message ) {
					input.setAttribute( 'aria-invalid', 'true' );
					input.classList.add( 'has-error' );
				} else {
					input.removeAttribute( 'aria-invalid' );
					input.classList.remove( 'has-error' );
				}
			}
			// A hint and an error occupy the same place, as in the React form.
			if ( hint ) {
				hint.hidden = !! message;
			}
		}

		function clearErrors() {
			[].forEach.call( root.querySelectorAll( '[data-tb-error]' ), function ( el ) {
				el.textContent = '';
				el.hidden = true;
			} );
			[].forEach.call( root.querySelectorAll( '[data-tb-field]' ), function ( el ) {
				el.removeAttribute( 'aria-invalid' );
				el.classList.remove( 'has-error' );
			} );
			[].forEach.call( root.querySelectorAll( '[data-tb-hint]' ), function ( el ) {
				el.hidden = false;
			} );
			formError.textContent = '';
			formError.hidden = true;
		}

		function applyFieldErrors( fieldErrors ) {
			var first = null;

			Object.keys( fieldErrors || {} ).forEach( function ( key ) {
				setFieldError( key, fieldErrors[ key ] );
				if ( ! first ) {
					first = key;
				}
			} );

			var target = first && field( first );
			if ( target && target.focus ) {
				target.focus();
			}
		}

		function setFormError( message ) {
			formError.textContent = message || '';
			formError.hidden = ! message;
		}

		function setBusy( busy, label ) {
			state.busy = busy;
			submit.disabled = busy;
			submit.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
			if ( label ) {
				submitLabel.textContent = label;
			}
		}

		function stage( name ) {
			Object.keys( stages ).forEach( function ( key ) {
				if ( stages[ key ] ) {
					stages[ key ].hidden = key !== name;
				}
			} );
		}

		function values() {
			return {
				subcategoryId: ( field( 'subcategory' ) || {} ).value || '',
				fullName: ( field( 'fullName' ) || {} ).value || '',
				email: ( field( 'email' ) || {} ).value || '',
				phone: ( field( 'mobile' ) || {} ).value || '',
				address: ( field( 'address' ) || {} ).value || '',
				consent: !! ( field( 'consent' ) || {} ).checked
			};
		}

		/**
		 * The precise location the visitor chose on the way here — the ZIP they
		 * searched, coordinates, a landmark's own name. The URL carries only the
		 * canonical city/state, so this travels in sessionStorage instead.
		 * Guarded: storage can be unavailable, and the page's own location is a
		 * perfectly good fallback.
		 */
		function storedLocation() {
			try {
				var raw = window.sessionStorage.getItem( STORAGE_KEY );
				var parsed = raw ? JSON.parse( raw ) : null;
				return parsed && parsed.slug === root.getAttribute( 'data-location' ) ? parsed : null;
			} catch ( error ) {
				return null;
			}
		}

		/* ---------------- phone mask, matching the React form -------- */

		function formatPhone( value ) {
			var digits = String( value ).replace( /\D/g, '' ).slice( 0, 10 );
			if ( ! digits.length ) {
				return '';
			}
			if ( digits.length <= 3 ) {
				return '(' + digits;
			}
			if ( digits.length <= 6 ) {
				return '(' + digits.slice( 0, 3 ) + ') ' + digits.slice( 3 );
			}
			return '(' + digits.slice( 0, 3 ) + ') ' + digits.slice( 3, 6 ) + '-' + digits.slice( 6 );
		}

		var mobile = field( 'mobile' );
		if ( mobile ) {
			mobile.addEventListener( 'input', function () {
				mobile.value = formatPhone( mobile.value );
				setFieldError( 'mobile', '' );
				// Editing the number invalidates any verification already held,
				// exactly as the React form does.
				if ( state.token ) {
					state.token = null;
					state.verifiedPhone = null;
					verifiedBadge.hidden = true;
					submitLabel.textContent = S.sendCode;
					note.textContent = S.noteUnverified;
				}
			} );
		}

		[].forEach.call( root.querySelectorAll( '[data-tb-field]' ), function ( el ) {
			var name = el.getAttribute( 'data-tb-field' );
			el.addEventListener( el.type === 'checkbox' || el.tagName === 'SELECT' ? 'change' : 'input', function () {
				setFieldError( name, '' );
			} );
		} );

		/* ---------------- stage 1 submit ----------------------------- */

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( state.busy ) {
				return;
			}

			clearErrors();
			var v = values();

			// Consent is checked here only so the user is told immediately. The
			// request is refused server-side regardless; this never decides.
			if ( ! v.consent ) {
				setFieldError( 'consent', S.consentRequired );
				var box = field( 'consent' );
				if ( box ) {
					box.focus();
				}
				return;
			}

			if ( state.token && state.verifiedPhone === v.phone.replace( /\D/g, '' ) ) {
				submitLead( v );
				return;
			}

			requestCode( v );
		} );

		function requestCode( v ) {
			setBusy( true, S.sending );

			post( '/otp/request', {
				consent: v.consent,
				phone: v.phone,
				subcategoryId: v.subcategoryId,
				fullName: v.fullName,
				email: v.email,
				address: v.address
			} ).then( function ( res ) {
				setBusy( false, state.token ? S.findMyBest : S.sendCode );

				if ( ! res.ok || ! res.body || false === res.body.success ) {
					handleFailure( res );
					return;
				}

				state.otpId = res.body.otpId;
				maskedEl.textContent = res.body.maskedPhone || '';
				devNotice.hidden = false !== res.body.smsSent;
				startCooldown( res.body.resendAfter || 30 );
				resetDigits();
				stage( 'otp' );
				if ( otpInputs[ 0 ] ) {
					otpInputs[ 0 ].focus();
				}
			} ).catch( function () {
				setBusy( false, S.sendCode );
				setFormError( S.network );
			} );
		}

		function handleFailure( res ) {
			var body = res.body || {};

			if ( body.fieldErrors && Object.keys( body.fieldErrors ).length ) {
				applyFieldErrors( body.fieldErrors );
				return;
			}

			if ( 429 === res.status ) {
				setFormError( body.message || S.rateLimited );
				return;
			}

			setFormError( body.message || S.genericError );
		}

		/* ---------------- stage 2: code entry ------------------------ */

		function resetDigits() {
			otpInputs.forEach( function ( input ) {
				input.value = '';
			} );
			otpSubmit.disabled = true;
			otpError.hidden = true;
			otpError.textContent = '';
		}

		function code() {
			return otpInputs.map( function ( i ) {
				return i.value;
			} ).join( '' );
		}

		function syncOtpSubmit() {
			otpSubmit.disabled = code().length !== OTP_LENGTH;
		}

		otpInputs.forEach( function ( input, index ) {
			input.addEventListener( 'input', function () {
				var raw = input.value.replace( /\D/g, '' );
				otpError.hidden = true;

				if ( raw.length > 1 ) {
					// A pasted or autofilled code fills across the boxes.
					raw.slice( 0, OTP_LENGTH - index ).split( '' ).forEach( function ( ch, offset ) {
						if ( otpInputs[ index + offset ] ) {
							otpInputs[ index + offset ].value = ch;
						}
					} );
					var last = Math.min( index + raw.length, OTP_LENGTH - 1 );
					otpInputs[ last ].focus();
					syncOtpSubmit();
					return;
				}

				input.value = raw;
				if ( raw && index < OTP_LENGTH - 1 ) {
					otpInputs[ index + 1 ].focus();
				}
				syncOtpSubmit();
			} );

			input.addEventListener( 'keydown', function ( event ) {
				if ( 'Backspace' === event.key && ! input.value && index > 0 ) {
					event.preventDefault();
					otpInputs[ index - 1 ].value = '';
					otpInputs[ index - 1 ].focus();
					syncOtpSubmit();
					return;
				}
				if ( 'ArrowLeft' === event.key && index > 0 ) {
					event.preventDefault();
					otpInputs[ index - 1 ].focus();
					return;
				}
				if ( 'ArrowRight' === event.key && index < OTP_LENGTH - 1 ) {
					event.preventDefault();
					otpInputs[ index + 1 ].focus();
				}
			} );

			input.addEventListener( 'focus', function () {
				input.select();
			} );
		} );

		otpForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( otpSubmit.disabled ) {
				return;
			}

			otpSubmit.disabled = true;
			otpNotice.hidden = true;

			post( '/otp/verify', { otpId: state.otpId, code: code() } ).then( function ( res ) {
				if ( ! res.ok || ! res.body || false === res.body.success ) {
					var message = ( res.body && res.body.fieldErrors && res.body.fieldErrors.code )
						|| ( res.body && res.body.message )
						|| S.genericError;
					otpError.textContent = message;
					otpError.hidden = false;
					resetDigits();
					if ( otpInputs[ 0 ] ) {
						otpInputs[ 0 ].focus();
					}
					return;
				}

				// The token is the only proof of verification, and the server
				// issued it. Nothing here can manufacture one.
				state.token = res.body.verificationToken;
				state.verifiedPhone = values().phone.replace( /\D/g, '' );

				verifiedBadge.hidden = false;
				submitLabel.textContent = S.findMyBest;
				note.textContent = S.noteVerified;
				stage( 'form' );
				submit.focus();
			} ).catch( function () {
				otpSubmit.disabled = false;
				otpError.textContent = S.network;
				otpError.hidden = false;
			} );
		} );

		function startCooldown( seconds ) {
			state.cooldown = seconds;
			if ( state.timer ) {
				window.clearInterval( state.timer );
			}
			paintResend();
			state.timer = window.setInterval( function () {
				state.cooldown -= 1;
				paintResend();
				if ( state.cooldown <= 0 ) {
					window.clearInterval( state.timer );
					state.timer = null;
				}
			}, 1000 );
		}

		function paintResend() {
			if ( state.cooldown > 0 ) {
				resendBtn.disabled = true;
				resendBtn.textContent = ( S.resendIn || 'Resend Code in %ds' ).replace( '%d', state.cooldown );
			} else {
				resendBtn.disabled = false;
				resendBtn.textContent = S.resend;
			}
		}

		resendBtn.addEventListener( 'click', function () {
			if ( resendBtn.disabled ) {
				return;
			}
			resendBtn.disabled = true;
			otpError.hidden = true;

			var v = values();
			post( '/otp/request', {
				consent: v.consent,
				phone: v.phone,
				subcategoryId: v.subcategoryId,
				fullName: v.fullName,
				email: v.email,
				address: v.address
			} ).then( function ( res ) {
				if ( ! res.ok || ! res.body || false === res.body.success ) {
					otpError.textContent = ( res.body && res.body.message ) || S.genericError;
					otpError.hidden = false;
					resendBtn.disabled = false;
					return;
				}
				state.otpId = res.body.otpId;
				resetDigits();
				otpNotice.textContent = S.resent;
				otpNotice.hidden = false;
				startCooldown( res.body.resendAfter || 30 );
				if ( otpInputs[ 0 ] ) {
					otpInputs[ 0 ].focus();
				}
			} );
		} );

		changeBtn.addEventListener( 'click', function () {
			stage( 'form' );
			state.otpId = null;
			state.token = null;
			state.verifiedPhone = null;
			verifiedBadge.hidden = true;
			submitLabel.textContent = S.sendCode;
			note.textContent = S.noteUnverified;
			var m = field( 'mobile' );
			if ( m ) {
				m.focus();
			}
		} );

		/* ---------------- stage 3: submit the lead ------------------- */

		function submitLead( v ) {
			setBusy( true, S.submitting );

			var selected = storedLocation();

			post( '/leads', {
				serviceSlug: root.getAttribute( 'data-service' ),
				locationSlug: root.getAttribute( 'data-location' ),
				subcategoryId: v.subcategoryId,
				fullName: v.fullName,
				email: v.email,
				phone: v.phone,
				address: v.address,
				consent: v.consent,
				verificationToken: state.token,
				sourcePage: window.location.pathname,
				referrer: document.referrer || '',
				utm_source: param( 'utm_source' ),
				utm_medium: param( 'utm_medium' ),
				utm_campaign: param( 'utm_campaign' ),
				utm_term: param( 'utm_term' ),
				utm_content: param( 'utm_content' )
			} ).then( function ( res ) {
				setBusy( false, S.findMyBest );

				if ( ! res.ok || ! res.body || false === res.body.success ) {
					// A spent or expired token sends the user back to verify
					// rather than leaving them on a dead button.
					if ( 403 === res.status ) {
						state.token = null;
						state.verifiedPhone = null;
						verifiedBadge.hidden = true;
						submitLabel.textContent = S.sendCode;
					}
					handleFailure( res );
					return;
				}

				var chosen = field( 'subcategory' );
				root.querySelector( '[data-tb-summary-subcategory]' ).textContent =
					chosen && chosen.selectedIndex > 0 ? chosen.options[ chosen.selectedIndex ].text : '—';
				root.querySelector( '[data-tb-summary-reference]' ).textContent = res.body.leadId || '—';

				if ( selected ) {
					var label = root.querySelector( '[data-tb-summary-location]' );
					if ( label && selected.zip ) {
						label.textContent = label.textContent + ' ' + selected.zip;
					}
				}

				stage( 'success' );
				root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			} ).catch( function () {
				setBusy( false, S.findMyBest );
				setFormError( S.network );
			} );
		}

		function param( name ) {
			try {
				return new URLSearchParams( window.location.search ).get( name ) || '';
			} catch ( error ) {
				return '';
			}
		}

		// Show the precise location (with ZIP) the visitor actually chose.
		var selected = storedLocation();
		if ( selected && selected.zip ) {
			var label = root.querySelector( '[data-tb-location-label]' );
			if ( label ) {
				label.value = label.value + ' ' + selected.zip;
			}
		}
	}

	/**
	 * Subcategory cards on the landing page preselect the matching option and
	 * scroll to the form — the React SubcategoryCards behaviour, without the
	 * React context.
	 *
	 * The value is a canonical subcategory id rendered by the server, and the
	 * lead endpoint re-checks that it belongs to this service on submission, so
	 * editing it in the DOM achieves nothing.
	 */
	function initSubcategoryCards() {
		var cards = document.querySelectorAll( '[data-tb-pick-subcategory]' );
		var select = document.querySelector( '[data-tb-field="subcategory"]' );

		if ( ! cards.length || ! select ) {
			return;
		}

		[].forEach.call( cards, function ( card ) {
			card.addEventListener( 'click', function () {
				var id = card.getAttribute( 'data-tb-pick-subcategory' );
				var matched = false;

				[].forEach.call( select.options, function ( option ) {
					if ( option.value === id ) {
						matched = true;
					}
				} );

				if ( ! matched ) {
					return;
				}

				select.value = id;
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

				[].forEach.call( cards, function ( other ) {
					other.classList.toggle( 'is-selected', other === card );
				} );

				var form = document.getElementById( 'lead-form' );
				if ( form && form.scrollIntoView ) {
					form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			} );
		} );
	}

	function init() {
		[].forEach.call( document.querySelectorAll( '[data-tb-lead-form]' ), LeadForm );
		initSubcategoryCards();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
