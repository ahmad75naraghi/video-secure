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

/* ۱۱) دروازه‌ی استریم و کنترل دسترسی */
const stream = read( 'includes/class-stream.php' );
const access = read( 'includes/class-access.php' );
const token = read( 'includes/class-token.php' );
const learnDash = read( 'includes/class-learn-dash.php' );
const storage = read( 'includes/class-storage.php' );
const player = read( 'includes/class-player.php' );
const keyServer = read( 'includes/class-key-server.php' );
const adminMenu = read( 'admin/class-admin-menu.php' );

[ 'manifest', 'segment', 'key' ].forEach( ( method ) => {
	const body = stream.slice(
		stream.indexOf( 'public static function ' + method + '()' ),
		stream.indexOf( 'public static function', stream.indexOf( 'public static function ' + method + '()' ) + 10 )
	);
	check( /Token::verify/.test( body ), 'دروازه‌ی ' + method + ': توکن بررسی می‌شود' );
	check( /Access::reason/.test( body ), 'دروازه‌ی ' + method + ': دسترسی دوباره بررسی می‌شود' );
} );

check( /hash_equals/.test( token ) && /hash_hmac\(\s*'sha256'/.test( token ), 'امضای توکن با HMAC-SHA256 و مقایسه‌ی زمان‌ثابت' );
check( ! /\b(md5|sha1)\s*\(/.test( token ), 'توکن از md5/sha1 استفاده نمی‌کند' );
check( /'scope'|SCOPE_KEY/.test( token ), 'توکن دامنه (scope) دارد' );
check( /falnic_token_user/.test( token ), 'توکن به کاربر گره می‌خورد' );

check( /Require all denied/.test( storage ), 'دسترسی مستقیم به پوشه‌ی رسانه بسته است' );
check( /realpath\(/.test( storage ), 'مسیر فایل با realpath بررسی می‌شود' );
check( ! /playlist_url|videos_url/.test( storage ), 'هیچ URL عمومی برای پلی‌لیست ساخته نمی‌شود' );
check( ! /playlist_url|videos_url/.test( player ), 'پلیر آدرس فیزیکی/عمومی ویدیو را در HTML نمی‌گذارد' );

check( /sessionUrl/.test( player ), 'پلیر نشست را از سرور می‌گیرد' );
check( /Access::reason/.test( player ), 'پلیر پیش از رندر دسترسی را بررسی می‌کند' );
check( ! /'src'\s*=>/.test( player ), 'پلیر هیچ src عمومی در پیکربندی نمی‌گذارد' );

check( /Access::reason/.test( keyServer ), 'اندپوینت سازگاری کلید هم دسترسی را بررسی می‌کند' );
check( /sfwd_lms_has_access/.test( access ), 'بررسی ثبت‌نام با sfwd_lms_has_access انجام می‌شود' );
check( /learndash_get_course_id/.test( access ), 'دوره از learndash_get_course_id خوانده می‌شود' );
check( ! /post_meta\s*\(.*_sfwd|wpdb->query/.test( access ), 'هیچ کوئری SQL مستقیم در بررسی دسترسی نیست' );

check( /wp_verify_nonce/.test( learnDash ) && /current_user_can\(\s*'edit_post'/.test( learnDash ), 'متاباکس درس nonce و دسترسی دارد' );
check( /the_content/.test( learnDash ) && /falnic_lesson_video/.test( learnDash ), 'نمایش خودکار و شورت‌کد درس پیاده شده است' );

check( /falnic_migrate_storage/.test( adminMenu ) && /check_admin_referer/.test( adminMenu ), 'مهاجرت فایل‌ها با nonce محافظت می‌شود' );

check( ! /error_log\s*\(/.test( token + stream + access ), 'هیچ توکن/خطای حساسی لاگ نمی‌شود' );

/* ۱۲) همه‌ی ارجاع‌های Class::method معتبرند (جلوگیری از خطای زمان اجرا) */
const allPhp = [];
( function walk( dir ) {
	fs.readdirSync( dir ).forEach( ( name ) => {
		if ( [ 'node_modules', '.git', 'vendor' ].includes( name ) ) return;
		const full = path.join( dir, name );
		const stat = fs.statSync( full );
		if ( stat.isDirectory() ) { walk( full ); return; }
		if ( name.endsWith( '.php' ) ) allPhp.push( full );
	} );
}( ROOT ) );

const classSources = {};
allPhp.forEach( ( file ) => {
	const code = fs.readFileSync( file, 'utf8' );
	const match = code.match( /\bclass\s+([A-Za-z_]\w*)/ );
	if ( match ) classSources[ match[ 1 ] ] = code;
} );

const missing = [];
let refs = 0;
allPhp.forEach( ( file ) => {
	const code = fs.readFileSync( file, 'utf8' );
	const re = /\b([A-Z]\w+)::([a-zA-Z_]\w*)/g;
	let m;
	while ( ( m = re.exec( code ) ) !== null ) {
		const cls = m[ 1 ];
		if ( ! classSources[ cls ] || 'class' === m[ 2 ] ) continue;
		refs++;
		const body = classSources[ cls ];
		const ok = new RegExp( 'function\\s+' + m[ 2 ] + '\\s*\\(' ).test( body )
			|| new RegExp( 'const\\s+' + m[ 2 ] + '\\b' ).test( body );
		if ( ! ok ) missing.push( cls + '::' + m[ 2 ] );
	}
} );

check( missing.length === 0, 'همه‌ی ارجاع‌های ' + refs + ' موردی Class::method معتبرند', missing.join( ', ' ) );

/* ۱۲.۱) شناسه‌ی پایدار ویدیو (بدون بازنویسی عنوان پست) */
const metaBox = read( 'includes/class-meta-box.php' );
check(
	! /'post_title'\s*=>/.test( metaBox ),
	'متاباکس عنوان پست را بازنویسی نمی‌کند (F-11)'
);
check(
	/Post_Type::set_video_name/.test( metaBox ),
	'متاباکس شناسه‌ی پایدار را در متا ذخیره می‌کند'
);
check(
	/function\s+video_name/.test( read( 'includes/class-post-type.php' ) ),
	'Post_Type::video_name برای خواندن شناسه وجود دارد'
);

/* ۱۲.۲) صف انکود، گزارش تماشا و محدودیت پخش */
const queue = read( 'includes/class-encode-queue.php' );
const watchLog = read( 'includes/class-watch-log.php' );
const registry = read( 'includes/class-play-registry.php' );
const plugin = read( 'includes/class-plugin.php' );
const installer = read( 'includes/class-installer.php' );
const adminEncode = read( 'assets/js/admin-encode.js' );

check(
	/Encode_Queue::init\(\)/.test( plugin ) && /Watch_Log::init\(\)/.test( plugin ),
	'صف انکود و گزارش تماشا در بوت افزونه ثبت می‌شوند'
);
check(
	/wp_schedule_single_event\(/.test( queue ) && /CRON_HOOK/.test( queue ),
	'انکود از طریق WP-Cron اجرا می‌شود، نه درخواست آپلود'
);
check(
	!/\bEncoder::encode\s*\(/.test( metaBox ) && /Encode_Queue::enqueue\s*\(/.test( metaBox ),
	'متاباکس ویدیو را همگام انکود نمی‌کند و به صف می‌سپارد'
);
check(
	!/(shell_exec|system|passthru|popen|pcntl_exec)\s*\(/.test( queue ) && !/(shell_exec|system|passthru|popen|pcntl_exec)\s*\(/.test( read( 'includes/class-encoder.php' ) ),
	'موتور انکود هیچ فرمان shell اجرا نمی‌کند'
);
check(
	/check_ajax_referer\(\s*'falnic_encode_status'/.test( queue ) && /current_user_can\(\s*'edit_post'/.test( queue ),
	'AJAX صف انکود nonce و capability را بررسی می‌کند'
);
check(
	/'ip_hash'/.test( watchLog ) && !/INSERT[^;]*REMOTE_ADDR/s.test( watchLog ),
	'گزارش تماشا IP خام را ذخیره نمی‌کند'
);
check(
	/hash_hmac\(\s*'sha256'/.test( watchLog ),
	'اثر انگشت IP با HMAC-SHA256 ساخته می‌شود'
);
check(
	/delete_transient\(\s*self::key\(/.test( registry ) && /terminate_all/.test( registry ),
	'قطع پخش، نشست‌های فعال کاربر را پاک می‌کند'
);
check(
	/Play_Registry::check\(/.test( stream ),
	'تحویل هر سگمنت منوط به نشست پخش فعال است'
);
check(
	/Token::SCOPE_SUBTITLE/.test( stream ) && /falnic_stream_subtitle/.test( stream ),
	'زیرنویس‌ها با توکن اختصاصی و همان دروازه تحویل می‌شوند'
);
check(
	/sendBeacon/.test( playerJs ) && /heartbeatUrl/.test( playerJs ) && /stopHeartbeat/.test( playerJs ),
	'پلیر ضربان نشست را می‌فرستد و پخش را در صورت قطع می‌بندد'
);
check(
	/document\.createElement\(\s*'track'/.test( playerJs ) && /data-falnic/.test( playerJs ),
	'زیرنویس‌ها با نشانی امضاشده به پلیر اضافه می‌شوند'
);
check(
	/textContent/.test( adminEncode ) && !/innerHTML/.test( adminEncode ),
	'پنل پیشرفت انکود از innerHTML استفاده نمی‌کند'
);
check(
	/Watch_Log::install\(\)/.test( installer ) && /schedule_events\(\)/.test( installer ),
	'فعال‌سازی افزونه جدول گزارش و رویدادهای دوره‌ای را می‌سازد'
);
check(
	/wp_clear_scheduled_hook\(\s*Encode_Queue::CLEANUP_HOOK/.test( installer ),
	'غیرفعال‌سازی افزونه رویدادهای زمان‌بندی‌شده را پاک می‌کند'
);

/* ۱۳) سازگاری با PHP 7.4 */
const php74Banned = [
	[ /\?\?=/, 'عملگر ??=' ],
	[ /\bmatch\s*\(\s*\$/, 'match expression' ],
	[ /\breadonly\s+/, 'readonly' ],
	[ /\):\s*\w+\|\w+/, 'union type' ],
	[ /\?->/, 'nullsafe operator' ]
];
const newFiles = token + access + stream + learnDash + storage + player + keyServer
	+ queue + watchLog + registry + plugin + installer + metaBox + read( 'admin/class-admin-menu.php' )
	+ read( 'admin/views/watch-log.php' ) + read( 'admin/views/settings.php' ) + adminEncode;
php74Banned.forEach( ( pair ) => {
	check(
		! pair[ 0 ].test( updater ) && ! pair[ 0 ].test( page ) && ! pair[ 0 ].test( newFiles ),
		'سازگار با PHP 7.4: بدون ' + pair[ 1 ]
	);
} );

console.log( '\nنتیجه: ' + pass + ' موفق، ' + fail + ' ناموفق\n' );
process.exit( fail > 0 ? 1 : 0 );
