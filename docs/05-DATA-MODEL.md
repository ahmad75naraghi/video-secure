# ۰۵ — مدل داده

## بخش الف: وضعیت فعلی

### ۱. پست‌تایپ

```php
// L52-L61
register_post_type( 'secure_video', [
    'public'        => false,   // ✔ درست: بدون URL عمومی
    'show_ui'       => true,
    'supports'      => [ 'title', 'editor' ],
    ...
] );
```

هیچ متای اختصاصی ثبت نشده، هیچ `register_post_meta` وجود ندارد، و **شناسه‌ی ویدیو همان `post_title` است**. این بزرگ‌ترین ضعف مدل داده است: عنوان، داده‌ی نمایشی است و نباید کلید فنی باشد.

### ۲. آپشن‌ها

| نام | نوع | پیش‌فرض | توضیح | مشکل |
|---|---|---|---|---|
| `falnic_hls_max_sessions` | int | `1` | حداکثر لاگین هم‌زمان | فقط مقدار ۱ اثر دارد |
| `falnic_hls_bind_ip` | `yes`/`no` | `no` | قفل استریم روی IP | مبنای IP اشتباه است |

### ۳. متای کاربر

| کلید | مقدار | مشکل |
|---|---|---|
| `falnic_last_login_ip` | IP در لحظه‌ی لاگین | هرگز به‌روز نمی‌شود؛ پشت CDN اشتباه است |

### ۴. Transient ها

| کلید | عمر | کاربرد |
|---|---|---|
| `falnic_encode_success_{user_id}` | ۶۰ ثانیه | پیام موفقیت |
| `falnic_encode_error_{user_id}` | ۶۰ ثانیه | پیام خطا |

### ۴.۱. جدول گزارش تماشا (پیاده‌شده در ۳.۴)

برخلاف بخش «هدف» پایین که جدول نشست‌ها با `ended_at` را پیشنهاد می‌کرد، پیاده‌سازی نهایی یک جدول
تک‌ردیفی برای هر پخش با به‌روزرسانی درجا است (ساده‌تر برای گزارش و سازگار با `dbDelta`):

```sql
CREATE TABLE {$prefix}falnic_watch_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  video         VARCHAR(100)    NOT NULL DEFAULT '',
  course_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  session_id    VARCHAR(40)     NOT NULL DEFAULT '',     -- sid توکن
  ip_hash       CHAR(32)        NOT NULL DEFAULT '',     -- HMAC-SHA256 کوتاه‌شده
  agent         VARCHAR(40)     NOT NULL DEFAULT '',     -- خانواده‌ی دستگاه
  started_at    DATETIME        NOT NULL,
  last_seen     DATETIME        NOT NULL,
  seconds       INT UNSIGNED    NOT NULL DEFAULT 0,
  segments      INT UNSIGNED    NOT NULL DEFAULT 0,
  bytes         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY user_video (user_id,video),
  KEY started_at (started_at),
  KEY session_id (session_id)
) {$charset_collate};
```

- نسخه‌ی ساختار در `falnic_log_db_version` نگه داشته می‌شود؛ ساخت/ارتقا با `Watch_Log::install()`.
- نگه‌داری: `falnic_watch_log_days` (پیش‌فرض ۹۰) و هوک روزانه‌ی `falnic_watch_log_purge`.
- نشست‌های فعال پخش در transient `falnic_plays_<uid>` هستند، نه در دیتابیس (داده‌ی گذرا و پرنوسان).

### ۵. ساختار فایل‌ها (فعلی)

```
{dirname(ABSPATH)}/secure_keys/{title}/
    {title}.key          # ۱۶ بایت AES، مجوز 0644 (پیش‌فرض umask)
    {title}.keyinfo      # موقت: URL کلید + مسیر مطلق + IV

{wp_upload_dir}/secure_videos/{title}/
    {title}.m3u8         # عمومی و دانلودپذیر  ✘
    000.ts 001.ts ...    # عمومی و دانلودپذیر  ✘
```

مشکلات ساختاری:
- نام پوشه از عنوان پست می‌آید → تغییر عنوان = یتیم شدن فایل‌ها.
- کلید و ویدیو از هم جدا شده‌اند و پشتیبان‌گیری از یکی بدون دیگری، بازیابی را غیرممکن می‌کند.
- هیچ فایل متادیتایی (مدت، رزولوشن، وضعیت انکود، هش) وجود ندارد.
- هیچ نگاشتی بین `post_id` و ویدیو ذخیره نمی‌شود.

---

## بخش ب: مدل داده‌ی هدف

### ۱. اصول

1. **شناسه‌ی فنی تصادفی**، مستقل از عنوان.
2. **یک ریشه‌ی ذخیره‌سازی** با ساختار قابل پیش‌بینی.
3. **وضعیت انکود بخشی از داده است**، نه نتیجه‌ی وجود فایل.
4. **لاگ و نشست در جدول‌های اختصاصی**، نه در `wp_options` (که با حجم زیاد منفجر می‌شود).

### ۲. پست‌تایپ و متا

| کلید متا | نوع | مثال | توضیح |
|---|---|---|---|
| `_falnic_video_key` | string | `v_9f2c1a7b3d4e5f60` | **شناسه‌ی اصلی**، تصادفی، تغییرناپذیر |
| `_falnic_status` | enum | `queued` / `processing` / `ready` / `failed` / `disabled` | وضعیت چرخه‌ی عمر |
| `_falnic_source` | string | `uploads/2026/10/src-abc.mp4` | فایل اصلی (اختیاری، برای انکود مجدد) |
| `_falnic_duration` | int | `1834` | ثانیه |
| `_falnic_renditions` | array | `[{name:720p,w:1280,h:720,br:2500}, ...]` | کیفیت‌های موجود |
| `_falnic_poster_id` | int | `812` | شناسه‌ی تصویر شاخص |
| `_falnic_encrypted_at` | datetime | `2026-10-20 10:00:00` | زمان انکود |
| `_falnic_encoder_version` | string | `4.0.0` | برای مهاجرت/انکود مجدد |
| `_falnic_error` | string | `ffmpeg exited 1: ...` | آخرین خطا |
| `_falnic_lesson_ids` | array | `[445, 446]` | برای جست‌وجوی معکوس دسترسی |
| `_falnic_watermark_profile` | string | `default` / `strict` | پروفایل واترمارک |

شورت‌کد پیشنهادی: `[secure_video id="123"]` (پست‌آیدی) یا `[secure_video key="v_9f2c..."]`. پارامتر `name` برای سازگاری عقب‌رو نگه داشته می‌شود اما **deprecated** است.

### ۳. ساختار ریشه‌ی ذخیره‌سازی

```
{dirname(ABSPATH)}/falnic-secure/          # یا FALNIC_STORAGE_DIR
├── videos/
│   └── v_9f2c1a7b3d4e5f60/                # video_key
│       ├── master.m3u8                     # پلی‌لیست اصلی (با URLهای نسبی)
│       ├── poster.jpg
│       ├── meta.json                       # مدت، رندیشن‌ها، هش ورودی
│       └── 720p/
│           ├── index.m3u8                  # شامل #EXT-X-KEY با URI نسبی "k/1"
│           ├── 00001.ts ... 00042.ts
│           └── ...
├── keys/
│   └── v_9f2c1a7b3d4e5f60/
│       ├── 1.key                           # 0600
│       ├── 2.key                           # (چرخش)
│       └── index.json                      # {1:[0,39], 2:[40,79], ...}
├── incoming/                               # آپلودهای در انتظار انکود، 0700
├── tmp/                                    # keyinfo و فایل‌های موقت
└── .falnic-index.json                      # نسخه‌ی ساختار (برای مهاجرت)
```

قواعد:
- مجوز پوشه: `0700`؛ فایل کلید: `0600`؛ سگمنت: `0644` (چون فقط از طریق PHP خوانده می‌شود، این هم می‌تواند `0600` باشد).
- `realpath` + بررسی prefix در هر دسترسی.
- `meta.json` تنها منبع حقیقت برای رندیشن‌هاست، نه `scandir`.

### ۴. جدول نشست‌های پخش

```sql
CREATE TABLE {$prefix}falnic_sessions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_uid   CHAR(16)        NOT NULL,              -- S-7F3A2C ...
  user_id       BIGINT UNSIGNED NOT NULL,
  video_key     VARCHAR(32)     NOT NULL,
  token_hash    CHAR(64)        NOT NULL,              -- sha256 توکن فعال
  ip_hash       CHAR(64)        NULL,                  -- sha256(ip + salt)
  user_agent    VARCHAR(255)    NULL,
  started_at    DATETIME        NOT NULL,
  last_seen_at  DATETIME        NOT NULL,
  ended_at      DATETIME        NULL,
  end_reason    VARCHAR(32)     NULL,                  -- closed|timeout|limit|revoked
  position_sec  INT UNSIGNED    DEFAULT 0,
  PRIMARY KEY  (id),
  KEY idx_user_video (user_id, video_key, ended_at),
  KEY idx_session_uid (session_uid),
  KEY idx_last_seen (last_seen_at)
) {$charset_collate};
```

### ۵. جدول لاگ دسترسی

```sql
CREATE TABLE {$prefix}falnic_access_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts           DATETIME        NOT NULL,
  user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  session_uid  CHAR(16)        NULL,
  video_key    VARCHAR(32)     NOT NULL,
  resource     ENUM('player','manifest','segment','key','token') NOT NULL,
  result       ENUM('allow','deny','error') NOT NULL,
  reason       VARCHAR(48)     NULL,                   -- not_enrolled|bad_token|rate_limit|...
  ip_hash      CHAR(64)        NULL,
  bytes        BIGINT UNSIGNED DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_video_ts (video_key, ts),
  KEY idx_user_ts (user_id, ts),
  KEY idx_session (session_uid),
  KEY idx_result_ts (result, ts)
) {$charset_collate};
```

نگهداری: پاکسازی خودکار رکوردهای قدیمی‌تر از `falnic_log_retention_days` (پیش‌فرض ۹۰) با WP-Cron روزانه، به‌صورت `DELETE ... LIMIT 5000` در حلقه تا جلوگیری از قفل طولانی.

### ۶. آپشن‌های هدف

| نام | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `falnic_storage_dir` | string | `dirname(ABSPATH)/falnic-secure` | ریشه‌ی رسانه |
| `falnic_token_ttl` | int | `600` | عمر توکن (ثانیه) |
| `falnic_key_rotation_segments` | int | `40` | چرخش کلید |
| `falnic_max_concurrent` | int | `2` | پخش هم‌زمان |
| `falnic_heartbeat_timeout` | int | `90` | قطع نشست بی‌فعالیت |
| `falnic_watermark_mode` | enum | `canvas` | `off`/`dom`/`canvas`/`server` |
| `falnic_watermark_email` | enum | `masked` | `masked`/`full`/`hidden` |
| `falnic_watermark_opacity` | float | `0.35` | شفافیت |
| `falnic_watermark_interval` | int | `30` | جابه‌جایی (ثانیه) |
| `falnic_segment_rate_limit` | int | `60` | درخواست در دقیقه |
| `falnic_log_retention_days` | int | `90` | نگهداری لاگ |
| `falnic_ld_auto_embed` | `yes`/`no` | `yes` | نمایش خودکار در درس |
| `falnic_ld_position` | enum | `before` | `before`/`after` محتوا |
| `falnic_rerender` | bool | `false` | اجازه‌ی ثبت مجدد پلیر |
| `falnic_delete_data_on_uninstall` | bool | `false` | حذف کامل داده‌ها |

### ۷. ثابت‌های `wp-config.php`

```php
define( 'FALNIC_STORAGE_DIR', '/var/falnic-media' );
define( 'FALNIC_TOKEN_SECRET', '…۵۱۲ بیت تصادفی…' );   // اجباری در تولید
define( 'FALNIC_FFMPEG_BIN', '/usr/local/bin/ffmpeg' );
define( 'FALNIC_FFPROBE_BIN', '/usr/local/bin/ffprobe' );
define( 'FALNIC_CDN_BASE', 'https://media.example.com' );
define( 'FALNIC_TRUSTED_PROXY_HEADER', 'CF-Connecting-IP' );
```

### ۸. مهاجرت داده از نسخه‌ی فعلی

| از | به | نحوه |
|---|---|---|
| `post_title` = شناسه | `_falnic_video_key` | تولید تصادفی + نگاشت قدیمی→جدید در `wp_options` |
| `secure_videos/{title}/` | `videos/{key}/` | انتقال فایل + بازنویسی `#EXT-X-KEY` |
| `secure_keys/{title}/{title}.key` | `keys/{key}/1.key` | انتقال + `chmod 600` |
| شورت‌کد `name="x"` | `id="123"` | اسکریپت جست‌وجو و جایگزینی در محتوا (اختیاری) |

جزئیات گام‌به‌گام و دستور بازگشت: [۱۶-مهاجرت](16-MIGRATION.md).
