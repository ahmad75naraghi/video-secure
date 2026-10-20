<?php
/**
 * مدیریت مسیرها و فایل‌های رسانه.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * تمام دسترسی‌های فایل‌سیستم از این کلاس عبور می‌کنند.
 */
final class Storage {

	/**
	 * پوشه‌ی کلیدها، خارج از web root.
	 *
	 * @return string
	 */
	public static function secure_dir(): string {
		return trailingslashit( dirname( ABSPATH ) ) . 'secure_keys/';
	}

	/**
	 * پوشه‌ی سگمنت‌ها و پلی‌لیست‌ها.
	 *
	 * @return string
	 */
	public static function videos_dir(): string {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . 'secure_videos/';
	}

	/**
	 * آدرس عمومی پوشه‌ی ویدیوها.
	 *
	 * @return string
	 */
	public static function videos_url(): string {
		$uploads = wp_upload_dir();

		return trailingslashit( set_url_scheme( $uploads['baseurl'] ) ) . 'secure_videos/';
	}

	/**
	 * پوشه‌ی یک ویدیو.
	 *
	 * @param string $name نام ویدیو.
	 * @return string
	 */
	public static function video_dir( string $name ): string {
		return self::videos_dir() . $name . '/';
	}

	/**
	 * پوشه‌ی کلید یک ویدیو.
	 *
	 * @param string $name نام ویدیو.
	 * @return string
	 */
	public static function key_dir( string $name ): string {
		return self::secure_dir() . $name . '/';
	}

	/**
	 * مسیر فایل کلید.
	 *
	 * @param string $name نام ویدیو.
	 * @return string
	 */
	public static function key_path( string $name ): string {
		return self::key_dir( $name ) . $name . '.key';
	}

	/**
	 * آدرس پلی‌لیست اصلی.
	 *
	 * @param string $name نام ویدیو.
	 * @return string
	 */
	public static function playlist_url( string $name ): string {
		return self::videos_url() . $name . '/' . $name . '.m3u8';
	}

	/**
	 * اعتبارسنجی نام ویدیو (جلوگیری از path traversal).
	 *
	 * نام‌ها هنگام ذخیره با sanitize_title ساخته می‌شوند، بنابراین
	 * فقط حروف لاتین، رقم، خط تیره و زیرخط مجاز است.
	 *
	 * @param string $name نام ویدیو.
	 * @return bool
	 */
	public static function is_valid_name( string $name ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/', $name );
	}

	/**
	 * آیا ویدیو انکود شده است؟
	 *
	 * @param string $name نام ویدیو.
	 * @return bool
	 */
	public static function is_encoded( string $name ): bool {
		if ( ! self::is_valid_name( $name ) ) {
			return false;
		}

		$playlist = self::video_dir( $name ) . $name . '.m3u8';

		return is_readable( $playlist ) && is_readable( self::key_path( $name ) );
	}

	/**
	 * ساخت پوشه‌های لازم.
	 *
	 * @return void
	 */
	public static function ensure_dirs(): void {
		foreach ( array( self::secure_dir(), self::videos_dir() ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}

		self::protect();
	}

	/**
	 * پاک‌سازی امن پوشه‌ی یک ویدیو (بدون خطای مرگبار).
	 *
	 * @param string $name نام ویدیو.
	 * @return void
	 */
	public static function purge( string $name ): void {
		if ( ! self::is_valid_name( $name ) ) {
			return;
		}

		foreach ( array( self::video_dir( $name ), self::key_dir( $name ) ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$files = glob( $dir . '*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						wp_delete_file( $file );
					}
				}
			}

			// فقط اگر خالی است حذف شود.
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * افزودن فایل‌های محافظ به پوشه‌ی ویدیوها.
	 *
	 * پلی‌لیست و سگمنت‌ها باید عمومی بمانند تا پلیر بتواند آن‌ها را بخواند،
	 * اما فهرست پوشه و فایل‌های حساس (keyinfo و لاگ) بسته می‌شوند.
	 *
	 * @return void
	 */
	public static function protect(): void {
		$dir = self::videos_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Falnic Secure HLS\n"
				. "Options -Indexes\n"
				. "<FilesMatch \"\\.(key|keyinfo|log)$\">\n"
				. "\t<IfModule mod_authz_core.c>\n"
				. "\t\tRequire all denied\n"
				. "\t</IfModule>\n"
				. "\t<IfModule !mod_authz_core.c>\n"
				. "\t\tOrder allow,deny\n"
				. "\t\tDeny from all\n"
				. "\t</IfModule>\n"
				. "</FilesMatch>\n";

			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}

		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}
	}
}
