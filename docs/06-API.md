# ۰۶ — رابط برنامه‌نویسی (API)

## بخش الف: API فعلی (نسخه ۳.۳)

### ۰. اندپوینت‌های دروازه (پیاده‌شده در ۳.۳)

| اندپوینت | متد | احراز هویت | خروجی |
|---|---|---|---|
| `admin-ajax.php?action=falnic_stream_session` | POST `video` | کوکی وردپرس + `Access::reason` | JSON `{src, expires, watermark, name}` |
| `admin-ajax.php?action=falnic_stream_manifest&video=&t=` | GET | توکن `sc=manifest` + دسترسی | `application/vnd.apple.mpegurl` |
| `admin-ajax.php?action=falnic_stream_segment&video=&f=&t=` | GET | توکن `sc=segment` + دسترسی | بایت سگمنت (Range/206) |
| `admin-ajax.php?action=falnic_stream_key&video=&t=` | GET | توکن `sc=key` + دسترسی | ۱۶ بایت کلید AES |
| `admin-ajax.php?action=secure_hls_key&video=` | GET | سازگاری: کوکی + دسترسی | ۱۶ بایت کلید AES |

توکن: `f1.<b64url(payload)>.<b64url(hmac_sha256)>` با ادعاهای `sc`، `vid`، `uid`، `iat`، `exp` و اختیاری `ip`.
خطاها متن ساده با کد ۴۰۱ (مهمان)، ۴۰۳ (بدون ثبت‌نام/دسترسی)، ۴۰۴ (فایل) و ۴۲۹ (نرخ) هستند.

---

## بخش الف-۲: وضعیت ۳.۲ (تاریخی)

### ۱. دریافت کلید AES

```
GET /wp-admin/admin-ajax.php?action=secure_hls_key&video={name}
```

| پارامتر | نوع | اجباری | توضیح |
|---|---|---|---|
| `action` | string | ✔ | مقدار ثابت `secure_hls_key` |
| `video` | string | ✔ | نام ویدیو (در واقع عنوان پست) |

**احراز هویت:** فقط کوکی لاگین وردپرس، **بدون هیچ بررسی دسترسی**.
**پاسخ موفق:** `200`، `Content-Type: application/octet-stream`، ۱۶ بایت خام.
**پاسخ‌های خطا:** `401` مهمان، `400` پارامتر خالی، `404` نبود کلید — همه به‌صورت HTML خروجی `wp_die`.

**مشکلات:** IDOR ([F-02](02-SECURITY-AUDIT.md#f-02))، شمارش‌پذیری، بدون لاگ، بدون rate limit، بدون CORS/CSP مطلوب، خطاهای HTML به‌جای JSON.

```bash
curl -b cookies.txt \
  "https://example.com/wp-admin/admin-ajax.php?action=secure_hls_key&video=lesson-108" \
  -o key.bin && xxd key.bin
```

### ۲. صفحه‌ی embed

```
GET /?falnic_embed={name}
```

پاسخ: یک سند HTML کامل با پلیر. **احراز هویت:** فقط `is_user_logged_in()` — بدون بررسی دوره ([F-04](02-SECURITY-AUDIT.md#f-04)).

### ۳. شورت‌کد

```
[secure_video name="lesson-108"]
```

فقط پارامتر `name`. بدون `id`، بدون گزینه‌ی واترمارک، بدون کنترل قالب.

### ۴. مشاهده‌ی فایل‌های استریم (نه API، اما مهم‌ترین سطح حمله)

```
GET /wp-content/uploads/secure_videos/{name}/{name}.m3u8
GET /wp-content/uploads/secure_videos/{name}/{nnn}.ts
```
بدون احراز هویت. **این باید حذف شود.**

---

## بخش ب: API هدف (نسخه ۴ — طراحی، پیاده‌سازی نشده)

> در ۳.۳ همان قابلیت‌ها با `admin-ajax.php` پیاده شدند (بخش الف). تفاوت‌ها: بدون `X-WP-Nonce` (توکن کوتاه‌عمر جای آن را می‌گیرد)، بدون heartbeat، و مسیر فایل در URL نیست.

## بخش ب-۲: جزئیات طراحی REST (مرجع آینده)

### ۱. اصول

- namespace: `falnic/v1` روی REST API وردپرس.
- همه‌ی پاسخ‌ها JSON، به‌جز سگمنت و کلید که باینری‌اند.
- احراز هویت: کوکی وردپرس + هدر `X-WP-Nonce` برای اندپوینت‌های کنترلی؛ توکن امضاشده برای اندپوینت‌های رسانه.
- هیچ اندپوینتی بدون `permission_callback` صریح ثبت نمی‌شود.
- خطاها با کد ثابت و پیام ترجمه‌پذیر.

### ۲. قالب توکن

```
v1.{base64url(payload)}.{base64url(hmac_sha256(secret, "v1." + payload))}
```

```json
{
  "vid": "v_9f2c1a7b3d4e5f60",
  "uid": 42,
  "sid": "S-7F3A2C",
  "sc":  "segment",
  "exp": 1760951400,
  "iat": 1760950800,
  "ip":  "a1b2c3d4",
  "jti": "9f1e..."
}
```

| claim | توضیح |
|---|---|
| `vid` | video_key؛ توکن به یک ویدیو محدود است |
| `uid` | کاربر صادرکننده؛ در هر درخواست با کاربر جاری مقایسه می‌شود |
| `sid` | شناسه‌ی نشست؛ برای قطع دسترسی و ردیابی |
| `sc` | scope: `manifest` / `segment` / `key` |
| `exp` | انقضا (پیش‌فرض ۶۰۰ ثانیه) |
| `ip` | هش IP (فقط اگر `bind_ip` فعال باشد) |
| `jti` | شناسه‌ی یکتا برای جلوگیری از تکرار (اختیاری) |

امضا با `hash_hmac( 'sha256', $data, FALNIC_TOKEN_SECRET, true )`. مقایسه با `hash_equals`.

### ۳. جدول اندپوینت‌ها

| متد | مسیر | احراز | توضیح |
|---|---|---|---|
| `POST` | `/falnic/v1/session` | کوکی + nonce | شروع نشست، صدور توکن و پروفایل واترمارک |
| `POST` | `/falnic/v1/token` | کوکی + nonce + sid | تمدید توکن قبل از انقضا |
| `POST` | `/falnic/v1/heartbeat` | توکن | زنده نگه داشتن نشست + ذخیره‌ی موقعیت |
| `POST` | `/falnic/v1/session/close` | توکن | پایان نشست |
| `GET` | `/falnic/v1/manifest/{vid}` | توکن (`manifest`) | m3u8 بازنویسی‌شده |
| `GET` | `/falnic/v1/segment/{vid}/{rendition}/{n}` | توکن (`segment`) | بایت‌های `.ts` با Range |
| `GET` | `/falnic/v1/key/{vid}/{kid}` | توکن (`key`) | ۱۶ بایت کلید |

### ۴. `POST /falnic/v1/session`

**درخواست**
```json
{ "video_key": "v_9f2c1a7b3d4e5f60", "post_id": 445 }
```

**پاسخ موفق `200`**
```json
{
  "token": "v1.eyJ2aWQiOiJ2XzlmMm...",
  "expires_in": 600,
  "session": "S-7F3A2C",
  "watermark": {
    "line1": "علی رضایی (ali)",
    "line2": "al***@example.com",
    "session": "S-7F3A2C",
    "opacity": 0.35,
    "interval": 30,
    "mode": "canvas"
  },
  "manifest_url": "/falnic/v1/manifest/v_9f2c1a7b3d4e5f60",
  "player": { "autoplay": false, "pip": false, "download": false }
}
```

**خطاها**

| کد HTTP | `code` | معنی |
|---|---|---|
| `401` | `falnic_auth_required` | کاربر وارد نشده |
| `403` | `falnic_not_enrolled` | ثبت‌نام دوره ندارد |
| `403` | `falnic_lesson_locked` | درس هنوز باز نشده (Drip) |
| `403` | `falnic_session_limit` | بیش از حد پخش هم‌زمان |
| `404` | `falnic_video_missing` | ویدیو وجود ندارد |
| `409` | `falnic_not_ready` | هنوز انکود نشده |
| `429` | `falnic_rate_limited` | درخواست زیادی (هدر `Retry-After`) |

### ۵. `GET /falnic/v1/manifest/{vid}`

- ورودی: `t` (توکن) در query یا هدر `X-Falnic-Token`. توکن باید با `rawurlencode` کدگذاری شود (شامل `.` و کاراکترهای base64url).
- خروجی: `application/vnd.apple.mpegurl`، بدون کش (`no-store`).
- **بازنویسی:** هر `#EXT-X-KEY:URI="k/1"` به `/falnic/v1/key/{vid}/1?t=...` و هر `#EXT-X-MEDIA-SEQUENCE`/URI سگمنت به مسیر امضاشده.
- توکن جدید داخل manifest برای سگمنت‌ها صادر می‌شود (با همان `sid`) تا URLها مستقل و کوتاه‌عمر باشند.

```http
HTTP/1.1 200 OK
Content-Type: application/vnd.apple.mpegurl
Cache-Control: no-store, private
X-Falnic-Session: S-7F3A2C
```

```m3u8
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:10
#EXT-X-KEY:METHOD=AES-128,URI="/falnic/v1/key/v_9f2c1a7b3d4e5f60/1?t=v1.eyJ2aWQ..."
#EXTINF:10.00000,
/falnic/v1/segment/v_9f2c1a7b3d4e5f60/720p/00001?t=v1.eyJ2aWQ...
```

### ۶. `GET /falnic/v1/segment/{vid}/{rendition}/{n}`

| نکته | مقدار |
|---|---|
| اعتبارسنجی | `rendition ∈ _falnic_renditions`، `n` مطابق `^\d{5}$` |
| هدرها | `Content-Type: video/mp2t`، `Accept-Ranges: bytes`، `Cache-Control: private, max-age=0, no-store` |
| `Range` | پشتیبانی کامل (`206 Partial Content`) برای seek |
| کارایی | در Nginx با `X-Accel-Redirect` به `internal` location؛ در Apache با `readfile` چانک‌دار |
| محدودیت | هر نشست ۶۰ درخواست در دقیقه؛ در صورت تجاوز `429` |
| لاگ | یک رکورد `segment/allow` با تعداد بایت (نه برای هر تکه در حالت عادی؛ نمونه‌برداری ۱۰٪ برای کاهش حجم لاگ) |

```http
HTTP/1.1 206 Partial Content
Content-Type: video/mp2t
Content-Range: bytes 0-1023/2048
Accept-Ranges: bytes
```

### ۷. `GET /falnic/v1/key/{vid}/{kid}`

| بررسی | نتیجه‌ی رد |
|---|---|
| توکن معتبر و `sc=key` | `401` |
| `vid` و `kid` با توکن هم‌خوان | `403` |
| `kid` در محدوده‌ی مجاز این بازه (چرخش کلید) | `403` |
| دسترسی کاربر به ویدیو | `403` |
| rate limit | `429` |

پاسخ: `200 OK`، `Content-Type: application/octet-stream`، `Content-Length: 16`، `Cache-Control: no-store`، `X-Content-Type-Options: nosniff`. **هیچ‌گاه کش نشود.**

> نکته‌ی مهم: HLS به پلیر اجازه می‌دهد کلید را مستقل از سگمنت بگیرد. ما نمی‌توانیم جلوی کاربر مجاز را بگیریم؛ فقط می‌توانیم کلید را به نشست و زمان محدود کنیم و همه‌چیز را لاگ کنیم.

### ۸. خطاهای استاندارد

```json
{
  "code": "falnic_not_enrolled",
  "message": "برای تماشای این ویدیو باید در دوره ثبت‌نام کنید.",
  "data": { "status": 403, "course_id": 71 }
}
```

| `code` | HTTP | پیام پیشنهادی به کاربر |
|---|---|---|
| `falnic_auth_required` | 401 | «برای تماشا وارد شوید.» |
| `falnic_not_enrolled` | 403 | «این ویدیو مخصوص دانشجویان دوره است.» |
| `falnic_lesson_locked` | 403 | «این درس از تاریخ … باز می‌شود.» |
| `falnic_session_limit` | 403 | «این حساب روی دستگاه دیگری در حال پخش است.» |
| `falnic_token_expired` | 401 | «نشست منقضی شد؛ صفحه را تازه کنید.» |
| `falnic_rate_limited` | 429 | «کمی صبر کنید.» |
| `falnic_not_ready` | 409 | «ویدیو در حال آماده‌سازی است.» |
| `falnic_video_missing` | 404 | «ویدیو یافت نشد.» |

### ۹. نمونه‌ی استفاده‌ی کلاینت

```js
const res = await fetch('/wp-json/falnic/v1/session', {
  method: 'POST',
  credentials: 'same-origin',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': FalnicConfig.nonce
  },
  body: JSON.stringify({ post_id: FalnicConfig.postId })
});
if (!res.ok) { showError(await res.json()); return; }
const { token, manifest_url, watermark } = await res.json();

const hls = new Hls({
  xhrSetup: (xhr) => xhr.setRequestHeader('X-Falnic-Token', token)
});
hls.loadSource(manifest_url);
hls.attachMedia(video);
```

### ۱۰. سازگاری عقب‌رو

| قدیمی | جدید | رفتار |
|---|---|---|
| `admin-ajax.php?action=secure_hls_key` | `/falnic/v1/key` | در نسخه ۴ حذف؛ در ۳.۲ با هشدار deprecate و بررسی دسترسی |
| `?falnic_embed=` | همان مسیر، ولی با بررسی دسترسی و CSP | حفظ می‌شود |
| `[secure_video name="x"]` | `[secure_video id="123"]` | `name` تا نسخه ۵ کار می‌کند |
| `uploads/secure_videos/*` | `falnic-secure/videos/*` | در نسخه ۴ مسدود و به ۴۰۳ نقشه می‌شود |
