#!/usr/bin/env bash
#
# ساخت بسته‌ی نصب‌شدنی افزونه برای انتشار.
#
# خروجی: dist/falnic-secure-hls.zip  (با پوشه‌ی ریشه‌ی falnic-secure-hls/)
#
# فقط فایل‌های لازم برای اجرا کپی می‌شوند؛ فایل‌های توسعه، تست و گیت
# داخل بسته نمی‌روند تا حجم نصب کم و سطح حمله کوچک بماند.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/dist"
STAGE="$DIST/falnic-secure-hls"
NAME="falnic-secure-hls"

rm -rf "$DIST"
mkdir -p "$STAGE"

# فایل‌های اجرایی.
cp "$ROOT/falnic-secure-hls.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$STAGE/"
cp "$ROOT/README.md" "$ROOT/CHANGELOG.md" "$STAGE/"

for dir in includes admin templates assets languages docs; do
	if [ -d "$ROOT/$dir" ]; then
		cp -R "$ROOT/$dir" "$STAGE/"
	fi
done

# حذف چیزهایی که در بسته‌ی اجرایی لازم نیستند (بر اساس .distignore).
if [ -f "$ROOT/.distignore" ]; then
	while IFS= read -r pattern; do
		# خط‌های خالی و توضیح‌ها.
		case "$pattern" in ''|'#'*) continue ;; esac

		pattern="${pattern%/}"
		rm -rf "$STAGE/$pattern" 2>/dev/null || true
	done < "$ROOT/.distignore"
fi

find "$STAGE" -name '.DS_Store' -delete
find "$STAGE" -name '*.map' -delete
rm -rf "$STAGE/assets/vendor/plyr/demo" 2>/dev/null || true

# مجوز کتابخانه‌های شخص ثالث باید بماند؛ بررسی سریع.
for license in assets/vendor/hls/LICENSE assets/vendor/plyr/LICENSE.md; do
	if [ ! -f "$STAGE/$license" ]; then
		echo "هشدار: فایل مجوز $license در بسته نیست." >&2
	fi
done

# بررسی اینکه فایل اصلی افزونه سالم است.
if ! grep -q "Plugin Name:" "$STAGE/falnic-secure-hls.php"; then
	echo "خطا: هدر افزونه پیدا نشد." >&2
	exit 1
fi

# آرشیو باید پوشه‌ی ریشه داشته باشد تا وردپرس آن را درست نصب کند.
cd "$DIST"
rm -f "$NAME.zip"
zip -q -r -9 "$NAME.zip" "$NAME"

listing=$( unzip -l "$NAME.zip" ) || listing=""

if [[ "$listing" != *"$NAME/falnic-secure-hls.php"* ]]; then
	echo "خطا: آرشیو پوشه‌ی ریشه‌ی $NAME ندارد." >&2
	exit 1
fi

cd "$ROOT"
SIZE=$(wc -c < "$DIST/$NAME.zip" | tr -d ' ')
echo "ساخته شد: dist/$NAME.zip ($SIZE بایت)"
