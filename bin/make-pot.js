#!/usr/bin/env node
/**
 * ساخت قالب ترجمه (POT) بدون وابستگی خارجی.
 *
 * چرا: افزونه هیچ وابستگی Composer ندارد و در CI نمی‌خواهیم WP-CLI نصب کنیم.
 * این اسکریپت رشته‌های ترجمه‌پذیر را از فایل‌های PHP جمع می‌کند و یک فایل
 * استاندارد Gettext می‌سازد.
 *
 * اجرا:
 *   node bin/make-pot.js            # نوشتن languages/falnic-secure-hls.pot
 *   node bin/make-pot.js --check    # فقط بررسی به‌روز بودن (برای CI)
 *
 * @package Falnic\SecureHLS
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const DOMAIN = 'falnic-secure-hls';
const OUTPUT = path.join( ROOT, 'languages', DOMAIN + '.pot' );
const CHECK = process.argv.includes( '--check' );

/**
 * فهرست توابع ترجمه: نام → [آرگومان متن, آرگومان domain, آرگومان context]
 */
const FUNCTIONS = {
	__: [ 0, 1 ],
	_e: [ 0, 1 ],
	esc_html__: [ 0, 1 ],
	esc_html_e: [ 0, 1 ],
	esc_attr__: [ 0, 1 ],
	esc_attr_e: [ 0, 1 ],
	esc_html_x: [ 0, 1, 2 ],
	esc_attr_x: [ 0, 1, 2 ],
	_x: [ 0, 1, 2 ],
	_n: [ 0, 1, 3 ],
	_nx: [ 0, 1, 4 ]
};

/**
 * فهرست بازگشتی فایل‌های PHP.
 *
 * @param {string} dir پوشه.
 * @return {string[]} مسیرها.
 */
function phpFiles( dir ) {
	const out = [];

	fs.readdirSync( dir ).forEach( ( name ) => {
		if ( [ 'node_modules', 'vendor', '.git', '.arena' ].includes( name ) ) {
			return;
		}

		const full = path.join( dir, name );
		const stat = fs.statSync( full );

		if ( stat.isDirectory() ) {
			out.push( ...phpFiles( full ) );
			return;
		}

		if ( name.endsWith( '.php' ) ) {
			out.push( full );
		}
	} );

	return out;
}

/**
 * خواندن یک رشته‌ی PHP با پشتیبانی از نقل‌قول یگانه/دوتایی.
 *
 * @param {string} code متن کامل.
 * @param {number} start اندیس شروع (بعد از نقل‌قول).
 * @return {{value: string, end: number}|null} نتیجه.
 */
function readString( code, start ) {
	const quote = code[ start - 1 ];

	if ( quote !== '"' && quote !== "'" ) {
		return null;
	}

	let index = start;
	let value = '';

	while ( index < code.length ) {
		const char = code[ index ];

		if ( char === '\\' ) {
			const next = code[ index + 1 ];

			if ( quote === "'" ) {
				// در نقل‌قول یگانه فقط \\ و \' معنا دارند.
				value += next === "'" || next === '\\' ? next : '\\' + next;
			} else {
				value += next === undefined ? '' : next;
			}

			index += 2;
			continue;
		}

		if ( char === quote ) {
			return { value, end: index + 1 };
		}

		value += char;
		index++;
	}

	return null;
}

/**
 * استخراج فراخوانی‌های ترجمه از یک فایل.
 *
 * @param {string} code    متن.
 * @param {string} relPath مسیر نسبی برای مرجع.
 * @return {object[]} رشته‌ها.
 */
function extract( code, relPath ) {
	const entries = [];
	const pattern = new RegExp(
		'(?:^|[^\\w$>])(' + Object.keys( FUNCTIONS ).join( '|' ) + ')\\s*\\(',
		'g'
	);
	const commentPattern = /\/\*([^*]|\*(?!\/))*\*\//g;
	let match;

	while ( ( match = pattern.exec( code ) ) !== null ) {
		const name = match[ 1 ];
		const args = FUNCTIONS[ name ];
		const open = match.index + match[ 0 ].length;

		// تقسیم آرگومان‌ها با احترام به پرانتز/کاما داخل رشته.
		const values = [];
		let index = open;
		let depth = 0;
		let current = '';
		let currentStart = open;

		while ( index < code.length ) {
			const char = code[ index ];

			if ( char === '"' || char === "'" ) {
				const read = readString( code, index + 1 );

				if ( ! read ) {
					break;
				}

				current += char + code.slice( index + 1, read.end - 1 ) + char;
				index = read.end;
				continue;
			}

			if ( '(' === char || '[' === char ) {
				depth++;
			} else if ( ')' === char || ']' === char ) {
				if ( 0 === depth ) {
					values.push( { text: current.trim(), start: currentStart } );
					break;
				}

				depth--;
			} else if ( ',' === char && 0 === depth ) {
				values.push( { text: current.trim(), start: currentStart } );
				current = '';
				currentStart = index + 1;
				index++;
				continue;
			}

			current += char;
			index++;
		}

		if ( values.length < 2 ) {
			continue;
		}

		const literal = ( argIndex ) => {
			const raw = values[ argIndex ];
			if ( ! raw ) {
				return null;
			}

			const trimmed = raw.text;
			const quote = trimmed[ 0 ];

			if ( quote !== '"' && quote !== "'" ) {
				return null;
			}

			const read = readString( trimmed, 1 );

			return read && read.end === trimmed.length ? read.value : null;
		};

		const domain = literal( args[ 1 ] );

		if ( domain !== DOMAIN ) {
			continue;
		}

		const msgid = literal( args[ 0 ] );

		if ( null === msgid || '' === msgid ) {
			continue;
		}

		const context = args[ 2 ] !== undefined ? literal( args[ 2 ] ) : null;
		const pluralArg = args[ 3 ];
		const msgidPlural = args.length > 3 && 2 === args[ 3 ] ? literal( 1 ) : null;

		// کامنت مترجم بلافاصله قبل از فراخوانی.
		let translators = '';
		commentPattern.lastIndex = 0;
		let comment;
		let lastComment = null;

		while ( ( comment = commentPattern.exec( code ) ) !== null ) {
			if ( comment.index >= match.index ) {
				break;
			}
			lastComment = comment;
		}

		if ( lastComment && match.index - ( lastComment.index + lastComment[ 0 ].length ) < 40 ) {
			const body = lastComment[ 0 ].replace( /^\/\*|\*\/$/g, '' ).replace( /^\s*\*\s?/gm, '' ).trim();

			if ( /^translators:/i.test( body ) ) {
				translators = body;
			}
		}

		const line = code.slice( 0, match.index ).split( '\n' ).length;
		const entry = {
			msgid,
			msgidPlural: pluralArg === undefined ? null : msgidPlural,
			context,
			translators,
			ref: relPath + ':' + line
		};

		if ( 'string' === typeof entry.msgidPlural ) {
			entries.push( entry );
		} else {
			entries.push( entry );
		}
	}

	return entries;
}

/**
 * escape کردن رشته برای قالب PO.
 *
 * @param {string} value مقدار.
 * @return {string} رشته‌ی امن.
 */
function esc( value ) {
	return String( value )
		.replace( /\\/g, '\\\\' )
		.replace( /"/g, '\\"' )
		.replace( /\n/g, '\\n' )
		.replace( /\t/g, '\\t' );
}

// جمع‌آوری.
const files = phpFiles( ROOT ).sort();
const collected = [];

files.forEach( ( file ) => {
	const rel = path.relative( ROOT, file ).split( path.sep ).join( '/' );
	collected.push( ...extract( fs.readFileSync( file, 'utf8' ), rel ) );
} );

// ادغام بر اساس context + msgid.
const merged = new Map();

collected.forEach( ( entry ) => {
	const key = ( entry.context || '' ) + '\u0004' + entry.msgid + '\u0004' + ( entry.msgidPlural || '' );
	const current = merged.get( key );

	if ( current ) {
		if ( ! current.refs.includes( entry.ref ) ) {
			current.refs.push( entry.ref );
		}

		if ( ! current.translators && entry.translators ) {
			current.translators = entry.translators;
		}

		return;
	}

	merged.set( key, {
		msgid: entry.msgid,
		msgidPlural: entry.msgidPlural,
		context: entry.context,
		translators: entry.translators,
		refs: [ entry.ref ]
	} );
} );

const list = Array.from( merged.values() ).sort( ( a, b ) => {
	if ( a.context !== b.context ) {
		return String( a.context ).localeCompare( String( b.context ) );
	}

	return a.msgid.localeCompare( b.msgid );
} );

const header = [
	'# Copyright (C) 2026 Ahmad Naraghi',
	'# This file is distributed under the GPL-2.0-or-later license.',
	'msgid ""',
	'msgstr ""',
	'"Project-Id-Version: Falnic Secure HLS Player 3.4.0\\n"',
	'"Report-Msgid-Bugs-To: https://github.com/ahmad75naraghi/video-secure/issues\\n"',
	'"POT-Creation-Date: 2026-10-20T00:00:00+00:00\\n"',
	'"MIME-Version: 1.0\\n"',
	'"Content-Type: text/plain; charset=UTF-8\\n"',
	'"Content-Transfer-Encoding: 8bit\\n"',
	'"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"',
	'"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"',
	'"Language-Team: LANGUAGE <LL@li.org>\\n"',
	'"Language: fa_IR\\n"',
	'"Plural-Forms: nplurals=2; plural=(n > 1);\\n"',
	'"X-Domain: ' + DOMAIN + '\\n"',
	'"X-Generator: bin/make-pot.js\\n"'
].join( '\n' );

const blocks = list.map( ( entry ) => {
	const lines = [];

	if ( entry.translators ) {
		lines.push( '#. ' + entry.translators );
	}

	entry.refs.forEach( ( ref ) => lines.push( '#: ' + ref ) );

	if ( entry.context ) {
		lines.push( 'msgctxt "' + esc( entry.context ) + '"' );
	}

	lines.push( 'msgid "' + esc( entry.msgid ) + '"' );

	if ( entry.msgidPlural ) {
		lines.push( 'msgid_plural "' + esc( entry.msgidPlural ) + '"' );
		lines.push( 'msgstr[0] ""' );
		lines.push( 'msgstr[1] ""' );
	} else {
		lines.push( 'msgstr ""' );
	}

	return lines.join( '\n' );
} );

const content = header + '\n\n' + blocks.join( '\n\n' ) + '\n';

if ( CHECK ) {
	const existing = fs.existsSync( OUTPUT ) ? fs.readFileSync( OUTPUT, 'utf8' ) : '';

	if ( existing !== content ) {
		console.error( 'FAIL  ' + path.relative( ROOT, OUTPUT ) + ' به‌روز نیست؛ node bin/make-pot.js را اجرا کنید.' );
		process.exit( 1 );
	}

	console.log( 'OK  ' + list.length + ' رشته در قالب ترجمه، فایل به‌روز است.' );
	process.exit( 0 );
}

fs.mkdirSync( path.dirname( OUTPUT ), { recursive: true } );
fs.writeFileSync( OUTPUT, content, 'utf8' );

console.log( 'نوشته شد: ' + path.relative( ROOT, OUTPUT ) + ' — ' + list.length + ' رشته' );
