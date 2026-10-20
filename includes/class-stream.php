<?php
/**
 * دروازه‌ی تحویل استریم.
 *
 * پلی‌لیست، سگمنت و کلید هیچ‌گاه مستقیم از وب‌سرور تحویل نمی‌شوند؛
 * هر درخواست باید توکن امضاشده‌ی کوتاه‌عمر داشته باشد و دسترسی کاربر
 * دوباره بررسی می‌شود.
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
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		$actions = array(
			self::ACTION_SESSION  => 'session',
			self::ACTION_MANIFEST => 'manifest',
			self::ACTION_SEGMENT  => 'segment',
			self::ACTION_KEY      => 'key',
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
		$name = isset( $_REQUEST['video'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['video'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- توکن/احراز هویت جای nonce را می‌گیرد.

		if ( ! is_user_logged_in() ) {
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
			wp_send_json_error(
				array(
					'reason'   => $reason,
					'message'  => Access::message( $reason ),
					'courses'  => 'not_enrolled' === $reason || 'locked' === $reason ? Access::courses_for( $name ) : array(),
				),
				403
			);
		}

		if ( self::rate_limited( 'session', self::SESSION_LIMIT ) ) {
			wp_send_json_error( array( 'message' => __( 'درخواست‌های بیش از حد. کمی بعد تلاش کنید.', 'falnic-secure-hls' ) ), 429 );
		}

		$ttl = max( 60, (int) get_option( 'falnic_token_ttl', 600 ) );

		wp_send_json_success(
			array(
				'name'      => $name,
				'src'       => Token::url( self::ACTION_MANIFEST, $name, Token::SCOPE_MANIFEST, array(), $ttl ),
				'expires'   => time() + $ttl,
				'watermark' => Player::watermark_lines(),
			)
		);
	}

	/**
	 * AJAX: تحویل manifest بازنویسی‌شده.
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

		$path = Storage::playlist_path( $name );

		if ( ! is_readable( $path ) ) {
			self::fail( __( 'پلی‌لیست این ویدیو پیدا نشد.', 'falnic-secure-hls' ), 404 );
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen

		if ( false === $handle ) {
			self::fail( __( 'پلی‌لیست قابل خواندن نیست.', 'falnic-secure-hls' ), 500 );
		}

		$body = '';

		while ( ! feof( $handle ) ) {
			$line = fgets( $handle );

			if ( false === $line ) {
				break;
			}

			$body .= self::rewrite_line( rtrim( $line, "\r\n" ), $name ) . "\n";
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

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

		if ( self::rate_limited( 'segment', self::SEGMENT_LIMIT ) ) {
			header( 'Retry-After: 5' );
			self::fail( __( 'درخواست‌های بیش از حد. کمی بعد تلاش کنید.', 'falnic-secure-hls' ), 429 );
		}

		$relative = isset( $_GET['f'] ) ? sanitize_text_field( wp_unslash( $_GET['f'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$path     = Storage::resolve_file( $name, $relative );

		if ( null === $path ) {
			self::fail( __( 'فایل درخواستی معتبر نیست.', 'falnic-secure-hls' ), 404 );
		}

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

	/* ---------------------------------------------------------------------
	 * بازنویسی manifest
	 * ------------------------------------------------------------------ */

	/**
	 * بازنویسی یک خط از manifest.
	 *
	 * @param string $line خط.
	 * @param string $name نام ویدیو.
	 * @return string
	 */
	public static function rewrite_line( string $line, string $name ): string {
		$line = trim( $line );

		if ( '' === $line ) {
			return '';
		}

		if ( 0 === strpos( $line, '#EXT-X-KEY' ) || 0 === strpos( $line, '#EXT-X-SESSION-KEY' ) ) {
			return (string) preg_replace_callback(
				'/URI="([^"]*)"/',
				static function ( $matches ) use ( $name ) {
					return 'URI="' . Token::url( self::ACTION_KEY, $name, Token::SCOPE_KEY ) . '"';
				},
				$line
			);
		}

		if ( 0 === strpos( $line, '#EXT-X-MAP' ) ) {
			return (string) preg_replace_callback(
				'/URI="([^"]*)"/',
				static function ( $matches ) use ( $name ) {
					return 'URI="' . self::segment_url( $name, (string) $matches[1] ) . '"';
				},
				$line
			);
		}

		if ( '#' === $line[0] ) {
			return $line;
		}

		return self::segment_url( $name, $line );
	}

	/**
	 * نشانی امضاشده‌ی یک سگمنت.
	 *
	 * @param string $name     نام ویدیو.
	 * @param string $relative مسیر نسبی.
	 * @return string
	 */
	private static function segment_url( string $name, string $relative ): string {
		$ttl = max( 300, (int) get_option( 'falnic_segment_token_ttl', 7200 ) );

		return add_query_arg(
			array(
				'action' => self::ACTION_SEGMENT,
				'video'  => $name,
				'f'      => $relative,
				't'      => Token::issue( Token::SCOPE_SEGMENT, $name, array(), $ttl ),
			),
			admin_url( 'admin-ajax.php' )
		);
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

		$start = 0;
		$end   = max( 0, $size - 1 );
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
	 * کد وضعیت برای خطای توکن: مهمان ۴۰۱، کاربر لاگین‌کرده ۴۰۳.
	 *
	 * @return int
	 */
	private static function token_status(): int {
		return is_user_logged_in() ? 403 : 401;
	}

	/**
	 * نام ویدیو از درخواست.
	 *
	 * @return string
	 */
	private static function request_name(): string {
		return isset( $_GET['video'] ) ? sanitize_text_field( wp_unslash( $_GET['video'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * توکن از درخواست.
	 *
	 * @return string
	 */
	private static function request_token(): string {
		return isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

	/**
	 * نشانی جاری برای بازگشت پس از ورود.
	 *
	 * @return string
	 */
	private static function current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return home_url( $uri );
	}
}
