<?php
/**
 * فعال‌سازی و غیرفعال‌سازی پلاگین.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * آماده‌سازی محیط در زمان فعال‌سازی.
 */
final class Installer {

	/**
	 * مقدار پیش‌فرض آپشن‌های امنیتی.
	 */
	private const DEFAULTS = array(
		'falnic_hls_max_sessions'      => 1,
		'falnic_hls_bind_ip'           => 'no',
		'falnic_ld_auto_embed'         => 'no',
		'falnic_ld_position'           => 'before',
		'falnic_ld_require_enrollment' => 'yes',
		'falnic_token_ttl'             => 600,
		'falnic_max_upload_mb'         => 2048,
		'falnic_segment_token_ttl'     => 7200,
		'falnic_hls_quality'           => 'auto',
		'falnic_hls_max_renditions'    => 2,
		'falnic_hls_segment_seconds'   => 6,
		'falnic_max_concurrent'        => 1,
		'falnic_watch_log_days'        => 90,
	);

	/**
	 * فعال‌سازی.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Storage::ensure_dirs();
		self::add_defaults();
		self::install_schema();
		self::schedule_events();

		update_option( 'falnic_db_version', FALNIC_VERSION, false );
	}

	/**
	 * ساخت جدول‌های سفارشی و زمان‌بندی رویدادهای دوره‌ای.
	 *
	 * @return void
	 */
	private static function install_schema(): void {
		if ( Watch_Log::DB_VERSION !== (string) get_option( 'falnic_log_db_version', '' ) ) {
			Watch_Log::install();
		}
	}

	/**
	 * زمان‌بندی رویدادهای WP-Cron.
	 *
	 * @return void
	 */
	public static function schedule_events(): void {
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) ) {
			if ( ! wp_next_scheduled( Encode_Queue::CLEANUP_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Encode_Queue::CLEANUP_HOOK );
			}

			if ( ! wp_next_scheduled( Watch_Log::PURGE_HOOK ) ) {
				wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', Watch_Log::PURGE_HOOK );
			}
		}
	}

	/**
	 * حذف رویدادهای زمان‌بندی‌شده.
	 *
	 * @return void
	 */
	public static function unschedule_events(): void {
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return;
		}

		wp_clear_scheduled_hook( Encode_Queue::CLEANUP_HOOK );
		wp_clear_scheduled_hook( Watch_Log::PURGE_HOOK );
		wp_clear_scheduled_hook( Encode_Queue::CRON_HOOK );
	}

	/**
	 * اجرای یک‌باره‌ی به‌روزرسانی ساختار پس از آپدیت افزونه.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( FALNIC_VERSION === get_option( 'falnic_db_version', '' ) ) {
			return;
		}

		Storage::ensure_dirs();
		self::add_defaults();
		self::schedule_events();

		// 3.5.20: حذف نمایش خودکار در دوره — طبق درخواست کاربر، ویدیو فقط با شورت‌کد دستی نمایش داده می‌شود
		if ( 'yes' === get_option( 'falnic_ld_auto_embed', 'no' ) ) {
			update_option( 'falnic_ld_auto_embed', 'no' );
		}

		if ( Watch_Log::DB_VERSION !== (string) get_option( 'falnic_log_db_version', '' ) ) {
			Watch_Log::install();
		}

		update_option( 'falnic_db_version', FALNIC_VERSION, false );
	}

	/**
	 * افزودن گزینه‌های پیش‌فرض بدون بازنویسی مقادیر موجود.
	 *
	 * @return void
	 */
	private static function add_defaults(): void {
		foreach ( self::DEFAULTS as $key => $value ) {
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $value );
			}
		}
	}

	/**
	 * غیرفعال‌سازی (داده‌ها حذف نمی‌شوند).
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// داده‌ها حذف نمی‌شوند؛ فقط رویدادهای دوره‌ای پاک می‌شوند.
		self::unschedule_events();
	}
}
