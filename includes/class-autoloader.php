<?php
/**
 * بارگذار خودکار کلاس‌ها.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * نگاشت نام کلاس به فایل، بدون نیاز به Composer.
 *
 * قاعده: Falnic\SecureHLS\Meta_Box  →  class-meta-box.php
 * ترتیب جست‌وجو: includes/ سپس admin/
 */
final class Autoloader {

	/**
	 * پیشوند namespace پلاگین.
	 */
	private const PREFIX = 'Falnic\\SecureHLS\\';

	/**
	 * ثبت بارگذار.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * بارگذاری فایل کلاس در صورت تعلق به این پلاگین.
	 *
	 * @param string $class_name نام کامل کلاس.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		if ( '' === $relative || false !== strpos( $relative, '\\' ) ) {
			return;
		}

		$file = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

		foreach ( array( FALNIC_DIR . 'includes/', FALNIC_DIR . 'admin/' ) as $directory ) {
			$path = $directory . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
