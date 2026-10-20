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
		'falnic_hls_max_sessions' => 1,
		'falnic_hls_bind_ip'      => 'no',
	);

	/**
	 * فعال‌سازی.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Storage::ensure_dirs();

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
