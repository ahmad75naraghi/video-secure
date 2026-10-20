# ۱۴ — تست

## ۱. استراتژی

| لایه | ابزار | چه چیزی |
|---|---|---|
| واحد (این ریپو) | PHP CLI + stubهای سبک | `Token`, `Access`, `Storage`, بازنویسی manifest — `php tests/access-tests.php` (و `--no-ld` برای مسیر بدون LearnDash) |
| واحد | PHPUnit + WP_Mock | همان موارد در CI با وردپرس واقعی |
| یکپارچه | WP-CLI + دیتابیس تست | صف انکود، متاها، حذف |
| امنیتی | `curl` / `httpie` | کنترل دسترسی، IDOR، rate limit |
| مرورگر | Playwright | واترمارک، فول‌اسکرین، خطاها |
| بار | `k6` یا `ab` | ۲۰۰ پخش هم‌زمان |
| دستی | چک‌لیست زیر | موبایل واقعی، Safari |

---

## ۲. محیط تست

```bash
# ساخت محیط
docker compose up -d   # wordpress + mysql + nginx + php
wp plugin activate falnic-secure-hls --allow-root
wp user create student1 s1@example.com --role=subscriber
wp user create student2 s2@example.com --role=subscriber

# دوره و درس آزمایشی
wp post create --post_type=sfwd-courses --post_title='دوره تست' --post_status=publish
wp post create --post_type=sfwd-lessons --post_title='درس ۱' --post_status=publish \
  --meta_input='{"course_id":<course_id>}'
```

داده‌ی تست باید شامل: ویدیوی ۳۰ ثانیه‌ای، ۱۰ دقیقه‌ای، و یک ویدیوی بدون صدا باشد.

---

## ۲.۵. تست‌های داخل مخزن

```bash
php tests/lint-all.php          # syntax ۳۹ فایل با TOKEN_PARSE
php tests/access-tests.php      # ۶۰ assertion: توکن، دسترسی، مسیر، manifest، مهاجرت، شناسه‌ی پایدار
php tests/run-tests.php         # ۸۱ assertion: موتور آپدیت از گیت، پشتیبان و بازگردانی
php tests/queue-tests.php       # ۱۰۶ assertion: صف انکود، کیفیت ABR، رجیستری پخش، گزارش تماشا، زیرنویس
php tests/access-tests.php --no-ld             # همان مسیر بدون LearnDash (۶۰ assertion)
node tests/static-checks.js     # ۸۱ بررسی امنیتی/ساختاری ایستا (بدون وابستگی)
node bin/make-pot.js --check    # همگام بودن قالب ترجمه (۳۷۴ رشته)
bash bin/build-release.sh       # ساخت ZIP نصب
```

### یک فرمان به‌جای همه‌ی بالا

```bash
bash bin/ci.sh                 # همه‌ی بررسی‌های CI، بدون ساخت ZIP
bash bin/ci.sh --with-release  # + ساخت بسته‌ی نصب
bash bin/ci.sh --verbose       # خروجی کامل هر مرحله
```

`bin/ci.sh` دقیقاً همان مراحلی را اجرا می‌کند که `.github/workflows/ci.yml` اجرا می‌کند و با اولین
خطا کد خروج ۱ برمی‌گرداند؛ برای اجرای پیش از push یا روی سرور بدون GitHub Actions ساخته شده است.
اگر PHP با فرمان دیگری اجرا می‌شود (کانتینر، php-wasm، نسخه‌ی خاص):

```bash
FALNIC_PHP_CMD="php8.3" bash bin/ci.sh
```

CI مخزن (`.github/workflows/ci.yml`) همین مراحل را روی PHPهای ۷.۴/۸.۱/۸.۳/۸.۵ اجرا می‌کند، به‌همراه
بررسی syntax همه‌ی فایل‌های JS و ساخت بسته. برای اطمینان از اینکه CI واقعاً خطا را می‌گیرد:
`hash_equals` را در `includes/class-token.php` با `strcmp` عوض کنید — `tests/static-checks.js` باید قرمز شود
(بررسی «امضای توکن با HMAC-SHA256 و مقایسه‌ی زمان‌ثابت»).

این تست‌ها بدون نصب وردپرس اجرا می‌شوند (توابع لازم شبیه‌سازی شده‌اند) و روی PHP 7.4 تا 8.5 آزمایش شده‌اند.

## ۳. تست‌های امنیتی قابل اجرا

اندپوینت‌ها همان‌هایی هستند که در [۰۶ — API](06-API.md) مستند شده‌اند (`admin-ajax.php`، اکشن‌های `falnic_stream_*`).

```bash
BASE="https://staging.example.com"
AJAX="$BASE/wp-admin/admin-ajax.php"
STUDENT_COOKIE="cookies-student.txt"   # دانشجوی ثبت‌نام‌کرده
FREE_COOKIE="cookies-free.txt"         # کاربر لاگین بدون ثبت‌نام
VIDEO="lesson-108"

pass() { echo "✅ $1"; }
fail() { echo "❌ $1 (got: $2)"; }

# S-01 مسیر عمومی قدیمی باید بسته باشد
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/wp-content/uploads/secure_videos/$VIDEO/$VIDEO.m3u8")
[ "$code" = "403" ] || [ "$code" = "404" ] && pass S-01 || fail S-01 "$code"

# S-02 ریشه‌ی جدید بیرون از web root است (نباید اصلاً سرو شود)
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/falnic-secure/videos/$VIDEO/$VIDEO.m3u8")
[ "$code" = "403" ] || [ "$code" = "404" ] && pass S-02 || fail S-02 "$code"

# S-03 manifest بدون توکن و بدون نشست → ۴۰۱ (هیچ مسیری بدون توکن باز نیست)
code=$(curl -s -o /dev/null -w '%{http_code}' "$AJAX?action=falnic_stream_manifest&video=$VIDEO")
[ "$code" = "401" ] && pass S-03 || fail S-03 "$code"

# S-04 کاربر لاگین بدون ثبت‌نام → ۴۰۳ روی نشست
code=$(curl -s -b "$FREE_COOKIE" -o /dev/null -w '%{http_code}' \
  -X POST -d "video=$VIDEO" "$AJAX?action=falnic_stream_session")
[ "$code" = "403" ] && pass S-04 || fail S-04 "$code"

# S-05 مهمان → ۴۰۱ روی نشست
code=$(curl -s -o /dev/null -w '%{http_code}' \
  -X POST -d "video=$VIDEO" "$AJAX?action=falnic_stream_session")
[ "$code" = "401" ] && pass S-05 || fail S-05 "$code"

# توکن معتبر را از تب Network همان دانشجو بردارید (پارامتر t در URL سگمنت).
TOKEN="${TOKEN:?توکن سگمنت را از نشست دانشجو بگیرید}"
OTHER="${OTHER_TOKEN:?توکن سگمنت دانشجوی دیگر}"

# S-06 توکن دانشجو روی حساب دیگر → ۴۰۳ (uid گره‌خورده)
code=$(curl -s -b "$FREE_COOKIE" -o /dev/null -w '%{http_code}' \
  "$AJAX?action=falnic_stream_segment&video=$VIDEO&f=00001.ts&t=$TOKEN")
[ "$code" = "403" ] && pass S-06 || fail S-06 "$code"

# S-07 توکن سگمنت روی ویدیوی دیگر → ۴۰۳ (vid گره‌خورده)
code=$(curl -s -b "$STUDENT_COOKIE" -o /dev/null -w '%{http_code}' \
  "$AJAX?action=falnic_stream_segment&video=another-video&f=00001.ts&t=$TOKEN")
[ "$code" = "403" ] && pass S-07 || fail S-07 "$code"

# S-08 path traversal در نام فایل → ۴۰۴
code=$(curl -s -b "$STUDENT_COOKIE" -o /dev/null -w '%{http_code}' \
  "$AJAX?action=falnic_stream_segment&video=$VIDEO&f=..%2F..%2Fwp-config.php&t=$TOKEN")
[ "$code" = "404" ] && pass S-08 || fail S-08 "$code"

# S-09 کلید بدون توکن → ۴۰۳
code=$(curl -s -b "$STUDENT_COOKIE" -o /dev/null -w '%{http_code}' \
  "$AJAX?action=falnic_stream_key&video=$VIDEO")
[ "$code" = "403" ] && pass S-09 || fail S-09 "$code"

# S-10 rate limit کلید (۳۰ در دقیقه) → باید ۴۲۹ بدهد
for i in $(seq 1 40); do
  curl -s -o /dev/null -w '%{http_code}\n' -b "$STUDENT_COOKIE" \
    "$AJAX?action=falnic_stream_key&video=$VIDEO&t=$TOKEN"
done | sort | uniq -c

# S-11 CSRF روی تنظیمات (بدون nonce) → ۴۰۳
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST \
  "$BASE/wp-admin/admin-post.php" -d 'action=falnic_save_settings&max_sessions=99')
[ "$code" = "403" ] || [ "$code" = "400" ] && pass S-11 || fail S-11 "$code"

# S-12 بدون eval در پلیر
curl -s "$BASE/lesson/test/" | grep -E 'eval\(|debugger' && fail S-12 "found" || pass S-12

# S-13 yt-dlp
yt-dlp "$BASE/lesson/test/" 2>&1 | grep -qi 'no video formats' && pass S-13 || fail S-13 "downloaded"

# S-14 لینک embed برای مهمان باز نمی‌شود
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?falnic_embed=$VIDEO")
[ "$code" = "401" ] && pass S-14 || fail S-14 "$code"
```

> نکته: کد ۴۰۳ برای «توکن نامعتبر/منقضی» و «بدون ثبت‌نام» است و ۴۰۱ فقط برای مهمان. تفکیک پیام‌ها (`falnic_token_*`) در `Token::verify` دیده می‌شود.

---

## ۴. تست‌های هویت بصری (واترمارک)

| # | مرحله | انتظار |
|---|---|---|
| WM-1 | پخش ۱۰ ثانیه | واترمارک در ۳ نقطه دیده شود |
| WM-2 | pause → seek → play | واترمارک بلافاصله بازگردد |
| WM-3 | تغییر کیفیت ۳۶۰→۷۲۰ | بدون ناپدید شدن |
| WM-4 | فول‌اسکرین دسکتاپ | دیده شود |
| WM-5 | iPhone Safari | `playsinline`؛ فول‌اسکرین کانتینر |
| WM-6 | حذف canvas در DevTools | پخش قطع + لاگ |
| WM-7 | نام `</script><script>alert(1)</script>` | بدون اجرای JS |
| WM-8 | ایمیل | `al***@example.com` |
| WM-9 | ۳۰ دقیقه پخش | CPU < ۱۵٪ |
| WM-10 | ضبط با OBS | واترمارک در فایل ضبط‌شده |

---

## ۵. تست‌های LearnDash

| # | سناریو | انتظار |
|---|---|---|
| LD-1 | دانشجوی ثبت‌نام‌کرده | پخش |
| LD-2 | کاربر لاگین بدون دوره | `403` + پیام |
| LD-3 | مهمان | هدایت به ورود |
| LD-4 | Drip فردا | پیام زمان |
| LD-5 | درس نمونه | پخش برای همه (مطابق LD) |
| LD-6 | بازپرداخت و لغو دسترسی | `403` در درخواست بعدی |
| LD-7 | لغو عضویت گروه | `403` |
| LD-8 | یک ویدیو در دو درس | هر دو پخش |
| LD-9 | بدون اتصال | پیام «تنظیم نشده» |
| LD-10 | LearnDash غیرفعال | بدون Fatal error |
| LD-11 | Focus Mode | چیدمان سالم |
| LD-12 | کش صفحه | واترمارک هر کاربر درست |

---

## ۶. تست مرورگر (Playwright)

```js
import { test, expect } from '@playwright/test';

test('watermark survives seek', async ({ page }) => {
  await page.goto('/lesson/test/');
  await page.click('.falnic-play');
  await page.waitForTimeout(2000);
  await page.evaluate(() => document.querySelector('video').currentTime = 30);
  await page.waitForTimeout(1000);
  const pixels = await page.evaluate(() => {
    const c = document.querySelector('canvas.falnic-frame');
    return c.getContext('2d').getImageData(0, 0, c.width, c.height).data
      .reduce((acc, v) => acc + (v > 200 ? 1 : 0), 0);
  });
  expect(pixels).toBeGreaterThan(1000); // پیکسل‌های روشن واترمارک
});

test('no eval in bundle', async ({ page }) => {
  const scripts = [];
  page.on('response', r => r.url().endsWith('.js') && scripts.push(r.url()));
  await page.goto('/lesson/test/');
  expect(scripts.some(s => s.includes('cdn'))).toBeFalsy();
});
```

---

## ۷. تست بار

```bash
# ۲۰۰ کاربر هم‌زمان، هر کدام ۲۰ سگمنت
k6 run --vus 200 --duration 2m load/segments.js
```

معیار پذیرش:
- `p95` زمان پاسخ سگمنت < ۳۰۰ms
- نرخ خطا < ۰.۱٪
- بدون `502/504`
- مصرف CPU سرور < ۸۰٪

---

## ۸. تست واحد نمونه

```php
final class TokenTest extends WP_UnitTestCase {

    public function test_valid_token_roundtrip(): void {
        $t = Token::issue( [ 'vid' => 'v_9f2c1a7b3d4e5f60', 'uid' => 7,
            'sid' => 'S-AAAAAA', 'sc' => 'segment' ], 600 );
        wp_set_current_user( 7 );
        $claims = Token::verify( $t, [ 'vid' => 'v_9f2c1a7b3d4e5f60', 'sc' => 'segment' ] );
        $this->assertIsArray( $claims );
        $this->assertSame( 'S-AAAAAA', $claims['sid'] );
    }

    public function test_wrong_video_is_rejected(): void {
        $t = Token::issue( [ 'vid' => 'v_aaaaaaaaaaaaaaaa', 'uid' => 7,
            'sid' => 'S-AAAAAA', 'sc' => 'segment' ], 600 );
        wp_set_current_user( 7 );
        $this->assertWPError(
            Token::verify( $t, [ 'vid' => 'v_9f2c1a7b3d4e5f60', 'sc' => 'segment' ] )
        );
    }

    public function test_tampering_breaks_signature(): void {
        $t = Token::issue( [ 'vid' => 'v_9f2c1a7b3d4e5f60', 'uid' => 7,
            'sid' => 'S-AAAAAA', 'sc' => 'segment' ], 600 );
        $t[10] = $t[10] === 'A' ? 'B' : 'A';
        $this->assertWPError( Token::verify( $t, [] ) );
    }

    public function test_expired(): void {
        $t = Token::issue( [ 'vid' => 'v_9f2c1a7b3d4e5f60', 'uid' => 7,
            'sid' => 'S-AAAAAA', 'sc' => 'key' ], -10 );
        wp_set_current_user( 7 );
        $err = Token::verify( $t, [] );
        $this->assertSame( 'falnic_token_expired', $err->get_error_code() );
    }
}
```

---

## ۹. درگاه انتشار (Release Gate)

هیچ نسخه‌ای منتشر نمی‌شود مگر:

- [ ] `php -l` روی همه‌ی فایل‌ها
- [ ] WPCS بدون خطای جدید
- [ ] همه‌ی تست‌های S-01 تا S-11 پاس
- [ ] همه‌ی تست‌های WM و LD (برای فاز مربوطه)
- [ ] تست دستی روی iPhone و Android واقعی
- [ ] بررسی نبود `eval`، `debugger`، `@` اضافی
- [ ] `CHANGELOG.md` به‌روز
- [ ] بکاپ قبل از ارتقا گرفته شده
- [ ] مسیر rollback تست شده
