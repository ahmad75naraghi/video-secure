<?php
/**
 * تست‌های واحد موتور «آپدیت از گیت» — بدون نیاز به نصب وردپرس.
 *
 * اجرا:
 *   php tests/run-tests.php
 *
 * این فایل توابع حداقلی وردپرس را شبیه‌سازی می‌کند تا منطق خالص
 * (اعتبارسنجی، پشتیبان‌گیری، ZIP، lint، همگام‌سازی و بازگردانی) واقعاً اجرا شود.
 *
 * @package Falnic\SecureHLS
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'FALNIC_VERSION', '3.2.0' );
define( 'FALNIC_DIR', dirname( __DIR__ ) . '/' );

use Falnic\SecureHLS\Git_Updater;

/* -------------------------------------------------------------------------
 * شبیه‌سازی حداقلی وردپرس
 * ---------------------------------------------------------------------- */

/**
 * کلاس خطای وردپرس.
 */
class WP_Error {

	/** @var string */
	private $code;

	/** @var string */
	private $message;

	/** @var mixed */
	private $data;

	/**
	 * سازنده.
	 *
	 * @param string $code    کد.
	 * @param string $message پیام.
	 * @param mixed  $data    داده.
	 */
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
		$this->data    = $data;
	}

	/** @return string */
	public function get_error_code() {
		return $this->code;
	}

	/** @return string */
	public function get_error_message() {
		return $this->message;
	}

	/** @return mixed */
	public function get_error_data() {
		return $this->data;
	}
}

$GLOBALS['falnic_http_queue'] = array();
$GLOBALS['falnic_http_log']   = array();

/**
 * صف پاسخ‌های HTTP ساختگی.
 *
 * @param array $response پاسخ ساختگی.
 * @return void
 */
function falnic_queue_http( $response ) {
	$GLOBALS['falnic_http_queue'][] = $response;
}

/**
 * آخرین درخواست ارسال‌شده.
 *
 * @return array
 */
function falnic_last_request() {
	return $GLOBALS['falnic_http_log'] ? $GLOBALS['falnic_http_log'][ count( $GLOBALS['falnic_http_log'] ) - 1 ] : array();
}

/**
 * جایگزین wp_remote_get.
 *
 * @param string $url  نشانی.
 * @param array  $args آرگومان‌ها.
 * @return array|WP_Error
 */
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['falnic_http_log'][] = array( 'url' => $url, 'args' => $args );

	if ( empty( $GLOBALS['falnic_http_queue'] ) ) {
		return new WP_Error( 'falnic_no_queue', 'no mock queued for ' . $url );
	}

	return array_shift( $GLOBALS['falnic_http_queue'] );
}

/** @param mixed $response @return int */
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

/** @param mixed $response @return string */
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? (string) $response['body'] : '';
}

/**
 * خواندن هدر پاسخ.
 *
 * @param mixed  $response پاسخ.
 * @param string $header   نام هدر.
 * @return string|null
 */
function wp_remote_retrieve_header( $response, $header ) {
	$key = strtolower( (string) $header );

	if ( isset( $response['headers'][ $key ] ) ) {
		return $response['headers'][ $key ];
	}

	if ( isset( $response['headers'][ $header ] ) ) {
		return $response['headers'][ $header ];
	}

	return null;
}

/**
 * ساخت پاسخ ساختگی.
 *
 * @param int    $code کد.
 * @param string $body بدنه.
 * @param array  $headers هدرها.
 * @return array
 */
function falnic_http( $code, $body = '', $headers = array() ) {
	return array(
		'response' => array( 'code' => $code ),
		'body'     => $body,
		'headers'  => $headers,
	);
}

/** @param mixed $data @return string */
function wp_json_encode( $data ) {
	return (string) json_encode( $data );
}

$GLOBALS['falnic_test_options']    = array();
$GLOBALS['falnic_test_transients'] = array();

/** @param mixed $thing مقدار. @return bool */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/** @param string $text @return string */
function __( $text, $domain = null ) { // phpcs:ignore
	return $text;
}

/** @param string $text @return string */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/** @param string $key @param mixed $default @return mixed */
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['falnic_test_options'] ) ? $GLOBALS['falnic_test_options'][ $key ] : $default;
}

/** @param string $key @param mixed $value @return bool */
function update_option( $key, $value, $autoload = null ) { // phpcs:ignore
	$GLOBALS['falnic_test_options'][ $key ] = $value;
	return true;
}

/** @param string $key @return bool */
function delete_option( $key ) {
	unset( $GLOBALS['falnic_test_options'][ $key ] );
	return true;
}

/** @param string $key @param mixed $value @param int $ttl @return bool */
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['falnic_test_transients'][ $key ] = $value;
	return true;
}

/** @param string $key @return mixed */
function get_transient( $key ) {
	return isset( $GLOBALS['falnic_test_transients'][ $key ] ) ? $GLOBALS['falnic_test_transients'][ $key ] : false;
}

/** @param string $key @return bool */
function delete_transient( $key ) {
	unset( $GLOBALS['falnic_test_transients'][ $key ] );
	return true;
}

/** @return int */
function get_current_user_id() {
	return 1;
}

/** @return array */
function wp_upload_dir() {
	return array(
		'basedir' => $GLOBALS['falnic_test_uploads'],
		'baseurl' => 'http://example.test/uploads',
	);
}

/** @param string $dir @return bool */
function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}

/** @param string $path @return bool */
function wp_is_writable( $path ) {
	return is_writable( $path );
}

/** @param string $file @return bool */
function wp_delete_file( $file ) {
	if ( file_exists( $file ) ) {
		return unlink( $file );
	}
	return true;
}

/** @param string $path @return string */
function trailingslashit( $path ) {
	return rtrim( (string) $path, '/\\' ) . '/';
}

/** @param string $path @return string */
function untrailingslashit( $path ) {
	return rtrim( (string) $path, '/\\' );
}

/** @param string $path @return string */
function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

/** @param string $key @return string */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/** @param int $length @return string */
function wp_generate_password( $length = 12, $special = true, $extra = true ) { // phpcs:ignore
	return substr( bin2hex( random_bytes( 32 ) ), 0, $length );
}

/** @param string $tag @param mixed $value @return mixed */
function apply_filters( $tag, $value ) {
	return $value;
}

/** @param string $date @param string $format @return string */
function get_date_from_gmt( $date, $format = 'Y-m-d H:i:s' ) {
	return gmdate( $format, strtotime( $date . ' UTC' ) );
}

/** @return bool */
function is_multisite() {
	return false;
}

/** @return array */
function get_site_option( $key, $default = array() ) {
	return array();
}

/** @param string $file @param array $headers @return array */
function get_file_data( $file, $headers ) {
	$out = array();
	if ( ! is_readable( $file ) ) {
		return $out;
	}
	$data = (string) file_get_contents( $file, false, null, 0, 8192 );
	foreach ( $headers as $key => $pattern ) {
		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $pattern, '/' ) . ':(.*)$/mi', $data, $m ) ) {
			$out[ $key ] = trim( $m[1] );
		}
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * ابزار تست
 * ---------------------------------------------------------------------- */

$GLOBALS['falnic_pass'] = 0;
$GLOBALS['falnic_fail'] = 0;

/**
 * ثبت نتیجه.
 *
 * @param bool   $ok    نتیجه.
 * @param string $label برچسب.
 * @param string $note  توضیح.
 * @return void
 */
function falnic_assert( $ok, $label, $note = '' ) {
	if ( $ok ) {
		$GLOBALS['falnic_pass']++;
		echo "PASS  {$label}\n";
		return;
	}

	$GLOBALS['falnic_fail']++;
	echo "FAIL  {$label}" . ( $note ? " :: {$note}" : '' ) . "\n";
}

/**
 * ساخت پوشهٔ موقت.
 *
 * @param string $prefix پیشوند.
 * @return string
 */
function falnic_tmpdir( $prefix = 'falnic-test-' ) {
	$dir = sys_get_temp_dir() . '/' . $prefix . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0777, true );

	return $dir;
}

/**
 * حذف بازگشتی پوشه.
 *
 * @param string $dir پوشه.
 * @return void
 */
function falnic_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $dir );
}

/**
 * نوشتن فایل با ساخت پوشه.
 *
 * @param string $path مسیر.
 * @param string $body محتوا.
 * @return void
 */
function falnic_write( $path, $body ) {
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	file_put_contents( $path, $body );
}

require_once FALNIC_DIR . 'includes/class-git-updater.php';

$GLOBALS['falnic_test_uploads'] = falnic_tmpdir( 'falnic-uploads-' );

echo "\n=== Falnic Git Updater — unit tests ===\n\n";

/* -------------------------------------------------------------------------
 * ۱) اعتبارسنجی مخزن
 * ---------------------------------------------------------------------- */
falnic_assert( Git_Updater::is_valid_repo( 'owner/repo' ), 'repo معتبر ساده' );
falnic_assert( Git_Updater::is_valid_repo( 'ahmad75naraghi/video-secure' ), 'repo با خط تیره' );
falnic_assert( ! Git_Updater::is_valid_repo( 'owner' ), 'repo بدون اسلش رد می‌شود' );
falnic_assert( ! Git_Updater::is_valid_repo( 'a/b/c' ), 'repo با دو اسلش رد می‌شود' );
falnic_assert( ! Git_Updater::is_valid_repo( '../../etc/passwd' ), 'repo با traversal رد می‌شود' );
falnic_assert( ! Git_Updater::is_valid_repo( 'owner/repo?x=1' ), 'repo با کاراکتر غیرمجاز رد می‌شود' );
falnic_assert( ! Git_Updater::is_valid_repo( '' ), 'repo خالی رد می‌شود' );

/* -------------------------------------------------------------------------
 * ۲) اعتبارسنجی شاخه
 * ---------------------------------------------------------------------- */
falnic_assert( Git_Updater::is_valid_branch( 'main' ), 'branch ساده' );
falnic_assert( Git_Updater::is_valid_branch( 'feature/update-2' ), 'branch با اسلش' );
falnic_assert( ! Git_Updater::is_valid_branch( '../main' ), 'branch با traversal رد می‌شود' );
falnic_assert( ! Git_Updater::is_valid_branch( 'main; rm -rf /' ), 'branch با فرمان shell رد می‌شود' );
falnic_assert( ! Git_Updater::is_valid_branch( '' ), 'branch خالی رد می‌شود' );
falnic_assert( ! Git_Updater::is_valid_branch( 'a//b' ), 'branch با اسلش دوتایی رد می‌شود' );

/* -------------------------------------------------------------------------
 * ۳) نام پشتیبان
 * ---------------------------------------------------------------------- */
falnic_assert( Git_Updater::is_valid_backup_name( 'falnic-before-update-20260101-120000-abcdef.zip' ), 'نام پشتیبان معتبر' );
falnic_assert( ! Git_Updater::is_valid_backup_name( '../falnic-x.zip' ), 'پشتیبان با traversal رد می‌شود' );
falnic_assert( ! Git_Updater::is_valid_backup_name( '/etc/passwd' ), 'مسیر absolute رد می‌شود' );
falnic_assert( ! Git_Updater::is_valid_backup_name( 'evil.php' ), 'پسوند غیر zip رد می‌شود' );

/* -------------------------------------------------------------------------
 * ۴) امنیت entry های ZIP
 * ---------------------------------------------------------------------- */
falnic_assert( Git_Updater::is_safe_zip_entry( 'root/file.php' ), 'مسیر معمولی مجاز' );
falnic_assert( ! Git_Updater::is_safe_zip_entry( '../../evil.php' ), 'traversal رد می‌شود' );
falnic_assert( ! Git_Updater::is_safe_zip_entry( 'root/../../evil.php' ), 'traversal وسط مسیر رد می‌شود' );
falnic_assert( ! Git_Updater::is_safe_zip_entry( '/abs/path.php' ), 'مسیر absolute رد می‌شود' );
falnic_assert( ! Git_Updater::is_safe_zip_entry( 'C:\\windows\\evil.php' ), 'مسیر ویندوزی رد می‌شود' );
falnic_assert( ! Git_Updater::is_safe_zip_entry( "a\0b.php" ), 'null byte رد می‌شود' );

/* -------------------------------------------------------------------------
 * ۵) تشخیص فایل اصلی
 * ---------------------------------------------------------------------- */
falnic_assert(
	'custom-folder/falnic-secure-hls.php' === Git_Updater::detect_main_file(
		array( 'other/plugin.php', 'custom-folder/falnic-secure-hls.php' ),
		'custom-folder',
		'custom-folder/falnic-secure-hls.php'
	),
	'تشخیص فایل اصلی با نام پوشهٔ متفاوت'
);

falnic_assert(
	'our-folder/fallback.php' === Git_Updater::detect_main_file( array( 'x/y.php' ), 'our-folder', 'our-folder/fallback.php' ),
	'fallback وقتی افزونه در لیست فعال نیست'
);

/* -------------------------------------------------------------------------
 * ۶) نگهداشت پشتیبان‌ها
 * ---------------------------------------------------------------------- */
$items = array();
for ( $i = 1; $i <= 7; $i++ ) {
	$items[] = array( 'file' => "falnic-b{$i}.zip", 'mtime' => 1000 + $i );
}
$to_delete = Git_Updater::select_backups_to_delete( $items, 5 );
falnic_assert( 2 === count( $to_delete ), 'retention: دو مورد مازاد حذف می‌شود', (string) count( $to_delete ) );
falnic_assert( in_array( 'falnic-b1.zip', $to_delete, true ) && in_array( 'falnic-b2.zip', $to_delete, true ), 'retention: قدیمی‌ترین‌ها حذف می‌شوند' );
falnic_assert( array() === Git_Updater::select_backups_to_delete( $items, 10 ), 'retention: اگر کمتر از سقف باشد چیزی حذف نمی‌شود' );

/* -------------------------------------------------------------------------
 * ۷) پشتیبان‌گیری واقعی (نیازمند ZipArchive)
 * ---------------------------------------------------------------------- */
$zip_ready = class_exists( 'ZipArchive' );
falnic_assert( $zip_ready, 'ZipArchive روی این محیط موجود است (در غیر این صورت تست‌های ZIP رد می‌شوند)' );

$plugin_dir = falnic_tmpdir( 'falnic-plugin-' );

falnic_write( $plugin_dir . '/falnic-secure-hls.php', "<?php\n/**\n * Plugin Name: Test\n * Version: 1.0.0\n */\n" );
falnic_write( $plugin_dir . '/includes/class-a.php', "<?php\n// a\n" );
falnic_write( $plugin_dir . '/.git/config', "secret\n" );

if ( $zip_ready ) {
	// پوشهٔ افزونه باید همان جایی باشد که کلاس انتظار دارد؛ اینجا مسیر واقعی کلاس تست می‌شود.
	$backup = Git_Updater::create_backup( 'test' );

	if ( is_wp_error( $backup ) ) {
		falnic_assert( false, 'ساخت پشتیبان', $backup->get_error_message() );
	} else {
		falnic_assert( file_exists( $backup ), 'فایل پشتیبان ساخته شد' );
		falnic_assert( 0 === strpos( wp_normalize_path( $backup ), wp_normalize_path( $GLOBALS['falnic_test_uploads'] ) ), 'پشتیبان خارج از پوشهٔ افزونه است' );

		$zip = new ZipArchive();
		$zip->open( $backup );
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat    = $zip->statIndex( $i );
			$names[] = isset( $stat['name'] ) ? $stat['name'] : '';
		}
		$zip->close();

		falnic_assert( in_array( 'falnic-secure-hls.php', $names, true ), 'فایل اصلی در پشتیبان هست' );
		$has_git = false;
		foreach ( $names as $name ) {
			$normalized = '/' . ltrim( str_replace( '\\', '/', (string) $name ), '/' );

			if ( false !== strpos( $normalized, '/.git/' ) || '/.git' === $normalized ) {
				$has_git = true;
			}
		}
		falnic_assert( ! $has_git, 'پوشهٔ .git در پشتیبان نیست' );
	}
}

/* -------------------------------------------------------------------------
 * ۸) اعتبارسنجی ZIP
 * ---------------------------------------------------------------------- */
if ( $zip_ready ) {
	$good_dir = falnic_tmpdir( 'falnic-good-' );
	falnic_write( $good_dir . '/root/falnic-secure-hls.php', "<?php\n/**\n * Plugin Name: Test\n */\n" );
	falnic_write( $good_dir . '/root/x.php', "<?php\n" );

	$good_zip = $GLOBALS['falnic_test_uploads'] . '/good.zip';
	$zip      = new ZipArchive();
	$zip->open( $good_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFile( $good_dir . '/root/falnic-secure-hls.php', 'owner-repo-abc123/falnic-secure-hls.php' );
	$zip->addFile( $good_dir . '/root/x.php', 'owner-repo-abc123/x.php' );
	$zip->close();

	$valid = Git_Updater::validate_zip( $good_zip );
	falnic_assert( ! is_wp_error( $valid ) && 'owner-repo-abc123' === $valid['root'], 'ZIP گیت‌هاب (با پوشهٔ ریشه) شناسایی شد', is_wp_error( $valid ) ? $valid->get_error_message() : '' );

	$no_main = $GLOBALS['falnic_test_uploads'] . '/nomain.zip';
	$zip     = new ZipArchive();
	$zip->open( $no_main, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'root/other.php', "<?php\n" );
	$zip->close();
	$result = Git_Updater::validate_zip( $no_main );
	falnic_assert( is_wp_error( $result ) && 'falnic_zip_main_missing' === $result->get_error_code(), 'ZIP بدون فایل اصلی رد می‌شود' );

	$bad_zip = $GLOBALS['falnic_test_uploads'] . '/traversal.zip';
	$zip     = new ZipArchive();
	$zip->open( $bad_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'root/falnic-secure-hls.php', "<?php\n/**\n * Plugin Name: X\n */\n" );
	$zip->addFromString( '../../evil.php', "<?php\n" );
	$zip->close();
	$result = Git_Updater::validate_zip( $bad_zip );
	falnic_assert( is_wp_error( $result ) && 'falnic_zip_traversal' === $result->get_error_code(), 'ZIP با path traversal رد می‌شود', is_wp_error( $result ) ? $result->get_error_code() : 'no error' );

	$no_header = $GLOBALS['falnic_test_uploads'] . '/noheader.zip';
	$zip       = new ZipArchive();
	$zip->open( $no_header, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'root/falnic-secure-hls.php', "<?php\n// not a plugin\n" );
	$zip->close();
	$result = Git_Updater::validate_zip( $no_header );
	falnic_assert( is_wp_error( $result ) && 'falnic_zip_not_plugin' === $result->get_error_code(), 'ZIP بدون هدر افزونه رد می‌شود' );
}

/* -------------------------------------------------------------------------
 * ۹) Preflight syntax
 * ---------------------------------------------------------------------- */
$lint_dir = falnic_tmpdir( 'falnic-lint-' );
falnic_write( $lint_dir . '/ok.php', "<?php\nfunction falnic_ok() { return 1 + 1; }\n" );
falnic_assert( array() === Git_Updater::lint_php_files( $lint_dir ), 'فایل سالم خطا ندارد' );

falnic_write( $lint_dir . '/broken.php', "<?php\nfunction falnic_broken( {\n" );
$errors = Git_Updater::lint_php_files( $lint_dir );
falnic_assert( ! empty( $errors ), 'فایل با syntax error شناسایی می‌شود' );
falnic_assert( false !== strpos( implode( ' ', $errors ), 'broken.php' ), 'نام فایل خطادار در گزارش هست' );

// فایلی که اگر اجرا شود اثر می‌گذارد؛ باید فقط parse شود.
$marker = $lint_dir . '/executed.marker';
falnic_write( $lint_dir . '/sideeffect.php', "<?php\nfile_put_contents(" . var_export( $marker, true ) . ", 'x');\n" );
Git_Updater::lint_php_files( $lint_dir );
falnic_assert( ! file_exists( $marker ), 'lint فایل را اجرا نمی‌کند (بدون side effect)' );

/* -------------------------------------------------------------------------
 * ۱۰) همگام‌سازی فایل‌ها (apply) — از طریق Reflection
 * ---------------------------------------------------------------------- */
$apply_method = new ReflectionMethod( Git_Updater::class, 'apply' );

if ( PHP_VERSION_ID < 80100 ) {
	$apply_method->setAccessible( true );
}

$src = falnic_tmpdir( 'falnic-src-' );
$dst = falnic_tmpdir( 'falnic-dst-' );

falnic_write( $src . '/falnic-secure-hls.php', "<?php\n/**\n * Plugin Name: New\n */\n" );
falnic_write( $src . '/includes/new.php', "<?php\n// new\n" );
falnic_write( $src . '/includes/changed.php', "<?php\n// v2\n" );

falnic_write( $dst . '/falnic-secure-hls.php', "<?php\n/**\n * Plugin Name: Old\n */\n" );
falnic_write( $dst . '/includes/changed.php', "<?php\n// v1\n" );
falnic_write( $dst . '/includes/removed.php', "<?php\n// should be deleted\n" );
falnic_write( $dst . '/.git/config', "keep me\n" );

$result = $apply_method->invoke( null, $src, $dst );

falnic_assert( is_array( $result ), 'apply خروجی گزارش می‌دهد' );

if ( is_array( $result ) ) {
	falnic_assert( in_array( 'includes/new.php', $result['added'], true ), 'فایل جدید در added گزارش می‌شود' );
	falnic_assert( in_array( 'includes/changed.php', $result['changed'], true ), 'فایل تغییریافته در changed گزارش می‌شود' );
	falnic_assert( in_array( 'includes/removed.php', $result['removed'], true ), 'فایل حذف‌شده در removed گزارش می‌شود' );
	falnic_assert( file_exists( $dst . '/includes/new.php' ), 'فایل جدید روی دیسک کپی شد' );
	falnic_assert( false !== strpos( (string) file_get_contents( $dst . '/includes/changed.php' ), 'v2' ), 'محتوای فایل تغییرکرده به‌روز شد' );
	falnic_assert( ! file_exists( $dst . '/includes/removed.php' ), 'فایل حذف‌شده از نصب پاک شد' );
	falnic_assert( file_exists( $dst . '/.git/config' ), 'پوشهٔ .git حذف نمی‌شود' );
}

// فایل اصلی هرگز نباید حذف شود، حتی اگر در نسخهٔ جدید نباشد.
$src2 = falnic_tmpdir( 'falnic-src2-' );
falnic_write( $src2 . '/includes/only.php', "<?php\n// only\n" );

$result2 = $apply_method->invoke( null, $src2, $dst );
falnic_assert( is_array( $result2 ), 'apply دوم خروجی دارد' );
falnic_assert( file_exists( $dst . '/falnic-secure-hls.php' ), 'فایل اصلی افزونه هرگز حذف نمی‌شود' );
falnic_assert( is_array( $result2 ) && in_array( 'falnic-secure-hls.php', $result2['skipped'], true ), 'فایل اصلی در skipped گزارش می‌شود' );

// ریشهٔ پوشه دست‌نخورده می‌ماند.
falnic_assert( is_dir( $dst ), 'ریشهٔ پوشهٔ مقصد پس از apply باقی است' );

/* -------------------------------------------------------------------------
 * ۱۰ب) Rollback امن — رد ورودی نامعتبر و مسیر بیرونی
 * ---------------------------------------------------------------------- */
$bad = Git_Updater::run_rollback( '../../etc/passwd' );
falnic_assert( is_wp_error( $bad ), 'rollback با نام نامعتبر رد می‌شود' );

$bad = Git_Updater::run_rollback( 'falnic-not-exists.zip' );
falnic_assert( is_wp_error( $bad ), 'rollback با فایل ناموجود رد می‌شود' );

if ( $zip_ready && isset( $backup ) && ! is_wp_error( $backup ) ) {
	$info = Git_Updater::validate_zip( $backup );
	falnic_assert( ! is_wp_error( $info ) && '' === $info['root'], 'پشتیبان داخلی (بدون پوشهٔ ریشه) شناسایی می‌شود', is_wp_error( $info ) ? $info->get_error_message() : '' );
}

/* -------------------------------------------------------------------------
 * ۱۱) قفل هم‌زمانی
 * ---------------------------------------------------------------------- */
delete_transient( Git_Updater::LOCK_KEY );
set_transient( Git_Updater::LOCK_KEY, array( 'time' => time(), 'user' => 1 ), 900 );
falnic_assert( Git_Updater::is_locked(), 'قفل فعال تشخیص داده می‌شود' );
Git_Updater::unlock();
falnic_assert( ! Git_Updater::is_locked(), 'آزادسازی قفل کار می‌کند' );

/* -------------------------------------------------------------------------
 * ۱۲) عدم افشای توکن در تنظیمات
 * ---------------------------------------------------------------------- */
update_option( Git_Updater::OPTION_TOKEN, 'FAKE-TOKEN-NOT-REAL-1234' );
update_option( Git_Updater::OPTION_REPO, 'owner/repo' );
$settings = Git_Updater::get_settings();
falnic_assert( ! isset( $settings['token'] ), 'get_settings مقدار توکن را برنمی‌گرداند' );
falnic_assert( true === $settings['has_token'], 'وضعیت «توکن ذخیره شده» گزارش می‌شود' );
falnic_assert( false === strpos( wp_json_encode( $settings ), 'FAKE-TOKEN-NOT-REAL-1234' ), 'توکن در خروجی JSON نیست' );

update_option( Git_Updater::OPTION_TOKEN, 'FAKE-TOKEN-NOT-REAL-1234' );
$saved = Git_Updater::save_settings( 'owner/repo', 'main', '', false );
falnic_assert( true === $saved && 'FAKE-TOKEN-NOT-REAL-1234' === get_option( Git_Updater::OPTION_TOKEN ), 'توکن خالی، مقدار قبلی را حفظ می‌کند' );

Git_Updater::save_settings( 'owner/repo', 'main', '', true );
falnic_assert( false === get_option( Git_Updater::OPTION_TOKEN ), 'checkbox حذف، توکن را پاک می‌کند' );


/* -------------------------------------------------------------------------
 * ۱۳) خطاهای متمایز API گیت‌هاب (بدون درخواست واقعی)
 * ---------------------------------------------------------------------- */
Git_Updater::save_settings( 'owner/repo', 'main', 'FAKE-TOKEN-NOT-REAL-5678', false );

$cases = array(
	'falnic_not_found'     => falnic_http( 404 ),
	'falnic_bad_token'     => falnic_http( 401 ),
	'falnic_no_access'     => falnic_http( 403 ),
	'falnic_rate_limited'  => falnic_http( 403, '', array( 'x-ratelimit-remaining' => '0' ) ),
	'falnic_http'          => falnic_http( 500 ),
);

foreach ( $cases as $expected => $response ) {
	$GLOBALS['falnic_http_queue'] = array();
	falnic_queue_http( $response );
	$outcome = Git_Updater::check_for_update();
	falnic_assert(
		is_wp_error( $outcome ) && $expected === $outcome->get_error_code(),
		'کد خطای متمایز: ' . $expected,
		is_wp_error( $outcome ) ? $outcome->get_error_code() : 'not an error'
	);
}

// شاخهٔ ناموجود: مخزن سالم، commit با 404.
$GLOBALS['falnic_http_queue'] = array();
falnic_queue_http( falnic_http( 200, wp_json_encode( array( 'full_name' => 'owner/repo', 'private' => true ) ) ) );
falnic_queue_http( falnic_http( 404 ) );
$outcome = Git_Updater::check_for_update();
falnic_assert(
	is_wp_error( $outcome ) && 'falnic_branch_missing' === $outcome->get_error_code(),
	'کد خطای متمایز: شاخهٔ ناموجود',
	is_wp_error( $outcome ) ? $outcome->get_error_code() : 'not an error'
);

// تایم‌اوت شبکه.
$GLOBALS['falnic_http_queue'] = array();
falnic_queue_http( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
$outcome = Git_Updater::check_for_update();
falnic_assert(
	is_wp_error( $outcome ) && 'falnic_timeout' === $outcome->get_error_code(),
	'کد خطای متمایز: تایم‌اوت'
);

// پاسخ ۲۰۰ با بدنهٔ غیر JSON (مثلاً صفحهٔ HTML کپچا).
$GLOBALS['falnic_http_queue'] = array();
falnic_queue_http( falnic_http( 200, '<html>not json</html>' ) );
$outcome = Git_Updater::check_for_update();
falnic_assert(
	is_wp_error( $outcome ) && 'falnic_unexpected' === $outcome->get_error_code(),
	'کد خطای متمایز: پاسخ غیرمنتظره'
);

// مسیر موفق.
$sha = str_repeat( 'a', 40 );
$GLOBALS['falnic_http_queue'] = array();
falnic_queue_http( falnic_http( 200, wp_json_encode( array( 'full_name' => 'owner/repo', 'private' => false ) ) ) );
falnic_queue_http(
	falnic_http(
		200,
		wp_json_encode(
			array(
				'sha'    => $sha,
				'commit' => array(
					'message'   => "Improve player\n\nbody",
					'committer' => array( 'date' => '2026-01-01T10:00:00Z' ),
					'author'    => array( 'name' => 'Tester' ),
				),
			)
		)
	)
);
$info = Git_Updater::check_for_update();
falnic_assert( is_array( $info ) && $sha === $info['sha'], 'بررسی آپدیت SHA را برمی‌گرداند' );
falnic_assert( is_array( $info ) && 'aaaaaaa' === $info['short_sha'], 'SHA کوتاه‌شده ۷ کاراکتری است' );
falnic_assert( is_array( $info ) && 'Improve player' === $info['message'], 'پیام commit فقط خط اول است' );
falnic_assert( is_array( $info ) && 'main' === $info['branch'], 'نام شاخه در گزارش هست' );

// هدر Authorization فقط برای درخواست‌های API ارسال می‌شود و در خروجی نیست.
$request = falnic_last_request();
$sent    = isset( $request['args']['headers']['Authorization'] ) ? $request['args']['headers']['Authorization'] : '';
falnic_assert( 'Bearer FAKE-TOKEN-NOT-REAL-5678' === $sent, 'هدر Authorization با Bearer ارسال می‌شود' );
falnic_assert( false === strpos( wp_json_encode( $info ), 'FAKE-TOKEN-NOT-REAL-5678' ), 'توکن در گزارش خروجی نیست' );
falnic_assert( isset( $request['args']['sslverify'] ) && true === $request['args']['sslverify'], 'SSL verify فعال است' );
falnic_assert( isset( $request['args']['timeout'] ) && $request['args']['timeout'] > 0, 'timeout درخواست تعیین شده است' );
falnic_assert( isset( $request['args']['headers']['User-Agent'] ), 'User-Agent ارسال می‌شود' );

// بدون توکن، هدر Authorization نباید ارسال شود.
delete_option( Git_Updater::OPTION_TOKEN );
$GLOBALS['falnic_http_queue'] = array();
falnic_queue_http( falnic_http( 200, wp_json_encode( array( 'full_name' => 'owner/repo' ) ) ) );
falnic_queue_http( falnic_http( 200, wp_json_encode( array( 'sha' => $sha, 'commit' => array( 'message' => 'x' ) ) ) ) );
Git_Updater::check_for_update();
$request = falnic_last_request();
falnic_assert( ! isset( $request['args']['headers']['Authorization'] ), 'بدون توکن هدر Authorization ارسال نمی‌شود' );

/* -------------------------------------------------------------------------
 * پاک‌سازی
 * ---------------------------------------------------------------------- */
falnic_rmdir( $GLOBALS['falnic_test_uploads'] );
falnic_rmdir( $plugin_dir );
falnic_rmdir( $lint_dir );
if ( isset( $good_dir ) ) {
	falnic_rmdir( $good_dir );
}

echo "\nنتیجه: {$GLOBALS['falnic_pass']} موفق، {$GLOBALS['falnic_fail']} ناموفق\n";

exit( $GLOBALS['falnic_fail'] > 0 ? 1 : 0 );
