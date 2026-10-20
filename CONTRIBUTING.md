# مشارکت

## راه‌اندازی محیط توسعه

```bash
git clone <repo> falnic-secure-hls
cd falnic-secure-hls

# بررسی سینتکس
php -l falnic-secure-hls.php

# استاندارد کد (اختیاری ولی توصیه‌شده)
composer global require squizlabs/php_codesniffer wp-coding-standards/wpcs
phpcs --standard=WordPress falnic-secure-hls.php

# محیط کامل وردپرس با docker (پیشنهاد برای فاز ۲)
docker compose up -d
```

## استاندارد کد

| مورد | قاعده |
|---|---|
| زبان | PHP ۷.۴+ (بدون تایپ‌های ۸.x تا تصمیم OPEN-5) |
| استاندارد | WordPress Coding Standards |
| نام‌گذاری | کلاس‌ها `StudlyCase`، متدها `snake_case`، ثابت‌ها `UPPER_SNAKE` |
| Namespace | `Falnic\SecureHLS` |
| خروجی | همیشه `esc_html`, `esc_attr`, `esc_url`, `wp_json_encode` |
| ورودی | `sanitize_*` + `wp_unslash` + nonce + capability |
| SQL | فقط `$wpdb->prepare` |
| فایل | `ABSPATH` guard در هر فایل PHP |
| i18n | `__( '…', 'falnic-secure-hls' )` |
| رشته‌ی JS | `wp_localize_script`، نه رشته‌سازی HTML |

## قواعد امنیتی (بازبینی PR)

هر PR باید این موارد را رعایت کند:

- [ ] هیچ `eval`، `debugger`، `document.body.innerHTML` در کد
- [ ] هیچ `exec`/`shell_exec` بدون `escapeshellarg` و allowlist
- [ ] هیچ مسیر فایل از ورودی کاربر بدون `realpath` + بررسی prefix
- [ ] هر اندپوینت REST دارای `permission_callback` صریح
- [ ] هر فرم دارای nonce + capability
- [ ] هیچ کلید/رمز در کد یا لاگ
- [ ] هیچ `@` برای مخفی کردن خطا (به‌جای آن بررسی شرط)
- [ ] خروجی کاربر همیشه escape شده

## گردش کار Git

```
main
└── develop
    └── feat/…   fix/…   security/…   docs/…
```

- پیام کامیت: `type(scope): subject` — مثال: `fix(stream): verify token before serving segment`
- نوع‌ها: `feat`, `fix`, `security`, `docs`, `refactor`, `test`, `chore`
- هر PR: توضیح مشکل، راه‌حل، نحوه‌ی تست، اثر روی سازگاری
- تغییرات امنیتی باید به یافته‌ی مربوطه در [ممیزی](docs/02-SECURITY-AUDIT.md) ارجاع دهند.

## تست

قبل از PR:

```bash
php -l $(find . -name '*.php' -not -path './vendor/*')
phpcs --standard=WordPress includes/
```

برای تغییرات استریم/دسترسی، اسکریپت‌های [۱۴-تست](docs/14-TESTING.md#۳-تستهای-امنیتی-قابل-اجرا) را اجرا کنید.

## مستندسازی

- تغییر رفتار عمومی → به‌روزرسانی `docs/`
- تغییر API → `docs/06-API.md`
- تغییر تنظیمات → `docs/12-CONFIGURATION.md`
- هر تغییر قابل توجه → `CHANGELOG.md`

## فرآیند انتشار

1. تست‌های [درگاه انتشار](docs/14-TESTING.md#۹-درگاه-انتشار-release-gate) پاس شوند.
2. `CHANGELOG.md` به‌روز شود.
3. شماره‌ی نسخه در هدر پلاگین و ثابت `FALNIC_VERSION` یکی شود.
4. تگ `vX.Y.Z` و ساخت ZIP.
5. انتشار یادداشت‌ها با تأکید بر تغییرات امنیتی.

## رفتار حرفه‌ای

- انتقاد فنی بدهید، نه شخصی.
- برای تغییرات بزرگ، اول ایشو باز کنید.
- در گزارش باگ امنیتی، از افشای عمومی قبل از وصله خودداری کنید ([SECURITY.md](SECURITY.md)).
