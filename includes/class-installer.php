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
		'falnic_ld_auto_embed'         => 'yes',
		'falnic_ld_position'           => 'before',
		'falnic_ld_require_enrollment' => 'yes',
		'falnic_token_ttl'             => 600,
		'falnic_max_upload_mb'         => 2048,
		'falnic_segment_token_ttl'     => 7200,
	);

	/**
	 * فعال‌سازی.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Storage::ensure_dirs();
		self::add_defaults();

		update_option( 'falnic_db_version', FALNIC_VERSION, false );
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
		// عمداً کاری انجام نمی‌شود؛ حذف داده‌ها فقط با uninstall.php.
	}
}
