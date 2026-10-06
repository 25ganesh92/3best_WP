/**
 * 3Best — scroll reveal and mobile navigation.
 *
 * Ported from the React Reveal component and the Header's mobile menu.
 * No dependencies, no build step.
 */
( function () {
	'use strict';

	/* ---------------------------------------------------------------------
	 * Scroll reveal
	 *
	 * Content is always in the DOM. If IntersectionObserver is missing or the
	 * visitor prefers reduced motion, everything is shown immediately rather
	 * than left hidden — a failed animation must never hide content.
	 * ------------------------------------------------------------------ */
	function initReveal() {
		var items = document.querySelectorAll( '.tb-reveal' );

		if ( ! items.length ) {
			return;
		}

		var reducedMotion = window.matchMedia &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		if ( reducedMotion || typeof IntersectionObserver === 'undefined' ) {
			Array.prototype.forEach.call( items, function ( item ) {
				item.classList.add( 'is-visible' );
			} );

			return;
		}

		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						entry.target.classList.add( 'is-visible' );
						observer.unobserve( entry.target );
					}
				} );
			},
			{ rootMargin: '0px 0px -8% 0px', threshold: 0.05 }
		);

		Array.prototype.forEach.call( items, function ( item ) {
			observer.observe( item );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Mobile navigation
	 *
	 * The React header tied the open state to the route so navigating closed
	 * it. There is no client-side routing here, so a navigation is a fresh
	 * document and the menu starts closed anyway. Escape and a click outside
	 * close it, matching the rest of the UI.
	 * ------------------------------------------------------------------ */
	function initMobileNav() {
		var toggle = document.querySelector( '[data-tb-menu-toggle]' );
		var panel = document.getElementById( 'tb-mobile-navigation' );

		if ( ! toggle || ! panel ) {
			return;
		}

		function setOpen( open ) {
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			toggle.setAttribute(
				'aria-label',
				open ? toggle.dataset.labelClose || 'Close menu' : toggle.dataset.labelOpen || 'Open menu'
			);
			panel.hidden = ! open;
			panel.classList.toggle( 'is-open', open );
			document.body.classList.toggle( 'tb-menu-open', open );
		}

		toggle.addEventListener( 'click', function () {
			setOpen( 'true' !== toggle.getAttribute( 'aria-expanded' ) );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && 'true' === toggle.getAttribute( 'aria-expanded' ) ) {
				setOpen( false );
				toggle.focus();
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( 'true' !== toggle.getAttribute( 'aria-expanded' ) ) {
				return;
			}

			if ( ! panel.contains( event.target ) && ! toggle.contains( event.target ) ) {
				setOpen( false );
			}
		} );
	}

	function init() {
		initReveal();
		initMobileNav();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
