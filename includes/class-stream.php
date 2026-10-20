<?php
/**
 * دروازه‌ی تحویل استریم.
 *
 * پلی‌لیست، سگمنت و کلید هیچ‌گاه مستقیم از وب‌سرور تحویل نمی‌شوند؛
 * هر درخواست باید توکن امضاشده‌ی کوتاه‌عمر داشته باشد و دسترسی کاربر
 * دوباره بررسی می‌شود. هر نشست پخش یک شناسه (sid) دارد که در توکن‌ها
 * تکرار می‌شود تا محدودیت پخش هم‌زمان و ثبت گزارش تماشا ممکن باشد.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * اندپوینت‌های admin-ajax برای پخش.
 */
final class Stream {

	/**
	 * اکشن نشست.
	 */
	public const ACTION_SESSION = 'falnic_stream_session';

	/**
	 * اکشن manifest.
	 */
	public const ACTION_MANIFEST = 'falnic_stream_manifest';

	/**
	 * اکشن سگمنت.
	 */
	public const ACTION_SEGMENT = 'falnic_stream_segment';

	/**
	 * اکشن کلید.
	 */
	public const ACTION_KEY = 'falnic_stream_key';

	/**
	 * اکشن زیرنویس.
	 */
	public const ACTION_SUBTITLE = 'falnic_stream_subtitle';

	/**
	 * اکشن ضربان نشست.
	 */
	public const ACTION_HEARTBEAT = 'falnic_stream_heartbeat';

	/**
	 * اکشن پایان نشست.
	 */
	public const ACTION_END = 'falnic_stream_end';

	/**
	 * سقف درخواست کلید در دقیقه.
	 */
	private const KEY_LIMIT = 30;

	/**
	 * سقف درخواست سگمنت در دقیقه.
	 */
	private const SEGMENT_LIMIT = 600;

	/**
	 * سقف درخواست نشست در دقیقه.
	 */
	private const SESSION_LIMIT = 30;

	/**
	 * سقف درخواست ضربان در دقیقه.
	 */
	private const HEARTBEAT_LIMIT = 120;

	/**
	 * فاصله‌ی ثبت به‌روزرسانی گزارش (ثانیه).
	 */
	private const LOG_INTERVAL = 20;

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		$actions = array(
			self::ACTION_SESSION   => 'session',
			self::ACTION_MANIFEST  => 'manifest',
			self::ACTION_SEGMENT   => 'segment',
			self::ACTION_KEY       => 'key',
			self::ACTION_SUBTITLE  => 'subtitle',
			self::ACTION_HEARTBEAT => 'heartbeat',
			self::ACTION_END       => 'end_session',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $method ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, $method ) );
		}
	}

	/**
	 * AJAX: صدور نشست پخش برای کاربر جاری.
	 *
	 * @return void
	 */
	public static function session(): void {
		$name = self::request_name();

		if ( ! is_user_logged_in() ) {
			Watch_Log::log_denial( 'guest', $name );

			wp_send_json_error(
				array(
					'reason'    => 'guest',
					'message'   => Access::message( 'guest' ),
					'login_url' => wp_login_url( self::current_url() ),
				),
				401
			);
		}

		$reason = Access::reason( $name );

		if ( 'ok' !== $reason ) {
			Watch_Log::log_denial( $reason, $name );

			wp_send_json_error(
				array(
					'reason'  => $reason,
					'message' => Access::message( $reason ),
					'courses' => in_array( $reason, array( 'not_enrolled', 'locked' ), true ) ? Access::courses_for( $name ) : array(),
				),
				403
			);
		}

		if ( self::rate_limited( 'session', self::SESSION_LIMIT ) ) {
			wp_send_json_error( array( 'message' => __( 'درخواست‌های بیش از حد. کمی بعد تلاش کنید.', 'falnic-secure-hls' ) ), 429 );
		}

		$user_id = (int) get_current_user_id();
		$sid     = Play_Registry::start( $user_id, $name );

		if ( is_wp_error( $sid ) ) {
			wp_send_json_error(
				array(
					'reason'   => $sid->get_error_code(),
					'message'  => $sid->get_error_message(),
					'sessions' => (array) ( $sid->get_error_data()['sessions'] ?? array() ),
				),
				409
			);
		}

		$ttl   = max( 60, (int) get_option( 'falnic_token_ttl', 600 ) );
		$extra = array( 'sid' => $sid );

		Watch_Log::record(
			array(
				'user_id'    => $user_id,
				'video'      => $name,
				'course_id'  => Access::course_for_name( $name ),
				'session_id' => $sid,
			)
		);

		wp_send_json_success(
			array(
				'name'      => $name,
				'sid'       => $sid,
				'src'       => Token::url( self::ACTION_MANIFEST, $name, Token::SCOPE_MANIFEST, $extra, $ttl ),
				'expires'   => time() + $ttl,
				'watermark' => Player::watermark_lines(),
				'subtitles' => self::subtitle_sources( $name, $extra, $ttl ),
				'heartbeat' => Token::issue( Token::SCOPE_HEARTBEAT, $name, $extra, max( $ttl, 900 ) ),
			)
		);
	}

	/**
	 * AJAX: تحویل manifest (اصلی یا یکی از کیفیت‌ها).
	 *
	 * @return void
	 */
	public static function manifest(): void {
		$name  = self::request_name();
		$token = self::request_token();

		$claims = Token::verify( $token, Token::SCOPE_MANIFEST, $name );

		if ( is_wp_error( $claims ) ) {
			self::fail( $claims->get_error_message(), self::token_status() );
		}

		$reason = Access::reason( $name );

		if ( 'ok' !== $reason ) {
			self::fail( Access::message( $reason ), 'guest' === $reason ? 401 : 403 );
		}

		if ( ! self::session_alive( $claims ) ) {
			self::fail( __( 'این نشست پخش پایان یافته است؛ صفحه را دوباره بارگذاری کنید.', 'falnic-secure-hls' ), 403 );
		}

		$relative = self::request_file();
		$path     = '' === $relative ? Storage::playlist_path( $name ) : Storage::resolve_file( $name, $relative );

		if ( null === $path || ! is_readable( $path ) ) {
			self::fail( __( 'پلی‌لیست این ویدیو پیدا نشد.', 'falnic-secure-hls' ), 404 );
		}

		if ( 'm3u8' !== strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			self::fail( __( 'درخواست معتبر نیست.', 'falnic-secure-hls' ), 404 );
		}

		$lines = file( $path, FILE_IGNORE_NEW_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file

		if ( ! is_array( $lines ) ) {
			self::fail( __( 'پلی‌لیست قابل خواندن نیست.', 'falnic-secure-hls' ), 500 );
		}

		$base        = '' === $relative ? '' : self::dir_of( $relative );
		$expect_var = false;
		$body       = '';

		foreach ( $lines as $line ) {
			$line = (string) $line;

			if ( '' === trim( $line ) ) {
				continue;
			}

			$variant = false;

			if ( $expect_var && '#' !== $line[0] ) {
				$variant   = true;
				$expect_var = false;
			}

			if ( 0 === strpos( $line, '#EXT-X-STREAM-INF' ) ) {
				$expect_var = true;
			}

			$body .= self::rewrite_line( $line, $name, $base, $variant, $claims ) . "\n";
		}

		self::clean_buffers();

		nocache_headers();
		header( 'Content-Type: application/vnd.apple.mpegurl; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store, max-age=0' );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- متن m3u8.
		exit;
	}

	/**
	 * AJAX: تحویل یک سگمنت (با پشتیبانی Range).
	 *
	 * @return void
	 */
	public static function segment(): void {
		$name  = self::request_name();
		$token = self::request_token();

		$claims = Token::verify( $token, Token::SCOPE_SEGMENT, $name );

		if ( is_wp_error( $claims ) ) {
			self::fail( $claims->get_error_message(), self::token_status() );
		}

		$reason = Access::reason( $name );

		if ( 'ok' !== $reason ) {
			self::fail( Access::message( $reason ), 'guest' === $reason ? 401 : 403 );
		}

		if ( ! self::session_alive( $claims ) ) {
			self::fail( __( 'این نشست پخش پایان یافته است؛ صفحه را دوباره بارگذاری کنید.', 'falnic-secure-hls' ), 403 );
		}

		if ( self::rate_limited( 'segment', self::SEGMENT_LIMIT ) ) {
			header( 'Retry-After: 5' );
			self::fail( __( 'درخواست‌های بیش از حد. کمی بعد تلاش کنید.', 'falnic-secure-hls' ), 429 );
		}

		$relative = self::request_file();
		$path     = Storage::resolve_file( $name, $relative );

		if ( null === $path ) {
			self::fail( __( 'فایل درخواستی معتبر نیست.', 'falnic-secure-hls' ), 404 );
		}

		self::log_activity( (string) ( $claims['sid'] ?? '' ), (int) filesize( $path ) );

		self::serve_file( $path, 'video/mp2t', 'videos/' . $name . '/' . str_replace( '\\', '/', $relative ) );
	}

	/**
	 * AJAX: تحویل کلید AES.
	 *
	 * @return void
	 */
	public static function key(): void {
		$name  = self::request_name();
		$token = self::request_token();

		$claims = Token::verify( $token, Token::SCOPE_KEY, $name );

		if ( is_wp_error( $claims ) ) {
			self::fail( $claims->get_error_message(), self::token_status() );
		}

		$reason = Access::reason( $name );

		if ( 'ok' !== $reason ) {
			self::fail( Access::message( $reason ), 'guest' === $reason ? 401 : 403 );
		}

		if ( ! self::session_alive( $claims ) ) {
			self::fail( __( 'این نشست پخش پایان یافته است؛ صفحه را دوباره بارگذاری کنید.', 'falnic-secure-hls' ), 403 );
		}

		if ( self::rate_limited( 'key', self::KEY_LIMIT ) ) {
			header( 'Retry-After: 10' );
			self::fail( __( 'درخواست‌های بیش از حد. کمی بعد تلاش کنید.', 'falnic-secure-hls' ), 429 );
		}

		$key_path = Storage::key_path( $name );

		if ( ! is_readable( $key_path ) ) {
			self::fail( __( 'کلید یافت نشد.', 'falnic-secure-hls' ), 404 );
		}

		$key = file_get_contents( $key_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $key ) {
			self::fail( __( 'کلید قابل خواندن نیست.', 'falnic-secure-hls' ), 500 );
		}

		self::clean_buffers();

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . strlen( $key ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate, private' );

		echo $key; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- بایت خام کلید.
		exit;
	}

	/**
	 * AJAX: تحویل فایل زیرنویس (VTT).
	 *
	 * @return void
	 */
	public static function subtitle(): void {
		$name  = self::request_name();
		$token = self::request_token();

		$claims = Token::verify( $token, Token::SCOPE_SUBTITLE, $name );

		if ( is_wp_error( $claims ) ) {
			self::fail( $claims->get_error_message(), self::token_status() );
		}

		$reason = Access::reason( $name );

		if ( 'ok' !== $reason ) {
			self::fail( Access::message( $reason ), 'guest' === $reason ? 401 : 403 );
		}

		if ( ! self::session_alive( $claims ) ) {
			self::fail( __( 'این نشست پخش پایان یافته است؛ صفحه را دوباره بارگذاری کنید.', 'falnic-secure-hls' ), 403 );
		}

		$lang = Storage::sanitize_lang( (string) ( $_GET['l'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $lang ) {
			self::fail( __( 'درخواست معتبر نیست.', 'falnic-secure-hls' ), 404 );
		}

		$path = Storage::resolve_file( $name, 'captions/' . $lang . '.vtt' );

		if ( null === $path ) {
			self::fail( __( 'زیرنویس یافت نشد.', 'falnic-secure-hls' ), 404 );
		}

		self::serve_file( $path, 'text/vtt; charset=utf-8', 'videos/' . $name . '/captions/' . $lang . '.vtt' );
	}

	/**
	 * AJAX: ضربان نشست.
	 *
	 * @return void
	 */
	public static function heartbeat(): void {
		$name  = self::request_name();
		$token = self::request_token();

		$claims = Token::verify( $token, Token::SCOPE_HEARTBEAT, $name );

		if ( is_wp_error( $claims ) ) {
			wp_send_json_error(
				array(
					'alive'   => false,
					'reason'  => $claims->get_error_code(),
					'message' => $claims->get_error_message(),
				),
				403
			);
		}

		if ( self::rate_limited( 'heartbeat', self::HEARTBEAT_LIMIT ) ) {
			wp_send_json_error( array( 'alive' => false, 'message' => __( 'درخواست‌های بیش از حد.', 'falnic-secure-hls' ) ), 429 );
		}

		$sid = (string) ( $claims['sid'] ?? '' );

		if ( ! Play_Registry::check( (int) get_current_user_id(), $sid ) ) {
			wp_send_json_error(
				array(
					'alive'   => false,
					'reason'  => 'ended',
					'message' => __( 'نشست پخش پایان یافت. اگر در دستگاه دیگری وارد شده‌اید، همان پخش فعال است.', 'falnic-secure-hls' ),
				),
				403
			);
		}

		Play_Registry::touch( (int) get_current_user_id(), $sid );

		wp_send_json_success(
			array(
				'alive'   => true,
				'sid'     => $sid,
				'expires' => time() + Play_Registry::TTL,
			)
		);
	}

	/**
	 * AJAX: پایان دادن به نشست (هنگام بستن صفحه).
	 *
	 * @return void
	 */
	public static function end_session(): void {
		$name  = self::request_name();
		$token = self::request_token();

		$claims = Token::verify( $token, Token::SCOPE_HEARTBEAT, $name );

		if ( is_wp_error( $claims ) ) {
			status_header( 204 );
			exit;
		}

		$sid = (string) ( $claims['sid'] ?? '' );

		if ( '' !== $sid ) {
			Play_Registry::end( (int) get_current_user_id(), $sid );
			Watch_Log::end( $sid );
		}

		status_header( 204 );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * بازنویسی manifest
	 * ------------------------------------------------------------------ */

	/**
	 * بازنویسی یک خط از manifest.
	 *
	 * @param string $line     خط.
	 * @param string $name     نام ویدیو.
	 * @param string $base     پوشه‌ی نسبی پلی‌لیست جاری (برای کیفیت‌ها).
	 * @param bool   $variant  آیا این خط، آدرس یک پلی‌لیست کیفیت است.
	 * @param array  $claims   ادعاهای توکن جاری.
	 * @return string
	 */
	public static function rewrite_line( string $line, string $name, string $base = '', bool $variant = false, array $claims = array() ): string {
		$line = trim( $line );

		if ( '' === $line ) {
			return '';
		}

		$extra = array();

		if ( ! empty( $claims['sid'] ) ) {
			$extra['sid'] = (string) $claims['sid'];
		}

		if ( 0 === strpos( $line, '#EXT-X-KEY' ) || 0 === strpos( $line, '#EXT-X-SESSION-KEY' ) ) {
			return (string) preg_replace_callback(
				'/URI="([^"]*)"/',
				static function () use ( $name, $extra ) {
					return 'URI="' . Token::url( self::ACTION_KEY, $name, Token::SCOPE_KEY, $extra ) . '"';
				},
				$line
			);
		}

		if ( 0 === strpos( $line, '#EXT-X-MAP' ) ) {
			return (string) preg_replace_callback(
				'/URI="([^"]*)"/',
				static function ( $matches ) use ( $name, $base, $extra ) {
					return 'URI="' . self::segment_url( $name, self::join_relative( $base, (string) $matches[1] ), $extra ) . '"';
				},
				$line
			);
		}

		if ( 0 === strpos( $line, '#EXT-X-MEDIA' ) ) {
			return (string) preg_replace_callback(
				'/URI="([^"]*)"/',
				static function ( $matches ) use ( $name, $base, $extra ) {
					$uri = (string) $matches[1];

					if ( 'vtt' === strtolower( (string) pathinfo( (string) parse_url( $uri, PHP_URL_PATH ), PATHINFO_EXTENSION ) ) ) {
						$lang = (string) pathinfo( (string) parse_url( $uri, PHP_URL_PATH ), PATHINFO_FILENAME );

						return 'URI="' . self::subtitle_url( $name, $lang, $extra ) . '"';
					}

					return 'URI="' . self::variant_url( $name, self::join_relative( $base, $uri ), $extra ) . '"';
				},
				$line
			);
		}

		if ( '#' === $line[0] ) {
			return $line;
		}

		$extension = strtolower( (string) pathinfo( (string) parse_url( $line, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		if ( $variant || 'm3u8' === $extension ) {
			return self::variant_url( $name, self::join_relative( $base, $line ), $extra );
		}

		if ( 'vtt' === $extension ) {
			$lang = (string) pathinfo( (string) parse_url( $line, PHP_URL_PATH ), PATHINFO_FILENAME );

			return self::subtitle_url( $name, $lang, $extra );
		}

		return self::segment_url( $name, self::join_relative( $base, $line ), $extra );
	}

	/**
	 * نشانی امضاشده‌ی یک سگمنت.
	 *
	 * @param string $name     نام ویدیو.
	 * @param string $relative مسیر نسبی.
	 * @param array  $extra    ادعاهای اضافی.
	 * @return string
	 */
	private static function segment_url( string $name, string $relative, array $extra = array() ): string {
		$ttl = max( 300, (int) get_option( 'falnic_segment_token_ttl', 7200 ) );

		return add_query_arg(
			array(
				'action' => self::ACTION_SEGMENT,
				'video'  => $name,
				'f'      => $relative,
				't'      => Token::issue( Token::SCOPE_SEGMENT, $name, $extra, $ttl ),
			),
			Token::base_url()
		);
	}

	/**
	 * نشانی امضاشده‌ی یک پلی‌لیست کیفیت.
	 *
	 * @param string $name     نام ویدیو.
	 * @param string $relative مسیر نسبی.
	 * @param array  $extra    ادعاهای اضافی.
	 * @return string
	 */
	private static function variant_url( string $name, string $relative, array $extra = array() ): string {
		return add_query_arg(
			array(
				'action' => self::ACTION_MANIFEST,
				'video'  => $name,
				'f'      => $relative,
				't'      => Token::issue( Token::SCOPE_MANIFEST, $name, $extra ),
			),
			Token::base_url()
		);
	}

	/**
	 * نشانی امضاشده‌ی یک زیرنویس.
	 *
	 * @param string $name  نام ویدیو.
	 * @param string $lang  کد زبان.
	 * @param array  $extra ادعاهای اضافی.
	 * @return string
	 */
	private static function subtitle_url( string $name, string $lang, array $extra = array() ): string {
		$lang = Storage::sanitize_lang( $lang );

		return add_query_arg(
			array(
				'action' => self::ACTION_SUBTITLE,
				'video'  => $name,
				'l'      => $lang,
				't'      => Token::issue( Token::SCOPE_SUBTITLE, $name, $extra ),
			),
			Token::base_url()
		);
	}

	/**
	 * فهرست زیرنویس‌ها با نشانی امضاشده.
	 *
	 * @param string $name  نام ویدیو.
	 * @param array  $extra ادعاهای اضافی.
	 * @param int    $ttl   عمر توکن.
	 * @return array
	 */
	private static function subtitle_sources( string $name, array $extra, int $ttl ): array {
		$out = array();

		foreach ( Storage::subtitles( $name ) as $item ) {
			$out[] = array(
				'lang'  => $item['lang'],
				'label' => $item['label'],
				'src'   => add_query_arg(
					array(
						'action' => self::ACTION_SUBTITLE,
						'video'  => $name,
						'l'      => $item['lang'],
						't'      => Token::issue( Token::SCOPE_SUBTITLE, $name, $extra, $ttl ),
					),
					Token::base_url()
				),
			);
		}

		return $out;
	}

	/**
	 * پیوند مسیر پایه با مسیر نسبی.
	 *
	 * @param string $base     پوشه‌ی نسبی (ممکن است خالی باشد).
	 * @param string $relative مسیر.
	 * @return string
	 */
	public static function join_relative( string $base, string $relative ): string {
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );

		if ( '' === $base ) {
			return $relative;
		}

		if ( 0 === strpos( $relative, $base . '/' ) ) {
			return $relative;
		}

		return $base . '/' . $relative;
	}

	/* ---------------------------------------------------------------------
	 * تحویل فایل
	 * ------------------------------------------------------------------ */

	/**
	 * ارسال فایل با پشتیبانی Range و X-Accel-Redirect.
	 *
	 * @param string $path      مسیر مطلق.
	 * @param string $mime      نوع محتوا.
	 * @param string $accel_rel مسیر نسبی برای Nginx.
	 * @return void
	 */
	public static function serve_file( string $path, string $mime, string $accel_rel = '' ): void {
		$size = (int) filesize( $path );
		$size = max( 0, $size );

		if ( $accel_rel && defined( 'FALNIC_ACCEL_PREFIX' ) && '' !== (string) FALNIC_ACCEL_PREFIX ) {
			self::clean_buffers();

			nocache_headers();
			header( 'Content-Type: ' . $mime );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: private, max-age=0, no-store' );
			header( 'X-Accel-Redirect: ' . rtrim( (string) FALNIC_ACCEL_PREFIX, '/' ) . '/' . ltrim( $accel_rel, '/' ) );

			exit;
		}

		$start   = 0;
		$end     = max( 0, $size - 1 );
		$partial = false;

		if ( isset( $_SERVER['HTTP_RANGE'] ) ) {
			$range = sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) );

			if ( preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $matches ) ) {
				if ( '' !== $matches[1] ) {
					$start = (int) $matches[1];
				}

				if ( '' !== $matches[2] ) {
					$end = min( (int) $matches[2], $end );
				}

				if ( $start > $end || $start >= $size ) {
					self::clean_buffers();
					status_header( 416 );
					header( 'Content-Range: bytes */' . $size );

					exit;
				}

				$partial = true;
			}
		}

		$length = ( $end - $start ) + 1;

		self::clean_buffers();

		nocache_headers();
		status_header( $partial ? 206 : 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . $length );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, max-age=0, no-store' );

		if ( $partial ) {
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen

		if ( false === $handle ) {
			exit;
		}

		fseek( $handle, $start );

		$remaining = $length;

		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, (int) min( 65536, $remaining ) );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- بایت خام سگمنت.
			$remaining -= strlen( $chunk );

			if ( function_exists( 'flush' ) ) {
				flush();
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

		exit;
	}

	/* ---------------------------------------------------------------------
	 * کمکی‌ها
	 * ------------------------------------------------------------------ */

	/**
	 * نام ویدیو از درخواست.
	 *
	 * @return string
	 */
	private static function request_name(): string {
		if ( isset( $_GET['video'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_text_field( wp_unslash( $_GET['video'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( isset( $_POST['video'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_text_field( wp_unslash( $_POST['video'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		return '';
	}

	/**
	 * توکن از درخواست (پارامتر یا بدنه، برای sendBeacon).
	 *
	 * @return string
	 */
	private static function request_token(): string {
		if ( isset( $_GET['t'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_text_field( wp_unslash( $_GET['t'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( isset( $_POST['t'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_text_field( wp_unslash( $_POST['t'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		return '';
	}

	/**
	 * مسیر فایل درخواستی (برای کیفیت‌ها/سگمنت).
	 *
	 * @return string
	 */
	private static function request_file(): string {
		if ( isset( $_GET['f'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_text_field( wp_unslash( $_GET['f'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return '';
	}

	/**
	 * پوشه‌ی یک مسیر نسبی.
	 *
	 * @param string $relative مسیر.
	 * @return string
	 */
	private static function dir_of( string $relative ): string {
		$relative = str_replace( '\\', '/', $relative );
		$dir      = dirname( $relative );

		return ( '.' === $dir || '/' === $dir ) ? '' : trim( $dir, '/' );
	}

	/**
	 * آیا نشست پخش هنوز معتبر است؟
	 *
	 * @param array $claims ادعاهای توکن.
	 * @return bool
	 */
	private static function session_alive( array $claims ): bool {
		if ( ! Play_Registry::enabled() ) {
			return true;
		}

		$sid = isset( $claims['sid'] ) ? (string) $claims['sid'] : '';

		return '' !== $sid && Play_Registry::check( (int) get_current_user_id(), $sid );
	}

	/**
	 * ثبت محدودشده‌ی فعالیت تماشا.
	 *
	 * @param string $sid   شناسه‌ی نشست.
	 * @param int    $bytes حجم تقریبی.
	 * @return void
	 */
	private static function log_activity( string $sid, int $bytes ): void {
		if ( '' === $sid ) {
			return;
		}

		$key = 'falnic_wl_' . md5( $sid );

		if ( false !== get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, self::LOG_INTERVAL );

		Watch_Log::touch( $sid, $bytes );
	}

	/**
	 * کد وضعیت برای خطای توکن: مهمان ۴۰۱، کاربر لاگین‌کرده ۴۰۳.
	 *
	 * @return int
	 */
	private static function token_status(): int {
		return is_user_logged_in() ? 403 : 401;
	}

	/**
	 * آدرس صفحه‌ی جاری (برای بازگشت پس از ورود).
	 *
	 * @return string
	 */
	private static function current_url(): string {
		$referer = wp_get_referer();

		return $referer ? $referer : home_url( '/' );
	}

	/**
	 * خروجی خطای متنی (برای درخواست‌های مدیا JSON نیست).
	 *
	 * @param string $message پیام.
	 * @param int    $status  کد وضعیت.
	 * @return void
	 */
	private static function fail( string $message, int $status ): void {
		self::clean_buffers();

		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );

		echo esc_html( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- متن ساده.
		exit;
	}

	/**
	 * پاک‌کردن بافرها پیش از ارسال بایت خام.
	 *
	 * @return void
	 */
	private static function clean_buffers(): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( function_exists( 'ini_set' ) && ini_get( 'zlib.output_compression' ) ) {
			@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		}
	}

	/**
	 * محدودیت نرخ ساده‌ی پنجره‌ی ۶۰ ثانیه‌ای.
	 *
	 * @param string $bucket نام سطل.
	 * @param int    $max    سقف.
	 * @return bool
	 */
	private static function rate_limited( string $bucket, int $max ): bool {
		$user_id = (int) get_current_user_id();
		$key     = 'falnic_rl_' . $bucket . '_' . $user_id;
		$data    = get_transient( $key );

		if ( ! is_array( $data ) || empty( $data['start'] ) || ( time() - (int) $data['start'] ) >= MINUTE_IN_SECONDS ) {
			$data = array(
				'count' => 0,
				'start' => time(),
			);
		}

		if ( (int) $data['count'] >= $max ) {
			return true;
		}

		$data['count'] = (int) $data['count'] + 1;
		set_transient( $key, $data, MINUTE_IN_SECONDS );

		return false;
	}
}
