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
		add_action( 'admin_post_falnic_watch_log_action', array( __CLASS__, 'handle_watch_log' ) );
		add_action( 'admin_post_falnic_run_queue', array( __CLASS__, 'handle_run_queue' ) );
		add_action( 'admin_post_falnic_recheck_binaries', array( __CLASS__, 'handle_recheck_binaries' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * بارگذاری استایل مشترک در همه‌ی صفحه‌های افزونه.
	 *
	 * @param string $hook پسوند صفحه‌ی جاری.
	 * @return void
	 */
	public static function enqueue_assets( $hook ): void {
		if ( false === strpos( (string) $hook, 'falnic_' ) && false === strpos( (string) $hook, 'falnic-git-update' ) ) {
			return;
		}

		wp_enqueue_style( 'falnic-admin', FALNIC_URL . 'assets/css/admin.css', array(), FALNIC_VERSION );
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
			__( 'گزارش تماشا', 'falnic-secure-hls' ),
			__( 'گزارش تماشا', 'falnic-secure-hls' ),
			'manage_options',
			'falnic_watch_log',
			array( __CLASS__, 'render_watch_log_page' )
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
			'wm_enabled'    => isset( $_POST['wm_enabled'] ) ? 'yes' : 'no',
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

		$quality = isset( $_POST['hls_quality'] ) ? sanitize_key( wp_unslash( $_POST['hls_quality'] ) ) : 'auto';
		$quality = in_array( $quality, array( 'auto', 'single' ), true ) ? $quality : 'auto';

		update_option( 'falnic_hls_quality', $quality );
		update_option(
			'falnic_hls_max_renditions',
			isset( $_POST['max_renditions'] ) ? max( 1, min( 3, absint( wp_unslash( $_POST['max_renditions'] ) ) ) ) : 2
		);
		update_option(
			'falnic_hls_segment_seconds',
			isset( $_POST['segment_seconds'] ) ? max( 2, min( 10, absint( wp_unslash( $_POST['segment_seconds'] ) ) ) ) : 6
		);
		update_option(
			'falnic_max_concurrent',
			isset( $_POST['max_concurrent'] ) ? min( 10, absint( wp_unslash( $_POST['max_concurrent'] ) ) ) : 1
		);
		update_option(
			'falnic_watch_log_days',
			isset( $_POST['watch_log_days'] ) ? max( 7, min( 365, absint( wp_unslash( $_POST['watch_log_days'] ) ) ) ) : 90
		);

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
		$input_file = trailingslashit( dirname( $input_file ) ) . basename( $input_file );
		$raw_name   = isset( $_POST['video_name'] ) ? sanitize_text_field( wp_unslash( $_POST['video_name'] ) ) : '';

		$name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( urldecode( sanitize_title( $raw_name ) ) ) );
		if ( ! is_string( $name ) || '' === $name ) {
			$name = 'video-' . time();
		}

		$redirect = array(
			'page' => 'falnic_hls_encoder',
		);

		if ( '' === $input_file || ! is_file( $input_file ) || ! is_readable( $input_file ) ) {
			$redirect['error'] = 'file_not_found';
			wp_safe_redirect( add_query_arg( $redirect, admin_url( 'edit.php?post_type=' . Post_Type::SLUG ) ) );
			exit;
		}

		// فقط فایل ویدیویی؛ جلوی کپی شدن فایل‌های دلخواه سرور به پوشه‌ی امن را می‌گیرد.
		$input_ext = strtolower( (string) pathinfo( $input_file, PATHINFO_EXTENSION ) );

		if ( ! in_array( $input_ext, array( 'mp4', 'm4v', 'mov' ), true ) ) {
			$redirect['error'] = 'file_type';
			wp_safe_redirect( add_query_arg( $redirect, admin_url( 'edit.php?post_type=' . Post_Type::SLUG ) ) );
			exit;
		}

		$queued = Encode_Queue::enqueue( $name, $input_file, 0, (int) get_current_user_id(), true );

		if ( ! empty( $queued['ok'] ) ) {
			$redirect['queued'] = $name;
		} else {
			$redirect['error'] = 'queue_failed';
		}

		wp_safe_redirect( add_query_arg( $redirect, admin_url( 'edit.php?post_type=' . Post_Type::SLUG ) ) );
		exit;
	}

	/**
	 * اجرای فوری کارهای در صف (بدون انتظار برای WP-Cron).
	 *
	 * @return void
	 */
	public static function handle_run_queue(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'falnic-secure-hls' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'falnic_run_queue' );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		ignore_user_abort( true );

		$done = Encode_Queue::run_now( 1 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => Post_Type::SLUG,
					'page'      => 'falnic_hls_encoder',
					'ran'       => (int) $done,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * پاک‌کردن کش تشخیص ffmpeg/ffprobe و بررسی دوباره.
	 *
	 * @return void
	 */
	public static function handle_recheck_binaries(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'falnic-secure-hls' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'falnic_recheck_binaries' );

		foreach ( array( 'ffmpeg', 'ffprobe' ) as $falnic_binary ) {
			delete_option( 'falnic_bin_' . $falnic_binary );
			delete_option( 'falnic_bin_missing_' . $falnic_binary );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => Post_Type::SLUG,
					'page'      => 'falnic_hls_encoder',
					'rechecked' => '1',
				),
				admin_url( 'edit.php' )
			)
		);
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
		$hls_quality    = (string) get_option( 'falnic_hls_quality', 'auto' );
		$max_renditions = (int) get_option( 'falnic_hls_max_renditions', 2 );
		$segment_secs   = (int) get_option( 'falnic_hls_segment_seconds', 6 );
		$max_concurrent = (int) get_option( 'falnic_max_concurrent', 1 );
		$watch_log_days = (int) get_option( 'falnic_watch_log_days', 90 );
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

		$encoder_jobs  = Encode_Queue::jobs();
		$encoder_checks = self::encoder_checks();

		include FALNIC_DIR . 'admin/views/encoder.php';
	}

	/**
	 * بررسی‌های لازم برای اجرای انکود.
	 *
	 * @return array
	 */
	private static function encoder_checks(): array {
		$ffmpeg  = Encoder::ffmpeg_bin();
		$ffprobe = Encoder::ffprobe_bin();

		return array(
			array(
				'label' => __( 'اجرای فرمان در PHP (proc_open/exec)', 'falnic-secure-hls' ),
				'ok'    => Encoder::can_exec(),
				'hint'  => __( 'در php.ini یا کانفیگ هاست، disable_functions شامل proc_open و exec نباشد.', 'falnic-secure-hls' ),
			),
			array(
				'label' => __( 'ffmpeg در دسترس است', 'falnic-secure-hls' ),
				'ok'    => self::binary_exists( $ffmpeg ),
				'hint'  => __( 'مسیر ffmpeg را در wp-config با FALNIC_FFMPEG_BIN تعریف کنید.', 'falnic-secure-hls' ),
			),
			array(
				'label' => __( 'ffprobe در دسترس است', 'falnic-secure-hls' ),
				'ok'    => self::binary_exists( $ffprobe ),
				'hint'  => __( 'ffprobe معمولاً همراه ffmpeg نصب می‌شود؛ مسیر را با FALNIC_FFPROBE_BIN بدهید.', 'falnic-secure-hls' ),
			),
			array(
				'label' => __( 'WP-Cron غیرفعال نشده است', 'falnic-secure-hls' ),
				'ok'    => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
				'hint'  => __( 'اگر DISABLE_WP_CRON=true است، cron سیستمی برای wp-cron.php بسازید یا دکمه‌ی «اجرای فوری صف» را بزنید.', 'falnic-secure-hls' ),
			),
			array(
				'label' => __( 'پوشه‌ی موقت قابل نوشتن است', 'falnic-secure-hls' ),
				'ok'    => wp_is_writable( Storage::tmp_dir() ),
				'hint'  => __( 'مجوز پوشه‌ی falnic-secure/tmp را بررسی کنید.', 'falnic-secure-hls' ),
			),
		);
	}

	/**
	 * آیا باینری در سرور قابل اجراست؟
	 *
	 * @param string $binary مسیر یا نام.
	 * @return bool
	 */
	private static function binary_exists( string $binary ): bool {
		if ( '' === $binary ) {
			return false;
		}

		if ( false !== strpos( $binary, '/' ) ) {
			return is_executable( $binary );
		}

		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		$output = array();
		$code   = 1;

		try {
			@exec( 'command -v ' . escapeshellarg( $binary ) . ' 2>/dev/null', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec, WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( \Throwable $error ) {
			return false;
		}

		return 0 === $code && ! empty( $output );
	}

	/**
	 * صفحه‌ی گزارش تماشا.
	 *
	 * @return void
	 */
	public static function render_watch_log_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$watch_days = max( 1, (int) ( $_GET['days'] ?? 7 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$watch_video = isset( $_GET['video'] ) ? sanitize_text_field( wp_unslash( $_GET['video'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$watch_user  = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$watch_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$watch_args  = array(
			'days'     => $watch_days,
			'video'    => $watch_video,
			'user_id'  => $watch_user,
			'per_page' => 25,
			'page'     => $watch_page,
		);

		$watch_rows    = Watch_Log::query( $watch_args );
		$watch_total   = Watch_Log::count( $watch_args );
		$watch_summary = Watch_Log::summary();
		$watch_shared  = Watch_Log::shared_accounts( 3 );
		$watch_denied  = Watch_Log::denials_today();
		$watch_exists  = Watch_Log::exists();

		include FALNIC_DIR . 'admin/views/watch-log.php';
	}

	/**
	 * پردازش عملیات گزارش تماشا (قطع پخش / خروجی CSV).
	 *
	 * @return void
	 */
	public static function handle_watch_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'falnic-secure-hls' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'falnic_watch_log_action' );

		$op      = isset( $_POST['falnic_op'] ) ? sanitize_key( wp_unslash( $_POST['falnic_op'] ) ) : '';
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$video   = isset( $_POST['video'] ) ? sanitize_text_field( wp_unslash( $_POST['video'] ) ) : '';

		if ( 'revoke' === $op && $user_id > 0 ) {
			$count = Play_Registry::terminate_all( $user_id );

			wp_safe_redirect(
				add_query_arg(
					array(
						'post_type' => Post_Type::SLUG,
						'page'      => 'falnic_watch_log',
						'falnic'    => 'revoked',
						'count'     => $count,
					),
					admin_url( 'edit.php' )
				)
			);
			exit;
		}

		if ( 'export' === $op ) {
			self::export_watch_log( $video );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => Post_Type::SLUG,
					'page'      => 'falnic_watch_log',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * خروجی CSV از گزارش تماشا (حداکثر ۵۰۰۰ ردیف).
	 *
	 * @param string $video فیلتر ویدیو.
	 * @return void
	 */
	private static function export_watch_log( string $video ): void {
		$rows = Watch_Log::query(
			array(
				'video'    => $video,
				'days'     => 90,
				'per_page' => 5000,
				'page'     => 1,
			)
		);

		$filename = 'falnic-watch-log-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen

		if ( false === $out ) {
			exit;
		}

		// BOM برای نمایش صحیح فارسی در Excel.
		fwrite( $out, "ï»¿" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite

		fputcsv(
			$out,
			array(
				__( 'کاربر', 'falnic-secure-hls' ),
				__( 'ویدیو', 'falnic-secure-hls' ),
				__( 'شروع', 'falnic-secure-hls' ),
				__( 'آخرین فعالیت', 'falnic-secure-hls' ),
				__( 'مدت (ثانیه)', 'falnic-secure-hls' ),
				__( 'سگمنت', 'falnic-secure-hls' ),
				__( 'دستگاه', 'falnic-secure-hls' ),
				__( 'اثر انگشت IP', 'falnic-secure-hls' ),
				__( 'نشست', 'falnic-secure-hls' )
			)
		);

		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row['user_id'] );

			fputcsv(
				$out,
				array(
					$user ? $user->user_login : (string) $row['user_id'],
					$row['video'],
					$row['started_at'],
					$row['last_seen'],
					(int) $row['seconds'],
					(int) $row['segments'],
					$row['agent'],
					$row['ip_hash'],
					$row['session_id'],
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		exit;
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
