<?php
/**
 * تست رندر نماهای مدیریت و متاباکس ویدیو — بدون نصب وردپرس.
 *
 * اجرا:
 *   php tests/views-tests.php
 *   php tests/views-tests.php --no-ld      # شبیه‌سازی نبود LearnDash
 *
 * هدف: هر نمای ادمین باید با حداقل داده‌ی واقعی رندر شود بدون هیچ خطا،
 * هشدار یا نوتیس PHP. این تست همان دسته خطایی را می‌گیرد که یک‌بار باعث
 * «Class "Admin_Tabs" not found» شد: نما کلاس‌ها را با use وارد نکرده بود
 * و تا زمان باز شدن صفحه در مرورگر کسی متوجه نمی‌شد.
 *
 * @package Falnic\SecureHLS
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'FALNIC_VERSION', '3.5.0' );
define( 'FALNIC_DIR', dirname( __DIR__ ) . '/' );
define( 'FALNIC_URL', 'https://example.test/wp-content/plugins/falnic-secure-hls/' );
define( 'FALNIC_BASENAME', 'falnic-secure-hls/falnic-secure-hls.php' );
define( 'FALNIC_STORAGE_DIR', sys_get_temp_dir() . '/falnic-views-' . getmypid() . '/' );

// جست‌وجوی باینری در این محیط معنا ندارد و می‌تواند اجرا را معلق کند.
define( 'FALNIC_SKIP_BINARY_PROBE', true );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['falnic_ld_off'] = 'off' === getenv( 'FALNIC_TEST_LD' )
	|| ( isset( $argv ) && in_array( '--no-ld', (array) $argv, true ) );

$GLOBALS['falnic_options']   = array();
$GLOBALS['falnic_post_meta'] = array();
$GLOBALS['falnic_titles']    = array();

/* -------------------------------------------------------------------------
 * شبیه‌سازی حداقلی وردپرس
 * ---------------------------------------------------------------------- */

/** @param string $text @return string */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/** @param string $text @return string */
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/** @param string $url @return string */
function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

/** @param string $url @return string */
function esc_url_raw( $url ) {
	return (string) $url;
}

/** @param string $text @return string */
function esc_js( $text ) {
	return addslashes( (string) $text );
}

/** @param string $text @return string */
function esc_textarea( $text ) {
	return esc_html( $text );
}

/** @param string $text @return string */
function wp_kses_post( $text ) {
	return (string) $text;
}

/**
 * ترجمه (هویت).
 *
 * @param string $text   متن.
 * @param string $domain دامنه.
 * @return string
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return (string) $text;
}

/**
 * ترجمه و چاپ.
 *
 * @param string $text   متن.
 * @param string $domain دامنه.
 * @return void
 */
function _e( $text, $domain = '' ) { // phpcs:ignore
	echo esc_html( $text );
}

/**
 * ترجمه با زمینه.
 *
 * @param string $text    متن.
 * @param string $context زمینه.
 * @param string $domain  دامنه.
 * @return string
 */
function _x( $text, $context, $domain = '' ) { // phpcs:ignore
	return (string) $text;
}

/** @param string $text @return string */
function esc_html__( $text, $domain = '' ) { // phpcs:ignore
	return esc_html( $text );
}

/** @param string $text @return void */
function esc_html_e( $text, $domain = '' ) { // phpcs:ignore
	echo esc_html( $text );
}

/** @param string $text @return string */
function esc_attr__( $text, $domain = '' ) { // phpcs:ignore
	return esc_attr( $text );
}

/** @param string $text @return void */
function esc_attr_e( $text, $domain = '' ) { // phpcs:ignore
	echo esc_attr( $text );
}

/** @param string $text @return string */
function esc_html_x( $text, $context, $domain = '' ) { // phpcs:ignore
	return esc_html( $text );
}

/**
 * عدد با قالب محلی.
 *
 * @param float $number عدد.
 * @param int   $decimals تعداد اعشار.
 * @return string
 */
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

/**
 * حجم خوانا.
 *
 * @param int $bytes    بایت.
 * @param int $decimals اعشار.
 * @return string
 */
function size_format( $bytes, $decimals = 0 ) {
	return number_format( (float) $bytes, (int) $decimals ) . ' B';
}

/**
 * اختلاف زمانی خوانا.
 *
 * @param int $from زمان شروع.
 * @param int $to   زمان پایان.
 * @return string
 */
function human_time_diff( $from, $to = 0 ) {
	return '۵ دقیقه';
}

/**
 * آدرس پنل.
 *
 * @param string $path مسیر.
 * @return string
 */
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

/**
 * افزودن پارامتر به آدرس.
 *
 * @param mixed  $args آرگومان‌ها.
 * @param string $url  آدرس.
 * @return string
 */
function add_query_arg( $args, $url = '' ) {
	if ( is_array( $args ) ) {
		$query = http_build_query( $args );
	} else {
		$query = rawurlencode( (string) $args ) . '=' . rawurlencode( (string) $url );
		$url   = func_num_args() > 2 ? (string) func_get_arg( 2 ) : '';
	}

	$url = (string) $url;

	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
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

/** @param mixed $value @return mixed */
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * پاک‌سازی متن.
 *
 * @param string $value مقدار.
 * @return string
 */
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) );
}

/**
 * پاک‌سازی کلید.
 *
 * @param string $value مقدار.
 * @return string
 */
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

/**
 * ساخت اسلاگ.
 *
 * @param string $value مقدار.
 * @return string
 */
function sanitize_title( $value ) {
	$value = strtolower( trim( (string) $value ) );

	return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $value ), '-' );
}

/**
 * عدد صحیح مثبت.
 *
 * @param mixed $value مقدار.
 * @return int
 */
function absint( $value ) {
	return abs( (int) $value );
}

/**
 * ادغام آرگومان‌ها.
 *
 * @param array $args     آرگومان‌ها.
 * @param array $defaults پیش‌فرض‌ها.
 * @return array
 */
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( (array) $defaults, (array) $args );
}

/**
 * خواندن آپشن.
 *
 * @param string $name    نام.
 * @param mixed  $default پیش‌فرض.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	return $GLOBALS['falnic_options'][ $name ] ?? $default;
}

/**
 * نوشتن آپشن.
 *
 * @param string $name  نام.
 * @param mixed  $value مقدار.
 * @param mixed  $auto  autoload.
 * @return bool
 */
function update_option( $name, $value, $auto = null ) { // phpcs:ignore
	$GLOBALS['falnic_options'][ $name ] = $value;

	return true;
}

/**
 * حذف آپشن.
 *
 * @param string $name نام.
 * @return bool
 */
function delete_option( $name ) {
	unset( $GLOBALS['falnic_options'][ $name ] );

	return true;
}

/** @param string $key @param mixed $value @param int $ttl @return bool */
function set_transient( $key, $value, $ttl = 0 ) { // phpcs:ignore
	$GLOBALS['falnic_options'][ '_transient_' . $key ] = $value;

	return true;
}

/** @param string $key @return mixed */
function get_transient( $key ) {
	return $GLOBALS['falnic_options'][ '_transient_' . $key ] ?? false;
}

/** @param string $key @return bool */
function delete_transient( $key ) {
	unset( $GLOBALS['falnic_options'][ '_transient_' . $key ] );

	return true;
}

/**
 * متای پست.
 *
 * @param int    $post_id شناسه.
 * @param string $key     کلید.
 * @param bool   $single  تک‌مقدار.
 * @return mixed
 */
function get_post_meta( $post_id, $key = '', $single = false ) {
	return $GLOBALS['falnic_post_meta'][ (int) $post_id ][ $key ] ?? '';
}

/**
 * نوشتن متای پست.
 *
 * @param int    $post_id شناسه.
 * @param string $key     کلید.
 * @param mixed  $value   مقدار.
 * @return bool
 */
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['falnic_post_meta'][ (int) $post_id ][ $key ] = $value;

	return true;
}

/**
 * حذف متای پست.
 *
 * @param int    $post_id شناسه.
 * @param string $key     کلید.
 * @return bool
 */
function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['falnic_post_meta'][ (int) $post_id ][ $key ] );

	return true;
}

/**
 * بررسی nonce (در تست همیشه معتبر).
 *
 * @param string $nonce  مقدار.
 * @param string $action اکشن.
 * @return bool
 */
function wp_verify_nonce( $nonce, $action = -1 ) { // phpcs:ignore
	return true;
}

/**
 * نوع پست.
 *
 * @param mixed $post پست یا شناسه.
 * @return string
 */
function get_post_type( $post = 0 ) {
	if ( is_object( $post ) ) {
		return (string) ( $post->post_type ?? '' );
	}

	$resolved = get_post( (int) $post );

	return $resolved ? (string) $resolved->post_type : 'post';
}

/**
 * آیا پست بازنگری است؟
 *
 * @param int $post_id شناسه.
 * @return bool
 */
function wp_is_post_revision( $post_id ) {
	return false;
}

/**
 * حذف فایل.
 *
 * @param string $path مسیر.
 * @return bool
 */
function wp_delete_file( $path ) {
	return true;
}

/**
 * پست از شناسه.
 *
 * @param mixed $post پست یا شناسه.
 * @return object|null
 */
function get_post( $post ) {
	if ( is_object( $post ) ) {
		return $post;
	}

	$id = (int) $post;

	if ( $id <= 0 ) {
		return null;
	}

	$type = 'post';

	if ( 700 === $id ) {
		$type = 'secure_video';
	} elseif ( in_array( $id, array( 501, 502, 999 ), true ) ) {
		$type = 'sfwd-courses';
	} elseif ( in_array( $id, array( 601, 602, 888 ), true ) ) {
		$type = 'sfwd-lessons';
	}

	return (object) array(
		'ID'         => $id,
		'post_title' => $GLOBALS['falnic_titles'][ $id ] ?? ( 'video-' . $id ),
		'post_type'  => $type,
	);
}

/**
 * فهرست پست‌ها.
 *
 * @param array $args آرگومان‌ها.
 * @return array
 */
function get_posts( $args = array() ) {
	$type = $args['post_type'] ?? 'post';
	$ids  = 'ids' === ( $args['fields'] ?? '' );

	// وردپرس با fields=ids شناسه برمی‌گرداند، نه آبجکت.
	$wrap = static function ( array $posts ) use ( $ids ) {
		return $ids ? array_map( static function ( $post ) { return (int) $post->ID; }, $posts ) : $posts;
	};

	if ( 'sfwd-courses' === $type ) {
		return $wrap( array( get_post( 501 ), get_post( 502 ) ) );
	}

	if ( is_array( $type ) && in_array( 'sfwd-lessons', $type, true ) ) {
		$value = (string) ( $args['meta_value'] ?? '' );
		$ids   = array();

		foreach ( array( 601, 602 ) as $candidate ) {
			if ( $value === (string) ( $GLOBALS['falnic_post_meta'][ $candidate ]['_falnic_video_name'] ?? '' ) ) {
				$ids[] = get_post( $candidate );
			}
		}

		return $wrap( $ids );
	}

	if ( 'secure_video' === $type ) {
		$value = (string) ( $args['meta_value'] ?? '' );
		$ids   = array();

		foreach ( $GLOBALS['falnic_post_meta'] as $candidate => $meta ) {
			if ( $value === (string) ( $meta['falnic_video_name'] ?? '' ) ) {
				$ids[] = get_post( $candidate );
			}
		}

		return $wrap( $ids );
	}

	return array();
}

/**
 * عنوان پست.
 *
 * @param mixed $post پست یا شناسه.
 * @return string
 */
function get_the_title( $post = 0 ) {
	if ( is_object( $post ) ) {
		return (string) ( $post->post_title ?? '' );
	}

	return (string) ( $GLOBALS['falnic_titles'][ (int) $post ] ?? '' );
}

/**
 * پیوند یکتا.
 *
 * @param mixed $post پست یا شناسه.
 * @return string
 */
function get_permalink( $post = 0 ) {
	$id = is_object( $post ) ? (int) $post->ID : (int) $post;

	return 'https://example.test/?p=' . $id;
}

/**
 * کاربر از شناسه.
 *
 * @param int $user_id شناسه.
 * @return object|null
 */
function get_userdata( $user_id ) {
	$user_id = (int) $user_id;

	if ( $user_id <= 0 ) {
		return false;
	}

	return (object) array(
		'ID'           => $user_id,
		'user_login'   => 'student' . $user_id,
		'user_email'   => 'student' . $user_id . '@example.test',
		'display_name' => 'دانشجو ' . $user_id,
	);
}

/** @param int $user_id @return string */
function get_edit_user_link( $user_id = 0 ) {
	return 'https://example.test/wp-admin/user-edit.php?user_id=' . (int) $user_id;
}

/** @param int $user_id @return int */
function get_current_user_id() {
	return 1;
}

/** @param string $cap @param mixed $args @return bool */
function current_user_can( $cap, $args = null ) { // phpcs:ignore
	return true;
}

/** @param string $action @return string */
function wp_create_nonce( $action = '' ) { // phpcs:ignore
	return 'nonce-' . substr( md5( (string) $action ), 0, 10 );
}

/**
 * فیلد nonce.
 *
 * @param string $action  اکشن.
 * @param string $name    نام فیلد.
 * @param bool   $referer ارجاع.
 * @param bool   $echo    چاپ.
 * @return string
 */
function wp_nonce_field( $action = '', $name = '_wpnonce', $referer = true, $echo = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';

	if ( $echo ) {
		echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	return $field;
}

/**
 * گزینه‌ی انتخاب‌شده.
 *
 * @param mixed $selected مقدار جاری.
 * @param mixed $current  مقدار گزینه.
 * @param bool  $echo     چاپ.
 * @return string
 */
function selected( $selected, $current = true, $echo = true ) {
	$result = (string) $selected === (string) $current ? ' selected="selected"' : '';

	if ( $echo ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	return $result;
}

/**
 * چک‌باکس تیک‌خورده.
 *
 * @param mixed $checked مقدار.
 * @param mixed $current مقدار جاری.
 * @param bool  $echo    چاپ.
 * @return string
 */
function checked( $checked, $current = true, $echo = true ) {
	$result = (string) $checked === (string) $current ? ' checked="checked"' : '';

	if ( $echo ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	return $result;
}

/**
 * غیرفعال بودن.
 *
 * @param mixed $disabled مقدار.
 * @param mixed $current  مقدار جاری.
 * @param bool  $echo     چاپ.
 * @return string
 */
function disabled( $disabled, $current = true, $echo = true ) {
	$result = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';

	if ( $echo ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	return $result;
}

/**
 * صفحه‌بندی.
 *
 * @param array $args آرگومان‌ها.
 * @return string
 */
function paginate_links( $args = array() ) {
	return '<span class="page-numbers">1</span>';
}

/**
 * پوشه‌ی آپلود.
 *
 * @return array
 */
function wp_upload_dir() {
	return array(
		'basedir' => sys_get_temp_dir() . '/falnic-uploads',
		'baseurl' => 'https://example.test/wp-content/uploads',
	);
}

/** @param string $dir @return bool */
function wp_mkdir_p( $dir ) {
	return true;
}

/** @param string $dir @return bool */
function wp_is_writable( $dir ) {
	return true;
}

/** @return bool */
function is_multisite() {
	return false;
}

/** @return bool */
function is_user_logged_in() {
	return true;
}

/** @param string $path @param string $scheme @return string */
function home_url( $path = '', $scheme = null ) { // phpcs:ignore
	return 'https://example.test/' . ltrim( (string) $path, '/' );
}

/** @param string $url @param string $scheme @return string */
function set_url_scheme( $url, $scheme = null ) { // phpcs:ignore
	return (string) $url;
}

/** @param int $post_id @return string|false */
function get_edit_post_link( $post_id = 0, $context = 'display' ) { // phpcs:ignore
	$post_id = (int) $post_id;
	return $post_id > 0 ? 'https://example.test/wp-admin/post.php?post=' . $post_id . '&action=edit' : '';
}

/** @param string $file @return string */
function plugin_dir_path( $file ) {
	return dirname( (string) $file ) . '/';
}

/** @param string $file @return string */
function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( (string) $file ) ) . '/';
}

/** @param string $file @return string */
function plugin_basename( $file ) {
	return basename( dirname( (string) $file ) ) . '/' . basename( (string) $file );
}

/** @param int|null $blog_id @return int */
function get_current_blog_id() {
	return 1;
}

/* -------------------------------------------------------------------------
 * شبیه‌سازی LearnDash (با --no-ld یا FALNIC_TEST_LD=off خاموش می‌شود)
 * ---------------------------------------------------------------------- */

if ( ! $GLOBALS['falnic_ld_off'] ) {
	/** @param int $post_id @param int $user_id @return bool */
	function sfwd_lms_has_access( $post_id, $user_id = 0 ) { // phpcs:ignore
		return true;
	}

	/** @param int $post_id @return int */
	function learndash_get_course_id( $post_id ) {
		return 501;
	}
}

require_once FALNIC_DIR . 'includes/class-autoloader.php';

Falnic\SecureHLS\Autoloader::register();

/* -------------------------------------------------------------------------
 * چارچوب تست
 * ---------------------------------------------------------------------- */

$GLOBALS['falnic_pass'] = 0;
$GLOBALS['falnic_fail'] = 0;

/**
 * ثبت نتیجه.
 *
 * @param bool   $ok   نتیجه.
 * @param string $name عنوان.
 * @return void
 */
function check( $ok, $name ) {
	if ( $ok ) {
		$GLOBALS['falnic_pass']++;
		return;
	}

	$GLOBALS['falnic_fail']++;
	echo "  ✗ " . $name . "\n";
}

/**
 * رندر یک نما در محدوده‌ی مستقل و گرفتن هر خطای PHP.
 *
 * @param string $view مسیر نسبی نمای.
 * @param array  $vars متغیرهای نما.
 * @return array{html: string, error: string}
 */
function render_view( $view, array $vars ) {
	$path = FALNIC_DIR . $view;

	if ( ! is_readable( $path ) ) {
		return array(
			'html'  => '',
			'error' => 'فایل نما پیدا نشد: ' . $view,
		);
	}

	set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		static function ( $errno, $errstr, $errfile, $errline ) {
			if ( E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno ) {
				return false;
			}

			throw new ErrorException( $errstr, 0, $errno, $errfile, $errline );
		}
	);

	$html = '';

	try {
		ob_start();
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $path;
		$html = (string) ob_get_clean();
	} catch ( Throwable $error ) {
		ob_end_clean();

		return array(
			'html'  => '',
			'error' => get_class( $error ) . ': ' . $error->getMessage() . ' @ ' . basename( (string) $error->getFile() ) . ':' . $error->getLine(),
		);
	} finally {
		restore_error_handler();
	}

	return array(
		'html'  => $html,
		'error' => '',
	);
}

echo "== آزمون رندر نماهای مدیریت ==\n";

/* -------------------------------------------------------------------------
 * ۱) نماهای ادمین
 * ---------------------------------------------------------------------- */

$falnic_views = array(
	'admin/views/encoder.php'   => array(
		'marker' => 'انکودر دستی سرور',
		'vars'   => array(
			'encoder_checks' => array(
				array(
					'label' => 'ffmpeg در دسترس است',
					'ok'    => true,
					'hint'  => '',
				),
				array(
					'label' => 'ffprobe در دسترس است',
					'ok'    => false,
					'hint'  => 'مسیر ffprobe را بدهید.',
				),
			),
			'encoder_jobs'   => array(
				'lesson-1' => array(
					'state'   => 'failed',
					'percent' => 40,
					'message' => 'انکود ناموفق بود.',
					'errors'  => 'ffmpeg: not found',
					'updated' => time() - 120,
				),
			),
		),
	),
	'admin/views/settings.php'  => array(
		'marker' => 'وضعیت سیستم',
		'vars'   => array(
			'max_sessions'   => 1,
			'bind_ip'        => 'no',
			'auto_embed'     => 'yes',
			'ld_position'    => 'before',
			'require_enrol'  => 'yes',
			'max_upload_mb'  => 2048,
			'hls_quality'    => 'auto',
			'max_renditions' => 2,
			'segment_secs'   => 6,
			'max_concurrent' => 1,
			'watch_log_days' => 90,
			'ld_active'      => ! $GLOBALS['falnic_ld_off'],
			'legacy_pending' => 0,
			'storage_root'   => '/var/falnic-secure/',
			'outside_root'   => true,
			'status'         => array(
				array(
					'label' => 'پوشه‌ی امن قابل نوشتن است',
					'ok'    => true,
				),
			),
			'page_slug'      => 'falnic_hls_settings',
		),
	),
	'admin/views/template.php'  => array(
		'marker' => 'تنظیمات قالب',
		'vars'   => array(
			'settings' => array(
				'radius'        => 8,
				'accent'        => '#2271b1',
				'ratio'         => '16:9',
				'wm_opacity'    => 0.45,
				'wm_interval'   => 30,
				'wm_font_scale' => 1.0,
				'wm_show_email' => 'yes',
			),
		),
	),
	'admin/views/git-update.php' => array(
		'marker' => 'آپدیت از گیت',
		'vars'   => array(
			'settings'     => array(
				'repo'      => '',
				'branch'    => 'main',
				'has_token' => false,
			),
			'error'        => '',
			'notice'       => '',
			'version'      => '3.5.0',
			'main_file'    => 'falnic-secure-hls/falnic-secure-hls.php',
			'plugin_dir'   => '/var/www/wp-content/plugins/falnic-secure-hls/',
			'backup_root'  => '/var/www/wp-content/uploads/falnic-backups/',
			'backups'      => array(
				array(
					'name'  => 'backup-1.zip',
					'date'  => '2026-10-20 10:00',
					'size'  => 4096,
					'mtime' => time(),
				),
			),
			'requirements' => array(
				array(
					'ok'      => true,
					'label'   => 'ZIP',
					'message' => '',
				),
			),
			'locked'       => false,
			'max_backups'  => 3,
		),
	),
	'admin/views/watch-log.php' => array(
		'marker' => 'گزارش تماشا',
		'vars'   => array(
			'watch_rows'    => array(
				array(
					'user_id'    => 7,
					'video'      => 'lesson-1',
					'started_at' => '2026-10-20 10:00:00',
					'seconds'    => 600,
					'segments'   => 100,
					'bytes'      => 1048576,
					'agent'      => 'Mozilla/5.0',
					'ip_hash'    => 'abcdef1234567890',
				),
			),
			'watch_total'   => 1,
			'watch_summary' => array(
				'today'    => 1,
				'week'     => 1,
				'users'    => 1,
				'segments' => 100,
			),
			'watch_shared'  => array(
				array(
					'user_id'   => 7,
					'ips'       => 3,
					'sessions'  => 4,
					'last_seen' => '2026-10-20 10:10:00',
				),
			),
			'watch_denied'  => array(
				array(
					'reason' => 'not_enrolled',
					'video'  => 'lesson-1',
					'count'  => 3,
				),
			),
			'watch_exists'  => true,
			'watch_days'    => 7,
			'watch_video'   => '',
			'watch_user'    => 0,
			'watch_page'    => 1,
		),
	),
);

foreach ( $falnic_views as $falnic_view => $falnic_case ) {
	$result = render_view( $falnic_view, $falnic_case['vars'] );

	check( '' === $result['error'], 'رندر بدون خطا: ' . $falnic_view . ( '' === $result['error'] ? '' : ' → ' . $result['error'] ) );
	check(
		'' !== trim( $result['html'] ) && false !== strpos( $result['html'], $falnic_case['marker'] ),
		'خروجی نمای ' . basename( $falnic_view ) . ' کامل است'
	);
}

// نماها نباید پیام خالی یا «Array» چاپ کنند.
$falnic_encoder = render_view( 'admin/views/encoder.php', $falnic_views['admin/views/encoder.php']['vars'] );
check( false !== strpos( $falnic_encoder['html'], 'اجرای فوری صف' ), 'دکمه‌ی «اجرای فوری صف» در صفحه‌ی انکودر هست' );
check( false !== strpos( $falnic_encoder['html'], 'وضعیت پیش‌نیازها' ), 'کارت پیش‌نیازها در صفحه‌ی انکودر هست' );
check( false !== strpos( $falnic_encoder['html'], 'ffmpeg: not found' ), 'خطای انکود در جدول صف نمایش داده می‌شود' );

/* -------------------------------------------------------------------------
 * ۲) متاباکس ویدیو
 * ---------------------------------------------------------------------- */

$GLOBALS['falnic_post_meta'] = array(
	700 => array(
		'falnic_video_name' => 'lesson-1',
		'falnic_course_id'  => 501,
		'falnic_lesson_id'  => 601,
	),
);

$GLOBALS['falnic_titles'] = array(
	501 => 'دوره‌ی نمونه',
	502 => 'دوره‌ی دوم',
	601 => 'درس نمونه',
	602 => 'درس دوم',
);

/**
 * رندر متاباکس ویدیو و گرفتن خطای احتمالی.
 *
 * @return array{html: string, error: string}
 */
function render_meta_box() {
	$post = get_post( 700 );
	$post->post_type = 'secure_video';

	set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		static function ( $errno, $errstr, $errfile, $errline ) {
			if ( E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno ) {
				return false;
			}

			throw new ErrorException( $errstr, 0, $errno, $errfile, $errline );
		}
	);

	$html = '';

	try {
		ob_start();
		Falnic\SecureHLS\Meta_Box::render( $post );
		$html = (string) ob_get_clean();
	} catch ( Throwable $error ) {
		ob_end_clean();

		return array(
			'html'  => '',
			'error' => get_class( $error ) . ': ' . $error->getMessage() . ' @ ' . basename( (string) $error->getFile() ) . ':' . $error->getLine(),
		);
	} finally {
		restore_error_handler();
	}

	return array(
		'html'  => $html,
		'error' => '',
	);
}

$falnic_box        = render_meta_box();
$falnic_box_html   = $falnic_box['html'];
$falnic_box_error  = $falnic_box['error'];

check( '' === $falnic_box_error, 'متاباکس ویدیو بدون خطا رندر می‌شود' . ( '' === $falnic_box_error ? '' : ' → ' . $falnic_box_error ) );
check( false !== strpos( $falnic_box_html, 'falnic_video_file' ), 'ورودی فایل ویدیو در متاباکس هست' );
check( false === strpos( $falnic_box_html, 'class="description"' ), 'جعبه‌ی توضیحات از متاباکس حذف شده است' );
check( false === strpos( $falnic_box_html, 'پس از ذخیره، فایل در پس‌زمینه پردازش' ), 'متن توضیحی قدیمی در متاباکس نیست' );

if ( $GLOBALS['falnic_ld_off'] ) {
	check( false === strpos( $falnic_box_html, 'falnic_course_id' ), 'بدون LearnDash انتخاب دوره نمایش داده نمی‌شود' );
	check( false === strpos( $falnic_box_html, 'falnic_lesson_id' ), 'بدون LearnDash انتخاب درس نمایش داده نمی‌شود' );
} else {
	check( false !== strpos( $falnic_box_html, 'falnic_course_id' ), 'انتخاب دوره در متاباکس هست' );
	check( false !== strpos( $falnic_box_html, 'falnic_lesson_id' ), 'انتخاب درس در متاباکس هست' );
	check( false !== strpos( $falnic_box_html, 'selected="selected"' ), 'اتصال ذخیره‌شده در متاباکس انتخاب‌شده نمایش داده می‌شود' );
	check( false !== strpos( $falnic_box_html, 'دوره‌ی نمونه' ), 'عنوان دوره‌ها در فهرست هست' );
	check( false !== strpos( $falnic_box_html, 'درس نمونه' ), 'عنوان درس‌ها در فهرست هست' );

	// انتخاب فعلی خارج از فهرست ۲۰۰/۵۰۰تایی (سایت بزرگ) نباید گم شود.
	$GLOBALS['falnic_post_meta'][700]['falnic_course_id'] = 999;
	$GLOBALS['falnic_post_meta'][700]['falnic_lesson_id'] = 888;
	$GLOBALS['falnic_titles'][999]                        = 'دوره‌ی قدیمی';
	$GLOBALS['falnic_titles'][888]                        = 'درس قدیمی';

	$falnic_extra = render_meta_box();

	check(
		false !== strpos( $falnic_extra['html'], 'value="999" selected="selected"' ),
		'دوره‌ی انتخاب‌شده‌ی خارج از فهرست در کشویی می‌ماند'
	);
	check(
		false !== strpos( $falnic_extra['html'], 'value="888" selected="selected"' ),
		'درس انتخاب‌شده‌ی خارج از فهرست در کشویی می‌ماند'
	);
}

/* -------------------------------------------------------------------------
 * ۳) ذخیره‌ی اتصال دستی دوره/درس
 * ---------------------------------------------------------------------- */

$GLOBALS['falnic_post_meta'][601]['course_id'] = 501;
$GLOBALS['falnic_post_meta'][700]             = array();
$GLOBALS['falnic_titles'][700]                = 'Lesson 1';

$_POST = array(
	'falnic_video_upload_nonce' => 'nonce-falnic_video_upload',
	'falnic_course_id'          => '502',
	'falnic_lesson_id'          => '601',
);

$falnic_save_error = '';

try {
	Falnic\SecureHLS\Meta_Box::save( 700 );
} catch ( Throwable $error ) {
	$falnic_save_error = get_class( $error ) . ': ' . $error->getMessage() . ' @ ' . basename( (string) $error->getFile() ) . ':' . $error->getLine();
}

check( '' === $falnic_save_error, 'ذخیره‌ی متاباکس بدون خطا انجام می‌شود' . ( '' === $falnic_save_error ? '' : ' → ' . $falnic_save_error ) );

if ( ! $GLOBALS['falnic_ld_off'] ) {
	check(
		501 === (int) get_post_meta( 700, 'falnic_course_id', true ),
		'دوره از درس انتخاب‌شده مشتق می‌شود (نه از فیلد ناسازگار)'
	);
	check( 601 === (int) get_post_meta( 700, 'falnic_lesson_id', true ), 'شناسه‌ی درس ذخیره می‌شود' );
	check(
		501 === (int) Falnic\SecureHLS\Access::manual_target( 'lesson-1' )['course'],
		'Access::manual_target() همان دوره‌ی ذخیره‌شده را می‌خواند'
	);
}

unset( $_POST );

/* -------------------------------------------------------------------------
 * ۴) نماهای ناشناس نباید مستقیم اجرا شوند
 * ---------------------------------------------------------------------- */

$falnic_guarded = array(
	'admin/views/encoder.php',
	'admin/views/settings.php',
	'admin/views/template.php',
	'admin/views/git-update.php',
	'admin/views/watch-log.php',
);

foreach ( $falnic_guarded as $falnic_file ) {
	$source = (string) file_get_contents( FALNIC_DIR . $falnic_file );

	check(
		false !== strpos( $source, "if ( ! defined( 'ABSPATH' ) )" ),
		'محافظ ABSPATH در ' . basename( $falnic_file ) . ' هست'
	);
}

echo "\nنتیجه: {$GLOBALS['falnic_pass']} موفق، {$GLOBALS['falnic_fail']} ناموفق\n";

exit( $GLOBALS['falnic_fail'] > 0 ? 1 : 0 );
