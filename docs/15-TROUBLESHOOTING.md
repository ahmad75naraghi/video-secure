# ۱۵ — رفع مشکلات رایج

## ۱. پخش

### صفحه سیاه است و هیچ خطایی دیده نمی‌شود

| علت احتمالی | تشخیص | راه‌حل |
|---|---|---|
| CDN در دسترس نیست (`hls.js`/`plyr`) | کنسول: `net::ERR_BLOCKED` یا timeout | دارایی‌ها را محلی کنید ([۱۰](10-PLAYER.md)) |
| خطای ۴۰۳/۴۰۱ در کلید | تب Network: `secure_hls_key` قرمز | لاگین بودن و دسترسی را چک کنید |
| مسیر m3u8 اشتباه | `curl` همان URL | باید m3u8 برگرداند، نه HTML 404 |
| `Hls.isSupported()` خطا | مرورگر قدیمی | پیام جایگزین نشان دهید |
| فایل `.ts` خالی/خراب | `ffprobe` روی سگمنت | انکود مجدد |

```js
// بررسی سریع در کنسول
document.querySelector('video').error
window.Hls && Hls.isSupported()
```

### ویدیو در کروم پخش می‌شود ولی در Safari نه

- Safari برای پخش بومی HLS به `video.src` نیاز دارد و هدر دلخواه نمی‌فرستد → **توکن باید در URL باشد** ([۰۹](09-STREAM-DELIVERY.md#۳۲-چرا-توکن-در-url-است-نه-هدر)).
- مسیر m3u8 باید `Content-Type: application/vnd.apple.mpegurl` برگرداند.
- اگر گواهی TLS ناقص است، Safari کل پخش را قطع می‌کند.

### واترمارک دیده نمی‌شود

| علت | راه‌حل |
|---|---|
| کاربر لاگین نیست | واترمارک فقط برای کاربر لاگین‌کرده ساخته می‌شود |
| `falnic_watermark_mode = off` | آپشن را بررسی کنید |
| canvas رسم نمی‌شود | `video.videoWidth` صفر است؛ منتظر `loadedmetadata` بمانید |
| فول‌اسکرین بومی iOS | کانتینر را fullscreen کنید ([۰۸](08-WATERMARKING.md)) |
| ویدیو `opacity:0` است ولی canvas پشت آن | ترتیب DOM/z-index را چک کنید |

### واترمارک هنگام seek یا pause ناپدید می‌شود

باگ شناخته‌شده‌ی نسخه ۳.۱.۰ ([F-20](02-SECURITY-AUDIT.md#f-20)): `rFrame` با `paused` برمی‌گردد. راه‌حل در [۰۸](08-WATERMARKING.md#۲۳-پیادهسازی-پیشنهادی): رسم مستقل از حالت پخش + شنونده‌ی `seeked`.

---

## ۲. آپلود و انکود

### آپلود انجام می‌شود ولی هیچ اتفاقی نمی‌افتد

- خطای `$_FILES['error']` نادیده گرفته می‌شود ([F-13](02-SECURITY-AUDIT.md#f-13)).
- `upload_max_filesize` یا `post_max_size` کوچک است.
- `client_max_body_size` در Nginx.
- نتیجه‌ی `$_FILES['error']` را چاپ کنید:

```php
error_log( print_r( $_FILES['falnic_video_file']['error'], true ) );
```
کدها: `1` = UPLOAD_ERR_INI_SIZE، `2` = FORM_SIZE، `3` = PARTIAL، `4` = NO_FILE، `6` = NO_TMP_DIR، `7` = CANT_WRITE.

### خطای «تابع exec() مسدود است»

```bash
php -i | grep disable_functions
```
`exec` را از `disable_functions` خارج کنید یا از سرویس انکود بیرونی استفاده کنید ([۱۱](11-INSTALLATION.md#بررسی-امکان-اجرای-ffmpeg-توسط-php)).

### `/usr/bin/env: 'ffmpeg': No such file or directory`

```bash
which ffmpeg
```
مسیر را در `FALNIC_FFMPEG_BIN` بگذارید. در PHP-FPM ممکن است `PATH` متفاوت از shell باشد.

### انکود شروع می‌شود ولی صفحه تایم‌اوت می‌دهد

رفتار مورد انتظار نسخه ۳.۱.۰ ([F-15](02-SECURITY-AUDIT.md#f-15)). تا فاز ۲، از WP-CLI استفاده کنید:

```bash
wp falnic encode /path/to/file.mp4 --title="lesson-1"
```

### سگمنت‌ها ساخته می‌شوند ولی ویدیو پخش نمی‌شود

- `#EXT-X-KEY` به URL اشتباه اشاره می‌کند (کلید `http` در سایت `https`) → [F-25](02-SECURITY-AUDIT.md#f-25).
- `IV` نادرست در `keyinfo` نوشته شده.
- کلید با `chmod` اشتباه خوانده نمی‌شود:

```bash
ls -l /path/secure_keys/*/
sudo -u www-data head -c 16 /path/secure_keys/x/x.key | xxd
```

### ویدیوی بیش از ~۲.۷ ساعت پخش نمی‌شود

`%03d.ts` بعد از ۹۹۹ سگمنت نام‌گذاری را می‌شکند ([F-31](02-SECURITY-AUDIT.md#f-31)). از `%05d.ts` استفاده کنید.

---

## ۳. دسترسی

### کاربر با اینکه دوره را خریده، ۴۰۳ می‌گیرد

| علت | بررسی |
|---|---|
| کش صفحه | صفحه‌ی درس را از کش مستثنی کنید |
| LearnDash دسترسی را نمی‌دهد | `sfwd_lms_has_access( $course_id, $user_id )` را مستقیم تست کنید |
| ویدیو به درس اشتباه وصل است | متای `_falnic_video_key` روی درس |
| گروه/عضویت منقضی شده | پنل LearnDash |
| لاگین با اکانت دیگر در مرورگر | کوکی‌ها |

```php
// در functions.php یا WP-CLI eval
var_dump( sfwd_lms_has_access( 71, 42 ) );
```

### «شما دسترسی ندارید» برای ادمین هم می‌آید

- در نسخه ۳.۱.۰، `deliver_key` ادمین را استثنا می‌کند ولی embed فقط لاگین را چک می‌کند؛ اگر ادمین لاگین نیست، ۴۰۳ می‌گیرد.
- در نسخه ۴، `Access::can_watch` برای `manage_options` همیشه `true` است؛ اگر این‌طور نیست، فیلتر `falnic_can_watch` را بررسی کنید.

### کاربر با یک دستگاه لاگین، از دستگاه دیگر بیرون می‌افتد

`max_sessions = 1` نشست‌های قبلی را نابود می‌کند ([F-12](02-SECURITY-AUDIT.md#f-12)). مقدار را بیشتر کنید یا منتظر فاز ۳ (نشست مبتنی بر پخش) بمانید.

### پشت Cloudflare، `IP Mismatch` می‌گیرم

`REMOTE_ADDR` آی‌پی کلادفلر است. یا `bind_ip` را خاموش کنید یا `FALNIC_TRUSTED_PROXY_HEADER` را روی `CF-Connecting-IP` بگذارید ([۱۲](12-CONFIGURATION.md)).

---

## ۴. پنل مدیریت

### عنوان ویدیو به `video-123` تبدیل شد

باگ [F-11](02-SECURITY-AUDIT.md#f-11). عنوان اصلی را دوباره وارد کنید و تا فاز ۱ از عنوان انگلیسی استفاده کنید.

### «ویدیو با موفقیت انکود شد» ولی فایل نیست

- `exec` خطا داده ولی `$return_var` صفر برگشته (مثلاً `2>&1` و پوسته).
- خروجی ffmpeg را در transient خطا ببینید.
- مسیر پوشه را دستی چک کنید:

```bash
find /path/secure_keys -name '*.key' -ls | tail
find /path/uploads/secure_videos -type f | tail
```

### `Fatal error: array_map(): Argument #2 must be of type array, bool given`

`glob()` خطا داده ([F-14](02-SECURITY-AUDIT.md#f-14)). مجوز پوشه را بررسی و کد را به `Storage::delete_video` تغییر دهید.

### صفحه‌ی تنظیمات «تنظیمات ذخیره شد» می‌دهد ولی چیزی تغییر نمی‌کند

`max_sessions` فقط مقدار ۱ اثر دارد؛ بقیه ذخیره می‌شوند ولی استفاده نمی‌شوند ([F-12](02-SECURITY-AUDIT.md#f-12)).

---

## ۵. خطاهای سرور

### `413 Request Entity Too Large`

```nginx
client_max_body_size 4096m;
```
```ini
upload_max_filesize = 4096M
post_max_size = 4096M
```

### `504 Gateway Timeout` هنگام ذخیره

انکود همگام. `proxy_read_timeout` را زیاد نکنید؛ به صف منتقل کنید ([۱۳](13-ROADMAP.md#فاز-۲)).

### `Allowed memory size exhausted`

- `exec` خروجی زیادی تولید می‌کند؛ `2>&1` را به فایل بفرستید نه حافظه.
- خواندن فایل بزرگ با `file_get_contents`.

```bash
php -r 'echo ini_get("memory_limit");'
```

### دیسک پر شد

```bash
du -sh /path/falnic-secure/*
wp falnic cleanup --older-than=30
```
فایل‌های `incoming/` و `tmp/` را دستی پاک کنید.

---

## ۶. استریم

### دانلود با `wget` کار می‌کند

یعنی F-01 باز است. مسیر را ببندید و به فاز ۱ بروید.

### `403` برای همه‌ی سگمنت‌ها با `X-Accel-Redirect`

- Nginx باید `location /falnic-internal/ { internal; alias ...; }` داشته باشد.
- مسیر `alias` باید به پوشه‌ای اشاره کند که با `FALNIC_STORAGE_DIR` هم‌خوان است.
- `alias` بدون `/` انتهایی، خطای ۴۰۴ می‌دهد.

### seek کار نمی‌کند

هدر `Accept-Ranges: bytes` و پشتیبانی `Range` را چک کنید ([۰۹](09-STREAM-DELIVERY.md#۵۳-range-و-seek)).

### `429` در پخش طولانی

rate limit را برای IP/نشست تنظیم کنید ([۱۲](12-CONFIGURATION.md)).

---

## ۷. لاگ و عیب‌یابی

```php
// wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'FALNIC_LOG_LEVEL', 'debug' );
```
لاگ در `wp-content/debug.log`. با `tail -f` ببینید.

```bash
tail -f wp-content/debug.log | grep -i falnic
```

برای مشاهده‌ی درخواست‌های شبکه:

```bash
curl -v -b cookies.txt "$BASE/wp-json/falnic/v1/manifest/$VIDEO?t=$TOKEN" 2>&1 | head -40
```

---

## ۸. جدول تشخیص سریع

| نشانه | حدس اول |
|---|---|
| صفحه سیاه + کنسول خالی | مسیر/توکن |
| صفحه سیاه + خطای ۴۰۳ | دسترسی |
| صفحه سیاه + خطای CDN | فیلتر/شبکه |
| ویدیو هست ولی واترمارک نیست | canvas/فول‌اسکرین |
| پخش قطع بعد ۱ دقیقه | توکن/نشست |
| پخش قطع بعد ۹۰ ثانیه | heartbeat/timeout |
| `403` فقط روی موبایل | IP bind |
| `403` فقط بعد از ورود دوم | max_sessions |
| دانلود با wget ممکن | F-01 باز است |
| کلید ۱۶ بایت ولی ویدیو باز نمی‌شود | IV/keyinfo |
