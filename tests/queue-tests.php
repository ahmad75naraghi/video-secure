<?php
/**
 * تست‌های صف انکود، رجیستری پخش، گزارش تماشا و بازنویسی manifest.
 *
 * اجرا:
 *   php tests/queue-tests.php
 *
 * @package Falnic\SecureHLS
 */

$falnic_root = dirname( __DIR__ );

$GLOBALS['falnic_tmp'] = sys_get_temp_dir() . '/falnic-queue-' . bin2hex( random_bytes( 4 ) );

define( 'ABSPATH', $GLOBALS['falnic_tmp'] . '/wp/' );
define( 'FALNIC_DIR', $falnic_root . '/' );
define( 'FALNIC_VERSION', '3.4.0' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );

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

$GLOBALS['falnic_options']    = array();
$GLOBALS['falnic_transients'] = array();
$GLOBALS['falnic_current']    = 7;
$GLOBALS['falnic_admin']      = array();
$GLOBALS['falnic_scheduled']  = array();
$GLOBALS['falnic_pass']       = 0;
$GLOBALS['falnic_fail']       = 0;

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
	return 'queue-test-secret-' . md5( (string) $salt ) . '-abcdef0123456789';
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

/** @param string $key @param mixed $value @return bool */
function add_option( $key, $value ) { // phpcs:ignore
	if ( array_key_exists( $key, $GLOBALS['falnic_options'] ) ) {
		return false;
	}

	$GLOBALS['falnic_options'][ $key ] = $value;

	return true;
}

/** @param string $key @return bool */
function delete_option( $key ) {
	unset( $GLOBALS['falnic_options'][ $key ] );

	return true;
}

/** @param string $key @param mixed $default @return mixed */
function get_transient( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['falnic_transients'] ) ? $GLOBALS['falnic_transients'][ $key ] : $default;
}

/** @param string $key @param mixed $value @param int $ttl @return bool */
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['falnic_transients'][ $key ] = $value;

	return true;
}

/** @param string $key @return bool */
function delete_transient( $key ) {
	unset( $GLOBALS['falnic_transients'][ $key ] );

	return true;
}

/** @return int */
function get_current_user_id() {
	return (int) $GLOBALS['falnic_current'];
}

/** @return bool */
function is_user_logged_in() {
	return get_current_user_id() > 0;
}

/** @param int    $uid @param string $cap @param mixed $args @return bool */
function user_can( $uid, $cap, $args = null ) { // phpcs:ignore
	if ( 'manage_options' === $cap ) {
		return ! empty( $GLOBALS['falnic_admin'][ (int) $uid ] );
	}

	return true;
}

/** @param string $cap @param mixed $args @return bool */
function current_user_can( $cap, $args = null ) { // phpcs:ignore
	return user_can( get_current_user_id(), $cap, $args );
}

/** @param array $defaults @param array $args @return array */
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( (array) $defaults, (array) $args );
}

/** @param string $salt @return string */
function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return substr( str_repeat( 'abcdefghijklmnopqrstuvwxyz0123456789', 3 ), 0, (int) $length );
}

/** @param string $path @return bool */
function wp_mkdir_p( $path ) {
	return is_dir( $path ) || mkdir( $path, 0777, true );
}

/** @param string $path @return string */
function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

/** @param string $path @return bool */
function wp_is_writable( $path ) {
	return is_writable( $path );
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

/** @param string $path @return bool */
function is_multisite() {
	return false;
}

/** @return int */
function get_current_blog_id() {
	return 1;
}

/** @return array */
function wp_upload_dir() {
	return array(
		'basedir' => $GLOBALS['falnic_tmp'] . '/uploads',
		'baseurl' => 'https://example.test/uploads',
	);
}

/** @param string $path @return string */
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

/** @param string $path @return string */
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

/** @param string $path @return string */
function site_url( $path = '' ) {
	return 'https://example.test/' . ltrim( (string) $path, '/' );
}

/** @param int $blog_id @return string */
function get_site_url( $blog_id = 0 ) {
	return 'https://example.test';
}

/** @param array  $args @param string $url @return string */
function add_query_arg( $args, $url = '' ) { // phpcs:ignore
	$query = array();

	foreach ( (array) $args as $key => $value ) {
		$query[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	}

	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . implode( '&', $query );
}

/** @param string $text @return string */
function esc_html__( $text, $domain = null ) { // phpcs:ignore
	return esc_html( $text );
}

/** @param string $tag @param mixed $value @return mixed */
function apply_filters( $tag, $value ) {
	return $value;
}

/** @param string $hook @param string $recurrence @param array $args @return bool */
function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) { // phpcs:ignore
	$GLOBALS['falnic_scheduled'][] = array(
		'hook' => (string) $hook,
		'args' => (array) $args,
	);

	return true;
}

/** @param string $hook @param array  $args @return bool */
function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$GLOBALS['falnic_scheduled'] = array_values(
		array_filter(
			$GLOBALS['falnic_scheduled'],
			static function ( $item ) use ( $hook ) {
				return $item['hook'] !== $hook;
			}
		)
	);

	return true;
}

/** @param string $hook @param array  $args @return bool */
function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['falnic_scheduled'] as $item ) {
		if ( $item['hook'] === $hook ) {
			return time() + 60;
		}
	}

	return false;
}

/** @param int    $timestamp @param string $recurrence @param string $hook @return bool */
function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	$GLOBALS['falnic_scheduled'][] = array(
		'hook' => (string) $hook,
		'args' => array(),
	);

	return true;
}

/** @param string $key @param mixed $data @param string $group @param int $expire @return bool */
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

/** @param string $type @return void */
function status_header( $code ) { // phpcs:ignore
	$GLOBALS['falnic_status'] = (int) $code;
}

/** @param array $headers @return void */
function nocache_headers( $headers = array() ) { // phpcs:ignore
}

/** @param string $path @return string */
function wp_login_url( $redirect = '' ) { // phpcs:ignore
	return 'https://example.test/wp-login.php?redirect_to=' . rawurlencode( (string) $redirect );
}

/** @return string|false */
function wp_get_referer() {
	return 'https://example.test/course/';
}

/** @param int $post_id @return string */
function get_the_title( $post_id ) {
	return 'Lesson ' . (int) $post_id;
}

/** @param int $post_id @return string */
function get_permalink( $post_id ) {
	return 'https://example.test/?p=' . (int) $post_id;
}

/** @param mixed $data @param int $flags @return string */
function wp_json_encode( $data, $flags = 0 ) {
	return (string) json_encode( $data, $flags );
}

require_once FALNIC_DIR . 'includes/class-storage.php';
require_once FALNIC_DIR . 'includes/class-token.php';
require_once FALNIC_DIR . 'includes/class-play-registry.php';
require_once FALNIC_DIR . 'includes/class-watch-log.php';
require_once FALNIC_DIR . 'includes/class-encoder.php';
require_once FALNIC_DIR . 'includes/class-encode-queue.php';
require_once FALNIC_DIR . 'includes/class-stream.php';

/* -------------------------------------------------------------------------
 * ابزار
 * ---------------------------------------------------------------------- */

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

/**
 * پیدا کردن آرگومان بعد از یک سوییچ در فرمان.
 *
 * @param array  $command فرمان.
 * @param string $flag    سوییچ.
 * @return string
 */
function falnic_flag_value( array $command, string $flag ): string {
	$index = array_search( $flag, $command, true );

	return ( false !== $index && isset( $command[ $index + 1 ] ) ) ? (string) $command[ $index + 1 ] : '';
}

mkdir( $GLOBALS['falnic_tmp'] . '/uploads', 0777, true );
mkdir( ABSPATH, 0777, true );

Falnic\SecureHLS\Storage::ensure_dirs();

echo "\n=== Falnic queue, registry, log & manifest tests ===\n\n";

/* -------------------------------------------------------------------------
 * ۱) کیفیت‌ها و فرمان ffmpeg
 * ---------------------------------------------------------------------- */
$GLOBALS['falnic_options']['falnic_hls_quality']        = 'auto';
$GLOBALS['falnic_options']['falnic_hls_max_renditions'] = 3;
$GLOBALS['falnic_options']['falnic_hls_segment_seconds'] = 6;

$variants = Falnic\SecureHLS\Encoder::variants( array( 'height' => 1080, 'audio' => true ) );
falnic_check( 3 === count( $variants ), 'سقف سه کیفیت رعایت می‌شود', (string) count( $variants ) );
falnic_check( '1080p' === $variants[0]['name'] && 1080 === $variants[0]['height'], 'بالاترین کیفیت اول فهرست است' );
falnic_check( '720p' === $variants[1]['name'], 'کیفیت دوم ۷۲۰p است' );
falnic_check( $variants[0]['kbps'] > $variants[1]['kbps'], 'نرخ بیت نزولی است' );

$GLOBALS['falnic_options']['falnic_hls_max_renditions'] = 2;
falnic_check( 2 === count( Falnic\SecureHLS\Encoder::variants( array( 'height' => 1080 ) ) ), 'کاهش سقف کیفیت اعمال می‌شود' );

$low = Falnic\SecureHLS\Encoder::variants( array( 'height' => 360 ) );
falnic_check( 1 === count( $low ) && 360 === $low[0]['height'], 'منبع کم‌کیفیت کیفیت بالاتر از خود نمی‌سازد' );

$GLOBALS['falnic_options']['falnic_hls_quality'] = 'single';
$single = Falnic\SecureHLS\Encoder::variants( array( 'height' => 900 ) );
falnic_check( 1 === count( $single ) && '' === $single[0]['name'], 'حالت تک‌کیفیت فقط یک خروجی می‌سازد' );
falnic_check( 720 === $single[0]['height'], 'تک‌کیفیت به نزدیک‌ترین پله‌ی پایین‌تر گرد می‌شود' );
$GLOBALS['falnic_options']['falnic_hls_quality'] = 'auto';

$video_dir = Falnic\SecureHLS\Storage::video_dir( 'lesson-1' );
$command   = Falnic\SecureHLS\Encoder::build_command(
	'/tmp/in.mp4',
	'lesson-1',
	array(
		array( 'height' => 720, 'kbps' => 2800, 'name' => '720p' ),
		array( 'height' => 480, 'kbps' => 1400, 'name' => '480p' ),
	),
	'/tmp/key.info',
	$video_dir,
	true
);

falnic_check( is_array( $command ) && $command === array_values( $command ), 'فرمان به‌صورت آرایه (بدون shell) ساخته می‌شود' );
falnic_check( isset( $command[0] ) && is_string( $command[0] ), 'عنصر اول فرمان رشته است' );

$all_strings = true;

foreach ( $command as $arg ) {
	if ( ! is_string( $arg ) ) {
		$all_strings = false;
	}
}

falnic_check( $all_strings, 'همه‌ی آرگومان‌ها رشته‌ی مستقل هستند' );
falnic_check( false !== array_search( '-progress', $command, true ), 'گزینه‌ی -progress برای درصد پیشرفت هست' );
falnic_check( 'pipe:1' === falnic_flag_value( $command, '-progress' ), 'پیشرفت روی stdout خوانده می‌شود' );
falnic_check( '/tmp/key.info' === falnic_flag_value( $command, '-hls_key_info_file' ), 'فایل کلید به ffmpeg داده می‌شود' );
falnic_check( '6' === falnic_flag_value( $command, '-hls_time' ), 'طول سگمنت از تنظیمات خوانده می‌شود', falnic_flag_value( $command, '-hls_time' ) );
falnic_check( false !== strpos( falnic_flag_value( $command, '-var_stream_map' ), 'name:720p' ), 'نقشه‌ی کیفیت نام پوشه را تعیین می‌کند' );
falnic_check( false !== strpos( falnic_flag_value( $command, '-hls_segment_filename' ), '%05d.ts' ), 'الگوی نام سگمنت ثابت است' );
falnic_check( '/tmp/key.info' === falnic_flag_value( $command, '-hls_key_info_file' ), 'رمزنگاری HLS از فایل keyinfo تغذیه می‌شود' );
falnic_check( false !== strpos( implode( ' ', $command ), '%v/segment_%05d.ts' ), 'سگمنت‌ها در پوشه‌ی هر کیفیت نوشته می‌شوند' );

$no_audio = Falnic\SecureHLS\Encoder::build_command( '/tmp/in.mp4', 'lesson-1', array( array( 'height' => 480, 'kbps' => 1400, 'name' => '480p' ) ), '/tmp/k', $video_dir, false );
falnic_check( ! in_array( '0:a:0', $no_audio, true ), 'ویدیوی بدون صدا نگاشت صوتی نمی‌گیرد' );

/* -------------------------------------------------------------------------
 * ۲) درصد پیشرفت
 * ---------------------------------------------------------------------- */
$progress = "frame=120\nout_time_us=30000000\nprogress=continue\nout_time_us=60000000\n";
falnic_check( 50 === Falnic\SecureHLS\Encoder::percent_from_progress( $progress, 120 ), 'درصد از out_time_us محاسبه می‌شود', (string) Falnic\SecureHLS\Encoder::percent_from_progress( $progress, 120 ) );
falnic_check( 100 === Falnic\SecureHLS\Encoder::percent_from_progress( "out_time_us=999999999\n", 120 ), 'درصد از ۱۰۰ بیشتر نمی‌شود' );
falnic_check( 0 === Falnic\SecureHLS\Encoder::percent_from_progress( "noise\n", 120 ), 'بدون داده‌ی زمانی صفر برمی‌گردد' );
falnic_check( 0 === Falnic\SecureHLS\Encoder::percent_from_progress( "out_time_us=1000000\n", 0 ), 'مدت نامعلوم باعث تقسیم بر صفر نمی‌شود' );

/* -------------------------------------------------------------------------
 * ۳) صف انکود
 * ---------------------------------------------------------------------- */
$source = $GLOBALS['falnic_tmp'] . '/upload.mp4';
file_put_contents( $source, str_repeat( 'V', 32 ) );

$bad = Falnic\SecureHLS\Encode_Queue::enqueue( '../evil', $source );
falnic_check( empty( $bad['ok'] ) && 'invalid' === $bad['state'], 'نام نامعتبر وارد صف نمی‌شود' );

$missing = Falnic\SecureHLS\Encode_Queue::enqueue( 'lesson-1', $GLOBALS['falnic_tmp'] . '/nope.mp4' );
falnic_check( empty( $missing['ok'] ) && 'missing' === $missing['state'], 'فایل ناموجود رد می‌شود' );

$queued = Falnic\SecureHLS\Encode_Queue::enqueue( 'lesson-1', $source, 44, 7 );
falnic_check( ! empty( $queued['ok'] ), 'صف‌کردن ویدیو موفق است', (string) ( $queued['message'] ?? '' ) );
falnic_check( 'queued' === Falnic\SecureHLS\Encode_Queue::status( 'lesson-1' )['state'], 'وضعیت اولیه «در صف» است' );
falnic_check( ! file_exists( $source ), 'فایل آپلود به پوشه‌ی موقت منتقل شد' );
falnic_check( is_readable( (string) Falnic\SecureHLS\Encode_Queue::status( 'lesson-1' )['source'] ), 'مسیر فایل موقت در وضعیت ذخیره شده است' );
falnic_check( 0 === Falnic\SecureHLS\Encode_Queue::status( 'lesson-1' )['percent'], 'درصد اولیه صفر است' );
falnic_check( 44 === Falnic\SecureHLS\Encode_Queue::status( 'lesson-1' )['post_id'], 'شناسه‌ی پست ثبت می‌شود' );
falnic_check( true === Falnic\SecureHLS\Encode_Queue::is_active( 'lesson-1' ), 'کار در صف «فعال» است' );

$hooks = array_column( $GLOBALS['falnic_scheduled'], 'hook' );
falnic_check( in_array( Falnic\SecureHLS\Encode_Queue::CRON_HOOK, $hooks, true ), 'رویداد WP-Cron برای اجرا زمان‌بندی شد' );

file_put_contents( $GLOBALS['falnic_tmp'] . '/upload2.mp4', str_repeat( 'W', 8 ) );
$duplicate = Falnic\SecureHLS\Encode_Queue::enqueue( 'lesson-1', $GLOBALS['falnic_tmp'] . '/upload2.mp4', 44, 7 );
falnic_check( empty( $duplicate['ok'] ) && 'queued' === $duplicate['state'], 'ذخیره‌ی دوباره کار تکراری نمی‌سازد' );

$jobs = get_option( Falnic\SecureHLS\Encode_Queue::JOBS_OPTION, array() );
falnic_check( 1 === count( $jobs ), 'فقط یک کار برای ویدیو ثبت شده است', (string) count( $jobs ) );

$temp_file = (string) Falnic\SecureHLS\Encode_Queue::status( 'lesson-1' )['source'];
falnic_check( Falnic\SecureHLS\Encode_Queue::cancel( 'lesson-1' ), 'لغو کار در صف موفق است' );
falnic_check( 'canceled' === Falnic\SecureHLS\Encode_Queue::status( 'lesson-1' )['state'], 'وضعیت پس از لغو «لغو‌شده» است' );
falnic_check( ! file_exists( $temp_file ), 'فایل موقت کار لغوشده حذف می‌شود' );
falnic_check( ! Falnic\SecureHLS\Encode_Queue::cancel( 'lesson-1' ), 'لغو دوباره‌ی کار تمام‌شده رد می‌شود' );

$retry = Falnic\SecureHLS\Encode_Queue::retry( 'lesson-1' );
falnic_check( empty( $retry['ok'] ), 'تلاش دوباره بدون فایل ورودی رد می‌شود' );

$retry_source = $GLOBALS['falnic_tmp'] . '/retry.mp4';
file_put_contents( $retry_source, str_repeat( 'R', 16 ) );
$GLOBALS['falnic_options'][ Falnic\SecureHLS\Encode_Queue::JOBS_OPTION ]['lesson-1']['source'] = $retry_source;
$retry = Falnic\SecureHLS\Encode_Queue::retry( 'lesson-1' );
falnic_check( ! empty( $retry['ok'] ) && 'queued' === Falnic\SecureHLS\Encode_Queue::status( 'lesson-1' )['state'], 'تلاش دوباره با فایل موجود موفق است' );
falnic_check( 0 === Falnic\SecureHLS\Encode_Queue::status( 'lesson-1' )['percent'], 'تلاش دوباره درصد را صفر می‌کند' );

// لغو کارِ در حال اجرا: وضعیت تا پایان ffmpeg «running» می‌ماند ولی پیام عوض می‌شود.
$GLOBALS['falnic_options'][ Falnic\SecureHLS\Encode_Queue::JOBS_OPTION ]['lesson-1']['state'] = 'running';
falnic_check( Falnic\SecureHLS\Encode_Queue::cancel( 'lesson-1' ), 'لغو کار در حال اجرا پذیرفته می‌شود' );
falnic_check( 'running' === Falnic\SecureHLS\Encode_Queue::status( 'lesson-1' )['state'], 'تا پایان ffmpeg وضعیت running می‌ماند' );
falnic_check( false !== strpos( Falnic\SecureHLS\Encode_Queue::status( 'lesson-1' )['message'], 'لغو' ), 'پیام «در حال لغو» نمایش داده می‌شود' );

$GLOBALS['falnic_options'][ Falnic\SecureHLS\Encode_Queue::JOBS_OPTION ]['lesson-1']['state'] = 'canceled';

// حالت کپی برای فایل‌های روی سرور: مبدأ باید حفظ شود.
$server_file = $GLOBALS['falnic_tmp'] . '/server-file.mp4';
file_put_contents( $server_file, str_repeat( 'S', 8 ) );
$copied = Falnic\SecureHLS\Encode_Queue::enqueue( 'lesson-copy', $server_file, 0, 7, true );
falnic_check( ! empty( $copied['ok'] ), 'صف‌کردن فایل سرور در حالت کپی موفق است' );
falnic_check( file_exists( $server_file ), 'فایل اصلی سرور پس از صف‌کردن حذف نمی‌شود' );

$idle = Falnic\SecureHLS\Encode_Queue::status( 'lesson-unknown' );
falnic_check( 'idle' === $idle['state'] && 0 === $idle['percent'], 'ویدیوی بدون کار، وضعیت idle دارد' );

// پاک‌سازی فایل‌های کهنه‌ی پوشه‌ی موقت.
$stale = Falnic\SecureHLS\Encode_Queue::incoming_dir() . 'old-upload.mp4';
file_put_contents( $stale, 'x' );
touch( $stale, time() - ( 3 * DAY_IN_SECONDS ) );
$fresh = Falnic\SecureHLS\Encode_Queue::incoming_dir() . 'fresh-upload.mp4';
file_put_contents( $fresh, 'x' );
Falnic\SecureHLS\Encode_Queue::cleanup();
falnic_check( ! file_exists( $stale ), 'فایل موقت کهنه پاک می‌شود' );
falnic_check( file_exists( $fresh ), 'فایل موقت تازه دست‌نخورده می‌ماند' );

/* -------------------------------------------------------------------------
 * ۴) رجیستری پخش
 * ---------------------------------------------------------------------- */
$GLOBALS['falnic_options']['falnic_max_concurrent'] = 1;

$sid = Falnic\SecureHLS\Play_Registry::start( 7, 'lesson-1' );
falnic_check( is_string( $sid ) && 16 === strlen( $sid ), 'نشست پخش شناسه‌ی ۱۶ نویسه‌ای می‌گیرد', (string) $sid );
falnic_check( Falnic\SecureHLS\Play_Registry::check( 7, $sid ), 'نشست تازه معتبر است' );
falnic_check( true === Falnic\SecureHLS\Play_Registry::touch( 7, $sid ), 'ضربان نشست پذیرفته می‌شود' );
falnic_check( false === Falnic\SecureHLS\Play_Registry::touch( 7, 'deadbeefdeadbeef' ), 'ضربان نشست ناشناس رد می‌شود' );

$second = Falnic\SecureHLS\Play_Registry::start( 7, 'lesson-1' );
falnic_check( is_wp_error( $second ), 'پخش دوم هم‌زمان رد می‌شود' );
falnic_check( is_wp_error( $second ) && 'falnic_too_many_plays' === $second->get_error_code(), 'کد خطای پخش هم‌زمان درست است' );
falnic_check( is_wp_error( $second ) && 409 === ( $second->get_error_data()['status'] ?? 0 ), 'خطای پخش دوم وضعیت ۴۰۹ دارد' );

$GLOBALS['falnic_admin'][7] = true;
$admin_sid                  = Falnic\SecureHLS\Play_Registry::start( 7, 'lesson-1' );
falnic_check( is_string( $admin_sid ), 'مدیر سایت محدودیت پخش هم‌زمان ندارد' );
unset( $GLOBALS['falnic_admin'][7] );

falnic_check( 2 === Falnic\SecureHLS\Play_Registry::terminate_all( 7 ), 'قطع همه‌ی پخش‌ها هر دو نشست را می‌بندد', (string) Falnic\SecureHLS\Play_Registry::terminate_all( 7 ) );
falnic_check( ! Falnic\SecureHLS\Play_Registry::check( 7, $sid ), 'پس از قطع، توکن نشست نامعتبر است' );

$again = Falnic\SecureHLS\Play_Registry::start( 7, 'lesson-2' );
falnic_check( is_string( $again ), 'پس از قطع، پخش تازه مجاز است' );
falnic_check( false === Falnic\SecureHLS\Play_Registry::end( 7, 'nope' ), 'پایان دادن به نشست ناشناس false برمی‌گرداند' );
falnic_check( true === Falnic\SecureHLS\Play_Registry::end( 7, $again ), 'پایان دادن به نشست موجود موفق است' );

$GLOBALS['falnic_options']['falnic_max_concurrent'] = 0;
falnic_check( ! Falnic\SecureHLS\Play_Registry::enabled(), 'مقدار صفر محدودیت را خاموش می‌کند' );
falnic_check( true === Falnic\SecureHLS\Play_Registry::check( 7, 'anything' ), 'بدون محدودیت همه‌ی نشست‌ها معتبرند' );

$idle_sid = Falnic\SecureHLS\Play_Registry::start( 0, 'lesson-1' );
falnic_check( is_wp_error( $idle_sid ), 'مهمان نشست پخش نمی‌گیرد' );
$GLOBALS['falnic_options']['falnic_max_concurrent'] = 1;

// نشست بی‌فعالیت پس از TTL منقضی می‌شود.
$GLOBALS['falnic_transients']['falnic_plays_9'] = array(
	'abcdef0123456789' => array(
		'video' => 'lesson-3',
		'seen'  => time() - ( Falnic\SecureHLS\Play_Registry::TTL + 30 ),
	),
);
falnic_check( array() === Falnic\SecureHLS\Play_Registry::sessions( 9 ), 'نشست بی‌فعالیت پس از TTL حذف می‌شود' );

/* -------------------------------------------------------------------------
 * ۵) گزارش تماشا
 * ---------------------------------------------------------------------- */
$plain = Falnic\SecureHLS\Watch_Log::build_filters( array() );
falnic_check( '1=1' === $plain['sql'] && array() === $plain['params'], 'بدون فیلتر شرط خنثی است' );

$filtered = Falnic\SecureHLS\Watch_Log::build_filters(
	array(
		'user_id' => 12,
		'video'   => 'lesson-1',
		'days'    => 7,
	)
);
falnic_check( 3 === count( $filtered['params'] ), 'سه فیلتر سه پارامتر می‌سازد' );
falnic_check( 12 === $filtered['params'][0] && 'lesson-1' === $filtered['params'][1], 'ترتیب پارامترها درست است' );
falnic_check( false !== strpos( $filtered['sql'], 'started_at >= %s' ), 'فیلتر زمانی با placeholder امن است' );

$_SERVER['REMOTE_ADDR']     = '203.0.113.5';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)';
$hash_a                     = Falnic\SecureHLS\Watch_Log::ip_hash();
$hash_b                     = Falnic\SecureHLS\Watch_Log::ip_hash();

falnic_check( 32 === strlen( $hash_a ), 'هش IP سی‌ودو نویسه است' );
falnic_check( $hash_a === $hash_b, 'هش IP پایدار است' );
falnic_check( false === strpos( $hash_a, '203.0.113' ), 'IP خام در هش دیده نمی‌شود' );

$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
falnic_check( $hash_a !== Falnic\SecureHLS\Watch_Log::ip_hash(), 'IP متفاوت هش متفاوت می‌دهد' );
falnic_check( 'iPhone' === Falnic\SecureHLS\Watch_Log::agent_family(), 'دستگاه از User-Agent تشخیص داده می‌شود' );

$_SERVER['HTTP_USER_AGENT'] = 'curl/8.1';
falnic_check( __( 'سایر' ) === Falnic\SecureHLS\Watch_Log::agent_family(), 'User-Agent ناشناس برچسب «سایر» می‌گیرد' );

unset( $GLOBALS['falnic_options']['falnic_deny_stats'] );
Falnic\SecureHLS\Watch_Log::log_denial( 'not_enrolled', 'lesson-1' );
Falnic\SecureHLS\Watch_Log::log_denial( 'not_enrolled', 'lesson-1' );

$denied = Falnic\SecureHLS\Watch_Log::denials_today();
falnic_check( 1 === count( $denied ), 'ردهای یکسان در یک سطر تجمیع می‌شوند' );
falnic_check( 2 === $denied[0]['count'], 'شمارنده‌ی رد دسترسی افزایش می‌یابد' );
falnic_check( 'not_enrolled' === $denied[0]['reason'] && 'lesson-1' === $denied[0]['video'], 'دلیل و ویدیو ذخیره می‌شوند' );

$stats = get_option( 'falnic_deny_stats', array() );
falnic_check( ! isset( $stats[ gmdate( 'Y-m-d', time() - ( 20 * DAY_IN_SECONDS ) ) ] ), 'آمار قدیمی‌تر از ۱۴ روز نگه داشته نمی‌شود' );

/* -------------------------------------------------------------------------
 * ۶) بازنویسی manifest و دسترسی به زیرنویس
 * ---------------------------------------------------------------------- */
$key_line = Falnic\SecureHLS\Stream::rewrite_line( '#EXT-X-KEY:METHOD=AES-128,URI="key.key",IV=0x1', 'lesson-1' );
falnic_check( false !== strpos( $key_line, 'action=falnic_stream_key' ), 'کلید به دروازه‌ی امن هدایت می‌شود' );
falnic_check( false !== strpos( $key_line, 'METHOD=AES-128' ), 'پارامترهای رمزنگاری حفظ می‌شوند' );
falnic_check( false === strpos( $key_line, 'URI="key.key"' ), 'مسیر مستقیم کلید حذف می‌شود' );

$comments = array( '#EXTM3U', '#EXT-X-VERSION:3', '#EXT-X-TARGETDURATION:6', '#EXTINF:6.0,', '#EXT-X-ENDLIST' );

foreach ( $comments as $comment ) {
	falnic_check( $comment === Falnic\SecureHLS\Stream::rewrite_line( $comment, 'lesson-1' ), 'خط کنترلی دست‌نخورده می‌ماند: ' . $comment );
}

$segment_line = Falnic\SecureHLS\Stream::rewrite_line( '00003.ts', 'lesson-1' );
falnic_check( false !== strpos( $segment_line, 'action=falnic_stream_segment' ) && false !== strpos( $segment_line, 'f=00003.ts' ), 'سگمنت به دروازه هدایت می‌شود' );

$nested = Falnic\SecureHLS\Stream::rewrite_line( '00003.ts', 'lesson-1', '720p' );
falnic_check( false !== strpos( $nested, 'f=720p%2F00003.ts' ), 'مسیر نسبی کیفیت حفظ می‌شود', $nested );

$variant_line = Falnic\SecureHLS\Stream::rewrite_line( '720p/index.m3u8', 'lesson-1', '', true );
falnic_check( false !== strpos( $variant_line, 'action=falnic_stream_manifest' ) && false !== strpos( $variant_line, 'f=720p' ), 'پلی‌لیست کیفیت نیز توکن می‌گیرد' );

$subtitle_line = Falnic\SecureHLS\Stream::rewrite_line( 'captions/fa.vtt', 'lesson-1' );
falnic_check( false !== strpos( $subtitle_line, 'action=falnic_stream_subtitle' ) && false !== strpos( $subtitle_line, 'l=fa' ), 'زیرنویس با توکن اختصاصی تحویل می‌شود' );

$media_line = Falnic\SecureHLS\Stream::rewrite_line(
	'#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="fa",LANGUAGE="fa",URI="captions/fa.vtt"',
	'lesson-1'
);
falnic_check( false !== strpos( $media_line, 'action=falnic_stream_subtitle' ), 'زیرنویس داخل EXT-X-MEDIA هم بازنویسی می‌شود' );

$map_line  = Falnic\SecureHLS\Stream::rewrite_line( '#EXT-X-MAP:URI="init.mp4"', 'lesson-1' );
falnic_check( false !== strpos( $map_line, 'action=falnic_stream_segment' ) && false !== strpos( $map_line, 'f=init.mp4' ), 'فایل init به دروازه هدایت می‌شود' );

/* -------------------------------------------------------------------------
 * ۷) مسیرها و زیرنویس‌ها
 * ---------------------------------------------------------------------- */
falnic_check( 'fa' === Falnic\SecureHLS\Storage::sanitize_lang( 'fa' ), 'کد زبان ساده پذیرفته می‌شود' );
falnic_check( 'en-us' === Falnic\SecureHLS\Storage::sanitize_lang( 'EN-US' ), 'کد زبان منطقه‌ای نرمال می‌شود' );
$unsafe_lang = Falnic\SecureHLS\Storage::sanitize_lang( '../etc' );
falnic_check( false === strpos( $unsafe_lang, '/' ) && false === strpos( $unsafe_lang, '.' ), 'کد زبان مخرب پاک‌سازی می‌شود', $unsafe_lang );
falnic_check( '' === Falnic\SecureHLS\Storage::sanitize_lang( 'f' ), 'کد زبان تک‌حرفی رد می‌شود' );
falnic_check( '' === Falnic\SecureHLS\Storage::sanitize_lang( 'thisisnotalang' ), 'کد زبان بلند رد می‌شود' );

$caption_dir = Falnic\SecureHLS\Storage::subtitle_dir( 'lesson-1' );
wp_mkdir_p( $caption_dir );
file_put_contents( $caption_dir . 'fa.vtt', "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nسلام\n" );
$captions = Falnic\SecureHLS\Storage::subtitles( 'lesson-1' );
falnic_check( 1 === count( $captions ) && 'fa' === $captions[0]['lang'], 'زیرنویس موجود شناسایی می‌شود' );
falnic_check( false !== strpos( Falnic\SecureHLS\Storage::subtitle_path( 'lesson-1', 'fa' ), 'captions/fa.vtt' ), 'مسیر زیرنویس در پوشه‌ی captions است' );

$video_root = Falnic\SecureHLS\Storage::video_dir( 'lesson-1' );
file_put_contents( $video_root . 'master.m3u8', "#EXTM3U\n" );
falnic_check( Falnic\SecureHLS\Storage::is_master( 'lesson-1' ), 'وجود master.m3u8 تشخیص داده می‌شود' );
falnic_check( false !== strpos( Falnic\SecureHLS\Storage::playlist_path( 'lesson-1' ), 'master.m3u8' ), 'پلی‌لیست اصلی master است' );
falnic_check( null === Falnic\SecureHLS\Storage::resolve_file( 'lesson-1', '../../etc/passwd' ), 'مسیر بیرون از پوشه پذیرفته نمی‌شود' );

$GLOBALS['falnic_options']['falnic_hls_quality']        = 'auto';
$GLOBALS['falnic_options']['falnic_hls_max_renditions'] = 2;

/* -------------------------------------------------------------------------
 * پاک‌سازی
 * ---------------------------------------------------------------------- */
falnic_rmtree( $GLOBALS['falnic_tmp'] );
falnic_rmtree( Falnic\SecureHLS\Storage::root() );

echo "\nنتیجه: {$GLOBALS['falnic_pass']} موفق، {$GLOBALS['falnic_fail']} ناموفق\n";

exit( $GLOBALS['falnic_fail'] > 0 ? 1 : 0 );
