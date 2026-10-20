# ۱۸ — ساختار پروژه (نسخه ۳.۲)

این سند ساختار فایل‌ها، قاعده‌های نام‌گذاری و سیاست «بدون وابستگی بیرونی» را تعریف می‌کند. اگر می‌خواهید کد جدید اضافه کنید، اول این را بخوانید.

## ۱. درخت فایل‌ها

```text
falnic-secure-hls/
├── falnic-secure-hls.php        ← فقط bootstrap: هدر، ثابت‌ها، autoload، فعال‌سازی
├── uninstall.php                ← پاک‌سازی آپشن‌ها و متا هنگام حذف کامل
├── readme.txt                   ← استاندارد مخزن وردپرس
├── README.md                    ← معرفی فارسی و وضعیت پروژه
├── CHANGELOG.md / SECURITY.md / CONTRIBUTING.md
│
├── includes/                    ← منطق اصلی (namespace: Falnic\SecureHLS)
│   ├── class-autoloader.php
│   ├── class-plugin.php         ← singleton، ثبت هوک‌های سرویس‌ها
│   ├── class-storage.php        ← تنها نقطه‌ی دسترسی به فایل‌سیستم
│   ├── class-post-type.php      ← CPT و ستون‌های لیست
│   ├── class-meta-box.php       ← آپلود و ذخیره‌ی پست
│   ├── class-encoder.php        ← ffmpeg، کلید، keyinfo
│   ├── class-player.php         ← شورت‌کد و ساخت پیکربندی پلیر
│   ├── class-assets.php         ← ثبت/بارگذاری CSS و JS
│   ├── class-embed.php          ← اندپوینت ?falnic_embed=
│   ├── class-key-server.php     ← تحویل کلید AES
│   ├── class-session.php        ← کنترل نشست
│   ├── class-installer.php      ← فعال/غیرفعال‌سازی
│   ├── class-template-settings.php ← تنظیمات ظاهری پلیر (گردی، رنگ، نسبت، واترمارک)
│   └── class-git-updater.php    ← موتور آپدیت از گیت (HTTP، ZIP، lint، پشتیبان، rollback، قفل)
│
├── admin/                       ← فقط پنل مدیریت
│   ├── class-admin-menu.php
│   ├── class-admin-notices.php
│   ├── class-admin-tabs.php     ← نوار تب مشترک صفحه‌های افزونه
│   ├── class-git-update-page.php ← کنترلر UI صفحهٔ آپدیت (جدا از موتور)
│   └── views/
│       ├── settings.php
│       ├── template.php
│       ├── git-update.php
│       └── encoder.php
│
├── templates/                   ← مارک‌آپ قابل بازنویسی
│   ├── player.php
│   └── embed.php
│
├── assets/
│   ├── css/player.css
│   ├── css/admin.css            ← استایل پنل (محدود به .falnic-admin)
│   ├── js/player.js
│   ├── js/admin-git.js          ← کلاینت AJAX صفحهٔ آپدیت
│   └── vendor/                  ← کتابخانه‌های شخص ثالث، محلی
│       ├── hls/{hls.min.js, LICENSE}
│       └── plyr/{plyr.min.js, plyr.css, LICENSE.md}
│
├── languages/                   ← فایل‌های ترجمه
├── tests/
│   ├── smoke.php                ← تست دود با wp eval-file
│   ├── run-tests.php            ← تست‌های واحد موتور آپدیت (PHP CLI، بدون وردپرس)
│   ├── lint-all.php             ← بررسی syntax همهٔ فایل‌ها با TOKEN_PARSE
│   └── static-checks.js         ← بررسی‌های امنیتی ایستا (Node، بدون وابستگی)
└── docs/                        ← مستندات (همین پوشه)
```

## ۲. قاعده‌ی autoloader

نگاشت نام کلاس به فایل کاملاً مکانیکی است:

```text
Falnic\SecureHLS\Meta_Box   →   class-meta-box.php
Falnic\SecureHLS\Key_Server →   class-key-server.php
```

الگوریتم: حذف پیشوند namespace → `_` به `-` → حروف کوچک → `class-` در ابتدا و `.php` در انتها. جست‌وجو به ترتیب در `includes/` و `admin/`.

**پیامد:** نام فایل و نام کلاس باید همیشه هم‌خوان باشند. اگر `class-foo-bar.php` بسازید ولی کلاس را `FooBar` بنامید، autoloader پیدایش نمی‌کند. برای بررسی سریع:

```bash
node -e "…"   # یا به‌سادگی: نام فایل را با نام کلاس مقایسه کنید
```

## ۳. قاعده‌ی تفکیک مسئولیت

| پوشه | چه چیزی مجاز است | چه چیزی مجاز نیست |
|---|---|---|
| `falnic-secure-hls.php` | ثابت‌ها، require، hook فعال‌سازی | هیچ منطق تجاری |
| `includes/` | منطق، دسترسی فایل، رندر | مارک‌آپ طولانی HTML |
| `admin/` | فرم، جدول، نوتیس | کوئری سنگین یا ffmpeg |
| `templates/` | HTML و حلقه‌ی نمایش | محاسبه‌ی مسیر یا کلید |
| `assets/vendor/` | فایل اصلی کتابخانه + LICENSE | ویرایش دستی فایل min |

قاعده‌ی طلایی: **هیچ فایلی خارج از `Storage` به مسیر ویدیو/کلید دست نمی‌زند.** اگر جایی `wp_upload_dir()` یا `dirname( ABSPATH )` دیدید، بوی باگ می‌دهد.

## ۴. سیاست «بدون وابستگی بیرونی»

هیچ درخواستی به CDN، فونت آنلاین، analytics یا دامنه‌ی دیگر زده نمی‌شود:

| منبع | نسخه | محل |
|---|---|---|
| hls.js | 1.5.17 | `assets/vendor/hls/hls.min.js` |
| Plyr (JS + CSS) | 3.7.8 | `assets/vendor/plyr/` |

نکات اجرایی:

- نسخه‌ها در `Assets::HLS_VERSION` و `Assets::PLYR_VERSION` ثابت شده‌اند و به‌عنوان `?ver=` به URL اضافه می‌شوند.
- `sourceMappingURL` از فایل‌های min حذف شده تا مرورگر دنبال `.map` نرود (خطای ۴۰۴ و لو رفتن ساختار).
- `plyr.css` هیچ `url()` بیرونی ندارد و فونت سیستمی استفاده می‌کند.
- مسیر فایل‌ها در `Assets::register()` با `wp_register_*` و در صفحه‌ی embed با `Assets::print_tags()` چاپ می‌شود.

### به‌روزرسانی یک کتابخانه

```bash
npm pack hls.js@1.6.0
tar xzf hls.js-1.6.0.tgz
cp package/dist/hls.min.js assets/vendor/hls/hls.min.js
cp package/LICENSE assets/vendor/hls/LICENSE
sed -i 's|//# sourceMappingURL=.*$||' assets/vendor/hls/hls.min.js
# سپس HLS_VERSION را در includes/class-assets.php به‌روز کنید
```

## ۵. الگوی افزودن یک کلاس جدید

فرض کنید می‌خواهید `Access` را اضافه کنید (بررسی دسترسی LearnDash):

1. فایل `includes/class-access.php` بسازید.
2. `namespace Falnic\SecureHLS;` و guard `ABSPATH` را بگذارید.
3. کلاس `final class Access` با متدهای `static`.
4. در `Plugin::boot()` یک خط `Access::init();` اضافه کنید (اگر هوک دارد).
5. در `Player::render`، `Key_Server::deliver` و در آینده‌ی manifest، همان تابع را صدا بزنید.

```php
<?php
namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Access {
	public static function init(): void {}

	public static function can_watch( int $user_id, string $name ): bool {
		return (bool) apply_filters( 'falnic_can_watch', true, $user_id, $name );
	}
}
```

## ۶. چک‌لیست پیش از کامیت

- [ ] نام فایل با نام کلاس هم‌خوان است (قاعده‌ی autoloader).
- [ ] هر فایل PHP دارای guard `ABSPATH` است.
- [ ] خروجی با `esc_html`/`esc_attr`/`esc_url`/`wp_json_encode` چاپ می‌شود.
- [ ] ورودی با `sanitize_*` + `wp_unslash` پردازش می‌شود.
- [ ] فرم‌ها nonce و capability دارند.
- [ ] مسیر فایل از `Storage` می‌آید، نه ساخت دستی.
- [ ] رشته‌ها با `__()` و دامنه‌ی `falnic-secure-hls` هستند.
- [ ] هیچ `eval`، `debugger` یا URL خارجی اضافه نشده است.

## ۷. بررسی سلامت پس از استقرار

```bash
# سینتکس همه‌ی فایل‌ها
find . -name '*.php' -not -path './assets/*' -exec php -l {} \;

# دارایی‌های محلی موجود و بدون ارجاع بیرونی
grep -rn "cdn\.\|jsdelivr\|unpkg\|googleapis" --include='*.php' --include='*.js' --include='*.css' . \
  | grep -v 'assets/vendor/'   # باید خالی باشد

# تست دود روی سرور
wp eval-file tests/smoke.php
```
