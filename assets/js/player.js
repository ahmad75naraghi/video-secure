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

	var DEFAULT_WATERMARK_INTERVAL = 30000;

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

		if ( ! config.src && ! config.sessionUrl ) {
			return;
		}

		var state = {
			hls: null,
			raf: 0,
			wmX: 30,
			wmY: 40,
			ctx: null,
			moveTimer: 0,
			destroyed: false,
			loading: false,
			retried: false,
			session: null,
			heartbeatTimer: 0,
			beatFailures: 0,
			endSent: false
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
			var opacity = 'number' === typeof wm.opacity ? wm.opacity : 0.45;
			var scale = 'number' === typeof wm.scale ? wm.scale : 1;
			var fontSize = Math.max( 11, Math.round( ( h / 42 ) * scale ) );

			ctx.save();
			ctx.textBaseline = 'top';
			ctx.shadowColor  = 'rgba(0,0,0,0.8)';
			ctx.shadowBlur   = 3;
			ctx.fillStyle    = 'rgba(255,255,255,' + opacity + ')';

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
			if ( root.querySelector( '.falnic-error' ) ) {
				return;
			}

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

		/* ---------------------------------------------------------------------
		 * نشست پخش: توکن کوتاه‌عمر پیش از هر بار بارگذاری
		 * ------------------------------------------------------------------ */
		function fetchSession() {
			var body = 'video=' + encodeURIComponent( config.name || '' );

			return fetch( config.sessionUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body
			} ).then( function ( response ) {
				return response.json().catch( function () {
					return null;
				} );
			} ).then( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data || ! payload.data.src ) {
					var message = payload && payload.data && payload.data.message
						? payload.data.message
						: ( i18n.sessionError || i18n.error );

					if ( payload && payload.data && 'falnic_too_many_plays' === payload.data.reason ) {
						message = payload.data.message || i18n.multiDevice || message;
					}

					throw new Error( message );
				}

				return payload.data;
			} );
		}

		function startStream( src ) {
			if ( ! src ) {
				showError( i18n.error );
				return;
			}

			if ( window.Hls && window.Hls.isSupported() ) {
				state.hls = new window.Hls( {
					xhrSetup: function ( xhr ) {
						xhr.withCredentials = true;
					}
				} );

				state.hls.on( window.Hls.Events.ERROR, function ( event, data ) {
					if ( ! data || ! data.fatal ) {
						return;
					}

					if ( data.type === window.Hls.ErrorTypes.NETWORK_ERROR ) {
						if ( ! state.retried && config.sessionUrl ) {
							state.retried = true;
							destroyStream();
							loadStream();
							return;
						}

						state.hls.startLoad();

						return;
					}

					if ( data.type === window.Hls.ErrorTypes.MEDIA_ERROR ) {
						state.hls.recoverMediaError();

						return;
					}

					showError( i18n.error );
				} );

				state.hls.loadSource( src );
				state.hls.attachMedia( video );

				return;
			}

			if ( video.canPlayType( 'application/vnd.apple.mpegurl' ) ) {
				video.src = src;

				return;
			}

			showError( i18n.unsupported );
		}

		function destroyStream() {
			if ( state.hls ) {
				state.hls.destroy();
				state.hls = null;
			}
		}

		function applyWatermark( watermark ) {
			if ( ! watermark || ! watermark.line1 ) {
				return;
			}

			config.watermark = Object.assign( {}, config.watermark || {}, watermark );
			draw();
		}

		/* ---------------------------------------------------------------------
		 * زیرنویس‌ها: هر بار پس از نشست، با نشانی امضاشده تازه ساخته می‌شوند
		 * ------------------------------------------------------------------ */
		function setSubtitles( subtitles ) {
			Array.prototype.forEach.call(
				video.querySelectorAll( 'track[data-falnic]' ),
				function ( track ) {
					if ( track.parentNode ) {
						track.parentNode.removeChild( track );
					}
				}
			);

			if ( ! subtitles || ! subtitles.length ) {
				return;
			}

			subtitles.forEach( function ( item, index ) {
				if ( ! item || ! item.src ) {
					return;
				}

				var track = document.createElement( 'track' );

				track.kind    = 'captions';
				track.label   = item.label || item.lang || '';
				track.srclang = item.lang || '';
				track.src     = item.src;
				track.setAttribute( 'data-falnic', '1' );

				if ( 0 === index ) {
					track.default = true;
				}

				video.appendChild( track );
			} );
		}

		/* ---------------------------------------------------------------------
		 * ضربان نشست: اگر مدیر پخش را قطع کند یا دستگاه دیگری جلو بزند،
		 * پلیر همان لحظه متوقف می‌شود.
		 * ------------------------------------------------------------------ */
		function stopHeartbeat() {
			if ( state.heartbeatTimer ) {
				window.clearInterval( state.heartbeatTimer );
				state.heartbeatTimer = 0;
			}
		}

		function sendEnd() {
			var session = state.session;

			if ( state.endSent || ! session || ! session.sid || ! config.endUrl ) {
				return;
			}

			state.endSent = true;

			var body = 'video=' + encodeURIComponent( config.name || '' ) +
				'&sid=' + encodeURIComponent( session.sid ) +
				'&t=' + encodeURIComponent( session.heartbeat || '' );

			if ( navigator.sendBeacon ) {
				try {
					navigator.sendBeacon( config.endUrl, new Blob( [ body ], { type: 'application/x-www-form-urlencoded' } ) );
					return;
				} catch ( e ) {}
			}

			fetch( config.endUrl, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body
			} ).catch( function () {} );
		}

		function startHeartbeat( session ) {
			stopHeartbeat();

			if ( ! session || ! session.sid || ! session.heartbeat || ! config.heartbeatUrl ) {
				return;
			}

			state.beatFailures = 0;

			state.heartbeatTimer = window.setInterval( function () {
				var body = 'video=' + encodeURIComponent( config.name || '' ) +
					'&sid=' + encodeURIComponent( session.sid ) +
					'&t=' + encodeURIComponent( session.heartbeat );

				fetch( config.heartbeatUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body
				} ).then( function ( response ) {
					return response.json().catch( function () {
						return null;
					} );
				} ).then( function ( payload ) {
					if ( payload && payload.success && payload.data && payload.data.alive ) {
						state.beatFailures = 0;
						return;
					}

					var message = payload && payload.data && payload.data.message
						? payload.data.message
						: ( i18n.sessionEnded || i18n.error );

					stopHeartbeat();
					destroyStream();

					try {
						video.pause();
					} catch ( e ) {}

					showError( message );
				} ).catch( function () {
					// خطای شبکه‌ی گذرا؛ چند بار دیگر تلاش می‌شود.
					state.beatFailures++;

					if ( state.beatFailures >= 4 ) {
						stopHeartbeat();
					}
				} );
			}, 30000 );
		}

		// با bfcache (بازگشت با دکمه‌ی Back) نباید نشست بسته شود.
		window.addEventListener( 'pagehide', function ( event ) {
			if ( ! event || true !== event.persisted ) {
				sendEnd();
			}
		} );

		window.addEventListener( 'pageshow', function ( event ) {
			if ( event && true === event.persisted ) {
				state.endSent = false;
			}
		} );

		function loadStream() {
			if ( ! config.sessionUrl ) {
				startStream( config.src );
				return;
			}

			if ( state.loading || state.destroyed ) {
				return;
			}

			state.loading = true;

			fetchSession().then( function ( session ) {
				state.loading = false;
				state.session = session;
				applyWatermark( session.watermark );
				setSubtitles( session.subtitles );
				startHeartbeat( session );
				startStream( session.src || config.src );
			} ).catch( function ( error ) {
				state.loading = false;
				showError( error && error.message ? error.message : ( i18n.sessionError || i18n.error ) );
			} );
		}

		loadStream();

		var moveInterval = config.watermark && config.watermark.interval
			? Math.max( 5000, parseInt( config.watermark.interval, 10 ) * 1000 )
			: DEFAULT_WATERMARK_INTERVAL;

		state.moveTimer = window.setInterval( moveWatermark, moveInterval );

		/* ---------------------------------------------------------------------
		 * پاک‌سازی
		 * ------------------------------------------------------------------ */
		function destroy() {
			if ( state.destroyed ) {
				return;
			}

			state.destroyed = true;
			stopLoop();
			stopHeartbeat();
			sendEnd();
			window.clearInterval( state.moveTimer );
			window.removeEventListener( 'blur', onBlur );
			window.removeEventListener( 'focus', onFocus );
			window.removeEventListener( 'resize', resizeCanvas );

			destroyStream();

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
