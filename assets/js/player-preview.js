/**
 * Falnic Secure HLS — پلیر پیش‌نمایش حرفه‌ای (ادمین) — Canvas
 * واترمارک روی Canvas (غیرقابل حذف) + پرش آنی بدون ترنزیشن
 * هر 15-30 ثانیه رندوم داخل کادر ویدیو + فول‌اسکرین درست (نام کاربری همیشه روی ویدیو)
 */
(function () {
	'use strict';

	function mountPreview(root) {
		var configNode = root.querySelector('.falnic-player-config');
		var video = root.querySelector('video');
		var canvas = root.querySelector('canvas.falnic-preview-canvas');
		var loadingEl = root.querySelector('.falnic-preview-loading');
		var errorEl = root.querySelector('.falnic-preview-error');
		var wrap = root.querySelector('.falnic-preview-video-wrap');

		if (!configNode || !video || !canvas || !wrap) {
			console.warn('[Falnic Preview] missing elements', root);
			return;
		}

		var ctx = canvas.getContext('2d');
		if (!ctx) {
			console.error('[Falnic Preview] canvas 2d not supported');
			return;
		}

		var config;
		try {
			config = JSON.parse(configNode.textContent || '{}');
		} catch (e) {
			showError('پیکربندی پلیر خوانده نشد');
			return;
		}

		if (!config.sessionUrl) {
			showError('آدرس نشست یافت نشد');
			return;
		}

		var state = {
			hls: null,
			session: null,
			wmTimer: 0,
			heartbeatTimer: 0,
			destroyed: false,
			loading: false,
			wmX: 20,
			wmY: 20,
			redirectingFullscreen: false
		};

		var i18n = config.i18n || {};

		// ---------- Canvas سایز ----------
		function getFullscreenElement() {
			return document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement || null;
		}

		function resizeCanvas() {
			var rect;
			var fsEl = getFullscreenElement();
			// اگر خودِ root یا wrap یا والدِ آن فول‌اسکرین است → اندازهٔ fullscreen
			// همچنین اگر ویدیو خودش فول‌اسکرین شد (مرورگر قدیمی)، از wrap استفاده کن
			if (fsEl && (fsEl === root || fsEl === wrap || fsEl.contains(root) || root.contains(fsEl))) {
				// اگر fsEl خودِ video باشد، rect را از wrap بگیر (چون canvas بیرون video است)
				if (fsEl === video) {
					rect = wrap.getBoundingClientRect();
					// در حالت video-fullscreen، همچنان به اندازهٔ viewport بساز
					if (!rect.width || rect.width < 100) {
						rect = { width: window.innerWidth, height: window.innerHeight };
					}
				} else {
					rect = fsEl.getBoundingClientRect();
				}
			} else {
				rect = wrap.getBoundingClientRect();
			}
			if (!rect.width || !rect.height) {
				rect = { width: wrap.offsetWidth || root.offsetWidth || 600, height: wrap.offsetHeight || root.offsetHeight || 340 };
			}
			// اگر در فول‌اسکرین هستیم، viewport را بگیر (مرورگر گاهی rect را کوچک می‌دهد قبل از paint)
			var inFs = !!fsEl;
			if (inFs && (rect.width < window.innerWidth * 0.9 || rect.height < window.innerHeight * 0.9)) {
				// فول‌اسکرین واقعی باید نزدیک viewport باشد
				if (fsEl === root || (fsEl && root.contains(fsEl))) {
					rect = { width: window.innerWidth, height: window.innerHeight };
				}
			}
			var dpr = window.devicePixelRatio || 1;
			canvas.width = Math.round(rect.width * dpr);
			canvas.height = Math.round(rect.height * dpr);
			canvas.style.width = Math.round(rect.width) + 'px';
			canvas.style.height = Math.round(rect.height) + 'px';
			ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
			if (config.watermark && config.watermark.line1) {
				ensureWatermarkInside();
			}
			drawWatermark();
		}

		function ensureWatermarkInside() {
			var wm = config.watermark || {};
			if (!wm.line1 || !canvas.width) return;
			var pad = 12;
			var bounds = measureWatermark();
			if (!bounds) return;
			var dpr = window.devicePixelRatio || 1;
			var maxX = (canvas.width / dpr) - bounds.totalW - pad;
			var maxY = (canvas.height / dpr) - bounds.totalH - pad;
			if (state.wmX > maxX || state.wmY > maxY || state.wmX < pad || state.wmY < pad) {
				placeWatermarkRandom();
			}
		}

		// ---------- واترمارک روی Canvas ----------
		function measureWatermark() {
			var wm = config.watermark || {};
			if (!wm.line1) return null;
			var opacity = typeof wm.opacity === 'number' ? wm.opacity : 0.55;
			var scale = typeof wm.scale === 'number' ? wm.scale : 1;
			var h = canvas.height / (window.devicePixelRatio || 1);
			var fontSize = Math.max(12, Math.round((h / 40) * scale));
			ctx.save();
			ctx.font = '700 ' + fontSize + 'px Tahoma, Arial, sans-serif';
			var w1 = ctx.measureText(wm.line1).width;
			var w2 = 0;
			if (wm.line2) {
				ctx.font = '400 ' + Math.max(10, fontSize - 2) + 'px Tahoma, Arial, sans-serif';
				w2 = ctx.measureText(wm.line2).width;
			}
			ctx.restore();
			var totalW = Math.max(w1, w2);
			var totalH = fontSize + (wm.line2 ? fontSize + 6 : 0);
			return { w1: w1, w2: w2, totalW: totalW, totalH: totalH, fontSize: fontSize, opacity: opacity };
		}

		function drawWatermark() {
			var wm = config.watermark || {};
			if (!wm.line1 || !ctx) return;
			var bounds = measureWatermark();
			if (!bounds) return;

			ctx.clearRect(0, 0, canvas.width, canvas.height);
			ctx.save();
			ctx.textBaseline = 'top';
			ctx.shadowColor = 'rgba(0,0,0,0.85)';
			ctx.shadowBlur = 4;
			ctx.shadowOffsetX = 1;
			ctx.shadowOffsetY = 1;
			ctx.fillStyle = 'rgba(255,255,255,' + bounds.opacity + ')';
			ctx.font = '700 ' + bounds.fontSize + 'px Tahoma, Arial, sans-serif';
			ctx.fillText(wm.line1, state.wmX, state.wmY);
			if (wm.line2) {
				ctx.font = '400 ' + Math.max(10, bounds.fontSize - 2) + 'px Tahoma, Arial, sans-serif';
				ctx.fillText(wm.line2, state.wmX, state.wmY + bounds.fontSize + 4);
			}
			ctx.restore();
		}

		function applyWatermark(wm) {
			if (!wm || !wm.line1) return;
			config.watermark = Object.assign({}, config.watermark || {}, wm);
			resizeCanvas();
			placeWatermarkRandom();
			drawWatermark();
		}

		function placeWatermarkRandom() {
			if (state.destroyed || !canvas.width) return;
			var wm = config.watermark || {};
			if (!wm.line1) return;
			var bounds = measureWatermark();
			if (!bounds) return;

			var dpr = window.devicePixelRatio || 1;
			var cw = canvas.width / dpr;
			var ch = canvas.height / dpr;
			var pad = 12;
			var maxX = Math.max(pad, cw - bounds.totalW - pad);
			var maxY = Math.max(pad, ch - bounds.totalH - pad);

			if (maxX <= pad || maxY <= pad) {
				state.wmX = Math.max(pad, (cw - bounds.totalW) / 2);
				state.wmY = Math.max(pad, (ch - bounds.totalH) / 2);
			} else {
				state.wmX = Math.round(pad + Math.random() * Math.max(1, maxX - pad));
				state.wmY = Math.round(pad + Math.random() * Math.max(1, maxY - pad));
			}
			drawWatermark();
		}

		function scheduleWatermark() {
			if (state.destroyed) return;
			var delay = 15000 + Math.random() * 15000;
			if (state.wmTimer) clearTimeout(state.wmTimer);
			state.wmTimer = setTimeout(function () {
				if (!state.destroyed) {
					placeWatermarkRandom();
				}
				scheduleWatermark();
			}, delay);
		}

		// ---------- خطا / لودینگ ----------
		function showError(msg) {
			if (loadingEl) loadingEl.classList.add('is-hidden');
			if (errorEl) {
				errorEl.innerHTML = '';
				var msgSpan = document.createElement('span');
				msgSpan.textContent = msg || i18n.error || 'خطا در پخش';
				errorEl.appendChild(msgSpan);
				if (msg && (msg.indexOf('توکن') !== -1 || msg.indexOf('زمان') !== -1 || msg.indexOf('انقضا') !== -1 || msg.indexOf('بارگذاری') !== -1)) {
					var btn = document.createElement('button');
					btn.textContent = '🔄 بارگذاری مجدد';
					btn.className = 'falnic-preview-retry-btn';
					btn.style.cssText = 'margin-top:10px;padding:8px 16px;background:#2271b1;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:Tahoma,sans-serif;font-size:13px;';
					btn.onclick = function() {
						errorEl.style.display = 'none';
						if (loadingEl) loadingEl.classList.remove('is-hidden');
						if (state.hls) { try { state.hls.destroy(); } catch(e) {} state.hls = null; }
						state.loading = false;
						loadStream();
					};
					errorEl.appendChild(document.createElement('br'));
					errorEl.appendChild(btn);
				}
				errorEl.style.display = 'flex';
				errorEl.style.flexDirection = 'column';
			}
			console.error('[Falnic Preview] ' + msg);
		}

		function hideLoading() {
			if (loadingEl) loadingEl.classList.add('is-hidden');
		}

		// ---------- نشست ----------
		function fetchSession() {
			var body = 'video=' + encodeURIComponent(config.name || '');
			return fetch(config.sessionUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body
			}).then(function (r) {
				if (r.status === 403) {
					return r.json().catch(function(){ return {success:false, data:{message: i18n.expired || 'زمان دسترسی تمام شد'}} }).then(function(j){ throw new Error(j.data.message); });
				}
				return r.json().catch(function () { return null; });
			}).then(function (payload) {
				if (!payload || !payload.success || !payload.data || !payload.data.src) {
					var msg = payload && payload.data && payload.data.message ? payload.data.message : (i18n.sessionError || 'ارتباط با سرور برقرار نشد');
					if (payload && payload.data && payload.data.reason === 'falnic_too_many_plays') {
						msg = payload.data.message || i18n.multiDevice || msg;
					}
					throw new Error(msg);
				}
				return payload.data;
			});
		}

		var retryCount = 0;
		function startStream(src) {
			if (!src) {
				showError(i18n.error || 'منبع ویدیو یافت نشد');
				return;
			}

			if (window.Hls && window.Hls.isSupported()) {
				if (state.hls) {
					try { state.hls.destroy(); } catch (e) {}
				}
				state.hls = new window.Hls({
					xhrSetup: function (xhr) { xhr.withCredentials = true; }
				});

				state.hls.on(window.Hls.Events.ERROR, function (event, data) {
					console.warn('[Falnic Preview] HLS error', data);
					if (!data || !data.fatal) return;
					if (retryCount < 2) {
						retryCount++;
						try { state.hls.destroy(); } catch(e) {}
						state.hls = null;
						if (loadingEl) loadingEl.classList.remove('is-hidden');
						if (errorEl) errorEl.style.display = 'none';
						fetchSession().then(function (newSession) {
							state.session = newSession;
							applyWatermark(newSession.watermark);
							startStream(newSession.src);
						}).catch(function (e) {
							showError(i18n.expired || 'زمان توکن تمام شد؛ صفحه را رفرش کنید');
						});
						return;
					}
					if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) {
						showError('خطا در دریافت ویدیو (شبکه). لطفاً صفحه را رفرش کنید.');
						return;
					}
					if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) {
						state.hls.recoverMediaError();
						return;
					}
					showError(i18n.error || 'خطا در پخش ویدیو');
				});

				state.hls.on(window.Hls.Events.MANIFEST_PARSED, function () {
					if (loadTimeout) { clearTimeout(loadTimeout); loadTimeout = 0; }
					hideLoading();
					resizeCanvas();
					video.play().catch(function () {
						hideLoading();
					});
				});

				state.hls.loadSource(src);
				state.hls.attachMedia(video);
				return;
			}

			if (video.canPlayType('application/vnd.apple.mpegurl')) {
				video.src = src;
				video.addEventListener('loadeddata', function onLoaded() {
					hideLoading();
					resizeCanvas();
					video.removeEventListener('loadeddata', onLoaded);
					video.play().catch(function () {});
				});
				video.load();
				return;
			}

			showError(i18n.unsupported || 'مرورگر شما از پخش این ویدیو پشتیبانی نمی‌کند');
		}

		// ---------- heartbeat ----------
		function startHeartbeat(session) {
			if (!session || !session.sid || !session.heartbeat || !config.heartbeatUrl) return;
			if (state.heartbeatTimer) clearInterval(state.heartbeatTimer);
			state.heartbeatTimer = setInterval(function () {
				var body = 'video=' + encodeURIComponent(config.name || '') + '&sid=' + encodeURIComponent(session.sid) + '&t=' + encodeURIComponent(session.heartbeat);
				fetch(config.heartbeatUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body
				}).then(function (r) { return r.json().catch(function () { return null; }); }).then(function (payload) {
					if (payload && payload.success && payload.data && payload.data.alive) return;
					console.warn('[Falnic Preview] heartbeat ended', payload);
				}).catch(function () {});
			}, 30000);
		}

		var loadTimeout = 0;
		function loadStream() {
			if (state.loading || state.destroyed) return;
			state.loading = true;
			if (loadingEl) loadingEl.classList.remove('is-hidden');
			if (errorEl) errorEl.style.display = 'none';
			if (loadTimeout) clearTimeout(loadTimeout);
			loadTimeout = setTimeout(function() {
				if (state.loading && !state.destroyed) {
					state.loading = false;
					showError('بارگذاری ویدیو بیش از حد طول کشید. لطفاً اتصال اینترنت را بررسی کنید و دوباره تلاش کنید.');
				}
			}, 15000);
			fetchSession().then(function (session) {
				if (loadTimeout) { clearTimeout(loadTimeout); loadTimeout = 0; }
				state.loading = false;
				state.session = session;
				applyWatermark(session.watermark);
				hideLoading();
				startHeartbeat(session);
				startStream(session.src || config.src);
				scheduleWatermark();
				video.addEventListener('playing', function onPlay() {
					resizeCanvas();
					placeWatermarkRandom();
					video.removeEventListener('playing', onPlay);
				});
			}).catch(function (err) {
				if (loadTimeout) { clearTimeout(loadTimeout); loadTimeout = 0; }
				state.loading = false;
				showError(err && err.message ? err.message : (i18n.sessionError || 'خطا در ارتباط با سرور'));
			});
		}

		// ---------- فول‌اسکرین: Canvas هم باید همراهش برود ----------
		function isFullscreen() {
			return !!getFullscreenElement();
		}

		function requestFullscreen() {
			var el = root;
			if (el.requestFullscreen) el.requestFullscreen().catch(function () {});
			else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
			else if (el.mozRequestFullScreen) el.mozRequestFullScreen();
			else if (el.msRequestFullscreen) el.msRequestFullscreen();
		}

		video.addEventListener('dblclick', function (e) {
			e.preventDefault();
			if (isFullscreen()) {
				if (document.exitFullscreen) document.exitFullscreen();
				else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
				else if (document.mozCancelFullScreen) document.mozCancelFullScreen();
				else if (document.msExitFullscreen) document.msExitFullscreen();
			} else {
				requestFullscreen();
			}
		});

		// دکمهٔ فول‌اسکرین اختصاصی اگر وجود داشت (برای آینده)
		root.addEventListener('click', function (e) {
			if (e.target && e.target.closest && e.target.closest('[data-falnic-fs]')) {
				e.preventDefault();
				if (isFullscreen()) {
					if (document.exitFullscreen) document.exitFullscreen();
				} else {
					requestFullscreen();
				}
			}
		});

		function onFullscreenChange() {
			var fsEl = getFullscreenElement();

			// حالت بحرانی: کاربر با کنترل بومی، خودِ video را فول‌اسکرین کرده → canvas بیرون می‌ماند!
			// فوراً از فول‌اسکرین video خارج شو و کانتینر را فول‌اسکرین کن.
			if (fsEl === video && !state.redirectingFullscreen) {
				state.redirectingFullscreen = true;
				var exitFn = document.exitFullscreen || document.webkitExitFullscreen || document.mozCancelFullScreen || document.msExitFullscreen;
				if (exitFn) {
					try {
						var p = exitFn.call(document);
						if (p && p.then) {
							p.then(function() {
								setTimeout(function() { state.redirectingFullscreen = false; requestFullscreen(); }, 150);
							}).catch(function(){ state.redirectingFullscreen = false; });
						} else {
							setTimeout(function() { state.redirectingFullscreen = false; requestFullscreen(); }, 200);
						}
					} catch (e) { state.redirectingFullscreen = false; }
				} else {
					state.redirectingFullscreen = false;
				}
				return;
			}
			if (!fsEl) {
				// خروج از فول‌اسکرین — فلگ را پاک کن
				state.redirectingFullscreen = false;
			}

			// با تأخیر کوتاه Canvas را به اندازهٔ جدید بساز و واترمارک را دوباره بکش
			setTimeout(function () {
				resizeCanvas();
				drawWatermark();
				placeWatermarkRandom();
			}, 200);
			// بعضی مرورگرها (Safari) نیاز به یک بار دیگر بعد از paint دارند
			setTimeout(function () {
				resizeCanvas();
				drawWatermark();
			}, 600);
		}
		document.addEventListener('fullscreenchange', onFullscreenChange);
		document.addEventListener('webkitfullscreenchange', onFullscreenChange);
		document.addEventListener('mozfullscreenchange', onFullscreenChange);
		document.addEventListener('MSFullscreenChange', onFullscreenChange);
		// iOS Safari رویداد جدا دارد
		video.addEventListener('webkitbeginfullscreen', function(e) {
			// iOS بومی: ویدیو فول‌اسکرین بومی می‌شود و Canvas دیده نمی‌شود — نمی‌توان جلوگیری کرد
			// ولی پیام هشدار در کنسول می‌دهیم تا کاربر بداند
			console.warn('[Falnic Preview] iOS native fullscreen — watermark may not be visible in native player');
		});
		video.addEventListener('webkitendfullscreen', function() {
			setTimeout(function(){ resizeCanvas(); drawWatermark(); }, 100);
		});
		window.addEventListener('resize', function () {
			clearTimeout(window._falnicResizeTimer);
			window._falnicResizeTimer = setTimeout(function() { resizeCanvas(); drawWatermark(); }, 300);
		});
		video.addEventListener('loadedmetadata', function() { resizeCanvas(); });

		// ---------- شروع ----------
		resizeCanvas();
		loadStream();

		// پاکسازی
		video.falnicDestroy = function () {
			state.destroyed = true;
			if (state.hls) { try { state.hls.destroy(); } catch (e) {} state.hls = null; }
			if (state.wmTimer) clearTimeout(state.wmTimer);
			if (state.heartbeatTimer) clearInterval(state.heartbeatTimer);
			if (loadTimeout) clearTimeout(loadTimeout);
			document.removeEventListener('fullscreenchange', onFullscreenChange);
			document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
			document.removeEventListener('mozfullscreenchange', onFullscreenChange);
			document.removeEventListener('MSFullscreenChange', onFullscreenChange);
		};
	}

	function bootPreview() {
		var roots = document.querySelectorAll('[data-falnic-preview]');
		Array.prototype.forEach.call(roots, function (root) {
			if (!root.dataset.falnicReady) {
				root.dataset.falnicReady = '1';
				mountPreview(root);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bootPreview);
	} else {
		bootPreview();
	}

	window.FalnicPreview = { mount: mountPreview };
})();
