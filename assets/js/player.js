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
			endSent: false,
			pausedDrawTimer: 0,
			visibilityTimer: 0,
			observing: false,
			ro: null,
			io: null
		};

		// نمونه‌ی Plyr (بعداً مقداردهی می‌شود)؛ توابع اندازه‌گیری به آن نیاز دارند.
		var plyrInstance = null;

		var i18n = config.i18n || {};
		// دیباگ فقط وقتی سرور آن را برای مدیر فعال کرده باشد (?falnic_debug=1 + manage_options)
		var isDebug = !!config.debug;
		function dlog(){ if(isDebug) try{ console.log.apply(console, ['[Falnic]'].concat([].slice.call(arguments))); }catch(e){} }
		function updateDebug(extra){
			if(!isDebug) return;
			try{
				var el = root.querySelector('.falnic-debug');
				if(!el){ el=document.createElement('div'); el.className='falnic-debug is-visible'; root.appendChild(el); }
				else el.classList.add('is-visible');
				var vw = video.videoWidth||0, vh=video.videoHeight||0, rect=root.getBoundingClientRect();
				var info = {
					video: config.name||'',
					session: state.session ? {sid: state.session.sid, hasSrc: !!state.session.src} : null,
					videoEl: { vw:vw, vh:vh, readyState: video.readyState, paused: video.paused, ended: video.ended, error: video.error? (video.error.message||video.error.code) : null, currentSrc: (video.currentSrc||video.src||'').slice(0,120) },
					rect: {w: Math.round(rect.width), h: Math.round(rect.height), top: Math.round(rect.top)},
					canvas: {w: canvas.width, h: canvas.height, sw: canvas.style.width, sh: canvas.style.height},
					plyr: !!root.querySelector('.plyr'),
					hls: state.hls ? 'attached' : 'none',
					extra: extra||''
				};
				el.textContent = '[Falnic debug]\n' + JSON.stringify(info, null, 2) + (extra ? '\n---\n'+String(extra) : '');
			}catch(e){}
		}

		canvas.className = 'falnic-canvas-overlay';
		root.appendChild( canvas );
		state.ctx = canvas.getContext( '2d' );
		dlog('mount', {id: config.id, name: config.name, hasPlyr: !!window.Plyr, hasHls: !!(window.Hls && window.Hls.isSupported && window.Hls.isSupported())});

		/* ---------------------------------------------------------------------
		 * اندازه‌ی canvas بر اساس ابعاد نمایش، نه ابعاد منبع
		 * ------------------------------------------------------------------ */
		/**
		 * آیا پلیر الآن در حالت تمام‌صفحه است؟
		 *
		 * فقط حالت «فعال» حساب می‌شود. کلاس `plyr--fullscreen-enabled` فقط می‌گوید مرورگر
		 * تمام‌صفحه را پشتیبانی می‌کند (همیشه روی .plyr هست) و نباید به‌عنوان نشانه‌ی
		 * «در حال تمام‌صفحه» استفاده شود؛ استفاده‌ی قبلی باعث می‌شد Canvas همیشه به‌اندازه‌ی
		 * کل پنجره ساخته شود نه کانتینر.
		 */
		function isFullscreenActive() {
			var fsEl = document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement;
			if ( fsEl && ( fsEl === root || fsEl.contains( root ) || root.contains( fsEl ) ) ) {
				return true;
			}
			// تمام‌صفحه‌ی شبیه‌سازی‌شده‌ی Plyr (iOS و مرورگرهای بدون Fullscreen API)
			if ( plyrInstance && plyrInstance.fullscreen && plyrInstance.fullscreen.active ) {
				return true;
			}
			var plyrEl = root.querySelector( '.plyr' );
			return !!( plyrEl && plyrEl.classList.contains( 'plyr--fullscreen-fallback' ) );
		}

		function getVisibleRect(){
			var rect = root.getBoundingClientRect();
			// تب لیرن‌دش / آکاردئون: المنت اول display:none است → rect صفر
			if(rect.width < 10 || rect.height < 10){
				// سعی کن والد قابل مشاهده را پیدا کنی
				var visParent = root.parentElement;
				while(visParent){
					var pr = visParent.getBoundingClientRect();
					if(pr.width > 20 && pr.height > 20){ rect = {width: pr.width, height: pr.width * 9/16}; break; }
					visParent = visParent.parentElement;
					if(!visParent || visParent === document.body) break;
				}
				if(rect.width < 10){
					var fallbackW = root.clientWidth || root.offsetWidth || (root.parentElement && root.parentElement.clientWidth) || 640;
					if(fallbackW < 10) fallbackW = 640;
					rect = {width: fallbackW, height: fallbackW * 9/16};
				}
			}
			return rect;
		}

		/**
		 * جعبه‌ای که ویدیو باید داخل آن جا شود: همان والدِ Canvas (wrapper پلیر).
		 * اگر والد هنوز اندازه ندارد (تب مخفی)، به کانتینر و سپس به والدهای دیدنی برمی‌گردد.
		 */
		function getBox() {
			var host = canvas.parentNode || root;
			var rect = host.getBoundingClientRect();
			if ( rect.width >= 10 && rect.height >= 10 ) {
				return rect;
			}
			return getVisibleRect();
		}

		function resizeCanvas() {
			var vw = video.videoWidth || 0;
			var vh = video.videoHeight || 0;
			var rect = getBox();

			// تمام‌صفحه در میانه‌ی transition: اگر هنوز جعبه کوچک است، از اندازه‌ی پنجره استفاده کن
			if ( isFullscreenActive() && ( rect.width < 200 || rect.height < 200 ) ) {
				rect = { width: window.innerWidth, height: window.innerHeight };
			}
			if ( ! rect.width || ! rect.height ) {
				rect = { width: window.innerWidth || document.documentElement.clientWidth, height: window.innerHeight || document.documentElement.clientHeight };
			}

			var boxW = Math.max( 1, Math.round( rect.width ) );
			var boxH = Math.max( 1, Math.round( rect.height ) );
			var cw = boxW;
			var ch = boxH;

			// تا رسیدن metadata، Canvas به‌اندازه‌ی جعبه است (واترمارک باید حتی قبل از پخش دیده شود)؛
			// بعد از آن، به نسبت واقعی ویدیو و بدون بیرون‌زدگی از جعبه.
			if ( vw && vh ) {
				var scale = Math.min( boxW / vw, boxH / vh );

				if ( ! isFinite( scale ) || scale <= 0 ) {
					scale = 1;
				}

				cw = Math.max( 1, Math.round( vw * scale ) );
				ch = Math.max( 1, Math.round( vh * scale ) );
			}

			// تغییر width/height بوم را پاک می‌کند؛ فقط وقتی لازم است انجام شود.
			if ( canvas.width !== cw || canvas.height !== ch ) {
				canvas.width  = cw;
				canvas.height = ch;
			}
			canvas.style.width  = cw + 'px';
			canvas.style.height = ch + 'px';

			// اگر واترمارک بیرون از کادر جدید افتاد، دوباره داخل بیاور
			var bounds = measureWatermarkBounds();
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
			// همیشه متن ساده (بدون innerHTML)؛ خط‌های جدید با CSS (white-space: pre-line) نمایش داده می‌شوند.
			box.textContent = message || i18n.error || '';
			root.appendChild( box );
			// هرگز سیاهِ خالی نماند: اگر fetch هنوز در حال لود بود، لودینگ را پنهان کن
			var loading = root.querySelector('.falnic-preview-loading, .falnic-player-loading');
			if (loading) loading.style.display='none';
			stopLoop();
			console.error('[Falnic] showError:', message);
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
		try {
			if ( window.Plyr ) {
				var plyrOptions = {
					controls: [ 'play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'settings', 'fullscreen' ],
					settings: [ 'speed' ],
					hideControls: true,
					clickToPlay: true,
					volume: 1,
					ratio: null,
					fullscreen: { enabled: true, fallback: true, iosNative: false, container: null }
				};

				// آیکن‌های Plyr به‌صورت پیش‌فرض از cdn.plyr.io گرفته می‌شوند؛ روی شبکه‌های محدود
				// (یا با فیلتر) دکمه‌ها نامرئی می‌شوند. نسخه‌ی محلی همراه پلاگین است.
				if ( config.iconUrl ) {
					plyrOptions.iconUrl = config.iconUrl;
					plyrOptions.loadSprite = true;
				}

				// eslint-disable-next-line no-new
				plyrInstance = new window.Plyr( video, plyrOptions );
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
				var ct = response.headers.get('content-type') || '';
				var isJson = ct.indexOf('application/json') !== -1;
				if (!isJson && !response.ok) {
					return response.text().then(function(t){ throw new Error(t || 'HTTP '+response.status); });
				}
				return response.json().catch( function () {
					return null;
				} ).then(function(payload){
					// اگر 403 با debug برای ادمین بود، جزئیات را لاگ کن
					if (payload && !payload.success && payload.data && payload.data.debug) {
						console.warn('[Falnic] access debug', payload.data.debug);
						// برای ادمین پیام دقیق‌تری نشان بده
						if (payload.data.debug.reason) {
							var d = payload.data.debug;
							var extra = '\n[debug] video:' + d.name + ' reason:' + d.reason + ' course:' + (d.manual_course||d.manual.course) + ' enrolled:' + d.manual_enrolled + ' ld_active:' + d.learn_dash;
							payload.data.message = (payload.data.message || '') + extra;
						}
					}
					return payload;
				});
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
				dlog('startStream: no src');
				return;
			}
			dlog('startStream', src.slice(0,120));

			if ( window.Hls && window.Hls.isSupported() ) {
				state.hls = new window.Hls( {
					xhrSetup: function ( xhr ) {
						xhr.withCredentials = true;
					}
				} );
				dlog('HLS created, supported');

				state.hls.on( window.Hls.Events.MEDIA_ATTACHED, function(){ dlog('HLS MEDIA_ATTACHED'); });
				state.hls.on( window.Hls.Events.MANIFEST_PARSED, function(ev,data){
					dlog('HLS MANIFEST_PARSED', data);
					updateDebug('MANIFEST_PARSED levels='+(data && data.levels ? data.levels.length : '?'));
					// مثل پیش‌نمایش: بعد از manifest باید resize + play
					resizeCanvas();
					if(loadTimeout) { clearTimeout(loadTimeout); loadTimeout=0; }
					// اگر تب هنوز مخفی بود، بعد از نمایان شدن دوباره resize خواهیم کرد
					// تلاش برای پخش خودکار با صدا؛ اگر مرورگر اجازه نداد، ویدیو روی فریم اول می‌ماند
					// و دکمه‌ی بزرگ پخش Plyr دیده می‌شود (بدون بی‌صدا کردنِ اجباری).
					var started = null;
					try { started = video.play(); } catch(e){}
					if ( started && started.then ) {
						started.then(function(){ dlog('play() ok after manifest'); updateDebug('playing'); }).catch(function(e){
							dlog('autoplay blocked', e && e.message);
							updateDebug('autoplay blocked: '+(e && e.message));
						});
					}
				});
				state.hls.on( window.Hls.Events.FRAG_LOADED, function(ev,data){ if(isDebug && data && data.frag) dlog('FRAG', data.frag.sn, data.frag.url && data.frag.url.slice(-30)); });
				state.hls.on( window.Hls.Events.LEVEL_LOADED, function(ev,data){ dlog('LEVEL_LOADED', data && data.details && data.details.live); });

				state.hls.on( window.Hls.Events.ERROR, function ( event, data ) {
					dlog('HLS ERROR', data);
					updateDebug('HLS ERROR '+JSON.stringify(data && {type:data.type, details:data.details, fatal:data.fatal, response:data.networkDetails && data.networkDetails.status}).slice(0,300));
					if ( ! data || ! data.fatal ) {
						return;
					}

					if ( data.type === window.Hls.ErrorTypes.NETWORK_ERROR ) {
						if ( ! state.retried && config.sessionUrl ) {
							state.retried = true;
							dlog('NETWORK_ERROR retry with new session');
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
					showError( i18n.error + ' (HLS fatal: '+(data.details||data.type)+')' );
				} );

				state.hls.loadSource( src );
				state.hls.attachMedia( video );

				return;
			}

			if ( video.canPlayType( 'application/vnd.apple.mpegurl' ) ) {
				dlog('native HLS');
				video.src = src;
				video.addEventListener('loadedmetadata', function nativeMeta(){ dlog('native loadedmetadata', video.videoWidth, video.videoHeight); resizeCanvas(); video.removeEventListener('loadedmetadata', nativeMeta); });
				return;
			}

			showError( i18n.unsupported );
			dlog('unsupported');
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

		var loadTimeout = 0;
		function loadStream() {
			if ( ! config.sessionUrl ) {
				startStream( config.src );
				return;
			}

			if ( state.loading || state.destroyed ) {
				return;
			}

			state.loading = true;
			dlog('fetchSession start', config.name);
			updateDebug('fetching session…');
			if(loadTimeout) clearTimeout(loadTimeout);
			loadTimeout = setTimeout(function(){
				if(state.loading && !state.destroyed){
					dlog('loadTimeout fired');
					updateDebug('fetch timeout');
					showError('بارگذاری بیش از حد طول کشید — اتصال را بررسی کنید');
				}
			}, 15000);

			fetchSession().then( function ( session ) {
				if(loadTimeout){ clearTimeout(loadTimeout); loadTimeout=0; }
				state.loading = false;
				state.session = session;
				dlog('session ok', {sid: session.sid, hasSrc: !!session.src, watermark: session.watermark});
				updateDebug('session ok');
				applyWatermark( session.watermark );
				setSubtitles( session.subtitles );
				startHeartbeat( session );
				startStream( session.src || config.src );
				// اگر والد مخفی بود، بعد از نمایان شدن دوباره resize کن
				setupVisibilityObserver();
			} ).catch( function ( error ) {
				if(loadTimeout){ clearTimeout(loadTimeout); loadTimeout=0; }
				state.loading = false;
				dlog('session FAIL', error && error.message);
				updateDebug('session FAIL: '+(error && error.message));
				showError( error && error.message ? error.message : ( i18n.sessionError || i18n.error ) );
			} );
		}

		// اگر کانتینر اول مخفی بود (تب/آکاردئون لرن‌دش)، وقتی نمایان یا بزرگ‌تر شد دوباره سایز بگیر.
		// فقط یک‌بار راه‌اندازی می‌شود (تلاش دوباره‌ی نشست ناظر تازه نمی‌سازد).
		function setupVisibilityObserver(){
			if(state.observing) return;
			state.observing = true;
			var lastW=0;
			state.visibilityTimer = setInterval(function(){
				if(state.destroyed){ clearInterval(state.visibilityTimer); state.visibilityTimer=0; return; }
				var r = root.getBoundingClientRect();
				if(r.width > 10 && r.width !== lastW){
					dlog('visibility changed', r);
					lastW = r.width;
					resizeCanvas();
					updateDebug('visibility change');
				}
			}, 400);
			// بعد از چند ثانیه چک دوره‌ای کافی است؛ ResizeObserver بقیه را پوشش می‌دهد.
			setTimeout(function(){ if(state.visibilityTimer){ clearInterval(state.visibilityTimer); state.visibilityTimer=0; } }, 8000);
			try{
				if(window.ResizeObserver){
					state.ro = new ResizeObserver(function(){ if(!state.destroyed){ resizeCanvas(); updateDebug('resizeObserver'); } });
					state.ro.observe(root);
				}
			}catch(e){}
			try{
				if(window.IntersectionObserver){
					state.io = new IntersectionObserver(function(entries){
						entries.forEach(function(ent){ if(ent.isIntersecting && !state.destroyed){ resizeCanvas(); updateDebug('intersection visible'); } });
					}, {threshold: 0.1});
					state.io.observe(root);
				}
			}catch(e){}
		}

		loadStream();

		moveWatermark();
		scheduleWatermarkMove();

		// Canvas روی ویدیو است. با Plyr، کلیک روی Canvas تا کانتینر Plyr بالا می‌رود و خودِ Plyr
		// play/pause می‌کند؛ هندلر جداگانه اینجا باعث می‌شد هر کلیک دو بار toggle شود (بی‌اثر).
		// فقط در حالت بدون Plyr (کنترل‌های بومی) خودمان toggle می‌کنیم.
		canvas.style.pointerEvents = 'auto';
		if ( ! plyrInstance ) {
			canvas.addEventListener('click', function(){
				if(state.destroyed) return;
				if(video.paused) { video.play().catch(function(){}); }
				else video.pause();
			});
		}
		video.addEventListener('loadeddata', function(){ dlog('video:loadeddata->draw'); resizeCanvas(); draw(); });
		video.addEventListener('canplay', function(){ dlog('video:canplay'); resizeCanvas(); draw(); });
		// حتی وقتی pause هستیم هر 600ms یک فریم بکش تا کاور سیاه نماند (فریم اول را نشان بده)
		state.pausedDrawTimer = setInterval(function(){
			if(state.destroyed) { clearInterval(state.pausedDrawTimer); state.pausedDrawTimer=0; return; }
			if(video.paused && video.readyState>=2 && video.videoWidth){
				resizeCanvas(); // سایز ممکن است عوض شده باشد (ریسپانسیو)
			}
		}, 600);

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

		// دیباگ: اگر بعد از 4 ثانیه هنوز MANIFEST_PARSED نیامده، لاگ بده تا سیاهی بی‌دلیل نماند
		setTimeout(function(){
			if(state.destroyed) return;
			dlog('4s check', {hasSession: !!state.session, hasHls: !!state.hls, vw: video.videoWidth, vh: video.videoHeight, readyState: video.readyState, rect: root.getBoundingClientRect()});
			updateDebug('4s check');
			if(!state.session) return;
			if(!video.videoWidth && !state.hls) {
				console.warn('[Falnic] still no video dimensions after 4s — session ok but HLS not started', state.session);
				updateDebug('⚠️ HLS not started after 4s');
			}
			// اگر هنوز کانواس 1×1 است یعنی تب مخفی بوده — سعی مجدد
			if(canvas.width <= 10 || canvas.height <= 10){
				dlog('canvas tiny after 4s, retry resize');
				resizeCanvas();
				updateDebug('retry resize (canvas tiny)');
			}
		}, 4000);
		// اگر ادمین/دیباگ: هر 2 ثانیه debug را تازه کن
		if(isDebug) setInterval(function(){ if(!state.destroyed) updateDebug(); }, 2000);
		// لاگ Plyr
		dlog('Plyr instance', !!window.Plyr);
		// ویدیو ایونت‌ها
		['loadstart','waiting','stalled','suspend','loadedmetadata','loadeddata','canplay','canplaythrough','playing','pause','ended','error'].forEach(function(ev){
			video.addEventListener(ev, function(){ dlog('video:'+ev, {vw:video.videoWidth, vh:video.videoHeight, rs:video.readyState, err: video.error && video.error.code}); updateDebug('video:'+ev); if(ev==='loadedmetadata' || ev==='loadeddata' || ev==='canplay'){ resizeCanvas(); } });
		});

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
			window.clearInterval( state.pausedDrawTimer );
			window.clearInterval( state.visibilityTimer );
			if ( state.ro ) { try { state.ro.disconnect(); } catch ( e ) {} state.ro = null; }
			if ( state.io ) { try { state.io.disconnect(); } catch ( e ) {} state.io = null; }
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
