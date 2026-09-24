#!/usr/bin/env node
/**
 * Minify-only build for the plugin's first-party scripts.
 *
 * Every committed `*.min.js` whose readable `*.js` source sits next to it is
 * regenerated from that source with esbuild in minify-only mode (no bundling,
 * no syntax lowering). Output is deterministic, so CI can rebuild and fail on
 * a diff — the shipped `.min.js` can never drift from its source. Vendored
 * libraries (flatpickr, jquery.schedule) are not first-party and are skipped.
 *
 * At runtime the `.js` source is enqueued when SCRIPT_DEBUG is on, the
 * `.min.js` otherwise.
 *
 * Usage: `npm run build`  (or `node scripts/build-assets.mjs`)
 */

import { transform } from 'esbuild';
import { readdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, relative } from 'node:path';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const SCAN = [ 'assets/js', 'incl' ];
const SKIP_DIRS = new Set( [ 'node_modules', 'vendor', 'flatpickr', 'schedule' ] );

/**
 * Readable first-party sources that have a `.min.js` sibling.
 *
 * @param {string} dir Absolute directory to scan.
 * @returns {string[]} Absolute source paths.
 */
function sources( dir ) {
	const out = [];
	for ( const entry of readdirSync( dir, { withFileTypes: true } ) ) {
		const path = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			if ( ! SKIP_DIRS.has( entry.name ) ) {
				out.push( ...sources( path ) );
			}
		} else if ( entry.name.endsWith( '.js' ) && ! entry.name.endsWith( '.min.js' ) && existsSync( path.replace( /\.js$/, '.min.js' ) ) ) {
			out.push( path );
		}
	}
	return out;
}

async function main() {
	const files = SCAN.flatMap( ( dir ) => sources( join( ROOT, dir ) ) ).sort();
	for ( const src of files ) {
		const code = readFileSync( src, 'utf8' );
		const result = await transform( code, { loader: 'js', minify: true, legalComments: 'none' } );
		writeFileSync( src.replace( /\.js$/, '.min.js' ), result.code );
		console.log( `  ${ relative( ROOT, src ) } -> .min.js (${ Buffer.byteLength( code ) } -> ${ Buffer.byteLength( result.code ) } B)` );
	}
	console.log( `build-assets: minified ${ files.length } file(s).` );
}

main().catch( ( err ) => {
	console.error( 'build-assets failed:', err );
	process.exit( 1 );
} );
