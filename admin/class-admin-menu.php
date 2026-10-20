<?php
/**
 * منوها و صفحه‌های مدیریت.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ثبت زیرمنوهای پلاگین و پردازش فرم‌ها.
 */
final class Admin_Menu {

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_falnic_save_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_falnic_run_encoder', array( __CLASS__, 'handle_encoder' ) );
	}

	/**
	 * ثبت زیرمنوها.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::SLUG,
			__( 'انکودر دستی', 'falnic-secure-hls' ),
			__( 'انکودر دستی', 'falnic-secure-hls' ),
			'manage_options',
			'falnic_hls_encoder',
			array( __CLASS__, 'render_encoder_page' )
		);

		add_submenu_page(
			'edit.php?post_type=' . Post_Type::SLUG,
			__( 'تنظیمات امنیتی', 'falnic-secure-hls' ),
			__( 'تنظیمات', 'falnic-secure-hls' ),
			'manage_options',
			'falnic_hls_settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * پردازش فرم تنظیمات.
	 *
	 * @return void
	 */
	public static function handle_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'falnic-secure-hls' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'falnic_settings' );

		$max_sessions = isset( $_POST['max_sessions'] ) ? absint( wp_unslash( $_POST['max_sessions'] ) ) : 1;
		$bind_ip      = isset( $_POST['bind_ip'] ) ? 'yes' : 'no';

		update_option( 'falnic_hls_max_sessions', max( 1, $max_sessions ) );
		update_option( 'falnic_hls_bind_ip', $bind_ip );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'falnic_hls_settings',
					'updated' => '1',
				),
				admin_url( 'edit.php?post_type=' . Post_Type::SLUG )
			)
		);
		exit;
	}

	/**
	 * پردازش فرم انکودر دستی.
	 *
	 * @return void
	 */
	public static function handle_encoder(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'falnic-secure-hls' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'falnic_encoder' );

		$input_file = isset( $_POST['input_file'] ) ? sanitize_text_field( wp_unslash( $_POST['input_file'] ) ) : '';
		$raw_name   = isset( $_POST['video_name'] ) ? sanitize_text_field( wp_unslash( $_POST['video_name'] ) ) : '';

		$name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( urldecode( sanitize_title( $raw_name ) ) ) );
		if ( ! is_string( $name ) || '' === $name ) {
			$name = 'video-' . time();
		}

		$redirect = array(
			'page' => 'falnic_hls_encoder',
		);

		if ( ! file_exists( $input_file ) ) {
			$redirect['error'] = 'file_not_found';
			wp_safe_redirect( add_query_arg( $redirect, admin_url( 'edit.php?post_type=' . Post_Type::SLUG ) ) );
			exit;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		ignore_user_abort( true );

		if ( Encoder::encode( $input_file, $name ) ) {
			if ( ! post_exists( $name ) ) {
				wp_insert_post(
					array(
						'post_type'   => Post_Type::SLUG,
						'post_title'  => $name,
						'post_status' => 'publish',
					)
				);
			}
			$redirect['encoded'] = $name;
		}

		wp_safe_redirect( add_query_arg( $redirect, admin_url( 'edit.php?post_type=' . Post_Type::SLUG ) ) );
		exit;
	}

	/**
	 * صفحه‌ی تنظیمات.
	 *
	 * @return void
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$max_sessions = (int) get_option( 'falnic_hls_max_sessions', 1 );
		$bind_ip      = get_option( 'falnic_hls_bind_ip', 'no' );
		$status       = self::system_status();

		include FALNIC_DIR . 'admin/views/settings.php';
	}

	/**
	 * صفحه‌ی انکودر دستی.
	 *
	 * @return void
	 */
	public static function render_encoder_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		include FALNIC_DIR . 'admin/views/encoder.php';
	}

	/**
	 * بررسی وضعیت سیستم برای نمایش در پنل.
	 *
	 * @return array
	 */
	private static function system_status(): array {
		$secure_dir = Storage::secure_dir();
		$videos_dir = Storage::videos_dir();

		return array(
			array(
				'label' => __( 'تابع exec (برای ffmpeg)', 'falnic-secure-hls' ),
				'ok'    => function_exists( 'exec' ),
			),
			array(
				'label' => __( 'پوشه‌ی کلیدها خارج از web root', 'falnic-secure-hls' ),
				'ok'    => 0 !== strpos( wp_normalize_path( $secure_dir ), wp_normalize_path( ABSPATH ) ),
			),
			array(
				'label' => __( 'پوشه‌ی کلیدها قابل نوشتن', 'falnic-secure-hls' ),
				'ok'    => wp_is_writable( $secure_dir ),
			),
			array(
				'label' => __( 'پوشه‌ی ویدیوها قابل نوشتن', 'falnic-secure-hls' ),
				'ok'    => wp_is_writable( $videos_dir ),
			),
			array(
				'label' => __( 'اتصال امن HTTPS', 'falnic-secure-hls' ),
				'ok'    => is_ssl(),
			),
			array(
				'label' => __( 'نسخه‌ی PHP 7.4 یا بالاتر', 'falnic-secure-hls' ),
				'ok'    => version_compare( PHP_VERSION, '7.4', '>=' ),
			),
		);
	}
}
