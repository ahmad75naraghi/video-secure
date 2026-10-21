<?php
/**
 * نمایش نوتیس‌های موفقیت و خطای انکود.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * نوتیس‌های مدیریت.
 */
final class Admin_Notices {

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
	}

	/**
	 * هشدار وقتی سقف آپلود PHP از سقف تنظیم‌شده کم‌تر است.
	 *
	 * در این حالت فایل بزرگ بی‌صدا دور ریخته می‌شود و کاربر فکر می‌کند
	 * «ویدیو انکود نشد»؛ پس دلیل واقعی را نشان می‌دهیم.
	 *
	 * @return void
	 */
	private static function server_limit_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || Post_Type::SLUG !== (string) $screen->post_type ) {
			return;
		}

		$configured = max( 1, (int) get_option( 'falnic_max_upload_mb', 2048 ) ) * MB_IN_BYTES;
		$post_max   = self::ini_bytes( (string) ini_get( 'post_max_size' ) );
		$file_max   = self::ini_bytes( (string) ini_get( 'upload_max_filesize' ) );
		$server     = min( array_filter( array( $post_max, $file_max ) ) ?: array( 0 ) );

		if ( $server <= 0 || $server >= $configured ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			sprintf(
				/* translators: 1: سقف سرور، 2: سقف تنظیم‌شده */
				esc_html__( 'سقف آپلود سرور (%1$s) از سقف تنظیم‌شده‌ی افزونه (%2$s) کم‌تر است؛ فایل‌های بزرگ‌تر بی‌صدا رد می‌شوند. مقدار post_max_size و upload_max_filesize را افزایش دهید یا فایل را کوچک‌تر کنید.', 'falnic-secure-hls' ),
				esc_html( size_format( $server ) ),
				esc_html( size_format( $configured ) )
			)
		);
	}

	/**
	 * تبدیل مقدار ini به بایت.
	 *
	 * @param string $value مقدار ini.
	 * @return int
	 */
	private static function ini_bytes( string $value ): int {
		$value = trim( $value );

		if ( '' === $value ) {
			return 0;
		}

		$unit  = strtolower( substr( $value, -1 ) );
		$number = (float) $value;

		switch ( $unit ) {
			case 'g':
				$number *= 1024;
				// می‌افتد به m.
			case 'm':
				$number *= 1024;
				// می‌افتد به k.
			case 'k':
				$number *= 1024;
		}

		return (int) $number;
	}

	/**
	 * چاپ نوتیس‌ها.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id = get_current_user_id();

		$error = get_transient( 'falnic_encode_error_' . $user_id );
		if ( $error ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s<br>%s</p></div>',
				esc_html__( 'خطا در پردازش ویدیو:', 'falnic-secure-hls' ),
				nl2br( esc_html( $error ) )
			);
			delete_transient( 'falnic_encode_error_' . $user_id );
		}

		self::server_limit_notice();

		$success = get_transient( 'falnic_encode_success_' . $user_id );
		if ( $success ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s <strong>%s</strong> %s</p></div>',
				esc_html__( '✅ ویدیوی', 'falnic-secure-hls' ),
				esc_html( $success ),
				esc_html__( 'آماده پخش است.', 'falnic-secure-hls' )
			);
			delete_transient( 'falnic_encode_success_' . $user_id );
		}
	}
}
