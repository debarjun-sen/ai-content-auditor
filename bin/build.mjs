#!/usr/bin/env node
/**
 * Build distributable plugin zips into dist/ with no external tools.
 *
 *   node bin/build.mjs
 *
 * Excludes tests, dev configs, vendor and other non-runtime files.
 * Requires Node 22+ (zlib.crc32).
 */
import { readdirSync, readFileSync, statSync, mkdirSync, writeFileSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { deflateRawSync, crc32 } from 'node:zlib';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath( new URL( '..', import.meta.url ) );
const PLUGINS = [ 'ai-content-auditor', 'ai-content-auditor-demo' ];
const EXCLUDE = [
	/^tests\//, /^vendor\//, /^node_modules\//, /^\.[^/]+/, /(^|\/)\.DS_Store$/,
	/^composer\.(json|lock)$/, /^phpunit.*\.xml(\.dist)?$/, /^phpcs\.xml(\.dist)?$/, /\.phpunit\.result\.cache$/,
];

function walk( dir, base = dir, out = [] ) {
	for ( const name of readdirSync( dir ) ) {
		const full = join( dir, name );
		const rel = relative( base, full ).split( sep ).join( '/' );
		if ( EXCLUDE.some( ( re ) => re.test( rel ) ) ) {
			continue;
		}
		if ( statSync( full ).isDirectory() ) {
			walk( full, base, out );
		} else {
			out.push( { full, rel } );
		}
	}
	return out;
}

function dosTime( d ) {
	return {
		time: ( d.getHours() << 11 ) | ( d.getMinutes() << 5 ) | ( d.getSeconds() >> 1 ),
		date: ( ( d.getFullYear() - 1980 ) << 9 ) | ( ( d.getMonth() + 1 ) << 5 ) | d.getDate(),
	};
}

function zip( entries ) {
	const locals = [];
	const centrals = [];
	let offset = 0;
	const { time, date } = dosTime( new Date() );
	for ( const { name, data } of entries ) {
		const nameBuf = Buffer.from( name, 'utf8' );
		const comp = deflateRawSync( data, { level: 9 } );
		const crc = crc32( data ) >>> 0;
		const local = Buffer.alloc( 30 );
		local.writeUInt32LE( 0x04034b50, 0 );
		local.writeUInt16LE( 20, 4 );
		local.writeUInt16LE( 0x0800, 6 ); // UTF-8 names.
		local.writeUInt16LE( 8, 8 );
		local.writeUInt16LE( time, 10 );
		local.writeUInt16LE( date, 12 );
		local.writeUInt32LE( crc, 14 );
		local.writeUInt32LE( comp.length, 18 );
		local.writeUInt32LE( data.length, 22 );
		local.writeUInt16LE( nameBuf.length, 26 );
		locals.push( local, nameBuf, comp );

		const central = Buffer.alloc( 46 );
		central.writeUInt32LE( 0x02014b50, 0 );
		central.writeUInt16LE( 20, 4 );
		central.writeUInt16LE( 20, 6 );
		central.writeUInt16LE( 0x0800, 8 );
		central.writeUInt16LE( 8, 10 );
		central.writeUInt16LE( time, 12 );
		central.writeUInt16LE( date, 14 );
		central.writeUInt32LE( crc, 16 );
		central.writeUInt32LE( comp.length, 20 );
		central.writeUInt32LE( data.length, 24 );
		central.writeUInt16LE( nameBuf.length, 28 );
		central.writeUInt32LE( offset, 42 );
		centrals.push( central, nameBuf );
		offset += 30 + nameBuf.length + comp.length;
	}
	const cdSize = centrals.reduce( ( n, b ) => n + b.length, 0 );
	const end = Buffer.alloc( 22 );
	end.writeUInt32LE( 0x06054b50, 0 );
	end.writeUInt16LE( entries.length, 8 );
	end.writeUInt16LE( entries.length, 10 );
	end.writeUInt32LE( cdSize, 12 );
	end.writeUInt32LE( offset, 16 );
	return Buffer.concat( [ ...locals, ...centrals, end ] );
}

mkdirSync( join( root, 'dist' ), { recursive: true } );
for ( const slug of PLUGINS ) {
	const files = walk( join( root, slug ) );
	const out = join( root, 'dist', slug + '.zip' );
	writeFileSync( out, zip( files.map( ( f ) => ( { name: slug + '/' + f.rel, data: readFileSync( f.full ) } ) ) ) );
	console.log( `✔ dist/${ slug }.zip (${ files.length } files)` );
}
