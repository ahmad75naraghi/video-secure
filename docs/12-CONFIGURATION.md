# ۱۲ — پیکربندی

## ۱. اولویت تنظیمات

```
ثابت در wp-config.php   >   فیلتر PHP   >   آپشن ذخیره‌شده در دیتابیس   >   مقدار پیش‌فرض
```
ثابت‌ها برای پیکربندی زیرساخت‌اند و از پنل قابل تغییر نیستند (تا ادمین اشتباهی امنیت را خاموش نکند). آپشن‌ها برای سیاست‌های محتوایی‌اند.

---

## ۲. ثابت‌ها (`wp-config.php`)

| ثابت | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `FALNIC_STORAGE_DIR` | path | `dirname(ABSPATH)/falnic-secure` | ریشه‌ی رسانه |
| `FALNIC_TOKEN_SECRET` | string | `wp_salt('falnic_secure_hls')` | کلید امضای توکن — **در تولید تعریف کنید** (حداقل ۳۲ کاراکتر) |
| `FALNIC_ACCEL_PREFIX` | path | خالی | پیشوند `X-Accel-Redirect` برای سرو سگمنت با Nginx (مثل `/falnic-internal`) |
| `FALNIC_FFMPEG_BIN` | path | `ffmpeg` | مسیر باینری ffmpeg |
| `FALNIC_FFPROBE_BIN` | path | `ffprobe` | مسیر ffprobe |
| `FALNIC_CDN_BASE` | url | خالی | دامنه‌ی CDN برای سگمنت‌ها |
| `FALNIC_TRUSTED_PROXY_HEADER` | string | خالی | هدر IP معتبر پشت پروکسی (`CF-Connecting-IP`) |
| `FALNIC_ALLOW_INSECURE` | bool | `false` | اجازه‌ی HTTP در استیجینگ (با `WP_DEBUG`) |
| `FALNIC_LOG_LEVEL` | string | `warning` | `debug`/`info`/`warning`/`error` |
| `FALNIC_MAX_CONCURRENT` | int | `2` | جایگزین آپشن مشابه |

---

## ۳. آپشن‌های دیتابیس

| کلید | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `falnic_token_ttl` | int (ثانیه) | `600` | عمر توکن manifest |
| `falnic_segment_token_ttl` | int | `7200` | عمر توکن سگمنت (برای پخش طولانی) |
| `falnic_max_upload_mb` | int | `2048` | حداکثر حجم فایل MP4 برای انکود |
| `falnic_db_version` | string | — | نسخه‌ی ساختار؛ مبنای مهاجرت یک‌باره |
| `falnic_key_rotation_segments` | int | `40` | تعداد سگمنت هر کلید |
| `falnic_max_concurrent` | int | `2` | پخش هم‌زمان هر کاربر |
| `falnic_heartbeat_timeout` | int | `90` | قطع نشست بی‌فعالیت |
| `falnic_bind_ip` | `yes`/`no` | `no` | گره زدن توکن به IP (برای موبایل توصیه نمی‌شود) |
| `falnic_segment_rate_limit` | int | `60` | درخواست سگمنت در دقیقه |
| `falnic_key_rate_limit` | int | `10` | درخواست کلید در دقیقه |
| `falnic_watermark_mode` | enum | `canvas` | `off`/`dom`/`canvas`/`server` |
| `falnic_watermark_email` | enum | `masked` | `masked`/`full`/`hidden` |
| `falnic_watermark_opacity` | float | `0.35` | ۰.۱ تا ۰.۸ |
| `falnic_watermark_interval` | int | `30` | جابه‌جایی واترمارک (ثانیه) |
| `falnic_watermark_session` | bool | `true` | نمایش شناسه‌ی نشست |
| `falnic_log_retention_days` | int | `90` | نگهداری لاگ |
| `falnic_log_segments` | bool | `false` | لاگ هر سگمنت (حجم زیاد) |
| `falnic_ld_auto_embed` | `yes`/`no` | `yes` | نمایش خودکار پلیر در درس/مبحث |
| `falnic_ld_position` | enum | `before` | `before`/`after` |
| `falnic_ld_require_enrollment` | `yes`/`no` | `yes` | ویدیوی بدون درس فقط با قابلیت `falnic_watch_videos` |
| `falnic_db_version` | string | نسخه‌ی افزونه | برای اجرای یک‌باره‌ی مهاجرت ساختار |
| `falnic_ld_lesson_locked_message` | string | «این درس هنوز باز نشده است.» | پیام Drip |
| `falnic_delete_data_on_uninstall` | bool | `false` | حذف کامل هنگام حذف پلاگین |
| `falnic_max_upload_mb` | int | `4096` | سقف آپلود |

---

## ۴. فیلترها

| فیلتر | پارامترها | کاربرد |
|---|---|---|
| `falnic_can_watch` | `bool $allowed, int $user_id, Video $video, array $lesson_ids` | افزودن قانون دسترسی سفارشی |
| `falnic_watermark_text` | `array $lines, int $user_id, string $video_key` | تغییر متن واترمارک |
| `falnic_watermark_position` | `array $positions, int $w, int $h` | تغییر نقاط واترمارک |
| `falnic_token_claims` | `array $claims, string $scope` | افزودن claim |
| `falnic_manifest_line` | `string $line, string $video_key` | دستکاری هر خط manifest |
| `falnic_segment_path` | `string $path, string $video_key, string $rendition, int $n` | مسیر سگمنت (S3/NFS) |
| `falnic_deny_message` | `string $message, string $reason, int $user_id` | پیام رد دسترسی |
| `falnic_player_args` | `array $args, int $post_id` | کنترل‌های پلیر |
| `falnic_encoder_args` | `array $args, string $video_key` | پارامترهای ffmpeg |
| `falnic_log_should_write` | `bool $write, string $resource, string $result` | کاهش حجم لاگ |
| `falnic_rest_permission` | `bool $allowed, WP_REST_Request $r` | کنترل نهایی REST |

### نمونه: دسترسی بر اساس عضویت

```php
add_filter( 'falnic_can_watch', function ( $allowed, $user_id, $video, $lesson_ids ) {
    if ( $allowed ) {
        return true;
    }
    if ( function_exists( 'wc_memberships_is_user_active_member' )
         && wc_memberships_is_user_active_member( $user_id, 'premium' ) ) {
        return true;
    }
    return $allowed;
}, 10, 4 );
```

### نمونه: ماسک نکردن ایمیل برای یک دوره

```php
add_filter( 'falnic_watermark_text', function ( $lines, $user_id, $video_key ) {
    if ( Video::find( $video_key )->has_course( 71 ) ) {
        $u = get_userdata( $user_id );
        $lines['line2'] = $u->user_email;
    }
    return $lines;
}, 10, 3 );
```

### نمونه: انکود با کیفیت بالاتر

```php
add_filter( 'falnic_encoder_args', function ( $args, $video_key ) {
    $args['crf']     = 20;
    $args['preset']  = 'slow';
    $args['renditions'] = [
        [ 'name' => '360p',  'w' => 640,  'h' => 360,  'br' => '800k' ],
        [ 'name' => '720p',  'w' => 1280, 'h' => 720,  'br' => '2500k' ],
        [ 'name' => '1080p', 'w' => 1920, 'h' => 1080, 'br' => '5000k' ],
    ];
    return $args;
}, 10, 2 );
```

---

## ۵. اکشن‌ها

| اکشن | زمان | کاربرد |
|---|---|---|
| `falnic_video_encoded` | پایان انکود موفق | ارسال ایمیل به ادمین، پاک کردن فایل اصلی |
| `falnic_video_failed` | شکست انکود | هشدار |
| `falnic_access_denied` | رد دسترسی | هشدار امنیتی، بلاک IP |
| `falnic_session_started` | شروع پخش | آمار |
| `falnic_session_ended` | پایان نشست | آمار |
| `falnic_leak_suspected` | حذف canvas / درخواست مشکوک | هشدار به ادمین |
| `falnic_before_stream` | قبل از ارسال سگمنت | لاگ پیشرفته |

---

## ۶. پیکربندی پیشنهادی برای سه سناریو

### الف) سایت آموزشی کوچک (< ۵۰ دانشجو)

```php
define( 'FALNIC_STORAGE_DIR', '/var/www/falnic-secure' );
// بقیه پیش‌فرض
```
واترمارک canvas، یک کیفیت ۷۲۰p، لاگ ۳۰ روز.

### ب) دوره‌ی گران با محتوای حساس

```php
define( 'FALNIC_STORAGE_DIR', '/mnt/secure-media' );
define( 'FALNIC_TRUSTED_PROXY_HEADER', 'CF-Connecting-IP' );
```
- `falnic_watermark_mode = server` (سوختن شناسه در تصویر)
- `falnic_watermark_email = full`
- `falnic_max_concurrent = 1`
- `falnic_bind_ip = yes` (فقط اگر کاربران موبایل کم‌اند)
- `falnic_log_segments = true`
- نسخه‌ی A/B فورنزیک

### ج) مقیاس بالا با CDN

```php
define( 'FALNIC_CDN_BASE', 'https://media.example.com' );
```
- سگمنت‌ها با URL امضاشده در لبه
- `FALNIC_TOKEN_SECRET` مشترک با سرویس امضا
- Redis برای rate limit و نشست
- سرور رسانه‌ی جدا از PHP

---

## ۷. تنظیمات ممنوعه (Anti-patterns)

| کار | چرا بد است |
|---|---|
| گذاشتن `FALNIC_STORAGE_DIR` داخل `wp-content/uploads` | دوباره عمومی می‌شود |
| `falnic_token_ttl` بیشتر از ۳۶۰۰ | توکن لو رفته عمر زیادی دارد |
| `falnic_watermark_mode = off` در دوره‌ی پولی | نشتی غیرقابل ردیابی |
| `falnic_bind_ip = yes` برای کاربران موبایل | قطع مکرر پخش |
| `falnic_log_segments = true` روی ترافیک بالا | دیتابیس چند میلیون ردیف در روز |
| خاموش کردن `sfwd_lms_has_access` با فیلتر | محتوای پولی آزاد می‌شود |
| کش صفحه بدون استثنا برای درس‌ها | واترمارک/توکن کاربر اشتباه |

---

## ۸. بازنشانی

```bash
wp option delete falnic_token_ttl falnic_max_concurrent falnic_watermark_mode
wp falnic cleanup --sessions --logs --dry-run
```
برای بازتولید کلید امضا (باطل کردن همه‌ی نشست‌ها): مقدار `FALNIC_TOKEN_SECRET` را عوض کنید و `wp falnic sessions flush` را بزنید.
