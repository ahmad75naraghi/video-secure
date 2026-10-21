<?php
/**
 * صفحه‌ی مدیریت «آپدیت از گیت».
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * رابط کاربری و هندلرهای درخواست.
 */
final class Git_Update_Page {

	/**
	 * اسلاگ صفحه.
	 */
	public const SLUG = 'falnic-git-update';

	/**
	 * نام اکشن nonce فرم تنظیمات.
	 */
	private const NONCE_SETTINGS = 'falnic_git_settings';

	/**
	 * نام اکشن nonce درخواست‌های AJAX.
	 */
	private const NONCE_AJAX = 'falnic_git_ajax';

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_falnic_save_git_settings', array( __CLASS__, 'handle_settings' ) );

		add_action( 'wp_ajax_falnic_git_check', array( __CLASS__, 'ajax_check' ) );
		add_action( 'wp_ajax_falnic_git_update', array( __CLASS__, 'ajax_update' ) );
		add_action( 'wp_ajax_falnic_git_rollback', array( __CLASS__, 'ajax_rollback' ) );
		add_action( 'wp_ajax_falnic_git_delete_backup', array( __CLASS__, 'ajax_delete_backup' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * بارگذاری دارایی‌ها فقط در همین صفحه.
	 *
	 * @param string $hook پسوند صفحه.
	 * @return void
	 */
	public static function enqueue( $hook ): void {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'falnic-admin',
			FALNIC_URL . 'assets/css/admin.css',
			array(),
			FALNIC_VERSION
		);

		wp_enqueue_script(
			'falnic-git-admin',
			FALNIC_URL . 'assets/js/admin-git.js',
			array(),
			FALNIC_VERSION,
			true
		);

		wp_localize_script(
			'falnic-git-admin',
			'FalnicGit',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
				'i18n'    => array(
					'confirmUpdate'   => __( 'آپدیت افزونه از گیت انجام شود؟ پیش از جایگزینی، پشتیبان گرفته می‌شود.', 'falnic-secure-hls' ),
					'confirmRollback' => __( 'بازگردانی به این پشتیبان انجام شود؟ فایل‌های فعلی جایگزین می‌شوند.', 'falnic-secure-hls' ),
					'confirmDelete'   => __( 'این فایل پشتیبان برای همیشه حذف شود؟', 'falnic-secure-hls' ),
					'working'         => __( 'در حال انجام…', 'falnic-secure-hls' ),
					'networkError'    => __( 'ارتباط با سرور برقرار نشد.', 'falnic-secure-hls' ),
					'reload'          => __( 'آپدیت انجام شد. صفحه دوباره بارگذاری می‌شود…', 'falnic-secure-hls' ),
					'stepCheck'       => __( 'در حال بررسی نسخه…', 'falnic-secure-hls' ),
					'stepDownload'    => __( 'دانلود، بررسی و پشتیبان‌گیری در حال انجام است…', 'falnic-secure-hls' ),
					'labelBranch'     => __( 'شاخه', 'falnic-secure-hls' ),
					'labelCommit'     => __( 'کامیت', 'falnic-secure-hls' ),
					'labelVersion'    => __( 'نسخه', 'falnic-secure-hls' ),
					'labelBackup'     => __( 'پشتیبان', 'falnic-secure-hls' ),
					'labelAdded'      => __( 'فایل‌های افزوده‌شده', 'falnic-secure-hls' ),
					'labelChanged'    => __( 'فایل‌های تغییرکرده', 'falnic-secure-hls' ),
					'labelRemoved'    => __( 'فایل‌های حذف‌شده', 'falnic-secure-hls' ),
					'backupAvailable' => __( 'پشتیبان موجود', 'falnic-secure-hls' ),
					'rollbackHint'    => __( 'از کارت پشتیبان‌ها بازگردانی کنید.', 'falnic-secure-hls' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * دسترسی
	 * ------------------------------------------------------------------ */

	/**
	 * بررسی capability و nonce درخواست AJAX.
	 *
	 * @return void
	 */
	private static function guard_ajax(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'شما مجوز به‌روزرسانی افزونه‌ها را ندارید.', 'falnic-secure-hls' ) ),
				403
			);
		}

		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
	}

	/* ---------------------------------------------------------------------
	 * Handler ها
	 * ------------------------------------------------------------------ */

	/**
	 * ذخیره‌ی تنظیمات منبع.
	 *
	 * @return void
	 */
	public static function handle_settings(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'شما مجوز به‌روزرسانی افزونه‌ها را ندارید.', 'falnic-secure-hls' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_SETTINGS );

		$repo        = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$branch      = isset( $_POST['branch'] ) ? sanitize_text_field( wp_unslash( $_POST['branch'] ) ) : 'main';
		$token       = isset( $_POST['token'] ) ? trim( (string) wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- توکن ممکن است کاراکتر خاص داشته باشد؛ فقط trim می‌شود.
		$clear_token = isset( $_POST['clear_token'] );

		$result = Git_Updater::save_settings( $repo, $branch, $token, $clear_token );

		if ( is_wp_error( $result ) ) {
			set_transient( 'falnic_git_settings_error_' . get_current_user_id(), $result->get_error_message(), 60 );
			$flag = 'error';
		} else {
			$flag = 'saved';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => Post_Type::SLUG,
					'page'      => self::SLUG,
					'falnic'    => $flag,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * AJAX: بررسی آخرین commit.
	 *
	 * @return void
	 */
	public static function ajax_check(): void {
		self::guard_ajax();

		$info = Git_Updater::check_for_update();

		if ( is_wp_error( $info ) ) {
			wp_send_json_error( array( 'message' => $info->get_error_message() ), 200 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'اطلاعات آخرین نسخه دریافت شد.', 'falnic-secure-hls' ),
				'info'    => $info,
			),
			200
		);
	}

	/**
	 * AJAX: اجرای آپدیت.
	 *
	 * @return void
	 */
	public static function ajax_update(): void {
		self::guard_ajax();

		$report = Git_Updater::run_update();

		if ( is_wp_error( $report ) ) {
			$data = (array) $report->get_error_data();

			wp_send_json_error(
				array(
					'message'          => $report->get_error_message(),
					'code'             => $report->get_error_code(),
					'backup_available' => ! empty( $data['backup'] ),
					'backup'           => isset( $data['backup'] ) ? (string) $data['backup'] : '',
					'errors'           => isset( $data['errors'] ) && is_array( $data['errors'] ) ? $data['errors'] : array(),
				),
				200
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'آپدیت با موفقیت انجام شد.', 'falnic-secure-hls' ),
				'report'  => $report,
			),
			200
		);
	}

	/**
	 * AJAX: بازگردانی از پشتیبان.
	 *
	 * @return void
	 */
	public static function ajax_rollback(): void {
		self::guard_ajax();

		$name = isset( $_POST['backup'] ) ? sanitize_file_name( wp_unslash( $_POST['backup'] ) ) : '';

		$report = Git_Updater::run_rollback( $name );

		if ( is_wp_error( $report ) ) {
			wp_send_json_error( array( 'message' => $report->get_error_message() ), 200 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'بازگردانی با موفقیت انجام شد.', 'falnic-secure-hls' ),
				'report'  => $report,
			),
			200
		);
	}

	/**
	 * AJAX: حذف پشتیبان.
	 *
	 * @return void
	 */
	public static function ajax_delete_backup(): void {
		self::guard_ajax();

		$name   = isset( $_POST['backup'] ) ? sanitize_file_name( wp_unslash( $_POST['backup'] ) ) : '';
		$result = Git_Updater::delete_backup( $name );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 200 );
		}

		wp_send_json_success( array( 'message' => __( 'فایل پشتیبان حذف شد.', 'falnic-secure-hls' ) ), 200 );
	}

	/* ---------------------------------------------------------------------
	 * نمایش
	 * ------------------------------------------------------------------ */

	/**
	 * رندر صفحه.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'شما مجوز به‌روزرسانی افزونه‌ها را ندارید.', 'falnic-secure-hls' ), '', array( 'response' => 403 ) );
		}

		$settings = Git_Updater::get_settings();

		$error = get_transient( 'falnic_git_settings_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'falnic_git_settings_error_' . get_current_user_id() );
		}

		$context = array(
			'settings'     => $settings,
			'error'        => $error ? (string) $error : '',
			'notice'       => isset( $_GET['falnic'] ) ? sanitize_key( wp_unslash( $_GET['falnic'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'version'      => Git_Updater::installed_version(),
			'main_file'    => Git_Updater::main_file_relative(),
			'plugin_dir'   => Git_Updater::plugin_dir(),
			'backup_root'  => Git_Updater::backup_root(),
			'backups'      => Git_Updater::list_backups(),
			'requirements' => Git_Updater::requirements(),
			'locked'       => Git_Updater::is_locked(),
			'max_backups'  => Git_Updater::MAX_BACKUPS,
		);

		// متغیرهای نما باید جداگانه در scope باشند — بدون extract همه‌ی نماها Warning می‌دهند.
		extract( $context, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract

		include FALNIC_DIR . 'admin/views/git-update.php';
	}
}
