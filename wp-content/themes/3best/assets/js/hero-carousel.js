/**
 * 3Best — hero carousel.
 *
 * Ported from components/HeroCarousel with the same behaviour and constants:
 * 5000 ms autoplay, pause on hover / focus / touch, arrow keys, dots, a 48 px
 * swipe threshold, and autoplay disabled under prefers-reduced-motion (read
 * from the OS and kept in sync if the setting changes).
 */
( function () {
	'use strict';

	var INTERVAL_MS = 5000;
	var SWIPE_THRESHOLD_PX = 48;
	var REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

	function initCarousel( root ) {
		var slides = root.querySelectorAll( '[data-tb-slide]' );
		var dots = root.querySelectorAll( '[data-tb-dot]' );
		var prev = root.querySelector( '[data-tb-prev]' );
		var next = root.querySelector( '[data-tb-next]' );

		if ( slides.length < 2 ) {
			return;
		}

		var count = slides.length;
		var index = 0;
		var paused = false;
		var timer = null;
		var touchStartX = null;

		var motionQuery = window.matchMedia ? window.matchMedia( REDUCED_MOTION_QUERY ) : null;

		function reducedMotion() {
			return !! ( motionQuery && motionQuery.matches );
		}

		function render() {
			Array.prototype.forEach.call( slides, function ( slide, i ) {
				var active = i === index;

				slide.classList.toggle( 'is-active', active );

				if ( active ) {
					slide.removeAttribute( 'aria-hidden' );
					slide.removeAttribute( 'inert' );
				} else {
					slide.setAttribute( 'aria-hidden', 'true' );
					slide.setAttribute( 'inert', '' );
				}
			} );

			Array.prototype.forEach.call( dots, function ( dot, i ) {
				var active = i === index;

				dot.classList.toggle( 'is-active', active );
				dot.setAttribute( 'aria-current', active ? 'true' : 'false' );
			} );
		}

		function go( target ) {
			index = ( ( target % count ) + count ) % count;
			render();
		}

		function stop() {
			if ( null !== timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		}

		function start() {
			stop();

			if ( paused || reducedMotion() ) {
				return;
			}

			timer = window.setInterval( function () {
				go( index + 1 );
			}, INTERVAL_MS );
		}

		function setPaused( value ) {
			paused = value;
			start();
		}

		root.addEventListener( 'mouseenter', function () {
			setPaused( true );
		} );
		root.addEventListener( 'mouseleave', function () {
			setPaused( false );
		} );
		root.addEventListener( 'focusin', function () {
			setPaused( true );
		} );
		root.addEventListener( 'focusout', function () {
			setPaused( false );
		} );

		root.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowLeft' === event.key ) {
				event.preventDefault();
				go( index - 1 );
			} else if ( 'ArrowRight' === event.key ) {
				event.preventDefault();
				go( index + 1 );
			}
		} );

		root.addEventListener(
			'touchstart',
			function ( event ) {
				touchStartX = event.touches[ 0 ] ? event.touches[ 0 ].clientX : null;
				setPaused( true );
			},
			{ passive: true }
		);

		root.addEventListener(
			'touchend',
			function ( event ) {
				var start = touchStartX;

				touchStartX = null;
				setPaused( false );

				if ( null === start ) {
					return;
				}

				var end = event.changedTouches[ 0 ] ? event.changedTouches[ 0 ].clientX : start;
				var delta = end - start;

				if ( Math.abs( delta ) < SWIPE_THRESHOLD_PX ) {
					return;
				}

				go( delta < 0 ? index + 1 : index - 1 );
			},
			{ passive: true }
		);

		if ( prev ) {
			prev.addEventListener( 'click', function () {
				go( index - 1 );
			} );
		}

		if ( next ) {
			next.addEventListener( 'click', function () {
				go( index + 1 );
			} );
		}

		Array.prototype.forEach.call( dots, function ( dot, i ) {
			dot.addEventListener( 'click', function () {
				go( i );
			} );
		} );

		// Autoplay follows the OS setting, including a change made while the
		// page is open.
		if ( motionQuery && motionQuery.addEventListener ) {
			motionQuery.addEventListener( 'change', start );
		}

		render();
		start();
	}

	function init() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-tb-carousel]' ),
			initCarousel
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
