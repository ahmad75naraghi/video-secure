/**
 * Falnic Secure HLS — پلیر پیش‌نمایش حرفه‌ای (ادمین)
 * ویدیو مرئی + واترمارک HTML (نه Canvas) — تضمینی، بدون سیاهی
 * فول‌اسکرین native + جابجایی رندوم 15-30 ثانیه داخل کادر
 */
(function () {
	'use strict';

	function mountPreview(root) {
		var configNode = root.querySelector('.falnic-player-config');
		var video = root.querySelector('video');
		var wmEl = root.querySelector('.falnic-preview-watermark');
		var wmLine1 = wmEl ? wmEl.querySelector('.falnic-wm-line1') : null;
		var wmLine2 = wmEl ? wmEl.querySelector('.falnic-wm-line2') : null;
		var loadingEl = root.querySelector('.falnic-preview-loading');
		var errorEl = root.querySelector('.falnic-preview-error');
		var wrap = root.querySelector('.falnic-preview-video-wrap');

		if (!configNode || !video || !wrap) {
			console.warn('[Falnic Preview] missing elements', root);
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
			loading: false
		};

		var i18n = config.i18n || {};

		// ---------- واترمارک ----------
		function applyWatermark(wm) {
			if (!wm || !wm.line1) return;
			config.watermark = Object.assign({}, config.watermark || {}, wm);
			if (wmLine1) wmLine1.textContent = wm.line1 || '';
			if (wmLine2) wmLine2.textContent = wm.line2 || '';
			if (wmEl) {
				var opacity = typeof wm.opacity === 'number' ? wm.opacity : 0.55;
				wmEl.style.setProperty('--falnic-wm-opacity', opacity);
				wmEl.classList.add('is-visible');
			}
			// موقعیت اولیه
			setTimeout(placeWatermarkRandom, 300);
		}

		function placeWatermarkRandom() {
			if (!wmEl || !wrap || state.destroyed) return;
			if (!config.watermark || !config.watermark.line1) return;

			// اندازه‌های واقعی
			var wrapRect = wrap.getBoundingClientRect();
			var wmRect = wmEl.getBoundingClientRect();

			// اگر هنوز اندازه نگرفته، دوباره تلاش کن
			if (!wrapRect.width || !wrapRect.height || !wmRect.width || !wmRect.height) {
				// از offsetWidth استفاده کن
				var ww = wrap.offsetWidth || wrapRect.width || 600;
				var wh = wrap.offsetHeight || wrapRect.height || 340;
				var wmw = wmEl.offsetWidth || 150;
				var wmh = wmEl.offsetHeight || 30;
				wrapRect = { width: ww, height: wh };
				wmRect = { width: wmw, height: wmh };
			}

			var pad = 12;
			var maxX = Math.max(pad, wrapRect.width - wmRect.width - pad);
			var maxY = Math.max(pad, wrapRect.height - wmRect.height - pad);

			// کوچک بودن کادر: وسط‌چین
			var x, y;
			if (maxX <= pad || maxY <= pad) {
				x = Math.max(pad, (wrapRect.width - wmRect.width) / 2);
				y = Math.max(pad, (wrapRect.height - wmRect.height) / 2);
			} else {
				x = pad + Math.random() * (maxX - pad);
				y = pad + Math.random() * (maxY - pad);
			}

			wmEl.style.left = Math.round(x) + 'px';
			wmEl.style.top = Math.round(y) + 'px';
			wmEl.style.right = 'auto';
			wmEl.style.bottom = 'auto';
		}

		function scheduleWatermark() {
			if (state.destroyed) return;
			var delay = 15000 + Math.random() * 15000; // 15-30 ثانیه رندوم
			if (state.wmTimer) clearTimeout(state.wmTimer);
			state.wmTimer = setTimeout(function () {
				if (!video.paused && !state.destroyed) {
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

			// اگر HLS پشتیبانی می‌شود
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
						console.log('[Falnic Preview] retry ' + retryCount);
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
					video.play().catch(function () {
						hideLoading();
					});
				});

				state.hls.loadSource(src);
				state.hls.attachMedia(video);
				return;
			}

			// fallback native HLS (Safari)
			if (video.canPlayType('application/vnd.apple.mpegurl')) {
				video.src = src;
				video.addEventListener('loadeddata', function onLoaded() {
					hideLoading();
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
				// ویدیو که شروع شد، واترمارک را دوباره جایگذاری کن
				video.addEventListener('playing', function onPlay() {
					placeWatermarkRandom();
					video.removeEventListener('playing', onPlay);
				});
			}).catch(function (err) {
				if (loadTimeout) { clearTimeout(loadTimeout); loadTimeout = 0; }
				state.loading = false;
				showError(err && err.message ? err.message : (i18n.sessionError || 'خطا در ارتباط با سرور'));
			});
		}

		// ---------- فول‌اسکرین حرفه‌ای ----------
		function isFullscreen() {
			return !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);
		}

		function requestFullscreen() {
			var el = root;
			if (el.requestFullscreen) el.requestFullscreen().catch(function () {});
			else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
			else if (el.mozRequestFullScreen) el.mozRequestFullScreen();
			else if (el.msRequestFullscreen) el.msRequestFullscreen();
		}

		// دکمهٔ فول‌اسکرینِ پیش‌نمایش (کنترل بومی ویدیو already دارد، ولی برای اطمینان)
		video.addEventListener('dblclick', function () {
			if (isFullscreen()) {
				if (document.exitFullscreen) document.exitFullscreen();
				else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
			} else {
				requestFullscreen();
			}
		});

		// بعد از فول‌اسکرین، واترمارک را دوباره داخل بیاور
		function onFullscreenChange() {
			setTimeout(function () {
				placeWatermarkRandom();
			}, 200);
		}
		document.addEventListener('fullscreenchange', onFullscreenChange);
		document.addEventListener('webkitfullscreenchange', onFullscreenChange);
		document.addEventListener('mozfullscreenchange', onFullscreenChange);
		document.addEventListener('MSFullscreenChange', onFullscreenChange);

		// ریسایز پنجره هم واترمارک را اصلاح کند
		window.addEventListener('resize', function () {
			clearTimeout(window._falnicResizeTimer);
			window._falnicResizeTimer = setTimeout(placeWatermarkRandom, 300);
		});

		// ---------- شروع ----------
		loadStream();

		// پاکسازی
		video.falnicDestroy = function () {
			state.destroyed = true;
			if (state.hls) { try { state.hls.destroy(); } catch (e) {} state.hls = null; }
			if (state.wmTimer) clearTimeout(state.wmTimer);
			if (state.heartbeatTimer) clearInterval(state.heartbeatTimer);
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
