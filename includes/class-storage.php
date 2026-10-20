<?php
/**
 * مدیریت مسیرها و فایل‌های رسانه.
 *
 * تمام دسترسی‌های فایل‌سیستم از این کلاس عبور می‌کنند. رسانه‌ها بیرون از
 * web root نگه داشته می‌شوند و فقط از طریق دروازه‌ی PHP تحویل داده می‌شوند.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * مسیرهای امن رسانه و مهاجرت از محل قدیمی.
 */
final class Storage {

	/**
	 * فایل‌های مجاز برای تحویل از طریق دروازه.
	 */
	private const ALLOWED_EXTENSIONS = array( 'ts', 'm4s', 'mp4', 'aac', 'vtt', 'm3u8' );

	/**
	 * ریشه‌ی رسانه (بیرون از web root).
	 *
	 * @return string
	 */
	public static function root(): string {
		if ( defined( 'FALNIC_STORAGE_DIR' ) && '' !== (string) FALNIC_STORAGE_DIR ) {
			return trailingslashit( wp_normalize_path( (string) FALNIC_STORAGE_DIR ) );
		}

		$dir = trailingslashit( dirname( ABSPATH ) ) . 'falnic-secure';

		// در چندسایتی، هر سایت پوشه‌ی مستقل دارد.
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$dir .= '/site-' . (int) get_current_blog_id();
		}

		return trailingslashit( wp_normalize_path( $dir ) );
	}

	/**
	 * پوشه‌ی ویدیوها در محل جدید.
	 *
	 * @return string
	 */
	public static function videos_dir(): string {
		return self::root() . 'videos/';
	}

	/**
	 * پوشه‌ی کلیدها (بیرون از web root).
	 *
	 * @return string
	 */
	public static function secure_dir(): string {
		return self::root() . 'keys/';
	}

	/**
	 * پوشه‌ی موقت (فقط برای عملیات کوتاه‌مدت).
	 *
	 * @return string
	 */
	public static function tmp_dir(): string {
		return self::root() . 'tmp/';
	}

	/**
	 * محل قدیمی ویدیوها (داخل uploads).
	 *
	 * @return string
	 */
	public static function legacy_videos_dir(): string {
		$uploads = wp_upload_dir();

		return trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . 'secure_videos/';
	}

	/**
	 * محل قدیمی کلیدها.
	 *
	 * @return string
	 */
	public static function legacy_keys_dir(): string {
		return trailingslashit( wp_normalize_path( dirname( ABSPATH ) ) ) . 'secure_keys/';
	}

	/**
	 * پوشه‌ی یک ویدیو؛ محل جدید در اولویت است.
	 *
	 * @param string $name نام ویدیو.
	 * @return string
	 */
	public static function video_dir( string $name ): string {
		$new = self::videos_dir() . $name . '/';

		if ( is_dir( $new ) ) {
			return $new;
		}

		$legacy = self::legacy_videos_dir() . $name . '/';

		return is_dir( $legacy ) ? $legacy : $new;
	}

	/**
	 * پوشه‌ی کلید یک ویدیو.
	 *
	 * @param string $name نام ویدیو.
	 * @return string
	 */
	public static function key_dir( string $name ): string {
		$new = self::secure_dir() . $name . '/';

		if ( is_dir( $new ) ) {
			return $new;
		}

		$legacy = self::legacy_keys_dir() . $name . '/';

		return is_dir( $legacy ) ? $legacy : $new;
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
	 * مسیر پلی‌لیست اصلی (در صورت وجود، master چندکیفیتی).
	 *
	 * @param string $name نام ویدیو.
	 * @return string
	 */
	public static function playlist_path( string $name ): string {
		$dir = self::video_dir( $name );

		if ( is_readable( $dir . 'master.m3u8' ) ) {
			return $dir . 'master.m3u8';
		}

		return $dir . $name . '.m3u8';
	}

	/**
	 * آیا این ویدیو پلی‌لیست چندکیفیتی (master) دارد؟
	 *
	 * @param string $name نام ویدیو.
	 * @return bool
	 */
	public static function is_master( string $name ): bool {
		$dir = self::video_dir( $name );

		return is_readable( $dir . 'master.m3u8' );
	}

	/**
	 * پوشه‌ی زیرنویس‌های یک ویدیو.
	 *
	 * @param string $name نام ویدیو.
	 * @return string
	 */
	public static function subtitle_dir( string $name ): string {
		return self::video_dir( $name ) . 'captions/';
	}

	/**
	 * مسیر فایل زیرنویس.
	 *
	 * @param string $name نام ویدیو.
	 * @param string $lang کد زبان.
	 * @return string
	 */
	public static function subtitle_path( string $name, string $lang ): string {
		$lang = self::sanitize_lang( $lang );

		return '' === $lang ? '' : self::subtitle_dir( $name ) . $lang . '.vtt';
	}

	/**
	 * زیرنویس‌های موجود یک ویدیو.
	 *
	 * @param string $name نام ویدیو.
	 * @return array<int, array{lang: string, label: string}>
	 */
	public static function subtitles( string $name ): array {
		if ( ! self::is_valid_name( $name ) ) {
			return array();
		}

		$dir = self::subtitle_dir( $name );

		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files = glob( $dir . '*.vtt' );
		$out   = array();

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$lang = self::sanitize_lang( (string) pathinfo( $file, PATHINFO_FILENAME ) );

			if ( '' === $lang ) {
				continue;
			}

			$out[] = array(
				'lang'  => $lang,
				'label' => self::language_label( $lang ),
			);
		}

		return $out;
	}

	/**
	 * پاک‌سازی کد زبان (fa، en، fa-IR …).
	 *
	 * @param string $lang کد.
	 * @return string
	 */
	public static function sanitize_lang( string $lang ): string {
		$lang = strtolower( str_replace( '_', '-', trim( $lang ) ) );
		$lang = (string) preg_replace( '/[^a-z\-]/', '', $lang );

		return preg_match( '/^[a-z]{2,3}(-[a-z]{2,4})?$/', $lang ) ? $lang : '';
	}

	/**
	 * نام نمایشی زبان برای منوی پلیر.
	 *
	 * @param string $lang کد زبان.
	 * @return string
	 */
	private static function language_label( string $lang ): string {
		$map = array(
			'fa'    => __( 'فارسی', 'falnic-secure-hls' ),
			'en'    => __( 'انگلیسی', 'falnic-secure-hls' ),
			'ar'    => __( 'عربی', 'falnic-secure-hls' ),
			'de'    => __( 'آلمانی', 'falnic-secure-hls' ),
			'tr'    => __( 'ترکی', 'falnic-secure-hls' ),
		);

		$base = substr( $lang, 0, 2 );

		if ( isset( $map[ $base ] ) ) {
			return $map[ $base ];
		}

		return strtoupper( $lang );
	}

	/**
	 * اعتبارسنجی نام ویدیو (جلوگیری از path traversal).
	 *
	 * @param string $name نام ویدیو.
	 * @return bool
	 */
	public static function is_valid_name( string $name ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/', $name );
	}

	/**
	 * تبدیل عنوان/رشته‌ی ورودی به نام امن و قابل استفاده به‌عنوان مسیر.
	 *
	 * فایل‌های این پلاگین با این نام ساخته می‌شوند، پس هر تغییر در قواعد باید
	 * با نسخه‌های قبلی سازگار بماند (عنوان‌های انگلیسی قدیمی خروجی یکسان می‌دهند).
	 *
	 * @param string $raw رشته‌ی ورودی (مثلاً عنوان پست).
	 * @return string
	 */
	public static function sanitize_name( string $raw ): string {
		$value = function_exists( 'sanitize_title' ) ? sanitize_title( $raw ) : $raw;
		$value = strtolower( urldecode( (string) $value ) );
		$value = (string) preg_replace( '/[^a-z0-9\-]/', '', $value );
		$value = trim( $value, '-' );

		if ( ! self::is_valid_name( $value ) ) {
			return '';
		}

		return $value;
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

		return is_readable( self::playlist_path( $name ) ) && is_readable( self::key_path( $name ) );
	}

	/**
	 * آیا این ویدیو هنوز در محل قدیمی (uploads) است؟
	 *
	 * @param string $name نام ویدیو.
	 * @return bool
	 */
	public static function is_legacy( string $name ): bool {
		if ( ! self::is_valid_name( $name ) ) {
			return false;
		}

		$legacy = self::legacy_videos_dir() . $name . '/';

		return is_dir( $legacy ) && ! is_dir( self::videos_dir() . $name . '/' );
	}

	/**
	 * لیست ویدیوهایی که فقط در محل قدیمی هستند.
	 *
	 * @return string[]
	 */
	public static function legacy_pending(): array {
		$legacy = self::legacy_videos_dir();

		if ( ! is_dir( $legacy ) ) {
			return array();
		}

		$items = glob( $legacy . '*', GLOB_ONLYDIR );

		if ( ! is_array( $items ) ) {
			return array();
		}

		$names = array();

		foreach ( $items as $dir ) {
			$name = basename( $dir );

			if ( self::is_valid_name( $name ) && ! is_dir( self::videos_dir() . $name . '/' ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * ساخت پوشه‌های لازم و فایل‌های محافظ.
	 *
	 * @return void
	 */
	public static function ensure_dirs(): void {
		foreach ( array( self::root(), self::videos_dir(), self::secure_dir(), self::tmp_dir() ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}

		// پوشه‌ی قدیمی هم (اگر وجود داشته باشد) باید بسته شود.
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['basedir'] ) && wp_is_writable( $uploads['basedir'] ) && ! is_dir( self::legacy_videos_dir() ) ) {
			wp_mkdir_p( self::legacy_videos_dir() );
		}

		self::protect();
	}

	/**
	 * بستن دسترسی وب به پوشه‌های رسانه.
	 *
	 * فایل‌ها فقط از دروازه‌ی PHP تحویل می‌شوند؛ دسترسی مستقیم باید بسته باشد.
	 *
	 * @return void
	 */
	public static function protect(): void {
		$rules = "# Falnic Secure HLS — deny direct access\n"
			. "<IfModule mod_authz_core.c>\n"
			. "\tRequire all denied\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\tOrder allow,deny\n"
			. "\tDeny from all\n"
			. "</IfModule>\n";

		foreach ( array( self::root(), self::legacy_videos_dir() ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$htaccess = $dir . '.htaccess';
			$current  = file_exists( $htaccess ) ? (string) file_get_contents( $htaccess ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			// نسخه‌های قدیمی این پوشه را باز گذاشته بودند؛ در صورت نبود قاعده‌ی deny بازنویسی می‌شود.
			if ( false === strpos( $current, 'Require all denied' ) ) {
				file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			}

			$index = $dir . 'index.php';

			if ( ! file_exists( $index ) ) {
				file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			}
		}
	}

	/**
	 * حذف امن پوشه‌ی یک ویدیو (در هر دو محل).
	 *
	 * @param string   $name نام ویدیو.
	 * @param string[] $keep زیرپوشه‌هایی که حفظ می‌شوند (مثل captions).
	 * @return void
	 */
	public static function purge( string $name, array $keep = array() ): void {
		if ( ! self::is_valid_name( $name ) ) {
			return;
		}

		foreach (
			array(
				self::videos_dir() . $name . '/',
				self::legacy_videos_dir() . $name . '/',
				self::secure_dir() . $name . '/',
				self::legacy_keys_dir() . $name . '/',
			) as $dir
		) {
			self::remove_dir( $dir, $keep );
		}
	}

	/**
	 * یافتن امن یک فایل داخل پوشه‌ی ویدیو.
	 *
	 * @param string $name نام ویدیو.
	 * @param string $relative مسیر نسبی (مثل 00001.ts یا 720p/00001.ts).
	 * @return string|null مسیر مطلق یا null.
	 */
	public static function resolve_file( string $name, string $relative ): ?string {
		if ( ! self::is_valid_name( $name ) ) {
			return null;
		}

		$relative = str_replace( '\\', '/', trim( $relative ) );

		if ( '' === $relative || strlen( $relative ) > 160 ) {
			return null;
		}

		if ( false !== strpos( $relative, "\0" ) || false !== strpos( $relative, '..' ) ) {
			return null;
		}

		if ( ! preg_match( '#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $relative ) ) {
			return null;
		}

		$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, self::ALLOWED_EXTENSIONS, true ) ) {
			return null;
		}

		$base = self::video_dir( $name );
		$path = $base . $relative;
		$real = realpath( $path );

		if ( false === $real ) {
			return null;
		}

		$real_base = realpath( $base );

		if ( false === $real_base || 0 !== strpos( $real, $real_base . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return is_file( $real ) && is_readable( $real ) ? $real : null;
	}

	/**
	 * انتقال یک ویدیو از uploads به محل امن.
	 *
	 * @param string $name نام ویدیو.
	 * @return bool
	 */
	public static function migrate_video( string $name ): bool {
		if ( ! self::is_valid_name( $name ) ) {
			return false;
		}

		$source = self::legacy_videos_dir() . $name . '/';

		if ( ! is_dir( $source ) ) {
			return false;
		}

		$target = self::videos_dir() . $name . '/';

		if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) {
			return false;
		}

		if ( ! self::copy_tree( $source, $target ) ) {
			return false;
		}

		$key_source = self::legacy_keys_dir() . $name . '/';
		$key_target = self::secure_dir() . $name . '/';

		if ( is_dir( $key_source ) ) {
			if ( ! is_dir( $key_target ) ) {
				wp_mkdir_p( $key_target );
			}

			if ( ! self::copy_tree( $key_source, $key_target ) ) {
				return false;
			}

			self::remove_dir( $key_source );
		}

		self::remove_dir( $source );

		return true;
	}

	/**
	 * انتقال همه‌ی ویدیوهای قدیمی (با سقف برای جلوگیری از تایم‌اوت).
	 *
	 * @param int $limit حداکثر تعداد.
	 * @return array ['moved' => int, 'failed' => int, 'remaining' => int]
	 */
	public static function migrate_all( int $limit = 20 ): array {
		self::ensure_dirs();

		$limit   = max( 1, min( 100, $limit ) );
		$moved   = 0;
		$failed  = 0;
		$pending = self::legacy_pending();

		foreach ( array_slice( $pending, 0, $limit ) as $name ) {
			if ( self::migrate_video( $name ) ) {
				$moved++;
			} else {
				$failed++;
			}
		}

		return array(
			'moved'     => $moved,
			'failed'    => $failed,
			'remaining' => count( self::legacy_pending() ),
		);
	}

	/**
	 * آیا ریشه‌ی رسانه بیرون از web root است؟
	 *
	 * @return bool
	 */
	public static function is_outside_webroot(): bool {
		$root   = wp_normalize_path( self::root() );
		$abspath = wp_normalize_path( ABSPATH );

		return 0 !== strpos( $root, rtrim( $abspath, '/' ) . '/' );
	}

	/**
	 * کپی بازگشتی یک درخت.
	 *
	 * @param string $source مبدأ (با اسلش انتهایی).
	 * @param string $target مقصد (با اسلش انتهایی).
	 * @return bool
	 */
	private static function copy_tree( string $source, string $target ): bool {
		$items = glob( $source . '*', GLOB_MARK );

		if ( ! is_array( $items ) ) {
			return false;
		}

		foreach ( $items as $item ) {
			$base = basename( $item );

			if ( '.' === $base || '..' === $base ) {
				continue;
			}

			if ( is_dir( $item ) ) {
				$sub = $target . $base . '/';

				if ( ! is_dir( $sub ) && ! wp_mkdir_p( $sub ) ) {
					return false;
				}

				if ( ! self::copy_tree( trailingslashit( $item ), $sub ) ) {
					return false;
				}

				continue;
			}

			if ( ! copy( $item, $target . $base ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_copy
				return false;
			}
		}

		return true;
	}

	/**
	 * حذف بازگشتی یک پوشه (فقط محتوای شناخته‌شده).
	 *
	 * @param string   $dir  پوشه.
	 * @param string[] $keep نام زیرپوشه‌هایی که نباید حذف شوند.
	 * @return void
	 */
	private static function remove_dir( string $dir, array $keep = array() ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = glob( $dir . '*', GLOB_MARK );

		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( is_dir( $item ) ) {
					if ( in_array( basename( $item ), $keep, true ) ) {
						continue;
					}

					self::remove_dir( trailingslashit( $item ) );
					continue;
				}

				wp_delete_file( $item );
			}
		}

		// اگر چیزی برای نگه‌داشتن مانده، پوشه حذف نمی‌شود.
		$remaining = glob( $dir . '*', GLOB_MARK );

		if ( empty( $remaining ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
