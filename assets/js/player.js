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
		function isPlyrFullscreen() {
			// Plyr fallback: class on <html> or on container
			if ( document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement ) return true;
			if ( root.classList.contains( 'plyr--fullscreen-fallback' ) || root.classList.contains( 'plyr--is-fullscreen' ) ) return true;
			var plyrEl = root.querySelector( '.plyr' );
			if ( plyrEl && ( plyrEl.classList.contains( 'plyr--fullscreen-enabled' ) || plyrEl.classList.contains( 'plyr--is-fullscreen' ) ) ) return true;
			if ( document.documentElement.classList.contains( 'plyr--fullscreen-fallback' ) ) return true;
			return false;
		}

		function resizeCanvas() {
			var vw = video.videoWidth || 0;
			var vh = video.videoHeight || 0;

			var rect;
			var fsEl = document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement;
			var inFs = !!( fsEl && ( fsEl === root || fsEl.contains( root ) || root.contains( fsEl ) ) ) || isPlyrFullscreen();

			if ( fsEl && ( fsEl === root || fsEl.contains( root ) || root.contains( fsEl ) ) ) {
				rect = fsEl.getBoundingClientRect();
				// اگر fullscreen است ولی rect هنوز کوچک است (transition)، از viewport استفاده کن
				if ( inFs && ( ! rect.width || rect.width < 200 || rect.height < 200 ) ) {
					rect = { width: window.innerWidth, height: window.innerHeight };
				}
			} else if ( inFs ) {
				rect = { width: window.innerWidth, height: window.innerHeight };
			} else {
				rect = root.getBoundingClientRect();
			}
			if ( ! rect.width || ! rect.height ) {
				rect = { width: window.innerWidth || document.documentElement.clientWidth, height: window.innerHeight || document.documentElement.clientHeight };
			}

			// اگر metadata هنوز نیامده، Canvas را به اندازهٔ کانتینر بساز (واترمارک باید حتی قبل از پخش دیده شود)
			if ( ! vw || ! vh ) {
				canvas.width = Math.max( 1, Math.round( rect.width ) );
				canvas.height = Math.max( 1, Math.round( rect.height ) );
				// اطمینان از اینکه Canvas حتی بدون ویدیو، کل کانتینر را پر می‌کند
				canvas.style.width = Math.round( rect.width ) + 'px';
				canvas.style.height = Math.round( rect.height ) + 'px';
				var b2 = ( typeof measureWatermarkBounds === 'function' ? measureWatermarkBounds() : null );
				if ( b2 ) {
					var maxX2 = canvas.width - b2.maxW - b2.pad;
					var maxY2 = canvas.height - b2.totalH - b2.pad;
					if ( state.wmX > maxX2 || state.wmY > maxY2 || state.wmX < b2.pad || state.wmY < b2.pad ) {
						moveWatermark();
					}
				}
				draw();
				return;
			}

			var scale = Math.min(
				rect.width / vw,
				rect.height / vh
			);

			if ( ! isFinite( scale ) || scale <= 0 ) {
				scale = 1;
			}

			canvas.width  = Math.max( 1, Math.round( vw * scale ) );
			canvas.height = Math.max( 1, Math.round( vh * scale ) );
			// در فول‌اسکرین، canvas باید دقیقاً وسط wrapper باشد و وسط‌چین شود (CSS margin:auto)
			// اما برای اطمینان، اندازهٔ استایل را هم ست می‌کنیم
			if ( inFs ) {
				canvas.style.width = canvas.width + 'px';
				canvas.style.height = canvas.height + 'px';
			}

			// اگر واترمارک بیرون از کادر جدید افتاد، دوباره داخل بیاور
			var bounds = ( typeof measureWatermarkBounds === 'function' ? measureWatermarkBounds() : null );
			if ( bounds ) {
				var maxX = canvas.width - bounds.maxW - bounds.pad;
				var maxY = canvas.height - bounds.totalH - bounds.pad;
				if ( state.wmX > maxX || state.wmY > maxY || state.wmX < bounds.pad || state.wmY < bounds.pad ) {
					moveWatermark();
				}
			}

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

			if ( ! ctx || ! canvas.width || ! canvas.height ) {
				return;
			}

			// اگر ویدیو هنوز فریم ندارد، پس‌زمینه سیاه بگذار و فقط واترمارک را بکش
			if ( ! video.videoWidth || ! video.videoHeight || video.readyState < 2 ) {
				ctx.fillStyle = '#000';
				ctx.fillRect( 0, 0, canvas.width, canvas.height );
				drawWatermark();
				return;
			}

			try {
				ctx.drawImage( video, 0, 0, canvas.width, canvas.height );
			} catch ( e ) {
				// حتی اگر drawImage شکست خورد، واترمارک را روی پس‌زمینه سیاه نشان بده
				ctx.fillStyle = '#000';
				ctx.fillRect( 0, 0, canvas.width, canvas.height );
				drawWatermark();
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
		function measureWatermarkBounds() {
		var wm = config.watermark || {};
		if ( ! state.ctx || ! wm.line1 ) {
			return null;
		}
		var ctx = state.ctx;
		var scale = 'number' === typeof wm.scale ? wm.scale : 1;
		var h = canvas.height || 0;
		var fontSize = Math.max( 11, Math.round( ( h / 42 ) * scale ) );
		var pad = 12;
		ctx.save();
		ctx.font = '600 ' + fontSize + 'px Tahoma, Arial, sans-serif';
		var w1 = ctx.measureText( wm.line1 ).width;
		var w2 = 0;
		if ( wm.line2 ) {
			ctx.font = Math.max( 10, fontSize - 2 ) + 'px Tahoma, Arial, sans-serif';
			w2 = ctx.measureText( wm.line2 ).width;
		}
		ctx.restore();
		var maxW = Math.max( w1, w2 );
		var totalH = fontSize + ( wm.line2 ? ( fontSize + 4 ) : 0 );
		return { maxW: maxW, totalH: totalH, pad: pad, fontSize: fontSize };
	}

	function moveWatermark() {
		if ( ! canvas.width || ! canvas.height ) {
			return;
		}
		var bounds = measureWatermarkBounds();
		if ( ! bounds ) {
			return;
		}
		var maxX = Math.max( bounds.pad, canvas.width - bounds.maxW - bounds.pad );
		var maxY = Math.max( bounds.pad, canvas.height - bounds.totalH - bounds.pad );
		if ( maxX <= bounds.pad || maxY <= bounds.pad ) {
			state.wmX = Math.round( ( canvas.width - bounds.maxW ) / 2 );
			state.wmY = Math.round( ( canvas.height - bounds.totalH ) / 2 );
		} else {
			state.wmX = Math.round( bounds.pad + Math.random() * Math.max( 1, maxX - bounds.pad ) );
			state.wmY = Math.round( bounds.pad + Math.random() * Math.max( 1, maxY - bounds.pad ) );
		}
		draw();
	}

	function scheduleWatermarkMove() {
		if ( state.destroyed ) {
			return;
		}
		var delay = 15000 + Math.random() * 15000;
		if ( state.moveTimer ) {
			clearTimeout( state.moveTimer );
		}
		state.moveTimer = setTimeout( function () {
			if ( ! video.paused && canvas.width ) {
				moveWatermark();
			}
			scheduleWatermarkMove();
		}, delay );
	}

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

		// نگه‌داشتن مرجع Plyr — فول‌اسکرین را خودِ Plyr روی .plyr انجام می‌دهد؛
		// بوم (canvas) باید داخل همان کانتینر باشد تا در تمام‌صفحه دیده شود.
		var plyrInstance = null;
		try {
			if ( window.Plyr ) {
				// eslint-disable-next-line no-new
				plyrInstance = new window.Plyr( video, {
					controls: [ 'play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'settings', 'fullscreen' ],
					settings: [ 'quality', 'speed' ],
					hideControls: true,
					clickToPlay: true,
					ratio: null,
					fullscreen: { enabled: true, fallback: true, iosNative: false, container: null }
				} );
				try {
					var plyrContainer = root.querySelector( '.plyr__video-wrapper' ) || root.querySelector( '.plyr' );
					if ( plyrContainer && canvas.parentNode === root ) {
						plyrContainer.appendChild( canvas );
						if ( pauseOverlay && pauseOverlay.parentNode === root ) {
							plyrContainer.appendChild( pauseOverlay );
						}
						if ( getComputedStyle( plyrContainer ).position === 'static' ) {
							plyrContainer.style.position = 'relative';
						}
					}
				} catch ( e ) {}
			} else {
				video.controls = true;
			}
		} catch ( e ) {
			// اگر Plyr خراب شد (مثل SyntaxError قبلی) فال‌بک به کنترل‌های بومی
			console.warn('Falnic Plyr init failed, fallback to native controls', e);
			video.classList.remove('falnic-hidden-video');
			video.style.setProperty('opacity','1','important');
			video.style.setProperty('pointer-events','auto','important');
			canvas.style.display = 'none';
			video.controls = true;
		}

		// در حالت فول‌اسکرین باید canvas دوباره اندازه بگیرد — واترمارک حتماً باید روی ویدیو دیده شود
		function onFullscreenChange() {
			setTimeout( function () {
				resizeCanvas();
				draw();
				// واترمارک را دوباره داخل کادر بیاور (مخصوصاً اگر اندازه عوض شده)
				try { moveWatermark(); } catch(e) {}
				draw();
				if ( ! video.paused ) {
					startLoop();
				}
			}, 150 );
			// Safari/transition fallback
			setTimeout( function () {
				resizeCanvas();
				draw();
			}, 600 );
		}
		document.addEventListener( 'fullscreenchange', onFullscreenChange );
		document.addEventListener( 'webkitfullscreenchange', onFullscreenChange );
		document.addEventListener( 'mozfullscreenchange', onFullscreenChange );
		document.addEventListener( 'MSFullscreenChange', onFullscreenChange );
		if ( plyrInstance && plyrInstance.on ) {
			plyrInstance.on( 'enterfullscreen', onFullscreenChange );
			plyrInstance.on( 'exitfullscreen', onFullscreenChange );
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

					console.warn('Falnic HLS fatal', data);
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
			// موقعیت اولیه واترمارک را داخل کادر بیاور
			if ( canvas.width && canvas.height ) {
				moveWatermark();
			}
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

		moveWatermark();
		scheduleWatermarkMove();

		// محافظت: پاک شدن Canvas = توقف کامل (ویدیو فقط روی Canvas دیده می‌شود)
		var tamperEl = document.createElement('div');
		tamperEl.className = 'falnic-tamper';
		tamperEl.textContent = '⚠️ دستکاری شناسایی شد — پخش متوقف شد.';
		var tamperParent = root.querySelector('.plyr__video-wrapper') || root.querySelector('.plyr') || root;
		try { tamperParent.appendChild(tamperEl); } catch(e) { root.appendChild(tamperEl); }
		function triggerTamper(){
			if(state.destroyed) return;
			state.destroyed=true; stopLoop();
			if(state.hls) try{state.hls.destroy()}catch(e){} state.hls=null;
			try{video.pause()}catch(e){}
			video.removeAttribute('src'); try{video.load()}catch(e){}
			try{ canvas.width=1; canvas.height=1; canvas.style.display='none'; }catch(e){}
			tamperEl.style.display='flex';
			console.warn('[Falnic] tamper detected');
		}
		try {
			var tObs = new MutationObserver(function(){ if(!root.contains(canvas)) triggerTamper(); });
			tObs.observe(root,{childList:true,subtree:true});
		} catch(e) {}
		canvas.addEventListener('contextmenu', function(e){ e.preventDefault(); });

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
			window.clearTimeout( state.moveTimer );
			window.removeEventListener( 'blur', onBlur );
			window.removeEventListener( 'focus', onFocus );
			window.removeEventListener( 'resize', resizeCanvas );
			document.removeEventListener( 'fullscreenchange', onFullscreenChange );
			document.removeEventListener( 'webkitfullscreenchange', onFullscreenChange );
			document.removeEventListener( 'mozfullscreenchange', onFullscreenChange );
			document.removeEventListener( 'MSFullscreenChange', onFullscreenChange );

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
