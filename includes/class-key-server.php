<?php
/**
 * تحویل کلید رمزنگاری به پلیر.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * اندپوینت سازگاری برای دریافت کلید AES.
 *
 * پلیر جدید کلید را از دروازه‌ی Stream (توکن‌دار) می‌گیرد؛ این اندپوینت
 * برای پلی‌لیست‌های قدیمی و سازگاری باقی مانده و همان بررسی دسترسی را دارد.
 */
final class Key_Server {

	/**
	 * حداکثر درخواست کلید در هر دقیقه برای هر کاربر.
	 */
	private const MAX_REQUESTS_PER_MINUTE = 30;

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		// فقط برای کاربران لاگین‌کرده؛ نسخه‌ی nopriv ثبت نمی‌شود.
		add_action( 'wp_ajax_secure_hls_key', array( __CLASS__, 'deliver' ) );
	}

	/**
	 * ارسال بایت‌های کلید.
	 *
	 * @return void
	 */
	public static function deliver(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Unauthorized', 'falnic-secure-hls' ), '', array( 'response' => 401 ) );
		}

		$name = isset( $_GET['video'] ) ? sanitize_text_field( wp_unslash( $_GET['video'] ) ) : '';

		if ( '' === $name || ! Storage::is_valid_name( $name ) ) {
			wp_die( esc_html__( 'درخواست نامعتبر است.', 'falnic-secure-hls' ), '', array( 'response' => 400 ) );
		}

		$reason = Access::reason( $name );

		if ( 'ok' !== $reason ) {
			wp_die(
				esc_html( Access::message( $reason ) ),
				'',
				array( 'response' => 'guest' === $reason ? 401 : 403 )
			);
		}

		if ( self::is_rate_limited() ) {
			wp_die( esc_html__( 'درخواست‌های بیش از حد. کمی بعد تلاش کنید.', 'falnic-secure-hls' ), '', array( 'response' => 429 ) );
		}

		if ( self::is_ip_mismatch() ) {
			wp_die( esc_html__( 'IP Mismatch', 'falnic-secure-hls' ), '', array( 'response' => 403 ) );
		}

		$key_path = Storage::key_path( $name );

		if ( ! is_readable( $key_path ) ) {
			wp_die( esc_html__( 'کلید یافت نشد.', 'falnic-secure-hls' ), '', array( 'response' => 404 ) );
		}

		$key = file_get_contents( $key_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $key ) {
			wp_die( esc_html__( 'کلید قابل خواندن نیست.', 'falnic-secure-hls' ), '', array( 'response' => 500 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . strlen( $key ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate, private' );

		echo $key; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- بایت خام کلید.
		exit;
	}

	/**
	 * محدودیت نرخ ساده با پنجره‌ی زمانی ۶۰ ثانیه‌ای.
	 *
	 * @return bool
	 */
	private static function is_rate_limited(): bool {
		$key  = 'falnic_key_rl_' . get_current_user_id();
		$data = get_transient( $key );

		if ( ! is_array( $data ) || empty( $data['start'] ) ) {
			$data = array(
				'count' => 0,
				'start' => time(),
			);
		}

		// شروع پنجره‌ی جدید.
		if ( ( time() - (int) $data['start'] ) >= MINUTE_IN_SECONDS ) {
			$data = array(
				'count' => 0,
				'start' => time(),
			);
		}

		if ( (int) $data['count'] >= self::MAX_REQUESTS_PER_MINUTE ) {
			return true;
		}

		$data['count'] = (int) $data['count'] + 1;
		set_transient( $key, $data, MINUTE_IN_SECONDS );

		return false;
	}

	/**
	 * بررسی تطابق IP در صورت فعال بودن گزینه.
	 *
	 * @return bool
	 */
	private static function is_ip_mismatch(): bool {
		if ( 'yes' !== get_option( 'falnic_hls_bind_ip', 'no' ) ) {
			return false;
		}

		$user = wp_get_current_user();

		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return false;
		}

		$session_ip = get_user_meta( $user->ID, 'falnic_last_login_ip', true );
		$current_ip = self::current_ip();

		return ( $session_ip && $current_ip && $session_ip !== $current_ip );
	}

	/**
	 * دریافت IP جاری با احترام به هدر پروکسی معتبر.
	 *
	 * @return string
	 */
	private static function current_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( defined( 'FALNIC_TRUSTED_PROXY_HEADER' ) ) {
			$header = (string) FALNIC_TRUSTED_PROXY_HEADER;
			if ( $header && ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			}
		}

		return $ip;
	}
}
