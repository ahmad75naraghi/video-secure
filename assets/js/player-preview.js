/**
 * Falnic Secure HLS — پلیر پیش‌نمایش حرفه‌ای (ادمین) — Canvas
 * ویدیو فقط روی Canvas رندر می‌شود (drawImage هر فریم) + واترمارک
 * هر 15-30 ثانیه رندوم + محافظت: پاک شدن Canvas = توقف پخش
 * فول‌اسکرین درست + حل سیاهی فرانت (heartbeat/logic مشابه front)
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
		try { config = JSON.parse(configNode.textContent || '{}'); } catch (e) {
			showError('پیکربندی پلیر خوانده نشد'); return;
		}
		if (!config.sessionUrl) { showError('آدرس نشست یافت نشد'); return; }

		var state = {
			hls: null, session: null,
			wmTimer: 0, heartbeatTimer: 0,
			destroyed: false, loading: false,
			wmX: 24, wmY: 36, raf: 0,
			redirectingFullscreen: false
		};
		var i18n = config.i18n || {};

		// ایجاد کنترل‌های ساده (ادمین)
		var controls = document.createElement('div');
		controls.className = 'falnic-preview-controls';
		controls.innerHTML = '<button type="button" data-falnic-play>▶ پخش</button><button type="button" data-falnic-fs>⛶ تمام‌صفحه</button>';
		wrap.appendChild(controls);
		var playBtn = controls.querySelector('[data-falnic-play]');
		var fsBtn = controls.querySelector('[data-falnic-fs]');

		// ---------- Canvas / محافظت ----------
		function getFullscreenElement() {
			return document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement || null;
		}

		function resizeCanvas() {
			var vw = video.videoWidth || 0, vh = video.videoHeight || 0;
			var rect, fsEl = getFullscreenElement();
			var inFs = !!(fsEl && (fsEl === root || fsEl === wrap || fsEl.contains(root) || root.contains(fsEl)));

			if (inFs) {
				if (fsEl === video) rect = { width: window.innerWidth, height: window.innerHeight };
				else rect = fsEl.getBoundingClientRect();
				if (!rect.width || rect.width < 200) rect = { width: window.innerWidth, height: window.innerHeight };
			} else {
				rect = wrap.getBoundingClientRect();
				if (!rect.width) rect = { width: wrap.offsetWidth || 640, height: wrap.offsetHeight || 360 };
			}
			// اگر هنوز متادیتا نیامده، به اندازهٔ کانتینر بساز
			if (!vw || !vh) {
				canvas.width = Math.max(1, Math.round(rect.width));
				canvas.height = Math.max(1, Math.round(rect.height));
				canvas.style.width = Math.round(rect.width) + 'px';
				canvas.style.height = Math.round(rect.height) + 'px';
				if (config.watermark && config.watermark.line1) ensureWatermarkInside();
				draw();
				return;
			}
			var scale = Math.min(rect.width / vw, rect.height / vh);
			if (!isFinite(scale) || scale <= 0) scale = 1;
			canvas.width = Math.max(1, Math.round(vw * scale));
			canvas.height = Math.max(1, Math.round(vh * scale));
			canvas.style.width = canvas.width + 'px';
			canvas.style.height = canvas.height + 'px';
			if (config.watermark && config.watermark.line1) ensureWatermarkInside();
			draw();
		}

		function ensureWatermarkInside() {
			var wm = config.watermark || {};
			if (!wm.line1 || !canvas.width) return;
			var pad = 12, bounds = measureWatermark(); if (!bounds) return;
			var maxX = canvas.width - bounds.totalW - pad, maxY = canvas.height - bounds.totalH - pad;
			if (state.wmX > maxX || state.wmY > maxY || state.wmX < pad || state.wmY < pad) placeWatermarkRandom();
		}

		// ---------- واترمارک ----------
		function measureWatermark() {
			var wm = config.watermark || {}; if (!wm.line1) return null;
			var opacity = typeof wm.opacity === 'number' ? wm.opacity : 0.55;
			var scale = typeof wm.scale === 'number' ? wm.scale : 1;
			var h = canvas.height || 300;
			var fontSize = Math.max(13, Math.round((h / 38) * scale));
			ctx.save();
			ctx.font = '700 ' + fontSize + 'px Tahoma, Arial, sans-serif';
			var w1 = ctx.measureText(wm.line1).width, w2 = 0;
			if (wm.line2) { ctx.font = '400 ' + Math.max(11, fontSize - 2) + 'px Tahoma, Arial, sans-serif'; w2 = ctx.measureText(wm.line2).width; }
			ctx.restore();
			return { totalW: Math.max(w1, w2), totalH: fontSize + (wm.line2 ? fontSize + 6 : 0), fontSize: fontSize, opacity: opacity };
		}

		function drawWatermark() {
			var wm = config.watermark || {}; if (!wm.line1 || !ctx) return;
			var b = measureWatermark(); if (!b) return;
			ctx.save();
			ctx.textBaseline = 'top';
			ctx.shadowColor = 'rgba(0,0,0,0.85)'; ctx.shadowBlur = 4; ctx.shadowOffsetX = 1; ctx.shadowOffsetY = 1;
			ctx.fillStyle = 'rgba(255,255,255,' + b.opacity + ')';
			ctx.font = '700 ' + b.fontSize + 'px Tahoma, Arial, sans-serif';
			ctx.fillText(wm.line1, state.wmX, state.wmY);
			if (wm.line2) {
				ctx.font = '400 ' + Math.max(11, b.fontSize - 2) + 'px Tahoma, Arial, sans-serif';
				ctx.fillText(wm.line2, state.wmX, state.wmY + b.fontSize + 4);
			}
			ctx.restore();
		}

		function draw() {
			if (!ctx || !canvas.width || !canvas.height) return;
			if (!video.videoWidth || !video.videoHeight || video.readyState < 2) {
				ctx.fillStyle = '#000'; ctx.fillRect(0,0,canvas.width,canvas.height);
				if (config.watermark && config.watermark.line1) drawWatermark();
				return;
			}
			try { ctx.drawImage(video, 0, 0, canvas.width, canvas.height); } catch(e) {
				ctx.fillStyle = '#000'; ctx.fillRect(0,0,canvas.width,canvas.height);
			}
			if (config.watermark && config.watermark.line1) drawWatermark();
			else drawWatermark(); // even if empty, keeps frame
		}

		function placeWatermarkRandom() {
			if (state.destroyed || !canvas.width) return;
			var wm = config.watermark || {}; if (!wm.line1) return;
			var b = measureWatermark(); if (!b) return;
			var pad = 12, maxX = Math.max(pad, canvas.width - b.totalW - pad), maxY = Math.max(pad, canvas.height - b.totalH - pad);
			if (maxX <= pad || maxY <= pad) { state.wmX = Math.max(pad, (canvas.width - b.totalW)/2); state.wmY = Math.max(pad, (canvas.height - b.totalH)/2); }
			else { state.wmX = Math.round(pad + Math.random()*Math.max(1,maxX-pad)); state.wmY = Math.round(pad + Math.random()*Math.max(1,maxY-pad)); }
			draw();
		}

		function scheduleWatermark() {
			if (state.destroyed) return;
			var delay = 15000 + Math.random()*15000;
			if (state.wmTimer) clearTimeout(state.wmTimer);
			state.wmTimer = setTimeout(function(){ if(!state.destroyed) placeWatermarkRandom(); scheduleWatermark(); }, delay);
		}

		function applyWatermark(wm) {
			if (!wm || !wm.line1) return;
			config.watermark = Object.assign({}, config.watermark||{}, wm);
			resizeCanvas(); placeWatermarkRandom(); draw();
		}

		// حلقه رندر
		function loop(){ if(state.destroyed) return; draw(); state.raf = requestAnimationFrame(loop); }
		function startLoop(){ if(!state.raf && !state.destroyed) state.raf = requestAnimationFrame(loop); root.classList.add('is-playing'); if(playBtn) playBtn.textContent='⏸ توقف'; }
		function stopLoop(){ if(state.raf) cancelAnimationFrame(state.raf); state.raf=0; root.classList.remove('is-playing'); if(playBtn) playBtn.textContent='▶ پخش'; draw(); }

		// ---------- خطا / لودینگ ----------
		function showError(msg){
			if(loadingEl) loadingEl.classList.add('is-hidden');
			stopLoop();
			if(errorEl){
				errorEl.innerHTML='';
				var s=document.createElement('span'); s.textContent=msg||i18n.error||'خطا در پخش'; errorEl.appendChild(s);
				if(msg && /توکن|زمان|انقضا|بارگذاری|دسترسی|ثبت‌نام|وارد/.test(msg)){
					var btn=document.createElement('button'); btn.textContent='🔄 تلاش دوباره'; btn.style.cssText='margin-top:10px;padding:8px 16px;background:#2271b1;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:Tahoma,sans-serif;font-size:13px;';
					btn.onclick=function(){ errorEl.style.display='none'; if(loadingEl) loadingEl.classList.remove('is-hidden'); if(state.hls){try{state.hls.destroy()}catch(e){} state.hls=null;} state.loading=false; loadStream(); };
					errorEl.appendChild(document.createElement('br')); errorEl.appendChild(btn);
				}
				errorEl.style.display='flex';
			}
			console.error('[Falnic Preview] '+msg);
		}
		function hideLoading(){ if(loadingEl) loadingEl.classList.add('is-hidden'); }

		// ---------- نشست ----------
		function fetchSession(){
			var body='video='+encodeURIComponent(config.name||'');
			return fetch(config.sessionUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body}).then(function(r){
				if(r.status===403) return r.json().catch(function(){return {success:false,data:{message:i18n.expired||'دسترسی ندارید'}}}).then(function(j){ throw new Error(j.data.message||j.message); });
				return r.json().catch(function(){return null;});
			}).then(function(payload){
				if(!payload||!payload.success||!payload.data||!payload.data.src){ var m=payload&&payload.data&&payload.data.message?payload.data.message:(i18n.sessionError||'ارتباط با سرور برقرار نشد'); if(payload&&payload.data&&payload.data.reason==='falnic_too_many_plays') m=payload.data.message||i18n.multiDevice||m; throw new Error(m); }
				return payload.data;
			});
		}

		var retryCount=0;
		function startStream(src){
			if(!src){ showError(i18n.error||'منبع ویدیو یافت نشد'); return; }
			if(window.Hls && window.Hls.isSupported()){
				if(state.hls) try{state.hls.destroy()}catch(e){}
				state.hls=new window.Hls({xhrSetup:function(xhr){xhr.withCredentials=true;}});
				state.hls.on(window.Hls.Events.ERROR,function(ev,data){
					console.warn('[Falnic Preview] HLS error',data);
					if(!data||!data.fatal) return;
					if(retryCount<2){ retryCount++; try{state.hls.destroy()}catch(e){} state.hls=null; if(loadingEl) loadingEl.classList.remove('is-hidden'); if(errorEl) errorEl.style.display='none'; fetchSession().then(function(ns){state.session=ns; applyWatermark(ns.watermark); startStream(ns.src);}).catch(function(){ showError(i18n.expired||'زمان توکن تمام شد'); }); return; }
					if(data.type===window.Hls.ErrorTypes.NETWORK_ERROR){ showError('خطا در دریافت ویدیو (شبکه)'); return; }
					if(data.type===window.Hls.ErrorTypes.MEDIA_ERROR){ state.hls.recoverMediaError(); return; }
					showError(i18n.error||'خطا در پخش');
				});
				state.hls.on(window.Hls.Events.MANIFEST_PARSED,function(){ if(loadTimeout) {clearTimeout(loadTimeout); loadTimeout=0;} hideLoading(); resizeCanvas(); video.play().catch(function(){ hideLoading(); startLoop(); }); });
				state.hls.loadSource(src); state.hls.attachMedia(video); return;
			}
			if(video.canPlayType('application/vnd.apple.mpegurl')){
				video.src=src;
				video.addEventListener('loadeddata', function onLoaded(){ hideLoading(); resizeCanvas(); video.removeEventListener('loadeddata',onLoaded); video.play().catch(function(){}); });
				video.load(); return;
			}
			showError(i18n.unsupported||'مرورگر پشتیبانی نمی‌کند');
		}

		function startHeartbeat(session){ if(!session||!session.sid||!session.heartbeat||!config.heartbeatUrl) return; if(state.heartbeatTimer) clearInterval(state.heartbeatTimer); state.heartbeatTimer=setInterval(function(){ var body='video='+encodeURIComponent(config.name||'')+'&sid='+encodeURIComponent(session.sid)+'&t='+encodeURIComponent(session.heartbeat); fetch(config.heartbeatUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body}).then(function(r){return r.json().catch(function(){return null;});}).then(function(p){ if(p&&p.success&&p.data&&p.data.alive) return; console.warn('[Falnic Preview] heartbeat ended',p);}).catch(function(){}); },30000); }

		var loadTimeout=0;
		function loadStream(){
			if(state.loading||state.destroyed) return; state.loading=true;
			if(loadingEl) loadingEl.classList.remove('is-hidden'); if(errorEl) errorEl.style.display='none';
			if(loadTimeout) clearTimeout(loadTimeout);
			loadTimeout=setTimeout(function(){ if(state.loading&&!state.destroyed){ state.loading=false; showError('بارگذاری بیش از حد طول کشید'); } },15000);
			fetchSession().then(function(session){
				if(loadTimeout){clearTimeout(loadTimeout); loadTimeout=0;} state.loading=false; state.session=session;
				applyWatermark(session.watermark); hideLoading(); startHeartbeat(session); startStream(session.src||config.src); scheduleWatermark();
				video.addEventListener('playing', function onPlay(){ resizeCanvas(); placeWatermarkRandom(); startLoop(); video.removeEventListener('playing',onPlay); });
				// اگر autoplay مسدود شد، کاربر با دکمه پخش می‌کند
				video.addEventListener('play', startLoop); video.addEventListener('pause', stopLoop); video.addEventListener('ended', stopLoop);
			}).catch(function(err){ if(loadTimeout){clearTimeout(loadTimeout); loadTimeout=0;} state.loading=false; showError(err&&err.message?err.message:(i18n.sessionError||'خطا در ارتباط با سرور')); });
		}

		// ---------- کنترل‌ها ----------
		if(playBtn) playBtn.addEventListener('click', function(){
			if(video.paused){ video.play().catch(function(){}); } else { video.pause(); }
		});
		function isFullscreen(){ return !!getFullscreenElement(); }
		function requestFullscreen(){ var el=root; if(el.requestFullscreen) el.requestFullscreen().catch(function(){}); else if(el.webkitRequestFullscreen) el.webkitRequestFullscreen(); else if(el.mozRequestFullScreen) el.mozRequestFullScreen(); else if(el.msRequestFullscreen) el.msRequestFullscreen(); }
		if(fsBtn) fsBtn.addEventListener('click', function(){
			if(isFullscreen()){ if(document.exitFullscreen) document.exitFullscreen(); else if(document.webkitExitFullscreen) document.webkitExitFullscreen(); }
			else requestFullscreen();
		});
		video.addEventListener('dblclick', function(e){ e.preventDefault(); if(isFullscreen()){ if(document.exitFullscreen) document.exitFullscreen(); } else requestFullscreen(); });

		wrap.addEventListener('click', function(e){
			if(e.target.closest && e.target.closest('[data-falnic-play],[data-falnic-fs]')) return;
			// کلیک روی خودِ ویدیو/Canvas = play/pause (مثل فرانت)
			if(video.paused) video.play().catch(function(){}); else video.pause();
		});

		// ---------- فول‌اسکرین ----------
		function onFullscreenChange(){
			var fsEl=getFullscreenElement();
			if(fsEl===video && !state.redirectingFullscreen){
				state.redirectingFullscreen=true;
				var exitFn=document.exitFullscreen||document.webkitExitFullscreen||document.mozCancelFullScreen||document.msExitFullscreen;
				if(exitFn){ try{ var p=exitFn.call(document); if(p&&p.then) p.then(function(){ setTimeout(function(){state.redirectingFullscreen=false; requestFullscreen();},150);}).catch(function(){state.redirectingFullscreen=false;}); else setTimeout(function(){state.redirectingFullscreen=false; requestFullscreen();},200); }catch(e){state.redirectingFullscreen=false;} } else state.redirectingFullscreen=false;
				return;
			}
			if(!fsEl) state.redirectingFullscreen=false;
			setTimeout(function(){ resizeCanvas(); placeWatermarkRandom(); },200);
			setTimeout(function(){ resizeCanvas(); draw(); },600);
		}
		document.addEventListener('fullscreenchange', onFullscreenChange);
		document.addEventListener('webkitfullscreenchange', onFullscreenChange);
		document.addEventListener('mozfullscreenchange', onFullscreenChange);
		document.addEventListener('MSFullscreenChange', onFullscreenChange);
		video.addEventListener('webkitbeginfullscreen', function(){ console.warn('[Falnic Preview] iOS native fullscreen'); });
		video.addEventListener('webkitendfullscreen', function(){ setTimeout(function(){resizeCanvas(); draw();},100); });
		window.addEventListener('resize', function(){ clearTimeout(window._falnicResizeTimer); window._falnicResizeTimer=setTimeout(function(){resizeCanvas(); draw();},300); });
		video.addEventListener('loadedmetadata', function(){ resizeCanvas(); draw(); });
		video.addEventListener('seeked', draw);
		video.addEventListener('loadeddata', draw);

		// ---------- محافظت: Canvas پاک شود = همه‌چیز پاک شود ----------
		var tamperEl=document.createElement('div'); tamperEl.className='falnic-preview-tamper'; tamperEl.textContent='⚠️ دستکاری شناسایی شد — پخش متوقف شد.'; wrap.appendChild(tamperEl);
		function triggerTamper(){
			if(state.destroyed) return;
			state.destroyed=true; stopLoop();
			if(state.hls) try{state.hls.destroy()}catch(e){} state.hls=null;
			try{ video.pause(); }catch(e){}
			video.removeAttribute('src'); try{video.load()}catch(e){}
			canvas.width=1; canvas.height=1; canvas.style.display='none';
			tamperEl.style.display='flex';
			if(loadingEl) loadingEl.classList.add('is-hidden');
			console.warn('[Falnic Preview] tamper detected — canvas removed');
		}
		// اگر Canvas از DOM حذف شد
		var observer=new MutationObserver(function(mutations){
			for(var i=0;i<mutations.length;i++){ var m=mutations[i];
				if(m.type==='childList'){
					var removed=false;
					for(var j=0;j<m.removedNodes.length;j++) if(m.removedNodes[j]===canvas) removed=true;
					if(removed || !wrap.contains(canvas)) triggerTamper();
				}
			}
			if(!wrap.contains(canvas)) triggerTamper();
		});
		observer.observe(wrap,{childList:true});
		observer.observe(root,{childList:true, subtree:true});
		// همچنین اگر کسی Canvas را hide کرد (display:none یا opacity:0 via style)
		var styleObserver=new MutationObserver(function(){ if(canvas.style.display==='none' || canvas.style.opacity==='0' || canvas.style.visibility==='hidden') triggerTamper(); });
		styleObserver.observe(canvas,{attributes:true, attributeFilter:['style','class']});
		// راست‌کلیک و Drag را ببند (ادمین هم)
		canvas.addEventListener('contextmenu', function(e){ e.preventDefault(); });
		wrap.addEventListener('dragstart', function(e){ e.preventDefault(); });

		// شروع
		resizeCanvas(); draw(); loadStream();

		video.falnicDestroy=function(){
			state.destroyed=true; if(state.hls) try{state.hls.destroy()}catch(e){} state.hls=null;
			if(state.wmTimer) clearTimeout(state.wmTimer); if(state.heartbeatTimer) clearInterval(state.heartbeatTimer);
			if(loadTimeout) clearTimeout(loadTimeout); if(state.raf) cancelAnimationFrame(state.raf);
			document.removeEventListener('fullscreenchange', onFullscreenChange); document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
			document.removeEventListener('mozfullscreenchange', onFullscreenChange); document.removeEventListener('MSFullscreenChange', onFullscreenChange);
			try{observer.disconnect()}catch(e){} try{styleObserver.disconnect()}catch(e){}
		};
	}
	function bootPreview(){ var roots=document.querySelectorAll('[data-falnic-preview]'); Array.prototype.forEach.call(roots,function(root){ if(!root.dataset.falnicReady){ root.dataset.falnicReady='1'; mountPreview(root); } }); }
	if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', bootPreview); else bootPreview();
	window.FalnicPreview={mount:mountPreview};
})();
