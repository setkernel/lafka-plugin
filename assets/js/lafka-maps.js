/**
 * Lafka maps: one small API over the configured map provider.
 *
 * Every Lafka map (store picker, delivery-zone editor, branch geocoding,
 * branch modal, checkout pin map, [lafka_shipping_areas]) talks to
 * `window.lafkaMaps` instead of a provider:
 *
 *   provider            'google' (a Maps key is set) or 'osm' (Leaflet +
 *                       OpenStreetMap, no key)
 *   defaults            the start view, { lat, lng, zoom } — PHP
 *                       lafka_get_map_default_view(), localized as
 *                       window.lafkaMapDefaults
 *   ready()             Promise, resolves once the provider library is usable
 *   map( el, view )     a map adapter (see createGoogleMap / createLeafletMap);
 *                       call it after ready()
 *   geocode( q, opts )  address → result | null (Promise)
 *   reverse( pt )       point → result | null (Promise)
 *   polyline            encode( points ) / decode( string ): Google's Encoded
 *                       Polyline Algorithm Format, the storage format of the
 *                       delivery-zone polygons (decoded server-side by
 *                       Lafka_Shipping_Areas::decode_polygon_coordinates)
 *   distance( a, b )    metres between two points
 *   contains( pt, path) point-in-polygon (the server's ray-casting test)
 *   point( value )      { lat, lng } from a LatLng, an array, JSON or
 *                       URL-encoded JSON; null when unusable
 *
 * A geocode result: { lat, lng, label, precise, level, address: { address_1,
 * city, state, postcode, country }, regions: [ { short, long } x3 ] }.
 * `precise` is false for area-level matches (a city, a postcode) where a
 * delivery pin is still needed; `level` is 'address' (a house or a place),
 * 'street' or 'area'. Keyless geocoding goes through the plugin's REST proxy
 * (Nominatim, cached server-side); it is never called while typing.
 *
 * Configuration: window.lafkaMapsConfig (lafka_maps_client_config()).
 */
( function ( window, document ) {
	'use strict';

	const config = window.lafkaMapsConfig || {};
	const i18n = config.i18n || {};
	const EARTH_RADIUS = 6378137; // Metres; the radius Google's spherical geometry uses.

	/**
	 * A { lat, lng } point from any of the shapes the plugin stores or a
	 * provider returns, or null.
	 *
	 * @param {*} value LatLng, { lat, lng }, [ lat, lng ], JSON or URL-encoded JSON.
	 * @return {?{lat: number, lng: number}} Point.
	 */
	function toPoint( value ) {
		let lat;
		let lng;
		if ( null === value || undefined === value || '' === value ) {
			return null;
		}
		if ( 'string' === typeof value ) {
			try {
				return toPoint( JSON.parse( decodeURIComponent( value ) ) );
			} catch {
				return null;
			}
		}
		if ( Array.isArray( value ) ) {
			lat = value[ 0 ];
			lng = value[ 1 ];
		} else if ( 'function' === typeof value.lat ) {
			lat = value.lat();
			lng = value.lng();
		} else {
			lat = value.lat;
			lng = undefined !== value.lng ? value.lng : value.lon;
		}
		lat = parseFloat( lat );
		lng = parseFloat( lng );
		if ( ! isFinite( lat ) || ! isFinite( lng ) || Math.abs( lat ) > 90 || Math.abs( lng ) > 180 ) {
			return null;
		}
		return { lat, lng };
	}

	/**
	 * The start view from window.lafkaMapDefaults (Canada if absent).
	 *
	 * @return {{lat: number, lng: number, zoom: number}} View.
	 */
	function defaultView() {
		const raw = window.lafkaMapDefaults || {};
		const point = toPoint( raw ) || { lat: 56.1304, lng: -106.3468 };
		const zoom = parseInt( raw.zoom, 10 );
		return { lat: point.lat, lng: point.lng, zoom: isFinite( zoom ) ? zoom : 3 };
	}

	// ── Polyline encoding (Google's Encoded Polyline Algorithm Format) ──────

	/**
	 * Encode one signed value (already multiplied by 1e5 and rounded).
	 *
	 * @param {number} value Integer delta.
	 * @return {string} Encoded chunk.
	 */
	function encodeValue( value ) {
		let rest = value < 0 ? ~( value << 1 ) : value << 1;
		let out = '';
		while ( rest >= 0x20 ) {
			out += String.fromCharCode( ( 0x20 | ( rest & 0x1f ) ) + 63 );
			rest >>= 5;
		}
		return out + String.fromCharCode( rest + 63 );
	}

	const polyline = {
		/**
		 * @param {Array} points Points ({ lat, lng } or [ lat, lng ]).
		 * @return {string} Encoded path.
		 */
		encode( points ) {
			let lastLat = 0;
			let lastLng = 0;
			let out = '';
			( points || [] ).forEach( ( raw ) => {
				const point = toPoint( raw );
				if ( ! point ) {
					return;
				}
				const lat = Math.round( point.lat * 1e5 );
				const lng = Math.round( point.lng * 1e5 );
				out += encodeValue( lat - lastLat ) + encodeValue( lng - lastLng );
				lastLat = lat;
				lastLng = lng;
			} );
			return out;
		},

		/**
		 * @param {string} encoded Encoded path.
		 * @return {Array<{lat: number, lng: number}>} Points.
		 */
		decode( encoded ) {
			const points = [];
			const text = String( encoded || '' );
			let index = 0;
			let lat = 0;
			let lng = 0;
			const next = () => {
				let shift = 0;
				let result = 0;
				let byte;
				do {
					if ( index >= text.length ) {
						return null;
					}
					byte = text.charCodeAt( index++ ) - 63;
					result |= ( byte & 0x1f ) << shift;
					shift += 5;
				} while ( byte >= 0x20 );
				return result & 1 ? ~( result >> 1 ) : result >> 1;
			};
			while ( index < text.length ) {
				const dLat = next();
				const dLng = null === dLat ? null : next();
				if ( null === dLng ) {
					break;
				}
				lat += dLat;
				lng += dLng;
				points.push( { lat: lat / 1e5, lng: lng / 1e5 } );
			}
			return points;
		},
	};

	// ── Geometry ────────────────────────────────────────────────────────────

	/**
	 * Great-circle distance in metres.
	 *
	 * @param {*} a Point.
	 * @param {*} b Point.
	 * @return {number} Metres (NaN when a point is unusable).
	 */
	function distance( a, b ) {
		const p = toPoint( a );
		const q = toPoint( b );
		if ( ! p || ! q ) {
			return NaN;
		}
		const rad = Math.PI / 180;
		const dLat = ( q.lat - p.lat ) * rad;
		const dLng = ( q.lng - p.lng ) * rad;
		const h = Math.sin( dLat / 2 ) ** 2 + Math.cos( p.lat * rad ) * Math.cos( q.lat * rad ) * Math.sin( dLng / 2 ) ** 2;
		return 2 * EARTH_RADIUS * Math.asin( Math.min( 1, Math.sqrt( h ) ) );
	}

	/**
	 * Ray-casting point-in-polygon (even-odd), the server's test.
	 *
	 * @param {*}            point Point.
	 * @param {Array|string} path  Points, or an encoded polyline.
	 * @return {boolean} Inside.
	 */
	function contains( point, path ) {
		const p = toPoint( point );
		const ring = ( 'string' === typeof path ? polyline.decode( path ) : path || [] ).map( toPoint ).filter( Boolean );
		if ( ! p || ring.length < 3 ) {
			return false;
		}
		let inside = false;
		for ( let i = 0, j = ring.length - 1; i < ring.length; j = i++ ) {
			const a = ring[ i ];
			const b = ring[ j ];
			if ( a.lat > p.lat !== b.lat > p.lat && p.lng < ( ( b.lng - a.lng ) * ( p.lat - a.lat ) ) / ( b.lat - a.lat ) + a.lng ) {
				inside = ! inside;
			}
		}
		return inside;
	}

	// ── Geocoding ───────────────────────────────────────────────────────────

	/**
	 * A Google Geocoder result (or a Places Autocomplete place) in the shared
	 * shape.
	 *
	 * @param {Object} result google.maps.GeocoderResult or PlaceResult.
	 * @return {Object} Result.
	 */
	function fromGoogle( result ) {
		const point = toPoint( result.geometry.location );
		const found = {};
		( result.address_components || [] ).forEach( ( component ) => {
			( component.types || [] ).forEach( ( type ) => {
				if ( ! found[ type ] ) {
					found[ type ] = component;
				}
			} );
		} );
		const short = ( type ) => ( found[ type ] ? found[ type ].short_name : '' );
		const long = ( type ) => ( found[ type ] ? found[ type ].long_name : '' );
		const street = [ long( 'street_number' ) || long( 'premise' ), short( 'route' ) ].filter( Boolean ).join( ' ' );
		const types = result.types || [];
		let level = 'area';
		if ( [ 'street_address', 'premise', 'subpremise', 'establishment', 'point_of_interest' ].some( ( type ) => types.includes( type ) ) || 'ROOFTOP' === result.geometry.location_type ) {
			level = 'address';
		} else if ( types.includes( 'route' ) ) {
			level = 'street';
		}
		let postcode = long( 'postal_code' );
		if ( postcode && long( 'postal_code_suffix' ) ) {
			postcode += '-' + long( 'postal_code_suffix' );
		}
		return {
			lat: point.lat,
			lng: point.lng,
			label: result.formatted_address || result.name || '',
			precise: 'APPROXIMATE' !== result.geometry.location_type,
			level,
			address: {
				address_1: street || short( 'sublocality_level_1' ) || result.name || '',
				city: long( 'locality' ) || long( 'postal_town' ) || long( 'sublocality' ),
				state: short( 'administrative_area_level_1' ),
				postcode,
				country: short( 'country' ),
			},
			regions: [ 1, 2, 3 ].map( ( level ) => ( {
				short: short( 'administrative_area_level_' + level ),
				long: long( 'administrative_area_level_' + level ),
			} ) ),
		};
	}

	/**
	 * Ask the plugin's geocoding proxy.
	 *
	 * @param {Object} params { q } or { lat, lng }.
	 * @return {Promise<?Object>} Result or null; rejects with an Error whose
	 *                            message is safe to show.
	 */
	function askProxy( params ) {
		if ( ! config.geocodeUrl ) {
			return Promise.reject( new Error( i18n.geocodeFailed || '' ) );
		}
		const url = new URL( config.geocodeUrl, window.location.href );
		Object.keys( params ).forEach( ( key ) => url.searchParams.set( key, params[ key ] ) );
		const headers = { Accept: 'application/json' };
		if ( config.nonce ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}
		return window
			.fetch( url.toString(), { credentials: 'same-origin', headers } )
			.then( ( response ) =>
				response.json().then( ( body ) => {
					if ( ! response.ok ) {
						throw new Error( ( body && body.message ) || i18n.geocodeFailed || '' );
					}
					return body && body.result ? body.result : null;
				} )
			);
	}

	/**
	 * Ask Google's Geocoder.
	 *
	 * @param {Object} request GeocoderRequest.
	 * @return {Promise<?Object>} Result or null.
	 */
	function askGoogle( request ) {
		return ready()
			.then( () => new window.google.maps.Geocoder().geocode( request ) )
			.then(
				( response ) => ( response.results && response.results[ 0 ] ? fromGoogle( response.results[ 0 ] ) : null ),
				( error ) => {
					if ( error && 'ZERO_RESULTS' === error.code ) {
						return null;
					}
					throw new Error( i18n.geocodeFailed || String( error ) );
				}
			);
	}

	const answers = {};

	/**
	 * Geocode an address (each address is asked once per page).
	 *
	 * @param {string} query Address.
	 * @return {Promise<?Object>} Result or null.
	 */
	function geocode( query ) {
		const text = String( query || '' ).replace( /\s+/g, ' ' ).trim();
		if ( ! text ) {
			return Promise.resolve( null );
		}
		if ( ! answers[ text ] ) {
			answers[ text ] = ( 'google' === lafkaMaps.provider ? askGoogle( { address: text } ) : askProxy( { q: text } ) ).catch( ( error ) => {
				delete answers[ text ];
				throw error;
			} );
		}
		return answers[ text ];
	}

	/**
	 * The address at a point.
	 *
	 * @param {*} value Point.
	 * @return {Promise<?Object>} Result or null.
	 */
	function reverse( value ) {
		const point = toPoint( value );
		if ( ! point ) {
			return Promise.resolve( null );
		}
		return 'google' === lafkaMaps.provider ? askGoogle( { location: point } ) : askProxy( { lat: point.lat.toFixed( 6 ), lng: point.lng.toFixed( 6 ) } );
	}

	/**
	 * The browser's location (asks the customer).
	 *
	 * @return {Promise<{lat: number, lng: number}>} Point.
	 */
	function locate() {
		return new Promise( ( resolve, reject ) => {
			if ( ! window.navigator.geolocation ) {
				reject( new Error( i18n.locateFailed || '' ) );
				return;
			}
			window.navigator.geolocation.getCurrentPosition(
				( position ) => resolve( { lat: position.coords.latitude, lng: position.coords.longitude } ),
				() => reject( new Error( i18n.locateFailed || '' ) ),
				{ enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
			);
		} );
	}

	// ── Map adapters ────────────────────────────────────────────────────────
	//
	// Both return the same object:
	//   view( point, zoom )   centre (and zoom)
	//   fit( points )         show every point
	//   onClick( fn )         fn( point ) on a map click
	//   marker( point, { draggable, onMove } ) → { set( point ), get(), remove() }
	//   polygon( path, { color } )            → { points }
	//   circle( center, metres, { color } )   → { points } (its bounding box)
	//   label( point, text )
	//   editor( path, onChange )              editable polygon: click the map
	//                                         to add a corner, drag a corner to
	//                                         move it, click a corner to remove
	//                                         it, drag a midpoint to insert one
	//   refresh()             re-measure after the container was shown

	const STYLE = { strokeOpacity: 0.8, strokeWeight: 3, fillOpacity: 0.35 };

	/**
	 * @param {Element}                                 element Container.
	 * @param {{lat: number, lng: number, zoom: number}} view    Start view.
	 * @return {Object} Adapter.
	 */
	function createGoogleMap( element, view ) {
		const maps = window.google.maps;
		const map = new maps.Map( element, {
			center: { lat: view.lat, lng: view.lng },
			zoom: view.zoom,
			mapTypeControl: false,
			streetViewControl: false,
		} );
		const pointOf = ( latLng ) => ( { lat: latLng.lat(), lng: latLng.lng() } );
		const shapeOptions = ( color ) => ( {
			strokeColor: color,
			strokeOpacity: STYLE.strokeOpacity,
			strokeWeight: STYLE.strokeWeight,
			fillColor: color,
			fillOpacity: STYLE.fillOpacity,
		} );

		return {
			native: map,
			view( point, zoom ) {
				map.setCenter( point );
				if ( zoom ) {
					map.setZoom( zoom );
				}
			},
			fit( points ) {
				const list = ( points || [] ).map( toPoint ).filter( Boolean );
				if ( 1 === list.length ) {
					this.view( list[ 0 ], 15 );
				} else if ( list.length ) {
					const bounds = new maps.LatLngBounds();
					list.forEach( ( point ) => bounds.extend( point ) );
					map.fitBounds( bounds );
				}
			},
			onClick( fn ) {
				map.addListener( 'click', ( event ) => fn( pointOf( event.latLng ) ) );
			},
			marker( point, options = {} ) {
				const marker = new maps.Marker( { position: point, map, draggable: !! options.draggable } );
				if ( options.onMove ) {
					marker.addListener( 'dragend', () => options.onMove( pointOf( marker.getPosition() ) ) );
				}
				return {
					set: ( next ) => marker.setPosition( next ),
					get: () => pointOf( marker.getPosition() ),
					remove: () => marker.setMap( null ),
				};
			},
			polygon( path, options = {} ) {
				const points = path.map( toPoint ).filter( Boolean );
				new maps.Polygon( Object.assign( { paths: points, map, clickable: false }, shapeOptions( options.color || '#0073ff' ) ) );
				return { points };
			},
			circle( center, metres, options = {} ) {
				const circle = new maps.Circle( Object.assign( { center, radius: metres, map, clickable: false }, shapeOptions( options.color || '#0073ff' ) ) );
				const bounds = circle.getBounds();
				return { points: [ pointOf( bounds.getNorthEast() ), pointOf( bounds.getSouthWest() ) ] };
			},
			label( point, text ) {
				new maps.Marker( {
					position: point,
					map,
					clickable: false,
					label: { text: String( text ), className: 'lafka-map-label', fontSize: '12px' },
					icon: { path: 'M 0 0', fillOpacity: 0, strokeWeight: 0 },
				} );
			},
			editor( path, onChange ) {
				const polygon = new maps.Polygon( Object.assign( { paths: path.map( toPoint ).filter( Boolean ), map, editable: true }, shapeOptions( '#0073ff' ) ) );
				const changed = () => onChange( polygon.getPath().getArray().map( pointOf ) );
				const watch = () => {
					const ring = polygon.getPath();
					[ 'set_at', 'insert_at', 'remove_at' ].forEach( ( name ) => ring.addListener( name, changed ) );
				};
				watch();
				map.addListener( 'click', ( event ) => polygon.getPath().push( event.latLng ) );
				polygon.addListener( 'click', ( event ) => {
					if ( undefined !== event.vertex ) {
						polygon.getPath().removeAt( event.vertex );
					}
				} );
				return {
					points: () => polygon.getPath().getArray().map( pointOf ),
				};
			},
			refresh() {},
		};
	}

	/**
	 * A Leaflet divIcon pin (inline SVG, coloured by CSS).
	 *
	 * @return {Object} L.DivIcon.
	 */
	function pinIcon() {
		return window.L.divIcon( {
			className: 'lafka-map-pin',
			html: '<svg viewBox="0 0 24 32" width="30" height="40" aria-hidden="true" focusable="false"><path d="M12 31s10-10.4 10-18.5A10 10 0 0 0 2 12.5C2 20.6 12 31 12 31z"/><circle cx="12" cy="12.5" r="3.6"/></svg>',
			iconSize: [ 30, 40 ],
			iconAnchor: [ 15, 39 ],
		} );
	}

	/**
	 * @param {Element}                                 element Container.
	 * @param {{lat: number, lng: number, zoom: number}} view    Start view.
	 * @return {Object} Adapter.
	 */
	function createLeafletMap( element, view ) {
		const L = window.L;
		const map = L.map( element, { scrollWheelZoom: false } ).setView( [ view.lat, view.lng ], view.zoom );
		L.tileLayer( config.tileUrl || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: config.attribution || '',
			maxZoom: parseInt( config.maxZoom, 10 ) || 19,
		} ).addTo( map );
		// Wheel zoom only once the map has been clicked: the page scrolls past it.
		map.once( 'focus', () => map.scrollWheelZoom.enable() );
		const pointOf = ( latLng ) => ( { lat: latLng.lat, lng: latLng.lng } );
		const shapeOptions = ( color ) => ( {
			color,
			opacity: STYLE.strokeOpacity,
			weight: STYLE.strokeWeight,
			fillColor: color,
			fillOpacity: STYLE.fillOpacity,
		} );

		return {
			native: map,
			view( point, zoom ) {
				map.setView( [ point.lat, point.lng ], zoom || map.getZoom() );
			},
			fit( points ) {
				const list = ( points || [] ).map( toPoint ).filter( Boolean );
				if ( 1 === list.length ) {
					this.view( list[ 0 ], 15 );
				} else if ( list.length ) {
					map.fitBounds( L.latLngBounds( list.map( ( p ) => [ p.lat, p.lng ] ) ), { padding: [ 20, 20 ], maxZoom: 16 } );
				}
			},
			onClick( fn ) {
				map.on( 'click', ( event ) => fn( pointOf( event.latlng ) ) );
			},
			marker( point, options = {} ) {
				const marker = L.marker( [ point.lat, point.lng ], { icon: pinIcon(), draggable: !! options.draggable, autoPan: true } ).addTo( map );
				if ( options.onMove ) {
					marker.on( 'dragend', () => options.onMove( pointOf( marker.getLatLng() ) ) );
				}
				return {
					set: ( next ) => marker.setLatLng( [ next.lat, next.lng ] ),
					get: () => pointOf( marker.getLatLng() ),
					remove: () => marker.remove(),
				};
			},
			polygon( path, options = {} ) {
				const points = path.map( toPoint ).filter( Boolean );
				L.polygon(
					points.map( ( p ) => [ p.lat, p.lng ] ),
					Object.assign( { interactive: false }, shapeOptions( options.color || '#0073ff' ) )
				).addTo( map );
				return { points };
			},
			circle( center, metres, options = {} ) {
				const circle = L.circle( [ center.lat, center.lng ], Object.assign( { radius: metres, interactive: false }, shapeOptions( options.color || '#0073ff' ) ) ).addTo( map );
				const bounds = circle.getBounds();
				return { points: [ pointOf( bounds.getNorthEast() ), pointOf( bounds.getSouthWest() ) ] };
			},
			label( point, text ) {
				const span = document.createElement( 'span' );
				span.textContent = String( text );
				L.marker( [ point.lat, point.lng ], {
					interactive: false,
					keyboard: false,
					icon: L.divIcon( { className: 'lafka-map-label', html: span.outerHTML, iconSize: null } ),
				} ).addTo( map );
			},
			editor( path, onChange ) {
				return createLeafletEditor( map, path.map( toPoint ).filter( Boolean ), onChange, shapeOptions( '#0073ff' ) );
			},
			refresh() {
				map.invalidateSize();
			},
		};
	}

	/**
	 * The keyless polygon editor: the same gestures as Google's editable
	 * polygon (add, drag, remove corners; drag a midpoint to insert one).
	 *
	 * @param {Object}   map      L.Map.
	 * @param {Array}    start    Points.
	 * @param {Function} onChange Called with the points after each edit.
	 * @param {Object}   style    Polygon style.
	 * @return {Object} { points() }.
	 */
	function createLeafletEditor( map, start, onChange, style ) {
		const L = window.L;
		const points = start.slice();
		const shape = L.polygon( [], Object.assign( { interactive: false }, style ) ).addTo( map );
		const handles = L.layerGroup().addTo( map );
		const icon = ( className ) => L.divIcon( { className, iconSize: [ 14, 14 ], iconAnchor: [ 7, 7 ] } );
		const latLngs = () => points.map( ( p ) => [ p.lat, p.lng ] );
		const changed = () => onChange( points.slice() );

		const draw = () => {
			shape.setLatLngs( latLngs() );
			handles.clearLayers();
			points.forEach( ( point, index ) => {
				const vertex = L.marker( [ point.lat, point.lng ], { icon: icon( 'lafka-map-vertex' ), draggable: true, keyboard: false } );
				vertex.on( 'drag', ( event ) => {
					points[ index ] = { lat: event.latlng.lat, lng: event.latlng.lng };
					shape.setLatLngs( latLngs() );
				} );
				vertex.on( 'dragend', () => {
					draw();
					changed();
				} );
				vertex.on( 'click', () => {
					points.splice( index, 1 );
					draw();
					changed();
				} );
				handles.addLayer( vertex );
			} );
			if ( points.length < 2 ) {
				return;
			}
			points.forEach( ( point, index ) => {
				const after = points[ ( index + 1 ) % points.length ];
				const middle = L.marker( [ ( point.lat + after.lat ) / 2, ( point.lng + after.lng ) / 2 ], {
					icon: icon( 'lafka-map-midpoint' ),
					draggable: true,
					keyboard: false,
				} );
				const insert = ( latLng ) => {
					points.splice( index + 1, 0, { lat: latLng.lat, lng: latLng.lng } );
					draw();
					changed();
				};
				middle.on( 'dragend', () => insert( middle.getLatLng() ) );
				middle.on( 'click', () => insert( middle.getLatLng() ) );
				handles.addLayer( middle );
			} );
		};

		map.on( 'click', ( event ) => {
			points.push( { lat: event.latlng.lat, lng: event.latlng.lng } );
			draw();
			changed();
		} );
		draw();

		return {
			points: () => points.slice(),
		};
	}

	/**
	 * A map in `element`, opened on `view` (the default view when omitted).
	 *
	 * @param {Element} element Container.
	 * @param {Object}  [view]  { lat, lng, zoom }.
	 * @return {?Object} Adapter, or null when no provider is loaded.
	 */
	function createMap( element, view ) {
		if ( ! element ) {
			return null;
		}
		const start = Object.assign( defaultView(), view && toPoint( view ) ? toPoint( view ) : {}, view && view.zoom ? { zoom: parseInt( view.zoom, 10 ) } : {} );
		element.classList.add( 'lafka-map' );
		if ( 'google' === lafkaMaps.provider ) {
			return window.google && window.google.maps && window.google.maps.Map ? createGoogleMap( element, start ) : null;
		}
		return window.L ? createLeafletMap( element, start ) : null;
	}

	let googleLoaded = null;

	/**
	 * Resolves once the provider's library is usable (Google's loader finishes
	 * after the page scripts run; Leaflet is ready at once).
	 *
	 * @return {Promise<Object>} window.lafkaMaps.
	 */
	function ready() {
		if ( 'google' !== lafkaMaps.provider ) {
			// Geocoding and geometry need nothing more; map() needs Leaflet.
			return Promise.resolve( lafkaMaps );
		}
		if ( ! googleLoaded ) {
			googleLoaded = new Promise( ( resolve, reject ) => {
				let tries = 0;
				const check = () => {
					const maps = window.google && window.google.maps;
					if ( maps && maps.Map && maps.Geocoder ) {
						resolve( lafkaMaps );
					} else if ( ++tries > 300 ) {
						reject( new Error( i18n.geocodeFailed || '' ) );
					} else {
						window.setTimeout( check, 50 );
					}
				};
				check();
			} );
		}
		return googleLoaded;
	}

	const lafkaMaps = {
		provider: 'google' === config.provider ? 'google' : 'osm',
		defaults: defaultView(),
		i18n,
		ready,
		map: createMap,
		geocode,
		reverse,
		fromGoogle,
		locate,
		polyline,
		distance,
		contains,
		point: toPoint,
	};

	window.lafkaMaps = lafkaMaps;
} )( window, document );
