# ۱۱ — نصب و راه‌اندازی

## ۱. پیش‌نیازها

| مورد | حداقل | توصیه |
|---|---|---|
| PHP | ۷.۴ | ۸.۱+ |
| وردپرس | ۵.۸ | آخرین نسخه |
| LearnDash | ۴.۰ (اختیاری) | آخرین نسخه |
| ffmpeg | با `libx264` و `aac` | نسخه ۵+ |
| فضای دیسک | ۳× حجم ویدیوی خام | ۵× |
| RAM | ۱GB | ۲GB+ (انکود) |
| CPU | ۲ هسته | ۴ هسته+ |

### بررسی ffmpeg

```bash
ffmpeg -version | head -1
ffmpeg -hide_banner -encoders 2>/dev/null | grep -E 'libx264|aac'
ffprobe -version | head -1
```

اگر `libx264` نبود:

```bash
# Debian/Ubuntu
sudo apt update && sudo apt install -y ffmpeg

# CentOS/RHEL (از RPM Fusion)
sudo dnf install -y https://download1.rpmfusion.org/free/el/rpmfusion-free-release-$(rpm -E %rhel).noarch.rpm
sudo dnf install -y ffmpeg
```

### بررسی امکان اجرای ffmpeg توسط PHP

```bash
php -r 'var_dump(function_exists("exec"), ini_get("disable_functions"));'
# باید bool(true) و رشته‌ای بدون exec باشد
```

اگر `exec` در `disable_functions` است، دو راه دارید:
1. از هاست بخواهید فقط مسیر ffmpeg را مجاز کند (بهتر: `exec` با allowlist).
2. انکود را از وردپرس جدا کنید: پلاگین فایل را در `incoming/` بگذارد و یک سرویس بیرونی (systemd + `inotify` یا صف) آن را انکود کند. جزئیات در [۱۳-نقشه راه](13-ROADMAP.md#فاز-۲).

---

## ۲. تنظیمات PHP

```ini
upload_max_filesize = 4096M
post_max_size       = 4096M
max_execution_time  = 0        ; فقط اگر انکود همگام دارید؛ در نسخه ۴ لازم نیست
max_input_time      = 600
memory_limit        = 512M
```

اگر از Nginx استفاده می‌کنید، `client_max_body_size 4096m;` را هم اضافه کنید. در Apache، `LimitRequestBody`.

---

## ۳. نصب پلاگین

```bash
cd /var/www/html/wp-content/plugins/
git clone <repo> falnic-secure-hls
wp plugin activate falnic-secure-hls
```

یا آپلود ZIP از پیشخوان.

### ساخت پوشه‌ی ذخیره‌سازی

```bash
sudo mkdir -p /var/www/falnic-secure/{videos,keys,incoming,tmp}
sudo chown -R www-data:www-data /var/www/falnic-secure
sudo chmod 700 /var/www/falnic-secure /var/www/falnic-secure/keys
sudo chmod 750 /var/www/falnic-secure/videos
```

قرار دادن آن **خارج از** `/var/www/html` یعنی حتی اگر وب‌سرور اشتباه تنظیم شود، فایل‌ها از اینترنت قابل دسترسی نیستند.

### ثابت‌ها در `wp-config.php`

```php
define( 'FALNIC_STORAGE_DIR', '/var/www/falnic-secure' );
define( 'FALNIC_TOKEN_SECRET', 'REPLACE_WITH_64_RANDOM_CHARS' );
define( 'FALNIC_FFMPEG_BIN',  '/usr/bin/ffmpeg' );
define( 'FALNIC_FFPROBE_BIN', '/usr/bin/ffprobe' );
```

تولید کلید:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

> `FALNIC_TOKEN_SECRET` را در بکاپ امن نگه دارید. تغییر آن همه‌ی توکن‌های فعال را باطل می‌کند (که در صورت نشتی مطلوب است).

---

## ۴. پیکربندی وب‌سرور

### Nginx

```nginx
server {
    # مسیرهای قدیمی که باید بسته باشند
    location ^~ /wp-content/uploads/secure_videos/ {
        deny all;
        return 403;
    }
    location ^~ /wp-content/plugins/falnic-secure-hls/ {
        # فقط دارایی‌های عمومی
        location ~* \.(js|css|woff2?|svg|png|jpg)$ { try_files $uri =404; expires 1y; add_header Cache-Control "public, immutable"; }
        deny all;
    }

    # سرو داخلی رسانه (فقط از طریق X-Accel-Redirect)
    location /falnic-internal/ {
        internal;
        alias /var/www/falnic-secure/;
        types { video/mp2t ts; application/vnd.apple.mpegurl m3u8; }
        add_header Accept-Ranges bytes;
        add_header Cache-Control "private, no-store";
        add_header X-Content-Type-Options nosniff;
    }

    client_max_body_size 4096m;
}
```

### Apache

```apache
# .htaccess در wp-content/uploads
<IfModule mod_authz_core.c>
    <Directory "secure_videos">
        Require all denied
    </Directory>
</IfModule>

# فعال‌سازی X-Sendfile
<IfModule mod_xsendfile.c>
    XSendFile On
    XSendFilePath /var/www/falnic-secure
</IfModule>
```

اگر `mod_xsendfile` ندارید، پلاگین با `readfile` چانک‌دار کار می‌کند (کندتر ولی درست).

---

## ۵. بعد از فعال‌سازی

1. **سلامت سیستم**: منوی «ویدیوهای امن → سلامت». باید همه‌ی موارد سبز باشند:
   - ffmpeg و ffprobe در دسترس
   - پوشه‌ها قابل نوشتن و خارج از web root
   - `FALNIC_TOKEN_SECRET` تعریف شده
   - HTTPS فعال
   - LearnDash شناسایی شده
2. **اولین ویدیو**: «ویدیوهای امن → افزودن» → آپلود MP4 → عنوان فارسی دلخواه → ذخیره. وضعیت باید `queued → processing → ready` شود.
3. **پیش‌نمایش**: دکمه‌ی «پیش‌نمایش» را بزنید. واترمارک باید نام و شناسه‌ی شما را نشان دهد.
4. **قرار دادن در درس**: ویرایش درس LearnDash → متاباکس «ویدیوی امن این درس» → انتخاب ویدیو → ذخیره.
5. **تست دسترسی**: با یک حساب رایگان صفحه‌ی درس را باز کنید؛ باید پیام «ثبت‌نام لازم» ببینید، نه ویدیو.

---

## ۶. WP-CLI

```bash
wp falnic health                      # بررسی سلامت
wp falnic encode /path/to/video.mp4 --title="درس ۱"
wp falnic encode --all-failed          # انکود مجدد ناموفق‌ها
wp falnic rekey v_9f2c1a7b3d4e5f60     # چرخش کلید یک ویدیو
wp falnic cleanup --older-than=90      # پاکسازی لاگ و نشست‌ها
wp falnic migrate                      # مهاجرت از نسخه ۳
wp falnic stats --days=30              # گزارش پخش
```

---

## ۷. کرون

| وظیفه | بازه | توضیح |
|---|---|---|
| پردازش صف انکود | هر دقیقه | اگر Action Scheduler نصب نیست |
| بستن نشست‌های بی‌فعال | هر ۵ دقیقه | `last_seen_at` قدیمی‌تر از ۹۰ ثانیه |
| پاکسازی لاگ | روزانه | حذف قدیمی‌تر از ۹۰ روز |
| پاکسازی tmp | روزانه | فایل‌های `keyinfo` یتیم |
| بررسی یکپارچگی | هفتگی | وجود کلید برای هر سگمنت |

```bash
# اگر WP-Cron غیرفعال است
*/5 * * * * cd /var/www/html && wp cron event run falnic_maintenance --quiet
```

---

## ۸. چند سایت (Multisite)

- پوشه‌ی ذخیره‌سازی می‌تواند مشترک باشد؛ `videos/{blog_id}/{video_key}` یا `video_key` یکتا در شبکه.
- جدول‌های نشست/لاگ per-site ساخته شوند.
- `FALNIC_TOKEN_SECRET` را در `wp-config.php` شبکه تعریف کنید تا همه‌ی سایت‌ها یکی باشند (یا per-site برای جداسازی).
- ذخیره‌سازی مشترک با `FALNIC_STORAGE_DIR` + `blog_id` در مسیر.

---

## ۹. پشتیبان‌گیری

```bash
# فایل‌های رسانه
rsync -a --delete /var/www/falnic-secure/ backup:/backup/falnic-secure/

# دیتابیس (شامل video_key ها و لاگ)
wp db export backup-$(date +%F).sql
```

**مهم:** کلیدها و سگمنت‌ها باید **با هم** پشتیبان‌گیری شوند. ویدیو بدون کلید قابل پخش نیست و کلید بدون ویدیو بی‌فایده است.

بکاپ را رمزنگاری کنید (`age`/`gpg`) چون شامل کلیدهای AES و لاگ کاربران است.

---

## ۱۰. محیط توسعه

```bash
docker run --rm -v "$PWD":/app -w /app php:8.2-cli bash -c "php -l falnic-secure-hls.php"
# برای محیط کامل وردپرس، به docker-compose نیاز دارید (در نقشه راه)
```

نکات محیط توسعه:
- HTTPS لازم نیست اگر `falnic_allow_insecure` روشن باشد (فقط در `WP_DEBUG`).
- ffmpeg با `-preset ultrafast` در محیط توسعه تا تست سریع شود.
- فایل‌های رسانه در `wp-content/falnic-dev/` برای سادگی (با هشدار در پنل).

---

## ۱۱. مهاجرت از نسخه ۳.۱.۰

به [۱۶-مهاجرت](16-MIGRATION.md) مراجعه کنید. خلاصه:

```bash
wp falnic migrate --dry-run
wp falnic migrate --backup-first
```

---

## ۱۲. چک‌لیست پس از نصب

- [ ] `ffmpeg` و `ffprobe` در سلامت سبز
- [ ] پوشه‌ی رسانه خارج از web root و `0700`
- [ ] `curl` به مسیر قدیمی `uploads/secure_videos` → `403`
- [ ] `FALNIC_TOKEN_SECRET` تعریف و بکاپ شده
- [ ] HTTPS اجباری
- [ ] اولین ویدیو با واترمارک پخش می‌شود
- [ ] کاربر غیرمجاز `403` می‌گیرد
- [ ] `yt-dlp` روی صفحه شکست می‌خورد
- [ ] کرون‌ها ثبت شده‌اند
- [ ] بکاپ اول گرفته شده
