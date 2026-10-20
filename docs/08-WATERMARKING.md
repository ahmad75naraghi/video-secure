# ۰۸ — واترمارک

## ۰. خلاصه‌ی صادقانه

واترمارک **جلوگیری نمی‌کند**؛ **منتسب می‌کند**. اگر کسی ویدیو را ضبط و منتشر کند، هدف این است که با دیدن ویدیوی لو رفته بتوانید بگویید «این از حساب X در تاریخ Y بود». هر ادعای دیگری درباره‌ی واترمارک بازاریابی است.

| لایه | جلوی چه چیزی را می‌گیرد | قابل حذف؟ | هزینه | توصیه |
|---|---|---|---|---|
| DOM overlay (CSS) | کاربر عادی | بله، با یک کلیک در DevTools | تقریباً صفر | فقط fallback |
| **Canvas composite** | کاربر فنی (واترمارک بخشی از تصویر است) | حذف canvas = حذف ویدیو | متوسط CPU | **پیش‌فرض** |
| سوختن سرورساید | همه، حتی دانلودکننده | فقط با پردازش تصویر | CPU + فضای زیاد | دوره‌های گران |
| DRM (Widevine/FairPlay) | تقریباً همه | نیاز به شکستن DRM | بسیار زیاد | خارج از دامنه |

---

## ۱. آنچه باید روی تصویر باشد

| جزء | مثال | چرا |
|---|---|---|
| نام نمایشی | `علی رضایی` | بازدارنده‌ی اجتماعی؛ کاربر می‌داند قابل ردیابی است |
| نام کاربری | `ali` | پایدار، برخلاف نام قابل تغییر |
| ایمیل | `al***@example.com` | نزدیک‌ترین شناسه به هویت واقعی؛ پیش‌فرض ماسک‌شده |
| شناسه‌ی نشست | `S-7F3A2C` | اگر اکانت مشترک باشد، دقیقاً کدام دستگاه |
| زمان | `۱۴۰۵/۰۷/۲۸ ۲۱:۳۴` | تاریخ‌گذاری نشتی |
| شناسه‌ی ویدیو | `v_9f2c` | اگر چند نسخه از یک درس وجود دارد |

قالب پیشنهادی دو خطی:

```
علی رضایی · ali
al***@example.com · S-7F3A2C · 21:34
```

---

## ۲. لایه‌ی پیش‌فرض: Canvas Composite

### ۲.۱ ایده

ویدیوی `<video>` با `opacity: 0` پنهان می‌شود و هر فریم روی یک `<canvas>` رسم می‌شود؛ واترمارک **روی همان canvas** کشیده می‌شود. نتیجه‌ی مهم:

> اگر کاربر canvas را حذف کند، ویدیو هم از بین می‌رود. اگر ویدیو را ضبط کند، واترمارک هم ضبط می‌شود.

این مزیت اصلی نسبت به overlay جداگانه است. ضعف آن: مصرف CPU و رفتار متفاوت مرورگرها در فول‌اسکرین.

### ۲.۲ ایرادهای پیاده‌سازی فعلی (نسخه ۳.۱.۰)

| کد | مشکل |
|---|---|
| `L375` `if (video.paused \|\| video.ended) return;` | بعد از seek در حالت توقف، فریم بدون واترمارک می‌ماند |
| `L371` `canvas.width = video.videoWidth` | رسم با رزولوشن کامل → CPU/باتری بالا |
| `L377` `drawImage(video, ...)` در `requestAnimationFrame` | در ۶۰fps حتی وقتی فریم ویدیو تغییر نکرده |
| `L405` `setTimeout(moveW, ...)` | فقط یک واترمارک متحرک؛ بقیه‌ی صفحه خالی است |
| `L352` `esc_js` | امکان شکستن رشته‌ی JS (F-19) |
| — | شناسه‌ی نشست و زمان در واترمارک نیست |
| — | فول‌اسکرین بومی موبایل، canvas را می‌پوشاند |

### ۲.۳ پیاده‌سازی پیشنهادی

```js
export function mountSecurePlayer(root, { video, watermark, tokenProvider }) {
  const canvas = root.querySelector('canvas.falnic-frame');
  const ctx = canvas.getContext('2d', { alpha: false, desynchronized: true });

  const dpr = Math.min(window.devicePixelRatio || 1, 2);
  let wmX = 0;
  let wmY = 0;
  let rafId = 0;

  function resize() {
    if (!video.videoWidth || !video.videoHeight) return;
    const rect = root.getBoundingClientRect();
    const scale = Math.min(rect.width / video.videoWidth, rect.height / video.videoHeight) || 1;
    canvas.width  = Math.round(video.videoWidth  * scale * dpr);
    canvas.height = Math.round(video.videoHeight * scale * dpr);
    canvas.style.width  = `${Math.round(video.videoWidth  * scale)}px`;
    canvas.style.height = `${Math.round(video.videoHeight * scale)}px`;
    draw();
  }

  function drawWatermark(w, h) {
    if (!watermark.line1) return;

    const base = Math.max(11, Math.round(h / 42));
    const stamp = `${watermark.session} · ${new Date().toLocaleTimeString('fa-IR', {
      hour: '2-digit', minute: '2-digit'
    })}`;

    ctx.textBaseline = 'top';
    ctx.shadowColor = 'rgba(0,0,0,.75)';
    ctx.shadowBlur = 3;
    ctx.fillStyle = `rgba(255,255,255,${watermark.opacity})`;

    // سه نسخه‌ی ثابت: بالا-چپ، مرکز، پایین-راست
    const fixed = [
      [Math.round(w * 0.03), Math.round(h * 0.04)],
      [Math.round(w * 0.50), Math.round(h * 0.46)],
      [Math.round(w * 0.62), Math.round(h * 0.90)]
    ];

    for (const [x, y] of fixed) {
      ctx.font = `600 ${base}px Tahoma, Arial, sans-serif`;
      ctx.fillText(watermark.line1, x, y);
      ctx.font = `${base - 1}px Tahoma, Arial, sans-serif`;
      ctx.fillText(stamp, x, y + base + 2);
    }

    // نسخه‌ی متحرک
    ctx.font = `600 ${base}px Tahoma, Arial, sans-serif`;
    ctx.fillText(watermark.line2, wmX, wmY);
  }

  function draw() {
    if (!video.videoWidth) return;
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    drawWatermark(canvas.width, canvas.height);
  }

  function moveWatermark() {
    if (!canvas.width) return;
    wmX = Math.round(canvas.width  * (0.08 + Math.random() * 0.55));
    wmY = Math.round(canvas.height * (0.15 + Math.random() * 0.65));
    draw();
  }

  // هر فریم واقعی ویدیو، نه هر ۱۶ms
  const rvfc = video.requestVideoFrameCallback?.bind(video);
  function tick() {
    draw();
    rafId = rvfc ? rvfc(tick) : requestAnimationFrame(tick);
  }
  function stop() {
    if (rafId) cancelAnimationFrame(rafId);
    rvfc && video.cancelVideoFrameCallback?.(rafId);
    draw();
  }

  // فریم ثابت هم باید واترمارک داشته باشد
  ['loadeddata', 'seeked', 'pause', 'visibilitychange', 'resize']
    .forEach(ev => video.addEventListener(ev, draw));

  video.addEventListener('loadedmetadata', resize);
  video.addEventListener('play', tick);
  video.addEventListener('ended', stop);

  // فول‌اسکرین روی کانتینر، نه روی <video>
  root.addEventListener('dblclick', () => root.requestFullscreen?.());

  // دستکاری canvas: حذف آن = توقف پخش + لاگ
  new MutationObserver(() => {
    if (!document.contains(canvas)) {
      video.pause();
      fetch(FalnicConfig.rest + 'heartbeat', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Falnic-Token': tokenProvider(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ event: 'canvas_removed' })
      }).catch(() => {});
    }
  }).observe(root, { childList: true, subtree: true });

  video.addEventListener('resize', resize);
  setInterval(moveWatermark, (watermark.interval || 30) * 1000);
  resize();
  return { destroy: stop };
}
```

### ۲.۴ نکات ضروری

| موضوع | راه‌حل |
|---|---|
| **فول‌اسکرین iOS** | فول‌اسکرین بومی `video` روی iOS باعث می‌شود canvas دیده نشود. `playsinline` + `requestFullscreen` روی کانتینر. در iOS 16.4+ کار می‌کند؛ در نسخه‌های قدیمی‌تر، واترمارک در fullscreen دیده نمی‌شود — این را در صفحه‌ی راهنما به کاربر بگویید یا فول‌اسکرین را غیرفعال کنید |
| **مصرف CPU** | رسم با رزولوشن نمایش (نه منبع)، `desynchronized: true`، و `requestVideoFrameCallback` که فقط با فریم جدید صدا زده می‌شود |
| **Battery Saver** | با `prefers-reduced-motion` جابه‌جایی واترمارک را آهسته‌تر کنید، نه حذف |
| **کش فریم** | با `alpha: false` ترکیب‌بندی ساده‌تر و سریع‌تر است |
| **تغییر کیفیت HLS** | بعد از `hls.levels` سوییچ، `videoWidth` ممکن است تغییر کند → `resize()` صدا زده شود |
| **زیرنویس** | زیرنویس را روی canvas نکشید (کاربر باید بتواند روشن/خاموش کند)؛ به‌جای آن `<track>` با DOM بالای canvas |

---

## ۳. لایه‌ی حساس: سوختن در تصویر (سرورساید)

```bash
ffmpeg -y -i input.mp4 \
  -vf "drawtext=fontfile=/usr/share/fonts/truetype/dejavu/Vazirmatn-Bold.ttf:\
text='ali · al***@example.com · S-7F3A2C':\
fontcolor=white@0.32:fontsize=h/34:x=w-tw-40:y=40:\
shadowcolor=black@0.6:shadowx=2:shadowy=2" \
  -c:v libx264 -preset veryfast -crf 26 -c:a copy out.mp4
```

### هزینه‌ی واقعی

| گزینه | فضای مصرفی | زمان تولید | مناسب برای |
|---|---|---|---|
| یک نسخه برای همه (بدون نام) | ۱× | یک‌بار | فقط deterrence عمومی |
| نسخه‌ی اختصاصی هر کاربر | `N×` حجم ویدیو | `N ×` زمان انکود | ❌ برای دوره‌های زیاد غیرعملی |
| **A/B فورنزیک** (۲ نسخه با الگوی نامرئی متفاوت) | ۲× | ۲× | ✅ تعادل خوب |
| گروه‌بندی (۵ نسخه برای ۵ گروه دانشجو) | ۵× | ۵× | ✅ عملی |

محاسبه‌ی سرانگشتی: ویدیوی ۷۲۰p یک‌ساعته ≈ ۱.۲GB. برای ۱۰۰ دانشجو، تولید per-user یعنی ~۱۲۰GB فقط برای یک ویدیو. **توصیه: per-user نکنید**؛ از ترکیب «واترمارک مرئی canvas + A/B نامرئی + لاگ» استفاده کنید.

### واترمارک A/B چگونه کار می‌کند

1. دو نسخه تولید می‌شود که از نظر بصری یکسان‌اند اما یک الگوی نامرئی (مثلاً تغییر جزئی روشنایی در بلوک‌های مشخص) دارند.
2. هر کاربر به‌طور تصادفی به نسخه‌ی A یا B تخصیص می‌یابد (ذخیره در متای نشست).
3. اگر ویدیو لو برود، با تحلیل بلوک‌ها می‌توان گفت از کدام نیمه آمده؛ با ترکیب با لاگ و زمان، دایره محدود می‌شود.

---

## ۴. ضد‌دستکاری: چه چیزی کار می‌کند و چه چیزی نه

| روش | اثر واقعی | توصیه |
|---|---|---|
| منوی راست‌کلیک غیرفعال | تقریباً صفر (کاربر فقط منو را نمی‌بیند) | مجاز، بی‌ضرر |
| `controlsList="nodownload"` | جلوی دکمه‌ی دانلود کروم را می‌گیرد | ✔ استفاده کنید |
| `disablePictureInPicture` | جلوی PiP را می‌گیرد | ✔ |
| `disableRemotePlayback` | جلوی Cast را می‌گیرد | ✔ |
| بستن `debugger` / `eval` | کاربر واقعی را اذیت می‌کند، مهاجم را نه | ❌ حذف |
| `document.body.innerHTML = ...` | صفحه را خراب می‌کند | ❌ حذف |
| تشخیص حذف canvas | تلاش برای دستکاری را لاگ می‌کند | ✔ (بهترین تلاش) |
| تشخیص DevTools با ابعاد پنجره | مثبت کاذب زیاد | ❌ |
| تشخیص ضبط صفحه با `getDisplayMedia` | فقط در همان صفحه و همان لحظه | 🟡 اختیاری، پیام آموزشی نه بلاک |
| منع انتخاب متن/کشیدن تصویر | جلوی ذخیره‌ی تصویر را می‌گیرد | ✔ |

---

<a id="ملاحظات-حقوقی-و-حریم-خصوصی"></a>
## ۵. ملاحظات حقوقی و حریم خصوصی

- ایمیل، **داده‌ی شخصی** است (GDPR ماده ۴). نمایش آن در ویدیو یعنی انتشار داده‌ی کاربر به هر بیننده‌ای. پیش‌فرض **ماسک‌شده** باشد.
- در صفحه‌ی ثبت‌نام/شرایط استفاده بنویسید: «برای جلوگیری از انتشار غیرمجاز، نام و شناسه‌ی شما روی ویدیو نمایش داده می‌شود.»
- برای کاربران اتحادیه اروپا، مبنای قانونی: منافع مشروع (حفاظت از مالکیت معنوی) — مستندسازی کنید.
- لاگ‌ها را بیش از حد لازم نگه ندارید (پیش‌فرض ۹۰ روز) و در سیاست حریم خصوصی ذکر کنید.
- اگر ایمیل کامل نمایش می‌دهید، گزینه‌ی خروج/رضایت صریح در پروفایل بگذارید.

---

## ۶. چک‌لیست تست واترمارک

| # | تست | انتظار |
|---|---|---|
| WM-1 | پخش عادی | واترمارک در ۳ نقطه + نسخه‌ی متحرک |
| WM-2 | توقف و seek | واترمارک بلافاصله بازسازی شود |
| WM-3 | تغییر کیفیت | واترمارک بدون پرش بازسازی شود |
| WM-4 | فول‌اسکرین دسکتاپ | واترمارک دیده شود |
| WM-5 | iOS Safari | `playsinline`؛ فول‌اسکرین کانتینر؛ ثبت محدودیت نسخه‌های قدیمی |
| WM-6 | حذف canvas در DevTools | پخش متوقف + رکورد `canvas_removed` در لاگ |
| WM-7 | نام شامل `<script>` | بدون XSS (تست F-19) |
| WM-8 | ایمیل ماسک‌شده | `al***@example.com` |
| WM-9 | ۳۰ دقیقه پخش | مصرف CPU < ۱۵٪ روی لپ‌تاپ میان‌رده |
| WM-10 | ضبط صفحه با OBS | واترمارک در فایل ضبط‌شده موجود باشد |
