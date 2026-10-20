#!/usr/bin/env node
/**
 * بررسی‌های امنیتی ایستا روی سورس افزونه.
 *
 * بدون هیچ وابستگی خارجی اجرا می‌شود:
 *   node tests/static-checks.js
 *
 * @package Falnic\SecureHLS
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const read = ( file ) => fs.readFileSync( path.join( ROOT, file ), 'utf8' );

let pass = 0;
let fail = 0;

/**
 * ثبت نتیجه.
 *
 * @param {boolean} ok    نتیجه.
 * @param {string}  label برچسب.
 * @param {string}  note  توضیح.
 */
function check( ok, label, note ) {
	if ( ok ) {
		pass++;
		console.log( 'PASS  ' + label );
		return;
	}
	fail++;
	console.log( 'FAIL  ' + label + ( note ? ' :: ' + note : '' ) );
}

const updater = read( 'includes/class-git-updater.php' );
const page = read( 'admin/class-git-update-page.php' );
const view = read( 'admin/views/git-update.php' );
const adminJs = read( 'assets/js/admin-git.js' );
const playerJs = read( 'assets/js/player.js' );

console.log( '\n=== Falnic Git Updater — static checks ===\n' );

/* ۱) بدون فرمان shell در بخش آپدیت */
const shellFns = /\b(exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(/g;
const shellHits = updater.match( shellFns ) || [];
check( shellHits.length === 0, 'updater هیچ فرمان shell اجرا نمی‌کند', shellHits.join( ', ' ) );

/* ۲) استفاده از ZIP داخلی PHP و پارسر بدون اجرا */
check( /new \\?ZipArchive\(\)/.test( updater ), 'از ZipArchive برای ZIP استفاده می‌شود' );
check( /token_get_all\s*\([^)]*TOKEN_PARSE/s.test( updater ), 'lint با token_get_all + TOKEN_PARSE انجام می‌شود' );

/* ۳) ریشهٔ افزونه rename/move نمی‌شود */
check( ! /\brename\s*\(/.test( updater ), 'در updater هیچ rename( وجود ندارد' );
check( ! /(unlink|wp_delete_file|rmdir)\s*\(\s*self::plugin_dir\(\)/.test( updater ), 'ریشهٔ پوشهٔ افزونه هرگز حذف نمی‌شود' );
check( /'falnic_git_lock'|LOCK_KEY/.test( updater ) && /set_transient/.test( updater ), 'قفل هم‌زمانی با transient پیاده شده است' );

/* ۴) ورودی‌ها اعتبارسنجی می‌شوند */
[ 'is_valid_repo', 'is_valid_branch', 'is_valid_backup_name', 'is_safe_zip_entry' ].forEach( ( fn ) => {
	check( new RegExp( 'function ' + fn + '\\s*\\(' ).test( updater ), 'تابع اعتبارسنجی ' + fn + ' موجود است' );
} );

/* ۵) محافظت AJAX: capability + nonce */
check( /current_user_can\(\s*'update_plugins'\s*\)/.test( page ), 'capability update_plugins بررسی می‌شود' );
check( /check_ajax_referer\(\s*self::NONCE_AJAX/.test( page ), 'nonce در درخواست‌های AJAX بررسی می‌شود' );
check( /check_admin_referer\(\s*self::NONCE_SETTINGS/.test( page ), 'nonce در فرم تنظیمات بررسی می‌شود' );

const guardCalls = ( page.match( /self::guard_ajax\(\);/g ) || [] ).length;
const ajaxHandlers = ( page.match( /public static function ajax_/g ) || [] ).length;
check( ajaxHandlers === 4, 'چهار هندلر AJAX وجود دارد', String( ajaxHandlers ) );
check( guardCalls === ajaxHandlers, 'هر هندلر AJAX از guard_ajax عبور می‌کند', guardCalls + '/' + ajaxHandlers );

/* ۶) پاسخ‌ها فقط با wp_send_json */
const jsonOk = /wp_send_json_success/.test( page ) && /wp_send_json_error/.test( page );
check( jsonOk, 'پاسخ AJAX با wp_send_json_success/error است' );
check( ! /echo\s+wp_json_encode/.test( page ), 'خروجی AJAX دستی echo نمی‌شود' );

/* ۷) توکن هرگز چاپ یا بازگردانده نمی‌شود */
const tokenLeaks = [
	/echo[^;\n]*\$token\b/,
	/wp_send_json[^;]*\$token\b/,
	/error_log[^;\n]*token/i,
	/'token'\s*=>\s*\$settings\[/,
	/var_dump|print_r\s*\(\s*\$settings/
];
tokenLeaks.forEach( ( re, index ) => {
	check( ! re.test( page ) && ! re.test( view ) && ! re.test( updater ), 'الگوی افشای توکن #' + ( index + 1 ) + ' وجود ندارد' );
} );
check( ! /name="token"[^>]*value="(?!")/.test( view ), 'فیلد توکن مقدار از پیش پرشده ندارد' );
check( /type="password"/.test( view ), 'فیلد توکن از نوع password است' );
check( /clear_token/.test( view ), 'گزینهٔ حذف صریح توکن وجود دارد' );

/* ۸) خروجی‌ها در view escape می‌شوند */
const rawEcho = ( view.match( /echo\s+\$[A-Za-z_]/g ) || [] );
check( rawEcho.length === 0, 'نمای صفحه هیچ متغیر خامی را echo نمی‌کند', rawEcho.join( ', ' ) );

/* ۹) آپشن‌ها prefix دارند */
const optionNames = [];
const optionRe = /(?:get_option|update_option|delete_option|add_option)\(\s*'([^']+)'/g;
let match;
while ( ( match = optionRe.exec( updater + page ) ) !== null ) {
	optionNames.push( match[ 1 ] );
}
const coreOptions = [ 'active_plugins' ];
check(
	optionNames.every( ( name ) => name.startsWith( 'falnic_' ) || coreOptions.includes( name ) ),
	'همهٔ آپشن‌های اختصاصی prefix falnic_ دارند',
	optionNames.filter( ( n ) => ! n.startsWith( 'falnic_' ) && ! coreOptions.includes( n ) ).join( ', ' )
);

/* ۱۰) جاوااسکریپت */
check( ! /\beval\s*\(/.test( adminJs ) && ! /\beval\s*\(/.test( playerJs ), 'هیچ eval( در JS نیست' );
check( ! /(^|[^\w])debugger\s*;/m.test( adminJs ) && ! /(^|[^\w])debugger\s*;/m.test( playerJs ), 'هیچ دستور debugger در JS نیست' );
check( ! /innerHTML/.test( adminJs ), 'در JS از innerHTML استفاده نمی‌شود (بدون XSS)' );
check( /nonce/.test( adminJs ), 'درخواست‌های JS شامل nonce هستند' );
check( /credentials:\s*'same-origin'/.test( adminJs ), 'درخواست‌های JS با کوکی same-origin ارسال می‌شوند' );

/* ۱۱) سازگاری با PHP 7.4 */
const php74Banned = [
	[ /\?\?=/, 'عملگر ??=' ],
	[ /\bmatch\s*\(\s*\$/, 'match expression' ],
	[ /\breadonly\s+/, 'readonly' ],
	[ /\):\s*\w+\|\w+/, 'union type' ],
	[ /\?->/, 'nullsafe operator' ]
];
php74Banned.forEach( ( pair ) => {
	check( ! pair[ 0 ].test( updater ) && ! pair[ 0 ].test( page ), 'سازگار با PHP 7.4: بدون ' + pair[ 1 ] );
} );

console.log( '\nنتیجه: ' + pass + ' موفق، ' + fail + ' ناموفق\n' );
process.exit( fail > 0 ? 1 : 0 );
