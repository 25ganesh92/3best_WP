/**
 * 3Best — service and location search.
 *
 * Ported from the React ServiceSelector, LocationAutocomplete and SearchForm.
 * No dependencies, no build step.
 *
 * Two comboboxes share one implementation and differ only in their adapter:
 * services filter synchronously from a catalogue that travels with the page,
 * locations are fetched from our own REST route. The browser never talks to a
 * geocoder, and cannot tell which one is behind the route.
 */
( function () {
	'use strict';

	var settings = window.threebestSearch;

	if ( ! settings || ! settings.restUrl ) {
		return;
	}

	var CFG = settings.config || {};
	var STRINGS = settings.strings || {};
	var TYPE_LABELS = settings.typeLabels || {};
	var MIN_LENGTH = CFG.minQueryLength || 2;
	var DEBOUNCE_MS = CFG.debounceMs || 300;
	var STORAGE_KEY = CFG.storageKey || '3best:selected-location';

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text && null !== text ) {
			node.textContent = text;
		}
		return node;
	}

	/**
	 * Highlights the matched substring.
	 *
	 * Built with createElement and textContent throughout — never innerHTML —
	 * so a provider-supplied place name can never inject markup.
	 */
	function highlight( text, query ) {
		var fragment = document.createDocumentFragment();
		var needle = ( query || '' ).trim().toLowerCase();

		if ( ! needle ) {
			fragment.appendChild( document.createTextNode( text ) );
			return fragment;
		}

		var haystack = text.toLowerCase();
		var index = haystack.indexOf( needle );

		if ( index === -1 ) {
			fragment.appendChild( document.createTextNode( text ) );
			return fragment;
		}

		fragment.appendChild( document.createTextNode( text.slice( 0, index ) ) );

		var mark = document.createElement( 'mark' );
		mark.textContent = text.slice( index, index + needle.length );
		fragment.appendChild( mark );

		fragment.appendChild( document.createTextNode( text.slice( index + needle.length ) ) );

		return fragment;
	}

	function emptyPanel( message, hint ) {
		var wrap = el( 'p', 'tb-combobox__empty', message );
		if ( hint ) {
			wrap.appendChild( el( 'span', 'tb-combobox__empty-hint', hint ) );
		}
		return wrap;
	}

	/* ------------------------------------------------------------------ *
	 * Combobox
	 * ------------------------------------------------------------------ */

	function Combobox( root, adapter ) {
		var input = root.querySelector( '[data-tb-input]' );
		var panel = root.querySelector( '[data-tb-panel]' );
		var clear = root.querySelector( '[data-tb-clear]' );
		var spinner = root.querySelector( '[data-tb-spinner]' );
		var errorEl = root.querySelector( '[data-tb-error]' );
		var liveEl = root.querySelector( '[data-tb-live]' );
		var valueEl = root.querySelector( '[data-tb-value]' );
		var wrap = root.querySelector( '[data-tb-wrap]' );

		if ( ! input || ! panel ) {
			return null;
		}

		var api = {
			selected: null,
			options: [],
			activeIndex: -1,
			open: false,
		};

		function announce( message ) {
			if ( liveEl ) {
				liveEl.textContent = message || '';
			}
		}

		function setError( message ) {
			if ( ! errorEl ) {
				return;
			}
			if ( message ) {
				errorEl.textContent = message;
				errorEl.hidden = false;
				wrap.classList.add( 'has-error' );
				input.setAttribute( 'aria-invalid', 'true' );
			} else {
				errorEl.textContent = '';
				errorEl.hidden = true;
				wrap.classList.remove( 'has-error' );
				input.removeAttribute( 'aria-invalid' );
			}
		}

		function setBusy( busy ) {
			if ( spinner ) {
				spinner.hidden = ! busy;
			}
			if ( clear ) {
				clear.hidden = busy || ( ! api.selected && '' === input.value );
			}
		}

		function setOpen( open ) {
			api.open = open;
			panel.hidden = ! open;
			input.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

			if ( ! open ) {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		function select( option ) {
			api.selected = option;
			input.value = adapter.inputValue( option );
			if ( valueEl ) {
				valueEl.value = adapter.identity( option );
			}
			setError( null );
			setBusy( false );
			setOpen( false );
			adapter.onChange( option );
			input.focus();
		}

		function clearSelection( refocus ) {
			api.selected = null;
			api.options = [];
			api.activeIndex = -1;
			input.value = '';
			if ( valueEl ) {
				valueEl.value = '';
			}
			setBusy( false );
			setError( null );
			adapter.onChange( null );
			adapter.reset();
			render( [] );
			setOpen( true );
			if ( refocus ) {
				input.focus();
			}
		}

		/**
		 * Draws the panel. `groups` is a list of { label, options }.
		 */
		function render( groups, state ) {
			panel.textContent = '';
			api.options = [];

			if ( state && state.message ) {
				panel.appendChild( emptyPanel( state.message, state.hint ) );
				announce( state.announce || '' );
				if ( clear ) {
					clear.hidden = ! api.selected && '' === input.value;
				}
				return;
			}

			groups.forEach( function ( group ) {
				var container = panel;

				if ( group.label ) {
					container = el( 'div', 'tb-combobox__group' );
					container.appendChild( el( 'div', 'tb-combobox__group-label', group.label ) );
					panel.appendChild( container );
				}

				group.options.forEach( function ( option ) {
					var index = api.options.length;
					var row = el( 'div', 'tb-combobox__option' );

					row.id = input.id + '-option-' + index;
					row.setAttribute( 'role', 'option' );
					row.setAttribute( 'aria-selected', api.selected && adapter.identity( api.selected ) === adapter.identity( option ) ? 'true' : 'false' );

					var main = el( 'span', 'tb-combobox__option-main' );
					main.appendChild( highlight( adapter.primary( option ), input.value ) );

					var secondary = adapter.secondary( option );
					if ( secondary ) {
						main.appendChild( el( 'span', 'tb-combobox__option-sub', secondary ) );
					}
					row.appendChild( main );

					var meta = adapter.meta( option );
					if ( meta ) {
						row.appendChild( el( 'span', 'tb-combobox__option-meta', meta ) );
					}

					// mousedown default is prevented so the input does not blur
					// before the click lands.
					row.addEventListener( 'mousedown', function ( event ) {
						event.preventDefault();
					} );
					row.addEventListener( 'click', function () {
						select( option );
					} );

					api.options.push( { option: option, node: row } );
					container.appendChild( row );
				} );
			} );

			if ( adapter.attribution && api.options.length ) {
				panel.appendChild( el( 'p', 'tb-combobox__attribution', adapter.attribution() ) );
			}

			api.activeIndex = api.options.length ? 0 : -1;
			paintActive();
			announce( adapter.announce( api.options.length ) );

			if ( clear ) {
				clear.hidden = ! api.selected && '' === input.value;
			}
		}

		function paintActive() {
			api.options.forEach( function ( entry, index ) {
				var active = index === api.activeIndex;
				entry.node.classList.toggle( 'is-active', active );
				if ( active ) {
					entry.node.setAttribute( 'data-active', 'true' );
					input.setAttribute( 'aria-activedescendant', entry.node.id );
					if ( entry.node.scrollIntoView ) {
						entry.node.scrollIntoView( { block: 'nearest' } );
					}
				} else {
					entry.node.removeAttribute( 'data-active' );
				}
			} );

			if ( api.activeIndex < 0 ) {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		function move( delta ) {
			if ( ! api.options.length ) {
				api.activeIndex = -1;
				return;
			}
			var count = api.options.length;
			api.activeIndex = ( ( api.activeIndex + delta ) % count + count ) % count;
			paintActive();
		}

		input.addEventListener( 'input', function () {
			// Typing clears the selection on purpose: the value must always be
			// a record the server produced, never whatever text is in the box.
			if ( api.selected ) {
				api.selected = null;
				if ( valueEl ) {
					valueEl.value = '';
				}
				adapter.onChange( null );
			}
			setError( null );
			setOpen( true );
			adapter.query( input.value, render, setBusy );
		} );

		input.addEventListener( 'focus', function () {
			setOpen( true );
			adapter.query( input.value, render, setBusy );
		} );

		input.addEventListener( 'click', function () {
			setOpen( true );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				if ( ! api.open ) {
					setOpen( true );
					return;
				}
				move( 1 );
				return;
			}

			if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				if ( ! api.open ) {
					setOpen( true );
					return;
				}
				move( -1 );
				return;
			}

			if ( 'Enter' === event.key ) {
				if ( api.open && api.activeIndex >= 0 && api.options[ api.activeIndex ] ) {
					event.preventDefault();
					select( api.options[ api.activeIndex ].option );
				}
				return;
			}

			if ( 'Escape' === event.key ) {
				if ( api.open ) {
					event.preventDefault();
					setOpen( false );
					input.value = api.selected ? adapter.inputValue( api.selected ) : '';
				}
				return;
			}

			if ( 'Tab' === event.key ) {
				setOpen( false );
			}
		} );

		if ( clear ) {
			clear.addEventListener( 'click', function () {
				clearSelection( true );
			} );
		}

		document.addEventListener( 'mousedown', function ( event ) {
			if ( api.open && ! root.contains( event.target ) ) {
				setOpen( false );
			}
		} );

		document.addEventListener( 'focusin', function ( event ) {
			if ( api.open && ! root.contains( event.target ) ) {
				setOpen( false );
			}
		} );

		api.setError = setError;
		api.selectOption = select;
		api.input = input;
		api.clearSelection = clearSelection;

		return api;
	}

	/* ------------------------------------------------------------------ *
	 * Service adapter — synchronous, from the catalogue on the page
	 * ------------------------------------------------------------------ */

	function serviceAdapter( onChange ) {
		var categories = settings.categories || [];

		return {
			identity: function ( service ) {
				return service ? service.slug : '';
			},
			inputValue: function ( service ) {
				return service ? service.name : '';
			},
			primary: function ( service ) {
				return service.name;
			},
			secondary: function ( service ) {
				return service.description;
			},
			meta: function () {
				return '';
			},
			announce: function ( count ) {
				return count + ' services available';
			},
			reset: function () {},
			onChange: onChange,
			query: function ( text, render ) {
				var needle = ( text || '' ).trim().toLowerCase();
				var groups = [];
				var total = 0;

				categories.forEach( function ( category ) {
					var matches = category.services.filter( function ( service ) {
						if ( ! needle ) {
							return true;
						}
						var haystack = [
							service.name,
							service.term,
							service.description,
							category.name,
							service.keywords
						].join( ' ' ).toLowerCase();
						return haystack.indexOf( needle ) !== -1;
					} );

					if ( matches.length ) {
						total += matches.length;
						groups.push( { label: category.name, options: matches } );
					}
				} );

				if ( ! total ) {
					render( [], {
						message: STRINGS.noServices,
						hint: STRINGS.noServicesHint,
						announce: STRINGS.noServices
					} );
					return;
				}

				render( groups );
			}
		};
	}

	/* ------------------------------------------------------------------ *
	 * Location adapter — debounced, aborted, cached, server-backed
	 * ------------------------------------------------------------------ */

	function locationAdapter( onChange ) {
		var cache = new Map();
		var MAX_CACHE = 50;
		var timer = null;
		var controller = null;
		var requestId = 0;
		var lastQuery = null;

		function readCache( key ) {
			return cache.has( key ) ? cache.get( key ) : null;
		}

		function writeCache( key, value ) {
			if ( cache.size >= MAX_CACHE ) {
				cache.delete( cache.keys().next().value );
			}
			cache.set( key, value );
		}

		function toGroups( results ) {
			return results.length ? [ { label: '', options: results } ] : [];
		}

		return {
			identity: function ( location ) {
				return location ? location.id : '';
			},
			inputValue: function ( location ) {
				return location ? location.displayName : '';
			},
			primary: function ( location ) {
				return location.primary || location.displayName;
			},
			secondary: function ( location ) {
				return location.secondary || '';
			},
			meta: function ( location ) {
				return TYPE_LABELS[ location.sourceType || location.locationType ] || '';
			},
			attribution: function () {
				return STRINGS.attribution;
			},
			announce: function ( count ) {
				if ( ! count ) {
					return STRINGS.noResultsAnnouncement;
				}
				return 1 === count
					? STRINGS.oneResultAvailable
					: ( STRINGS.resultsAvailable || '%d locations available' ).replace( '%d', count );
			},
			reset: function () {
				if ( timer ) {
					window.clearTimeout( timer );
					timer = null;
				}
				if ( controller ) {
					controller.abort();
					controller = null;
				}
				lastQuery = null;
			},
			onChange: onChange,
			query: function ( text, render, setBusy ) {
				var trimmed = ( text || '' ).trim();
				var key = trimmed.toLowerCase();

				if ( timer ) {
					window.clearTimeout( timer );
					timer = null;
				}

				if ( trimmed.length < MIN_LENGTH ) {
					if ( controller ) {
						controller.abort();
						controller = null;
					}
					lastQuery = null;
					setBusy( false );
					render( [], {
						message: STRINGS.startTyping,
						hint: STRINGS.startTypingHint
					} );
					return;
				}

				var cached = readCache( key );
				if ( cached ) {
					setBusy( false );
					lastQuery = key;
					if ( ! cached.length ) {
						render( [], { message: STRINGS.noLocations, hint: STRINGS.noLocationsHint } );
						return;
					}
					render( toGroups( cached ) );
					return;
				}

				// Same query already in flight: do not start a second request.
				if ( key === lastQuery ) {
					return;
				}

				setBusy( true );
				render( [], { message: STRINGS.searching } );

				timer = window.setTimeout( function () {
					var current = ++requestId;

					if ( controller ) {
						controller.abort();
					}
					controller = ( 'undefined' !== typeof AbortController ) ? new AbortController() : null;
					lastQuery = key;

					var url = settings.restUrl + '?q=' + encodeURIComponent( trimmed );

					window.fetch( url, {
						method: 'GET',
						credentials: 'same-origin',
						signal: controller ? controller.signal : undefined,
						headers: {
							Accept: 'application/json',
							'X-WP-Nonce': settings.nonce
						}
					} ).then( function ( response ) {
						return response.json().then( function ( body ) {
							return { ok: response.ok, body: body };
						} );
					} ).then( function ( payload ) {
						// Stale response guard: a slower earlier request must
						// never overwrite a newer one's results.
						if ( current !== requestId ) {
							return;
						}

						setBusy( false );

						if ( ! payload.ok || ! payload.body || false === payload.body.success ) {
							lastQuery = null;
							render( [], {
								message: ( payload.body && payload.body.message ) || STRINGS.searchError,
								hint: STRINGS.searchErrorHint,
								announce: STRINGS.searchError
							} );
							return;
						}

						var results = Array.isArray( payload.body.results ) ? payload.body.results : [];

						writeCache( key, results );

						if ( ! results.length ) {
							render( [], { message: STRINGS.noLocations, hint: STRINGS.noLocationsHint } );
							return;
						}

						render( toGroups( results ) );
					} ).catch( function ( error ) {
						if ( error && 'AbortError' === error.name ) {
							return;
						}
						if ( current !== requestId ) {
							return;
						}
						setBusy( false );
						lastQuery = null;
						render( [], {
							message: STRINGS.searchError,
							hint: STRINGS.searchErrorHint,
							announce: STRINGS.searchError
						} );
					} );
				}, DEBOUNCE_MS );
			}
		};
	}

	/* ------------------------------------------------------------------ *
	 * Search form
	 * ------------------------------------------------------------------ */

	function initForm( form ) {
		var serviceRoot = form.querySelector( '[data-tb-combobox="service"]' );
		var locationRoot = form.querySelector( '[data-tb-combobox="location"]' );

		if ( ! serviceRoot || ! locationRoot ) {
			return;
		}

		var state = { service: null, location: null };

		var service = Combobox( serviceRoot, serviceAdapter( function ( value ) {
			state.service = value;
		} ) );

		var location = Combobox( locationRoot, locationAdapter( function ( value ) {
			state.location = value;
		} ) );

		if ( ! service || ! location ) {
			return;
		}

		// Popular chips pre-select a service without touching the location.
		form.querySelectorAll( '[data-tb-chip]' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				var slug = chip.getAttribute( 'data-service-slug' );
				var found = null;

				( settings.categories || [] ).forEach( function ( category ) {
					category.services.forEach( function ( item ) {
						if ( item.slug === slug ) {
							found = item;
						}
					} );
				} );

				if ( found ) {
					service.selectOption( found );
					form.querySelectorAll( '[data-tb-chip]' ).forEach( function ( other ) {
						other.classList.toggle( 'is-active', other === chip );
					} );
				}
			} );
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var ok = true;

			if ( ! state.service ) {
				service.setError( STRINGS.servicePlaceholderError );
				ok = false;
			}

			if ( ! state.location ) {
				location.setError( STRINGS.locationError );
				ok = false;
			}

			if ( ! ok ) {
				( state.service ? location.input : service.input ).focus();
				return;
			}

			/*
			 * The URL carries only the canonical city/state. The precise
			 * selection — the ZIP searched, coordinates, a landmark's own name —
			 * travels in sessionStorage instead, for the lead form in Phase 5.
			 * Guarded because storage can be unavailable in private mode; the
			 * landing page then falls back to its own canonical location.
			 */
			try {
				window.sessionStorage.setItem( STORAGE_KEY, JSON.stringify( state.location ) );
			} catch ( error ) {
				// Intentionally ignored.
			}

			// Both halves are records the server produced, so the slug is built
			// from canonical values rather than anything the user typed.
			var path = state.service.slug + '-in-' + state.location.slug;

			window.location.href = settings.homeUrl.replace( /\/+$/, '' ) + '/' + path + '/';
		} );
	}

	function init() {
		document.querySelectorAll( '[data-tb-search-form]' ).forEach( initForm );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
