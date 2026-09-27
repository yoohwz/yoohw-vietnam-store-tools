'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const path = require( 'node:path' );

const source = fs.readFileSync( path.join( __dirname, '../assets/js/bacs-vietqr-copy.js' ), 'utf8' );

async function runCase( className, clipboardResult ) {
	let handler;
	let copied = '';
	let legacyCalls = 0;
	let prompted = false;
	const attributes = {
		'data-vck-copy': '123456789',
		'data-vck-copy-label': 'Copy account number',
		'data-vck-copied-label': 'Copied',
		'aria-label': 'Copy account number',
	};
	const button = {
		classList: { contains: name => name === className, add() {}, remove() {} },
		getAttribute: key => Object.prototype.hasOwnProperty.call( attributes, key ) ? attributes[key] : null,
		setAttribute: ( key, value ) => { attributes[key] = value; },
		removeAttribute: key => { delete attributes[key]; },
		textContent: 'Copy account number',
	};
	const textarea = { setAttribute() {}, style: {}, select() {} };
	const context = {
		Promise,
		navigator: { clipboard: { writeText: text => clipboardResult( text ) } },
		window: { isSecureContext: true, setTimeout: () => 1, clearTimeout() {}, prompt: () => { prompted = true; } },
		document: {
			addEventListener: ( type, listener ) => { assert.equal( type, 'click' ); handler = listener; },
			createElement: () => textarea,
			body: { appendChild() {}, removeChild() {} },
			execCommand: command => { assert.equal( command, 'copy' ); ++legacyCalls; copied = textarea.value; return true; },
		},
	};
	vm.runInNewContext( source, context );
	handler( { target: { closest: selector => { assert.equal( selector, '.vck-vietqr-copy' ); return 'vck-vietqr-copy' === className ? button : null; } }, preventDefault() {} } );
	await new Promise( resolve => setImmediate( resolve ) );
	return { button, copied, legacyCalls, prompted, attributes };
}

( async function() {
	let copied = '';
	let result = await runCase( 'vck-vietqr-copy', text => { copied = text; return Promise.resolve(); } );
	assert.equal( copied, '123456789' );
	assert.equal( result.legacyCalls, 0 );
	assert.equal( result.attributes['aria-label'], 'Copied' );
	assert.equal( result.prompted, false );

	result = await runCase( 'vck-vietqr-copy', () => Promise.reject( new Error( 'clipboard denied' ) ) );
	assert.equal( result.copied, '123456789' );
	assert.equal( result.legacyCalls, 1 );
	assert.equal( result.prompted, false );
	result = await runCase( 'vck-payment-copy', () => Promise.reject( new Error( 'removed control' ) ) );
	assert.equal( result.legacyCalls, 0 );
	assert.equal( result.prompted, false );
	console.log( 'PASS: VietQR clipboard and fallback; removed payment controls ignored.' );
}() ).catch( error => { console.error( error ); process.exitCode = 1; } );
