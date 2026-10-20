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

// تنظیمات آپدیت از گیت (شامل توکن).
delete_option( 'falnic_git_repo' );
delete_option( 'falnic_git_branch' );
delete_option( 'falnic_git_token' );
delete_transient( 'falnic_git_lock' );

// توجه: پوشهٔ پشتیبان‌ها (uploads/falnic-backups) و ویدیوهای رمزنگاری‌شده
// عمداً حذف نمی‌شوند تا حذف اتفاقی افزونه باعث از دست رفتن محتوا نشود.

// متای کاربران.
delete_metadata( 'user', 0, 'falnic_last_login_ip', '', true );

// transient های باقی‌مانده.
global $wpdb;

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_falnic_%'
	    OR option_name LIKE '_transient_timeout_falnic_%'"
);
