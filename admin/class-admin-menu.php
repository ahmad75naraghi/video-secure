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
		add_action( 'admin_post_falnic_save_template', array( __CLASS__, 'handle_template' ) );
		add_action( 'admin_post_falnic_migrate_storage', array( __CLASS__, 'handle_migrate_storage' ) );
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

		add_submenu_page(
			'edit.php?post_type=' . Post_Type::SLUG,
			__( 'تنظیمات قالب', 'falnic-secure-hls' ),
			__( 'تنظیمات قالب', 'falnic-secure-hls' ),
			'manage_options',
			'falnic_template_settings',
			array( __CLASS__, 'render_template_page' )
		);

		add_submenu_page(
			'edit.php?post_type=' . Post_Type::SLUG,
			__( 'آپدیت از گیت', 'falnic-secure-hls' ),
			__( 'آپدیت از گیت', 'falnic-secure-hls' ),
			'update_plugins',
			Git_Update_Page::SLUG,
			array( Git_Update_Page::class, 'render' )
		);
	}

	/**
	 * پردازش فرم تنظیمات قالب.
	 *
	 * @return void
	 */
	public static function handle_template(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'falnic-secure-hls' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'falnic_template_settings' );

		$input = array(
			'radius'        => isset( $_POST['radius'] ) ? absint( wp_unslash( $_POST['radius'] ) ) : 8,
			'accent'        => isset( $_POST['accent'] ) ? sanitize_text_field( wp_unslash( $_POST['accent'] ) ) : '#2271b1',
			'ratio'         => isset( $_POST['ratio'] ) ? sanitize_text_field( wp_unslash( $_POST['ratio'] ) ) : '16:9',
			'wm_opacity'    => isset( $_POST['wm_opacity'] ) ? (float) wp_unslash( $_POST['wm_opacity'] ) : 0.45,
			'wm_interval'   => isset( $_POST['wm_interval'] ) ? absint( wp_unslash( $_POST['wm_interval'] ) ) : 30,
			'wm_font_scale' => isset( $_POST['wm_font_scale'] ) ? (float) wp_unslash( $_POST['wm_font_scale'] ) : 1,
			'wm_show_email' => isset( $_POST['wm_show_email'] ) ? 'yes' : 'no',
		);

		Template_Settings::save( $input );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => Post_Type::SLUG,
					'page'      => 'falnic_template_settings',
					'falnic'    => 'saved',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * صفحه‌ی تنظیمات قالب.
	 *
	 * @return void
	 */
	public static function render_template_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Template_Settings::get();

		include FALNIC_DIR . 'admin/views/template.php';
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

		$max_sessions  = isset( $_POST['max_sessions'] ) ? absint( wp_unslash( $_POST['max_sessions'] ) ) : 1;
		$bind_ip       = isset( $_POST['bind_ip'] ) ? 'yes' : 'no';
		$auto_embed    = isset( $_POST['ld_auto_embed_present'] )
			? ( isset( $_POST['ld_auto_embed'] ) ? 'yes' : 'no' )
			: (string) get_option( 'falnic_ld_auto_embed', 'yes' );
		$position      = isset( $_POST['ld_position'] ) ? sanitize_key( wp_unslash( $_POST['ld_position'] ) ) : 'before';
		$require_enrol = isset( $_POST['ld_require_enrollment_present'] )
			? ( isset( $_POST['ld_require_enrollment'] ) ? 'yes' : 'no' )
			: (string) get_option( 'falnic_ld_require_enrollment', 'yes' );

		if ( ! in_array( $position, array( 'before', 'after' ), true ) ) {
			$position = 'before';
		}

		update_option( 'falnic_hls_max_sessions', max( 1, $max_sessions ) );
		update_option( 'falnic_hls_bind_ip', $bind_ip );
		update_option( 'falnic_ld_auto_embed', $auto_embed );
		update_option( 'falnic_ld_position', $position );
		update_option( 'falnic_ld_require_enrollment', $require_enrol );

		if ( isset( $_POST['max_upload_mb'] ) ) {
			update_option( 'falnic_max_upload_mb', max( 1, absint( wp_unslash( $_POST['max_upload_mb'] ) ) ) );
		}

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
	 * انتقال فایل‌های قدیمی از uploads به پوشه‌ی امن.
	 *
	 * @return void
	 */
	public static function handle_migrate_storage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'falnic-secure-hls' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'falnic_migrate_storage' );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$result = Storage::migrate_all( 20 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'falnic_hls_settings',
					'migrated'  => (int) $result['moved'],
					'failed'    => (int) $result['failed'],
					'pending'   => (int) $result['remaining'],
				),
				admin_url( 'edit.php?post_type=' . Post_Type::SLUG )
			)
		);
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

		$max_sessions   = (int) get_option( 'falnic_hls_max_sessions', 1 );
		$bind_ip        = get_option( 'falnic_hls_bind_ip', 'no' );
		$auto_embed     = get_option( 'falnic_ld_auto_embed', 'yes' );
		$ld_position    = get_option( 'falnic_ld_position', 'before' );
		$require_enrol  = get_option( 'falnic_ld_require_enrollment', 'yes' );
		$max_upload_mb  = (int) get_option( 'falnic_max_upload_mb', 2048 );
		$ld_active      = Learn_Dash::is_active();
		$legacy_pending = Storage::legacy_pending();
		$storage_root   = Storage::root();
		$outside_root   = Storage::is_outside_webroot();
		$status         = self::system_status();
		$page_slug      = 'falnic_hls_settings';

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
				'label' => __( 'پوشه‌ی رسانه خارج از web root', 'falnic-secure-hls' ),
				'ok'    => Storage::is_outside_webroot(),
			),
			array(
				'label' => __( 'همه‌ی ویدیوها به پوشه‌ی امن منتقل شده‌اند', 'falnic-secure-hls' ),
				'ok'    => empty( Storage::legacy_pending() ),
			),
			array(
				'label' => __( 'LearnDash فعال است', 'falnic-secure-hls' ),
				'ok'    => Learn_Dash::is_active(),
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
