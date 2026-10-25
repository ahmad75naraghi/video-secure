#!/usr/bin/env bash
#
# اجرای کامل دروازه‌ی کیفیت، دقیقاً همان چیزی که CI اجرا می‌کند.
#
# استفاده:
#   bash bin/ci.sh                # بررسی کامل (بدون ساخت ZIP)
#   bash bin/ci.sh --with-release # + ساخت بسته‌ی نصب
#   bash bin/ci.sh --verbose      # نمایش کامل خروجی هر مرحله
#
# اگر PHP با نام دیگری در دسترس است (یا داخل کانتینر/php-wasm است):
#   FALNIC_PHP_CMD="node /path/to/run-virtual.mjs $PWD" bash bin/ci.sh
#
# کد خروج ۰ یعنی همه‌ی بررسی‌ها سبز است؛ هر خطا کد ۱ برمی‌گرداند.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WITH_RELEASE=0
TAIL_LINES=3

for arg in "$@"; do
	case "$arg" in
		--with-release) WITH_RELEASE=1 ;;
		--verbose) TAIL_LINES=100000 ;;
	esac
done

cd "$ROOT" || exit 1

PHP_CMD="${FALNIC_PHP_CMD:-php}"
# فرمان PHP ممکن است چندبخشی باشد (مثلاً node + wrapper).
read -r -a PHP_ARR <<< "$PHP_CMD"

FAILED=0
PASSED=0

say() { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok() { PASSED=$((PASSED + 1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
no() { FAILED=$((FAILED + 1)); printf '  \033[31m✗\033[0m %s\n' "$1"; }

# اجرای یک مرحله؛ در خطا، انتهای خروجی برای بررسی چاپ می‌شود.
step() {
	local name="$1"
	shift

	local out rc
	out="$("$@" 2>&1)"
	rc=$?

	if [ "$rc" -eq 0 ]; then
		printf '%s\n' "$out" | tail -n "$TAIL_LINES" | sed 's/^/    /'
		ok "$name"
		return 0
	fi

	printf '%s\n' "$out" | tail -n 40 | sed 's/^/    /'
	no "$name"
	return 1
}

say "محیط"
printf '  root: %s\n  php:  %s\n  node: %s\n' "$ROOT" "$PHP_CMD" "$(node --version 2>/dev/null || echo 'ناموجود')"

if ! command -v "${PHP_ARR[0]}" >/dev/null 2>&1; then
	printf '\n\033[31mPHP پیدا نشد.\033[0m اگر PHP با نام دیگری نصب است، FALNIC_PHP_CMD را تنظیم کنید.\n'
	exit 1
fi

say "۱) بررسی syntax همه‌ی فایل‌های PHP"
step "lint-all.php" "${PHP_ARR[@]}" tests/lint-all.php

say "۲) تست‌های دسترسی، توکن و دروازه‌ی استریم"
step "access-tests (با LearnDash)" "${PHP_ARR[@]}" tests/access-tests.php
step "access-tests (بدون LearnDash)" env FALNIC_TEST_LD=off "${PHP_ARR[@]}" tests/access-tests.php

say "۳) تست‌های موتور آپدیت از گیت"
step "run-tests.php" "${PHP_ARR[@]}" tests/run-tests.php

say "۴) تست‌های رندر نماهای مدیریت و متاباکس"
step "views-tests (با LearnDash)" "${PHP_ARR[@]}" tests/views-tests.php
step "views-tests (بدون LearnDash)" env FALNIC_TEST_LD=off "${PHP_ARR[@]}" tests/views-tests.php

say "۵) تست‌های صف انکود، رجیستری پخش، گزارش تماشا و زیرنویس"
step "queue-tests.php" "${PHP_ARR[@]}" tests/queue-tests.php

if command -v node >/dev/null 2>&1; then
	say "۶) بررسی‌های ایستا و جاوااسکریپت"
	step "static-checks.js" node tests/static-checks.js

	for file in assets/js/*.js; do
		step "node --check $file" node --check "$file"
	done

	say "۷) همگام بودن قالب ترجمه"
	step "make-pot --check" node bin/make-pot.js --check
else
	say "۶–۷) Node در دسترس نیست؛ بررسی‌های ایستا و POT رد شد"
	no "node یافت نشد"
fi

if [ "$WITH_RELEASE" -eq 1 ]; then
	say "۸) ساخت بسته‌ی نصب"
	step "build-release.sh" bash bin/build-release.sh
	rm -rf "$ROOT/dist"
else
	say "۸) ساخت بسته‌ی نصب (با --with-release فعال می‌شود)"
fi

say "نتیجه"
printf '  موفق: %d   ناموفق: %d\n\n' "$PASSED" "$FAILED"

[ "$FAILED" -eq 0 ] || exit 1

printf '\033[32mهمه‌ی بررسی‌ها سبز است.\033[0m\n'
