<?php
/**
 * تست‌های واحد لایه‌ی دسترسی و دروازه‌ی استریم — بدون نیاز به وردپرس.
 *
 * اجرا:
 *   php tests/access-tests.php
 *   FALNIC_TEST_LD=off php tests/access-tests.php   # شبیه‌سازی نبود LearnDash
 *
 * @package Falnic\SecureHLS
 */

$falnic_root = dirname( __DIR__ );

$GLOBALS['falnic_tmp'] = sys_get_temp_dir() . '/falnic-access-' . bin2hex( random_bytes( 4 ) );

define( 'ABSPATH', $GLOBALS['falnic_tmp'] . '/wp/' );
define( 'FALNIC_DIR', $falnic_root . '/' );
define( 'FALNIC_VERSION', '3.3.0' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

/* -------------------------------------------------------------------------
 * شبیه‌سازی حداقلی وردپرس
 * ---------------------------------------------------------------------- */

/**
 * کلاس خطا.
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

$GLOBALS['falnic_options']   = array();
$GLOBALS['falnic_current']   = 7;
$GLOBALS['falnic_caps']      = array();
$GLOBALS['falnic_links']     = array();
$GLOBALS['falnic_course_of'] = array();
$GLOBALS['falnic_enrolled']  = array();
$GLOBALS['falnic_locked']    = array();

/** @param mixed $thing مقدار. @return bool */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/** @param string $text @param string|null $domain @return string */
function __( $text, $domain = null ) { // phpcs:ignore
	return $text;
}

/** @param string $text @return string */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/** @param string $key @param mixed $default @return mixed */
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['falnic_options'] ) ? $GLOBALS['falnic_options'][ $key ] : $default;
}

/** @param string $key @param mixed $value @param mixed $autoload @return bool */
function update_option( $key, $value, $autoload = null ) { // phpcs:ignore
	$GLOBALS['falnic_options'][ $key ] = $value;

	return true;
}

/** @return bool */
function is_user_logged_in() {
	return (int) get_current_user_id() > 0;
}

/** @return int */
function get_current_user_id() {
	return (int) $GLOBALS['falnic_current'];
}

/** @param int    $uid @param string $cap @param mixed $args @return bool */
function user_can( $uid, $cap, $args = null ) { // phpcs:ignore
	return in_array( $cap, $GLOBALS['falnic_caps'][ (int) $uid ] ?? array(), true );
}

/** @param string $cap @return bool */
function current_user_can( $cap ) {
	return user_can( get_current_user_id(), $cap );
}

/** @param array $args @return array */
function get_posts( $args = array() ) {
	$name = isset( $args['meta_value'] ) ? (string) $args['meta_value'] : '';

	return $GLOBALS['falnic_links'][ $name ] ?? array();
}

/** @param int    $post_id @param string $key @param bool $single @return mixed */
function get_post_meta( $post_id, $key = '', $single = false ) { // phpcs:ignore
	return '';
}

/** @param int    $user_id @param string $key @param bool $single @return mixed */
function get_user_meta( $user_id, $key = '', $single = false ) { // phpcs:ignore
	return '';
}

/** @param int $post_id @return string */
function get_the_title( $post_id ) {
	return 'Course ' . (int) $post_id;
}

/** @param int $post_id @return string */
function get_permalink( $post_id ) {
	return 'https://example.test/?p=' . (int) $post_id;
}

/** @return int */
function get_the_ID() {
	return 0;
}

/** @param mixed $data @param int $flags @return string */
function wp_json_encode( $data, $flags = 0 ) {
	return (string) json_encode( $data, $flags );
}

/** @param string $text @return string */
function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t\0\x0B]+/', ' ', (string) $text ) );
}

/** @param mixed $value @return int */
function absint( $value ) {
	return abs( (int) $value );
}

/** @param string $key @return string */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/** @param mixed $value @return mixed */
function wp_unslash( $value ) {
	return $value;
}

/** @param string $salt @return string */
function wp_salt( $salt = '' ) {
	return 'test-secret-' . md5( (string) $salt ) . '-0123456789abcdef';
}

/** @param string $path @return string */
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

/** @param array  $args @param string $url @return string */
function add_query_arg( $args, $url = '' ) { // phpcs:ignore
	$query = array();

	foreach ( (array) $args as $key => $value ) {
		$query[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	}

	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . implode( '&', $query );
}

/** @param string $path @return string */
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

/** @param string $redirect @return string */
function wp_login_url( $redirect = '' ) {
	return 'https://example.test/wp-login.php?redirect_to=' . rawurlencode( (string) $redirect );
}

/** @return array */
function wp_upload_dir() {
	return array(
		'basedir' => $GLOBALS['falnic_tmp'] . '/uploads',
		'baseurl' => 'https://example.test/uploads',
	);
}

/** @param string $path @return bool */
function wp_is_writable( $path ) {
	return is_writable( $path );
}

/** @param string $dir @return bool */
function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}

/** @param string $path @return string */
function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

/** @param string $path @return string */
function trailingslashit( $path ) {
	return rtrim( (string) $path, '/\\' ) . '/';
}

/** @param string $path @return string */
function untrailingslashit( $path ) {
	return rtrim( (string) $path, '/\\' );
}

/** @param string $file @return bool */
function wp_delete_file( $file ) {
	return file_exists( $file ) ? unlink( $file ) : true;
}

/** @param array $data @return bool */
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) { // phpcs:ignore
	return true;
}

/** @param string $key @param string $group @return mixed */
function wp_cache_get( $key, $group = '' ) { // phpcs:ignore
	return false;
}

/** @param string $key @param string $group @return bool */
function wp_cache_delete( $key, $group = '' ) { // phpcs:ignore
	return true;
}

/** @param string $tag @param mixed $value @return mixed */
function apply_filters( $tag, $value ) {
	return $value;
}

/** @param string $key @param mixed $default @return mixed */
function get_transient( $key, $default = false ) { // phpcs:ignore
	return $default;
}

/** @param string $key @param mixed $value @param int $ttl @return bool */
function set_transient( $key, $value, $ttl = 0 ) { // phpcs:ignore
	return true;
}

/* -------------------------------------------------------------------------
 * شبیه‌سازی LearnDash (با FALNIC_TEST_LD=off خاموش می‌شود)
 * ---------------------------------------------------------------------- */

if ( 'off' !== getenv( 'FALNIC_TEST_LD' ) ) {
	/** @param int $post_id @param int $user_id @return bool */
	function sfwd_lms_has_access( $post_id, $user_id = 0 ) {
		return in_array( (int) $user_id, $GLOBALS['falnic_enrolled'][ (int) $post_id ] ?? array(), true );
	}

	/** @param int $post_id @return int */
	function learndash_get_course_id( $post_id ) {
		return (int) ( $GLOBALS['falnic_course_of'][ (int) $post_id ] ?? 0 );
	}

	/** @param int $lesson @param int $user @param int $course @return bool */
	function learndash_is_lesson_not_available( $lesson, $user = 0, $course = 0 ) {
		return ! empty( $GLOBALS['falnic_locked'][ (int) $lesson ] );
	}

	/** @param int $post_id @return array */
	function learndash_get_setting( $post_id ) {
		return array();
	}
}

require_once FALNIC_DIR . 'includes/class-storage.php';
require_once FALNIC_DIR . 'includes/class-token.php';
require_once FALNIC_DIR . 'includes/class-access.php';
require_once FALNIC_DIR . 'includes/class-stream.php';

/* -------------------------------------------------------------------------
 * ابزار
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
function falnic_check( $ok, $label, $note = '' ) {
	if ( $ok ) {
		$GLOBALS['falnic_pass']++;
		echo "PASS  {$label}\n";
		return;
	}

	$GLOBALS['falnic_fail']++;
	echo "FAIL  {$label}" . ( $note ? " :: {$note}" : '' ) . "\n";
}

/**
 * ساخت ویدیوی آزمایشی.
 *
 * @param string $name        نام.
 * @param string $relative    مسیر نسبی.
 * @param string $content     محتوا.
 * @param bool   $with_key    کلید ساخته شود.
 * @return void
 */
function falnic_make_video( $name, $relative = '00001.ts', $content = 'SEGMENT', $with_key = true ) {
	$dir = Falnic\SecureHLS\Storage::videos_dir() . $name . '/';

	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}

	file_put_contents( $dir . $name . '.m3u8', "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"k\"\n#EXTINF:10,\n00001.ts\n" );
	file_put_contents( $dir . $relative, $content );

	if ( $with_key ) {
		$key_dir = Falnic\SecureHLS\Storage::secure_dir() . $name . '/';

		if ( ! is_dir( $key_dir ) ) {
			mkdir( $key_dir, 0777, true );
		}

		file_put_contents( $key_dir . $name . '.key', str_repeat( 'K', 16 ) );
	}
}

/**
 * حذف بازگشتی.
 *
 * @param string $dir پوشه.
 * @return void
 */
function falnic_rmtree( $dir ) {
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

// وردپرس معمولاً پوشه‌ی uploads را از قبل دارد.
mkdir( $GLOBALS['falnic_tmp'] . '/uploads', 0777, true );

Falnic\SecureHLS\Storage::ensure_dirs();

echo "\n=== Falnic access & stream tests ===\n\n";
echo 'LearnDash stubs: ' . ( 'off' === getenv( 'FALNIC_TEST_LD' ) ? 'OFF' : 'ON' ) . "\n\n";

/* -------------------------------------------------------------------------
 * ۱) اعتبارسنجی نام و مسیر
 * ---------------------------------------------------------------------- */
falnic_check( Falnic\SecureHLS\Storage::is_valid_name( 'lesson-108' ), 'نام ویدیو معتبر پذیرفته می‌شود' );
falnic_check( ! Falnic\SecureHLS\Storage::is_valid_name( '../etc/passwd' ), 'نام با traversal رد می‌شود' );
falnic_check( ! Falnic\SecureHLS\Storage::is_valid_name( 'a/b' ), 'نام با اسلش رد می‌شود' );
falnic_check( ! Falnic\SecureHLS\Storage::is_valid_name( '' ), 'نام خالی رد می‌شود' );

/* -------------------------------------------------------------------------
 * ۲) توکن
 * ---------------------------------------------------------------------- */
$GLOBALS['falnic_current'] = 7;

$token = Falnic\SecureHLS\Token::issue( Falnic\SecureHLS\Token::SCOPE_MANIFEST, 'lesson-108' );
$claims = Falnic\SecureHLS\Token::verify( $token, Falnic\SecureHLS\Token::SCOPE_MANIFEST, 'lesson-108' );

falnic_check( is_array( $claims ) && 'lesson-108' === $claims['vid'], 'توکن معتبر تأیید می‌شود' );
falnic_check( is_array( $claims ) && 7 === $claims['uid'], 'توکن به کاربر جاری گره می‌خورد' );

$other = Falnic\SecureHLS\Token::verify( $token, Falnic\SecureHLS\Token::SCOPE_KEY, 'lesson-108' );
falnic_check( is_wp_error( $other ) && 'falnic_token_scope' === $other->get_error_code(), 'توکن با scope اشتباه رد می‌شود' );

$other = Falnic\SecureHLS\Token::verify( $token, Falnic\SecureHLS\Token::SCOPE_MANIFEST, 'lesson-109' );
falnic_check( is_wp_error( $other ) && 'falnic_token_video' === $other->get_error_code(), 'توکن برای ویدیوی دیگر رد می‌شود' );

$parts    = explode( '.', $token );
$parts[1] = rtrim( strtr( base64_encode( '{"sc":"manifest","vid":"lesson-108","uid":7,"exp":9999999999}' ), '+/', '-_' ), '=' );
$tampered = implode( '.', $parts );
$other    = Falnic\SecureHLS\Token::verify( $tampered, Falnic\SecureHLS\Token::SCOPE_MANIFEST, 'lesson-108' );
falnic_check( is_wp_error( $other ) && 'falnic_token_signature' === $other->get_error_code(), 'دستکاری payload کشف می‌شود' );

$expired = Falnic\SecureHLS\Token::issue( Falnic\SecureHLS\Token::SCOPE_MANIFEST, 'lesson-108', array(), -120 );
$other   = Falnic\SecureHLS\Token::verify( $expired, Falnic\SecureHLS\Token::SCOPE_MANIFEST, 'lesson-108' );
falnic_check( is_wp_error( $other ) && 'falnic_token_expired' === $other->get_error_code(), 'توکن منقضی رد می‌شود' );

$GLOBALS['falnic_current'] = 8;
$other = Falnic\SecureHLS\Token::verify( $token, Falnic\SecureHLS\Token::SCOPE_MANIFEST, 'lesson-108' );
falnic_check( is_wp_error( $other ) && 'falnic_token_user' === $other->get_error_code(), 'توکن در مرورگر کاربر دیگر کار نمی‌کند' );
$GLOBALS['falnic_current'] = 7;

falnic_check( is_wp_error( Falnic\SecureHLS\Token::verify( '', Falnic\SecureHLS\Token::SCOPE_KEY, 'x' ) ), 'توکن خالی رد می‌شود' );
falnic_check( is_wp_error( Falnic\SecureHLS\Token::verify( 'garbage', Falnic\SecureHLS\Token::SCOPE_KEY, 'x' ) ), 'توکن بی‌قالب رد می‌شود' );

/* -------------------------------------------------------------------------
 * ۳) دسترسی
 * ---------------------------------------------------------------------- */
falnic_make_video( 'lesson-108' );

$GLOBALS['falnic_links']['lesson-108'] = array( 501 );
$GLOBALS['falnic_course_of'][501]     = 900;

// مهمان
$GLOBALS['falnic_current'] = 0;
falnic_check( 'guest' === Falnic\SecureHLS\Access::reason( 'lesson-108' ), 'مهمان رد می‌شود' );
falnic_check( ! Falnic\SecureHLS\Access::can_watch( 'lesson-108' ), 'can_watch برای مهمان false است' );

// کاربر بدون ثبت‌نام
$GLOBALS['falnic_current'] = 7;
$GLOBALS['falnic_caps']    = array();
$GLOBALS['falnic_enrolled'] = array();
falnic_check( 'not_enrolled' === Falnic\SecureHLS\Access::reason( 'lesson-108' ), 'کاربر بدون ثبت‌نام رد می‌شود' );

// دانشجوی ثبت‌نام‌کرده
$GLOBALS['falnic_enrolled'][900] = array( 7 );
Falnic\SecureHLS\Access::flush();
falnic_check( 'ok' === Falnic\SecureHLS\Access::reason( 'lesson-108' ), 'دانشجوی ثبت‌نام‌کرده مجاز است' );
falnic_check( Falnic\SecureHLS\Access::can_watch( 'lesson-108' ), 'can_watch برای دانشجو true است' );

// Drip
$GLOBALS['falnic_locked'][501] = true;
Falnic\SecureHLS\Access::flush();
falnic_check( 'locked' === Falnic\SecureHLS\Access::reason( 'lesson-108' ), 'درس بازنشده (Drip) مسدود است' );
$GLOBALS['falnic_locked'] = array();
Falnic\SecureHLS\Access::flush();

// دانشجوی کاربر دیگر
$GLOBALS['falnic_current'] = 8;
falnic_check( 'not_enrolled' === Falnic\SecureHLS\Access::reason( 'lesson-108' ), 'کاربر دیگر دسترسی ندارد' );
$GLOBALS['falnic_current'] = 7;

// مدیر
$GLOBALS['falnic_caps'][7] = array( 'manage_options' );
Falnic\SecureHLS\Access::flush();
falnic_check( 'ok' === Falnic\SecureHLS\Access::reason( 'lesson-108' ), 'مدیر همیشه دسترسی دارد' );
$GLOBALS['falnic_caps'] = array();
Falnic\SecureHLS\Access::flush();

// ویدیوی بدون اتصال به درس
falnic_make_video( 'free-video' );
falnic_check( 'not_linked' === Falnic\SecureHLS\Access::reason( 'free-video' ), 'ویدیوی بدون دوره برای کاربر عادی مسدود است' );

$GLOBALS['falnic_caps'][7] = array( 'falnic_watch_videos' );
Falnic\SecureHLS\Access::flush();
falnic_check( 'ok' === Falnic\SecureHLS\Access::reason( 'free-video' ), 'قابلیت falnic_watch_videos برای ویدیوی آزاد کار می‌کند' );
$GLOBALS['falnic_caps'] = array();

update_option( 'falnic_ld_require_enrollment', 'no' );
Falnic\SecureHLS\Access::flush();
falnic_check( 'ok' === Falnic\SecureHLS\Access::reason( 'free-video' ), 'حالت غیرالزامی، کاربر لاگین را می‌پذیرد' );
update_option( 'falnic_ld_require_enrollment', 'yes' );
Falnic\SecureHLS\Access::flush();

// ویدیوی انکودنشده
falnic_check( 'not_ready' === Falnic\SecureHLS\Access::reason( 'not-encoded' ), 'ویدیوی انکودنشده رد می‌شود' );
falnic_check( 'invalid' === Falnic\SecureHLS\Access::reason( '../x' ), 'نام نامعتبر رد می‌شود' );

// دوره‌های مرتبط برای پیام
$courses = Falnic\SecureHLS\Access::courses_for( 'lesson-108' );
falnic_check( 1 === count( $courses ) && 900 === $courses[0]['id'], 'دوره‌ی مرتبط برای نمایش لینک پیدا می‌شود' );

// پیام‌ها
falnic_check( '' !== Falnic\SecureHLS\Access::message( 'not_enrolled' ), 'پیام عدم ثبت‌نام موجود است' );
falnic_check( '' !== Falnic\SecureHLS\Access::message( 'guest' ), 'پیام مهمان موجود است' );

/* -------------------------------------------------------------------------
 * ۴) بازنویسی manifest
 * ---------------------------------------------------------------------- */
$key_line = Falnic\SecureHLS\Stream::rewrite_line( '#EXT-X-KEY:METHOD=AES-128,URI="https://example.test/old.key"', 'lesson-108' );
falnic_check( false !== strpos( $key_line, 'action=' . Falnic\SecureHLS\Stream::ACTION_KEY ), 'URI کلید به دروازه بازنویسی می‌شود' );
falnic_check( false === strpos( $key_line, 'old.key' ), 'مسیر اصلی کلید در manifest نمی‌ماند' );
falnic_check( false !== strpos( $key_line, 't=' ), 'URI کلید توکن دارد' );

$segment_line = Falnic\SecureHLS\Stream::rewrite_line( '00001.ts', 'lesson-108' );
falnic_check( false !== strpos( $segment_line, 'action=' . Falnic\SecureHLS\Stream::ACTION_SEGMENT ), 'سگمنت به دروازه بازنویسی می‌شود' );
falnic_check( false === strpos( $segment_line, 'secure_videos' ), 'مسیر فیزیکی در manifest نیست' );
falnic_check( false !== strpos( $segment_line, 'f=00001.ts' ), 'نام فایل سگمنت در URL هست' );

$nested = Falnic\SecureHLS\Stream::rewrite_line( '720p/00001.ts', 'lesson-108' );
falnic_check( false !== strpos( $nested, 'f=720p%2F00001.ts' ), 'مسیر تودرتو یک‌بار کدگذاری می‌شود (بدون خرابی)', $nested );

$comment = Falnic\SecureHLS\Stream::rewrite_line( '#EXT-X-VERSION:3', 'lesson-108' );
falnic_check( '#EXT-X-VERSION:3' === $comment, 'خطوط دستور m3u8 دست‌نخورده می‌مانند' );

$map = Falnic\SecureHLS\Stream::rewrite_line( '#EXT-X-MAP:URI="init.mp4"', 'lesson-108' );
falnic_check( false !== strpos( $map, 'action=' . Falnic\SecureHLS\Stream::ACTION_SEGMENT ) && false !== strpos( $map, 'init.mp4' ), 'EXT-X-MAP هم بازنویسی می‌شود' );

falnic_check( '' === Falnic\SecureHLS\Stream::rewrite_line( '', 'lesson-108' ), 'خط خالی حفظ می‌شود' );

/* -------------------------------------------------------------------------
 * ۵) دسترسی فایل
 * ---------------------------------------------------------------------- */
$resolved = Falnic\SecureHLS\Storage::resolve_file( 'lesson-108', '00001.ts' );
falnic_check( is_string( $resolved ) && is_readable( $resolved ), 'فایل سگمنت معتبر resolve می‌شود' );

falnic_check( null === Falnic\SecureHLS\Storage::resolve_file( 'lesson-108', '../../wp-config.php' ), 'traversal در سگمنت رد می‌شود' );
falnic_check( null === Falnic\SecureHLS\Storage::resolve_file( 'lesson-108', '/etc/passwd' ), 'مسیر مطلق رد می‌شود' );
falnic_check( null === Falnic\SecureHLS\Storage::resolve_file( 'lesson-108', 'x.php' ), 'پسوند غیرمجاز رد می‌شود' );
falnic_check( null === Falnic\SecureHLS\Storage::resolve_file( '../x', '00001.ts' ), 'نام ویدیوی نامعتبر رد می‌شود' );
falnic_check( null === Falnic\SecureHLS\Storage::resolve_file( 'lesson-108', "\0x.ts" ), 'null byte رد می‌شود' );

// symlink به بیرون از پوشه
$outside = $GLOBALS['falnic_tmp'] . '/outside.txt';
file_put_contents( $outside, 'secret' );
@symlink( $outside, Falnic\SecureHLS\Storage::videos_dir() . 'lesson-108/link.ts' );
falnic_check( null === Falnic\SecureHLS\Storage::resolve_file( 'lesson-108', 'link.ts' ), 'symlink به بیرون رد می‌شود' );

// محافظت پوشه
falnic_check( file_exists( Falnic\SecureHLS\Storage::root() . '.htaccess' ), 'فایل htaccess محافظ ساخته می‌شود' );
$htaccess = (string) file_get_contents( Falnic\SecureHLS\Storage::root() . '.htaccess' );
falnic_check( false !== strpos( $htaccess, 'Require all denied' ), 'دسترسی مستقیم در htaccess بسته است' );
falnic_check( file_exists( Falnic\SecureHLS\Storage::legacy_videos_dir() . '.htaccess' ), 'پوشه‌ی قدیمی uploads هم بسته می‌شود' );

// مهاجرت
$legacy = Falnic\SecureHLS\Storage::legacy_videos_dir() . 'old-video/';
mkdir( $legacy, 0777, true );
file_put_contents( $legacy . 'old-video.m3u8', "#EXTM3U\n" );
$key_legacy = Falnic\SecureHLS\Storage::legacy_keys_dir() . 'old-video/';
mkdir( $key_legacy, 0777, true );
file_put_contents( $key_legacy . 'old-video.key', str_repeat( 'L', 16 ) );

falnic_check( in_array( 'old-video', Falnic\SecureHLS\Storage::legacy_pending(), true ), 'ویدیوی قدیمی شناسایی می‌شود' );
falnic_check( Falnic\SecureHLS\Storage::migrate_video( 'old-video' ), 'مهاجرت ویدیوی قدیمی موفق است' );
falnic_check( is_readable( Falnic\SecureHLS\Storage::videos_dir() . 'old-video/old-video.m3u8' ), 'فایل به پوشه‌ی امن منتقل شد' );
falnic_check( ! is_dir( $legacy ), 'پوشه‌ی قدیمی پس از مهاجرت حذف می‌شود' );
falnic_check( Falnic\SecureHLS\Storage::is_encoded( 'old-video' ), 'ویدیوی مهاجرت‌شده قابل پخش است' );

/* -------------------------------------------------------------------------
 * ۶) مسیر امن
 * ---------------------------------------------------------------------- */
falnic_check( Falnic\SecureHLS\Storage::is_outside_webroot(), 'ریشه‌ی رسانه بیرون از web root است' );
falnic_check( 0 === strpos( Falnic\SecureHLS\Storage::root(), dirname( ABSPATH ) ), 'ریشه در کنار wp-content ساخته می‌شود' );

/* -------------------------------------------------------------------------
 * ۷) نام پایدار ویدیو
 * ---------------------------------------------------------------------- */
falnic_check( 'lesson-108' === Falnic\SecureHLS\Storage::sanitize_name( 'Lesson-108' ), 'عنوان انگلیسی به شناسه‌ی امن تبدیل می‌شود' );
falnic_check( '' === Falnic\SecureHLS\Storage::sanitize_name( 'درس ۱۰۸' ), 'عنوان فارسی شناسه‌ی خودکار نمی‌سازد (متا لازم است)' );

$unsafe = Falnic\SecureHLS\Storage::sanitize_name( '../etc/passwd' );
falnic_check( false === strpos( $unsafe, '/' ) && false === strpos( $unsafe, '..' ), 'شناسه‌ی مخرب پاک‌سازی می‌شود', (string) $unsafe );

/* -------------------------------------------------------------------------
 * پاک‌سازی
 * ---------------------------------------------------------------------- */
falnic_rmtree( $GLOBALS['falnic_tmp'] );
falnic_rmtree( Falnic\SecureHLS\Storage::root() );

echo "\nنتیجه: {$GLOBALS['falnic_pass']} موفق، {$GLOBALS['falnic_fail']} ناموفق\n";

exit( $GLOBALS['falnic_fail'] > 0 ? 1 : 0 );
