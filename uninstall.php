<?php
/**
 * پاک‌سازی هنگام حذف کامل پلاگین.
 *
 * توجه: فایل‌های ویدیو و کلیدها به‌صورت پیش‌فرض حذف نمی‌شوند تا حذف
 * اتفاقی پلاگین باعث از دست رفتن محتوا نشود. برای حذف آن‌ها از دستور
 * `wp falnic purge-all` یا حذف دستی پوشه‌ها استفاده کنید.
 *
 * @package Falnic\SecureHLS
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// آپشن‌ها.
delete_option( 'falnic_hls_max_sessions' );
delete_option( 'falnic_hls_bind_ip' );
delete_option( 'falnic_template_settings' );
delete_option( 'falnic_ld_auto_embed' );
delete_option( 'falnic_ld_position' );
delete_option( 'falnic_ld_require_enrollment' );
delete_option( 'falnic_token_ttl' );
delete_option( 'falnic_segment_token_ttl' );
delete_option( 'falnic_db_version' );
delete_option( 'falnic_max_upload_mb' );
delete_option( 'falnic_hls_quality' );
delete_option( 'falnic_hls_max_renditions' );
delete_option( 'falnic_hls_segment_seconds' );
delete_option( 'falnic_max_concurrent' );
delete_option( 'falnic_watch_log_days' );
delete_option( 'falnic_log_db_version' );
delete_option( 'falnic_log_salt' );
delete_option( 'falnic_deny_stats' );
delete_option( 'falnic_encode_jobs' );

// تنظیمات آپدیت از گیت (شامل توکن).
delete_option( 'falnic_git_repo' );
delete_option( 'falnic_git_branch' );
delete_option( 'falnic_git_token' );
delete_transient( 'falnic_git_lock' );

// توجه: پوشهٔ پشتیبان‌ها (uploads/falnic-backups) و ویدیوهای رمزنگاری‌شده
// عمداً حذف نمی‌شوند تا حذف اتفاقی افزونه باعث از دست رفتن محتوا نشود.

// متای کاربران.
delete_metadata( 'user', 0, 'falnic_last_login_ip', '', true );

// متای پست‌ها: شناسه‌ی پایدار ویدیو و اتصال درس.
delete_metadata( 'post', 0, 'falnic_video_name', '', true );
delete_metadata( 'post', 0, '_falnic_video_name', '', true );

// جدول گزارش تماشا و رویدادهای زمان‌بندی‌شده.
wp_clear_scheduled_hook( 'falnic_encode_run' );
wp_clear_scheduled_hook( 'falnic_encode_cleanup' );
wp_clear_scheduled_hook( 'falnic_watch_log_purge' );

global $wpdb;

$falnic_watch_table = $wpdb->prefix . 'falnic_watch_log';

$wpdb->query( "DROP TABLE IF EXISTS {$falnic_watch_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// transient های باقی‌مانده.

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_falnic_%'
	    OR option_name LIKE '_transient_timeout_falnic_%'"
);
