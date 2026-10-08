/**
 * [lafka_shipping_areas]: draw each map's delivery zones (Encoded Polyline
 * polygons, with optional labels) and the optional radius around the store,
 * then fit the view to them. With nothing to draw the map shows
 * window.lafkaMapDefaults.
 *
 * Each map reads its own settings from data-lafka-zones:
 *   { areas: [ { polygon, label, position, color } ],
 *     circle: { metres, label, color, store: { lat, lng } | null, storeAddress } | null }
 */
( function ( window, document ) {
	'use strict';

	const DEFAULT_COLOR = '#0073ff';

	/**
	 * Where a zone's label goes: the middle of its bounding box, or halfway
	 * from there to the named edge.
	 *
	 * @param {Array}  points   Polygon points.
	 * @param {string} position '', 'top', 'right', 'bottom' or 'left'.
	 * @return {{lat: number, lng: number}} Label point.
	 */
	function labelPoint( points, position ) {
		const lats = points.map( ( p ) => p.lat );
		const lngs = points.map( ( p ) => p.lng );
		const south = Math.min( ...lats );
		const north = Math.max( ...lats );
		const west = Math.min( ...lngs );
		const east = Math.max( ...lngs );
		const lat = ( south + north ) / 2;
		const lng = ( west + east ) / 2;
		switch ( position ) {
			case 'top':
				return { lat: ( lat + north ) / 2, lng };
			case 'bottom':
				return { lat: ( lat + south ) / 2, lng };
			case 'right':
				return { lat, lng: ( lng + east ) / 2 };
			case 'left':
				return { lat, lng: ( lng + west ) / 2 };
			default:
				return { lat, lng };
		}
	}

	/**
	 * The radius centre: the store point, else a geocode of the store address.
	 *
	 * @param {Object} maps   window.lafkaMaps.
	 * @param {Object} circle Circle settings.
	 * @return {Promise<?{lat: number, lng: number}>} Centre.
	 */
	function circleCentre( maps, circle ) {
		const store = maps.point( circle.store );
		if ( store ) {
			return Promise.resolve( store );
		}
		return maps.geocode( circle.storeAddress ).then( ( result ) => maps.point( result ), () => null );
	}

	/**
	 * @param {Object}  maps      window.lafkaMaps.
	 * @param {Element} container Map container.
	 */
	function draw( maps, container ) {
		let settings;
		try {
			settings = JSON.parse( container.getAttribute( 'data-lafka-zones' ) || '{}' );
		} catch {
			return;
		}
		const map = maps.map( container, maps.defaults );
		if ( ! map ) {
			return;
		}
		const all = [];
		( settings.areas || [] ).forEach( ( area ) => {
			const points = maps.polyline.decode( area.polygon );
			if ( points.length < 3 ) {
				return;
			}
			map.polygon( points, { color: area.color || DEFAULT_COLOR } );
			if ( area.label ) {
				map.label( labelPoint( points, area.position ), area.label );
			}
			all.push( ...points );
		} );
		map.fit( all );

		const circle = settings.circle;
		if ( circle && circle.metres > 0 ) {
			circleCentre( maps, circle ).then( ( centre ) => {
				if ( ! centre ) {
					return;
				}
				const drawn = map.circle( centre, circle.metres, { color: circle.color || DEFAULT_COLOR } );
				if ( circle.label ) {
					map.label( centre, circle.label );
				}
				map.fit( all.concat( drawn.points ) );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', () => {
		const containers = document.querySelectorAll( '.lafka-shipping-areas-shortcode-map[data-lafka-zones]' );
		if ( ! containers.length || ! window.lafkaMaps ) {
			return;
		}
		window.lafkaMaps.ready().then( ( maps ) => containers.forEach( ( container ) => draw( maps, container ) ) );
	} );
} )( window, document );
