# ۱۶ — مهاجرت

## ۱. از چه چیزی به چه چیزی

| از | به | سطح تغییر |
|---|---|---|
| ۳.۱.۰ (فایل واحد، مسیر عمومی) | ۳.۲ (فاز ۱: توقف خون‌ریزی) | کم — سازگار با داده‌ی فعلی |
| ۳.۲ | ۴.۰ (معماری جدید، `video_key`, توکن) | زیاد — نیازمند مهاجرت داده |

این سند هر دو مرحله را پوشش می‌دهد.

---

## ۲. پیش از شروع

> ⚠️ **مهاجرت بدون بکاپ انجام ندهید.** فایل‌های ویدیو و کلیدها باید با هم پشتیبان‌گیری شوند.

```bash
# ۱) بکاپ دیتابیس
wp db export pre-migration-$(date +%F).sql

# ۲) بکاپ رسانه
rsync -a /var/www/secure_keys/ /backup/secure_keys-$(date +%F)/
rsync -a /var/www/html/wp-content/uploads/secure_videos/ /backup/secure_videos-$(date +%F)/

# ۳) ثبت وضعیت فعلی
wp post list --post_type=secure_video --fields=ID,post_title,post_name --format=csv > videos-before.csv
```

---

## ۳. مهاجرت به ۳.۲ (فاز ۱)

این مرحله فقط امنیت را می‌بندد و شناسه‌ها را تغییر نمی‌دهد:

```bash
# ۱) پوشه‌ی جدید خارج از web root
sudo mkdir -p /var/www/falnic-secure/{videos,keys,incoming,tmp}
sudo chown -R www-data:www-data /var/www/falnic-secure
sudo chmod 700 /var/www/falnic-secure/keys

# ۲) انتقال فایل‌ها
sudo rsync -a --remove-source-files \
  /var/www/html/wp-content/uploads/secure_videos/ /var/www/falnic-secure/videos/
sudo rsync -a --remove-source-files \
  /var/www/secure_keys/ /var/www/falnic-secure/keys/
sudo chmod -R 600 /var/www/falnic-secure/keys/*/*.key

# ۳) بستن مسیر قدیمی در وب‌سرور (نمونه Nginx)
# location ^~ /wp-content/uploads/secure_videos/ { deny all; return 403; }

# ۴) به‌روزرسانی URL کلید در m3u8های موجود
wp falnic migrate --rewrite-key-uri --dry-run
wp falnic migrate --rewrite-key-uri

# ۵) بررسی
wp falnic health
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/wp-content/uploads/secure_videos/lesson-108/000.ts
# باید 403/404 باشد
```

> اگر پلاگین را روی ۳.۲ آپدیت کردید، `get_videos_dir()` و `get_secure_dir()` جدید را قبل از حذف پوشه‌های قدیمی فعال کنید تا فایل‌ها یتیم نشوند.

---

## ۴. مهاجرت به ۴.۰ (ساختار جدید)

### ۴.۱ نگاشت داده

| داده‌ی قدیمی | داده‌ی جدید |
|---|---|
| `post_title` = `lesson-108` | `_falnic_video_key` = `v_<16 hex>` |
| `secure_videos/lesson-108/` | `videos/v_<16hex>/` |
| `secure_keys/lesson-108/lesson-108.key` | `keys/v_<16hex>/1.key` |
| `[secure_video name="lesson-108"]` | `[secure_video id="123"]` (با نگاشت در آپشن) |
| `?falnic_embed=lesson-108` | `?falnic_embed=v_<16hex>` (با نگاشت و ریدایرکت) |

### ۴.۲ مراحل

```bash
# ۱) نقشه‌ی نگاشت بسازید (فقط گزارش)
wp falnic migrate key-map --output=migration-map.csv

# ۲) بررسی خروجی
# legacy_name,post_id,new_video_key,has_video,has_key
head -5 migration-map.csv

# ۳) تولید video_key و ذخیره در متا
wp falnic migrate generate-keys --dry-run
wp falnic migrate generate-keys

# ۴) انتقال فایل‌ها بر اساس نقشه
wp falnic migrate move-files --dry-run
wp falnic migrate move-files

# ۵) بازنویسی m3u8 (URI کلید نسبی + مسیر جدید)
wp falnic migrate rewrite-manifests --dry-run
wp falnic migrate rewrite-manifests

# ۶) بازنویسی شورت‌کدها در محتوا (اختیاری)
wp falnic migrate rewrite-shortcodes --dry-run
wp falnic migrate rewrite-shortcodes

# ۷) ساخت نگاشت برای لینک‌های قدیمی
wp falnic migrate legacy-redirects

# ۸) اعتبارسنجی
wp falnic health
wp falnic verify --all
```

### ۴.۳ اسکریپت معادل PHP (اگر WP-CLI نیست)

```php
foreach ( get_posts( [ 'post_type' => 'secure_video', 'numberposts' => -1 ] ) as $post ) {
    $legacy = $post->post_title;
    $key    = get_post_meta( $post->ID, '_falnic_video_key', true );
    if ( ! $key ) {
        $key = 'v_' . bin2hex( random_bytes( 8 ) );
        update_post_meta( $post->ID, '_falnic_video_key', $key );
    }

    $old_dir = Storage::videos() . $legacy;
    $new_dir = Storage::videos() . $key;
    if ( is_dir( $old_dir ) && ! is_dir( $new_dir ) ) {
        rename( $old_dir, $new_dir );
    }

    $old_key = Storage::keys() . $legacy . '/' . $legacy . '.key';
    $new_key = Storage::keys() . $key . '/1.key';
    if ( file_exists( $old_key ) && ! file_exists( $new_key ) ) {
        wp_mkdir_p( dirname( $new_key ) );
        rename( $old_key, $new_key );
        chmod( $new_key, 0600 );
    }

    update_option( 'falnic_legacy_map_' . $legacy, $key, false );
}
```

### ۴.۴ بازنویسی manifest

نسخه‌ی ۳.۱.۰ در m3u8 نوشته است:

```
#EXT-X-KEY:METHOD=AES-128,URI="https://site.com/wp-admin/admin-ajax.php?action=secure_hls_key&video=lesson-108"
```

نسخه‌ی ۴ انتظار URI نسبی دارد:

```
#EXT-X-KEY:METHOD=AES-128,URI="k/1"
```

```bash
wp falnic migrate rewrite-manifests --dry-run
```

اسکریپت باید:
- فایل را خط‌به‌خط بخواند،
- هر `URI="..."` را با `k/{kid}` جایگزین کند،
- شماره‌ی `kid` را از فایل کلید موجود استخراج کند،
- قبل از نوشتن، `.bak` بسازد.

### ۴.۵ سازگاری عقب‌رو

| مورد | رفتار در ۴.۰ | حذف در |
|---|---|---|
| `[secure_video name="x"]` | کار می‌کند با هشدار deprecation | نسخه ۵ |
| `?falnic_embed=lesson-108` | نگاشت به `video_key` و ریدایرکت ۳۰۱ | نسخه ۵ |
| `admin-ajax.php?action=secure_hls_key` | `410 Gone` با پیام | نسخه ۵ |
| `uploads/secure_videos/*` | `403` | فوری |
| آپشن `falnic_hls_max_sessions` | به `falnic_max_concurrent` مهاجرت می‌کند | نسخه ۵ |

---

## ۵. بازگشت (Rollback)

```bash
# ۱) پلاگین نسخه قبل را برگردانید
wp plugin install falnic-secure-hls-3.1.0.zip --force --activate

# ۲) دیتابیس
wp db import pre-migration-2026-10-20.sql

# ۳) فایل‌ها
rsync -a /backup/secure_videos-2026-10-20/ /var/www/html/wp-content/uploads/secure_videos/
rsync -a /backup/secure_keys-2026-10-20/   /var/www/secure_keys/

# ۴) قواعد وب‌سرور را برگردانید (فقط اگر لازم است)
```

**هشدار:** اگر بعد از مهاجرت انکود جدید انجام شده باشد، بازگشت به بکاپ، آن‌ها را از دست می‌دهد. قبل از rollback بکاپ جدید بگیرید.

---

## ۶. چک‌لیست مهاجرت

- [ ] بکاپ دیتابیس و فایل‌ها
- [ ] نقشه‌ی نگاشت تولید و بازبینی شد
- [ ] `FALNIC_STORAGE_DIR` و `FALNIC_TOKEN_SECRET` تعریف شد
- [ ] پوشه‌ها با مجوز `0700` ساخته شدند
- [ ] فایل‌ها منتقل و پوشه‌های قدیمی حذف شدند
- [ ] manifestها بازنویسی شدند
- [ ] مسیر قدیمی در وب‌سرور `403` می‌دهد
- [ ] همه‌ی ویدیوها با `wp falnic verify --all` سالم‌اند
- [ ] پخش یک ویدیوی نمونه در کروم و Safari
- [ ] کاربر غیرمجاز `403` می‌گیرد
- [ ] لاگ‌ها کار می‌کنند
- [ ] مسیر rollback تست شد

---

## ۷. مهاجرت بدون قطع سرویس

برای سایت فعال:

1. پلاگین جدید را نصب کنید ولی فعال نکنید.
2. فایل‌ها را **کپی** کنید (نه move) به مسیر جدید.
3. در یک پنجره‌ی کم‌ترافیک، پلاگین را تعویض کنید.
4. فقط بعد از تأیید پخش، فایل‌های قدیمی را حذف کنید.

در حالت ایده‌آل، تمام این مراحل روی استیجینگ با کپی دیتابیس تست شوند.
