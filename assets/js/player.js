/**
 * Falnic Secure HLS — پلیر سمت مرورگر
 *
 * اصول:
 *  - بدون eval، بدون debugger، بدون دستکاری document.body
 *  - همه‌ی دارایی‌ها محلی (hls.js و Plyr داخل پلاگین)
 *  - هر نمونه مستقل است (چند ویدیو در یک صفحه)
 *  - واترمارک روی canvas ترکیب می‌شود، پس در ضبط صفحه هم دیده می‌شود
 *
 * @package Falnic\SecureHLS
 */

( function () {
	'use strict';

	var WATERMARK_MOVE_INTERVAL = 30000;

	/**
	 * راه‌اندازی یک پلیر.
	 *
	 * @param {HTMLElement} root کانتینر پلیر.
	 */
	function mount( root ) {
		var configNode = root.querySelector( '.falnic-player-config' );
		var video      = root.querySelector( 'video' );
		var canvas     = document.createElement( 'canvas' );

		if ( ! configNode || ! video ) {
			return;
		}

		var config;
		try {
			config = JSON.parse( configNode.textContent || '{}' );
		} catch ( e ) {
			return;
		}

		if ( ! config.src ) {
			return;
		}

		var state = {
			hls: null,
			raf: 0,
			wmX: 30,
			wmY: 40,
			ctx: null,
			moveTimer: 0,
			destroyed: false
		};

		var i18n = config.i18n || {};

		canvas.className = 'falnic-canvas-overlay';
		root.appendChild( canvas );
		state.ctx = canvas.getContext( '2d' );

		/* ---------------------------------------------------------------------
		 * اندازه‌ی canvas بر اساس ابعاد نمایش، نه ابعاد منبع
		 * ------------------------------------------------------------------ */
		function resizeCanvas() {
			if ( ! video.videoWidth || ! video.videoHeight ) {
				return;
			}

			var rect  = root.getBoundingClientRect();
			var scale = Math.min(
				rect.width / video.videoWidth,
				rect.height / video.videoHeight
			);

			if ( ! isFinite( scale ) || scale <= 0 ) {
				scale = 1;
			}

			canvas.width  = Math.max( 1, Math.round( video.videoWidth * scale ) );
			canvas.height = Math.max( 1, Math.round( video.videoHeight * scale ) );

			draw();
		}

		/* ---------------------------------------------------------------------
		 * رسم فریم + واترمارک
		 * ------------------------------------------------------------------ */
		function drawWatermark() {
			var ctx = state.ctx;
			var wm  = config.watermark || {};

			if ( ! ctx || ! wm.line1 ) {
				return;
			}

			var w = canvas.width;
			var h = canvas.height;
			var fontSize = Math.max( 11, Math.round( h / 42 ) );

			ctx.save();
			ctx.textBaseline = 'top';
			ctx.shadowColor  = 'rgba(0,0,0,0.8)';
			ctx.shadowBlur   = 3;
			ctx.fillStyle    = 'rgba(255,255,255,0.45)';

			ctx.font = '600 ' + fontSize + 'px Tahoma, Arial, sans-serif';
			ctx.fillText( wm.line1, state.wmX, state.wmY );

			if ( wm.line2 ) {
				ctx.font = Math.max( 10, fontSize - 2 ) + 'px Tahoma, Arial, sans-serif';
				ctx.fillText( wm.line2, state.wmX, state.wmY + fontSize + 4 );
			}

			ctx.restore();
		}

		function draw() {
			var ctx = state.ctx;

			if ( ! ctx || ! video.videoWidth || ! canvas.width ) {
				return;
			}

			try {
				ctx.drawImage( video, 0, 0, canvas.width, canvas.height );
			} catch ( e ) {
				return;
			}

			drawWatermark();
		}

		/* ---------------------------------------------------------------------
		 * حلقه‌ی رسم
		 * ------------------------------------------------------------------ */
		function loop() {
			if ( state.destroyed ) {
				return;
			}

			draw();
			state.raf = window.requestAnimationFrame( loop );
		}

		function startLoop() {
			if ( ! state.raf && ! state.destroyed ) {
				state.raf = window.requestAnimationFrame( loop );
			}
		}

		function stopLoop() {
			if ( state.raf ) {
				window.cancelAnimationFrame( state.raf );
				state.raf = 0;
			}
			draw();
		}

		/* ---------------------------------------------------------------------
		 * جابه‌جایی واترمارک
		 * ------------------------------------------------------------------ */
		function moveWatermark() {
			if ( ! canvas.width || video.paused ) {
				return;
			}

			var maxX = Math.max( 20, canvas.width - Math.round( canvas.width * 0.35 ) );
			var maxY = Math.max( 30, canvas.height - Math.round( canvas.height * 0.15 ) );

			state.wmX = Math.round( 20 + Math.random() * Math.max( 1, maxX - 20 ) );
			state.wmY = Math.round( 30 + Math.random() * Math.max( 1, maxY - 30 ) );

			draw();
		}

		/* ---------------------------------------------------------------------
		 * لایه‌ی «برای ادامه کلیک کنید» هنگام از دست دادن فوکوس
		 * ------------------------------------------------------------------ */
		function buildPauseOverlay() {
			var overlay = document.createElement( 'button' );

			overlay.type      = 'button';
			overlay.className = 'falnic-pause-overlay';
			overlay.textContent = i18n.resume || '';
			root.appendChild( overlay );

			overlay.addEventListener( 'click', function () {
				overlay.classList.remove( 'is-visible' );
				if ( video.paused ) {
					var playing = video.play();
					if ( playing && playing.catch ) {
						playing.catch( function () {} );
					}
				}
			} );

			return overlay;
		}

		var pauseOverlay = buildPauseOverlay();

		function onBlur() {
			if ( ! video.paused ) {
				video.pause();
				pauseOverlay.classList.add( 'is-visible' );
			}
		}

		function onFocus() {
			pauseOverlay.classList.remove( 'is-visible' );
		}

		window.addEventListener( 'blur', onBlur );
		window.addEventListener( 'focus', onFocus );

		/* ---------------------------------------------------------------------
		 * نمایش خطا
		 * ------------------------------------------------------------------ */
		function showError( message ) {
			var box = document.createElement( 'div' );

			box.className   = 'falnic-error';
			box.textContent = message || i18n.error || '';
			root.appendChild( box );
			stopLoop();
		}

		/* ---------------------------------------------------------------------
		 * راه‌اندازی منبع ویدیو
		 * ------------------------------------------------------------------ */
		video.addEventListener( 'loadedmetadata', resizeCanvas );
		video.addEventListener( 'loadeddata', draw );
		video.addEventListener( 'seeked', draw );
		video.addEventListener( 'pause', stopLoop );
		video.addEventListener( 'play', startLoop );
		video.addEventListener( 'ended', stopLoop );
		video.addEventListener( 'contextmenu', function ( e ) {
			e.preventDefault();
		} );

		window.addEventListener( 'resize', resizeCanvas );

		if ( window.Plyr ) {
			// eslint-disable-next-line no-new
			new window.Plyr( video, {
				controls: [ 'play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'settings', 'fullscreen' ],
				settings: [ 'quality', 'speed' ],
				hideControls: true,
				clickToPlay: true,
				ratio: null,
				fullscreen: { enabled: true, fallback: true, iosNative: false, container: null }
			} );
		} else {
			video.controls = true;
		}

		if ( window.Hls && window.Hls.isSupported() ) {
			state.hls = new window.Hls( {
				xhrSetup: function ( xhr, url ) {
					if ( url.indexOf( 'secure_hls_key' ) !== -1 ) {
						xhr.withCredentials = true;
					}
				}
			} );

			state.hls.on( window.Hls.Events.ERROR, function ( event, data ) {
				if ( ! data || ! data.fatal ) {
					return;
				}

				if ( data.type === window.Hls.ErrorTypes.NETWORK_ERROR ) {
					state.hls.startLoad();

					return;
				}

				if ( data.type === window.Hls.ErrorTypes.MEDIA_ERROR ) {
					state.hls.recoverMediaError();

					return;
				}

				showError( i18n.error );
			} );

			state.hls.loadSource( config.src );
			state.hls.attachMedia( video );
		} else if ( video.canPlayType( 'application/vnd.apple.mpegurl' ) ) {
			video.src = config.src;
		} else {
			showError( i18n.unsupported );
		}

		state.moveTimer = window.setInterval( moveWatermark, WATERMARK_MOVE_INTERVAL );

		/* ---------------------------------------------------------------------
		 * پاک‌سازی
		 * ------------------------------------------------------------------ */
		function destroy() {
			if ( state.destroyed ) {
				return;
			}

			state.destroyed = true;
			stopLoop();
			window.clearInterval( state.moveTimer );
			window.removeEventListener( 'blur', onBlur );
			window.removeEventListener( 'focus', onFocus );
			window.removeEventListener( 'resize', resizeCanvas );

			if ( state.hls ) {
				state.hls.destroy();
			}

			if ( pauseOverlay && pauseOverlay.parentNode ) {
				pauseOverlay.parentNode.removeChild( pauseOverlay );
			}
		}

		video.falnicDestroy = destroy;
	}

	/**
	 * راه‌اندازی همه‌ی پلیرهای صفحه.
	 */
	function boot() {
		var roots = document.querySelectorAll( '[data-falnic-player]' );

		Array.prototype.forEach.call( roots, function ( root ) {
			if ( ! root.dataset.falnicReady ) {
				root.dataset.falnicReady = '1';
				mount( root );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	window.FalnicPlayer = { mount: mount };
}() );
