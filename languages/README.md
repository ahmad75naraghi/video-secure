# پوشه‌ی ترجمه‌ها

فایل‌های ترجمه در این پوشه قرار می‌گیرند:

- `falnic-secure-hls-fa_IR.po` — متن قابل ویرایش
- `falnic-secure-hls-fa_IR.mo` — فایل کامپایل‌شده که وردپرس می‌خواند

## ساخت فایل ترجمه

همه‌ی رشته‌ها با text domain `falnic-secure-hls` نوشته شده‌اند. برای ساخت قالب ترجمه:

```bash
wp i18n make-pot . languages/falnic-secure-hls.pot
wp i18n make-mo languages/
```

یا با Poedit: فایل `.pot` را باز کنید، ترجمه کنید و ذخیره کنید تا `.mo` ساخته شود.

## بارگذاری

فایل ترجمه با `load_plugin_textdomain()` در `includes/class-plugin.php` بارگذاری می‌شود و به `Domain Path: /languages` در هدر پلاگین وابسته است.
