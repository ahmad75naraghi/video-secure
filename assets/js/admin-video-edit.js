/**
 * تعاملات صفحه‌ی ویرایش ویدیوی امن.
 *
 * - جست‌وجوی زنده روی select های دوره/درس (بدون وابستگی خارجی)
 * - کپی شورت‌کد و لینک امن با Clipboard API + fallback
 * - هیچ innerHTML — فقط textContent / value
 *
 * @package Falnic\SecureHLS
 */
(function () {
	'use strict';

	/**
	 * کپی متن به کلیپ‌بورد.
	 *
	 * @param {string} text متن.
	 * @return {Promise<boolean>}
	 */
	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).then(
				function () { return true; },
				function () { return fallbackCopy( text ); }
			);
		}
		return Promise.resolve( fallbackCopy( text ) );
	}

	/**
	 * fallback برای HTTP یا مرورگر قدیمی.
	 *
	 * @param {string} text متن.
	 * @return {boolean}
	 */
	function fallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		var ok = false;
		try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
		document.body.removeChild( ta );
		return ok;
	}

	/**
	 * اتصال دکمه‌های کپی.
	 */
	function initCopy() {
		document.querySelectorAll( '[data-falnic-copy-btn]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var row = btn.closest( '.falnic-copy-row' );
				var input = row ? row.querySelector( '[data-falnic-copy]' ) : null;
				if ( ! input || ! input.value ) return;

				var original = btn.textContent;
				copyText( input.value ).then( function ( ok ) {
					if ( ok ) {
						btn.textContent = btn.getAttribute( 'data-copied-text' ) || '✓ کپی شد';
						btn.classList.add( 'is-copied' );
						window.setTimeout( function () {
							btn.textContent = original;
							btn.classList.remove( 'is-copied' );
						}, 1800 );
						input.select();
					}
				} );
			} );
		} );
	}

	/**
	 * جست‌وجوی زنده روی یک select.
	 *
	 * @param {HTMLElement} wrap کانتینر.
	 */
	function initSearch( wrap ) {
		var input = wrap.querySelector( '.falnic-ve-search' );
		var select = wrap.querySelector( 'select[data-falnic-select]' );
		if ( ! input || ! select ) return;

		var options = Array.prototype.slice.call( select.options );

		function filter() {
			var q = input.value.trim().toLowerCase();
			if ( ! q ) {
				options.forEach( function ( o ) { o.hidden = false; } );
				return;
			}
			options.forEach( function ( o ) {
				// گزینه‌ی خالی همیشه بماند.
				if ( '0' === o.value ) { o.hidden = false; return; }
				var t = ( o.textContent || '' ).toLowerCase();
				o.hidden = t.indexOf( q ) === -1;
			} );
		}

		input.addEventListener( 'input', filter );
		// پاک‌سازی با Escape.
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				input.value = '';
				filter();
			}
		} );
	}

	function boot() {
		initCopy();
		document.querySelectorAll( '[data-falnic-search]' ).forEach( initSearch );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}());
