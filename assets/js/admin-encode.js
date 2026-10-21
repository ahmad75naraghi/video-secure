/**
 * وضعیت زنده‌ی صف انکود در صفحه‌ی ویرایش ویدیو.
 *
 * - Polling سبک فقط وقتی کاری در جریان است.
 * - متن‌ها فقط با textContent نوشته می‌شوند (بدون تزریق HTML).
 *
 * @package Falnic\SecureHLS
 */

( function () {
	'use strict';

	var POLL_INTERVAL = 4000;

	/**
	 * ارسال درخواست POST به admin-ajax.
	 *
	 * @param {string} url   آدرس.
	 * @param {Object} data  داده.
	 * @return {Promise<Object>} پاسخ JSON.
	 */
	function post( url, data ) {
		var body = Object.keys( data )
			.map( function ( key ) {
				return encodeURIComponent( key ) + '=' + encodeURIComponent( data[ key ] );
			} )
			.join( '&' );

		return fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return null;
			} );
		} );
	}

	/**
	 * راه‌اندازی یک باکس وضعیت.
	 *
	 * @param {HTMLElement} box باکس.
	 */
	function mount( box ) {
		var bar     = box.querySelector( '.falnic-encode-bar span' );
		var message = box.querySelector( '.falnic-encode-message' );
		var cancel  = box.querySelector( '.falnic-encode-cancel' );
		var retry   = box.querySelector( '.falnic-encode-retry' );
		var details = box.querySelector( '.falnic-encode-log' );
		var timer   = 0;
		var busy    = false;

		var payload = {
			post_id: box.getAttribute( 'data-post' ),
			nonce: box.getAttribute( 'data-nonce' )
		};

		function setBar( percent ) {
			if ( bar ) {
				bar.style.width = Math.max( 0, Math.min( 100, percent ) ) + '%';
			}
		}

		function setMessage( text ) {
			if ( message && 'string' === typeof text ) {
				message.textContent = text;
			}
		}

		function setButtons( state, percent ) {
			var active = 'queued' === state || 'running' === state;

			if ( cancel ) {
				cancel.hidden = ! active;
			}

			if ( retry ) {
				retry.hidden = ! ( 'failed' === state || 'canceled' === state );
			}

			if ( details && 'failed' !== state ) {
				details.hidden = true;
			}

			box.setAttribute( 'data-active', active ? '1' : '0' );

			if ( 'done' === state ) {
				setBar( 100 );
			} else if ( 'number' === typeof percent ) {
				setBar( percent );
			}
		}

		function poll() {
			if ( busy ) {
				return;
			}

			busy = true;

			post( box.getAttribute( 'data-status-url' ), payload ).then( function ( response ) {
				busy = false;

				if ( ! response || ! response.success || ! response.data ) {
					return;
				}

				var data = response.data;

				setButtons( data.state, data.percent );
				setMessage( data.message );

				if ( data.errors && details ) {
					var pre = details.querySelector( 'pre' );

					if ( pre ) {
						pre.textContent = data.errors;
					}

					details.hidden = 'failed' !== data.state;
				}

				if ( 'done' === data.state || 'failed' === data.state || 'canceled' === data.state ) {
					stop();
				}
			} ).catch( function () {
				busy = false;
			} );
		}

		function start() {
			stop();
			timer = window.setInterval( poll, POLL_INTERVAL );
		}

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = 0;
			}
		}

		if ( cancel ) {
			cancel.addEventListener( 'click', function () {
				cancel.disabled = true;

				post( box.getAttribute( 'data-cancel-url' ), payload ).then( function ( response ) {
					cancel.disabled = false;

					if ( response && response.success && response.data ) {
						setMessage( response.data.message );
						poll();
					}
				} ).catch( function () {
					cancel.disabled = false;
				} );
			} );
		}

		if ( retry ) {
			retry.addEventListener( 'click', function () {
				retry.disabled = true;

				post( box.getAttribute( 'data-retry-url' ), payload ).then( function ( response ) {
					retry.disabled = false;

					if ( response && response.success && response.data ) {
						setMessage( response.data.message );
						setButtons( 'queued', 0 );
						start();
					} else if ( response && response.data && response.data.message ) {
						setMessage( response.data.message );
					}
				} ).catch( function () {
					retry.disabled = false;
				} );
			} );
		}

		if ( '1' === box.getAttribute( 'data-active' ) ) {
			start();
		}
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-falnic-encode]' ), mount );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
