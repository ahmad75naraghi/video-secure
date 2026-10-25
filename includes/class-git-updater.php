<?php
/**
 * به‌روزرسانی افزونه از مخزن گیت.
 *
 * تمام منطق امنیتی اینجا متمرکز است: تنظیمات، درخواست به GitHub، پشتیبان‌گیری،
 * اعتبارسنجی ZIP، بررسی syntax، همگام‌سازی فایل و بازگردانی.
 *
 * اصول:
 *  - هیچ فرمان shell اجرا نمی‌شود (بدون exec/unzip/git).
 *  - هیچ ورودی کاربر مستقیم وارد مسیر فایل نمی‌شود.
 *  - توکن هرگز در خروجی، لاگ یا خطا چاپ نمی‌شود.
 *  - ریشه‌ی پوشه‌ی افزونه هرگز حذف، تغییر نام یا جابه‌جا نمی‌شود.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * موتور آپدیت از گیت.
 */
final class Git_Updater {

	/**
	 * آپشن مخزن به شکل owner/repo.
	 */
	public const OPTION_REPO = 'falnic_git_repo';

	/**
	 * آپشن شاخه.
	 */
	public const OPTION_BRANCH = 'falnic_git_branch';

	/**
	 * آپشن توکن دسترسی.
	 */
	public const OPTION_TOKEN = 'falnic_git_token';

	/**
	 * کلید قفل هم‌زمانی.
	 */
	public const LOCK_KEY = 'falnic_git_lock';

	/**
	 * تعداد پشتیبان‌هایی که نگه داشته می‌شوند.
	 */
	public const MAX_BACKUPS = 5;

	/**
	 * مدت اعتبار قفل (ثانیه).
	 */
	public const LOCK_TTL = 900;

	/**
	 * آدرس پایه‌ی API گیت‌هاب.
	 */
	private const API_BASE = 'https://api.github.com';

	/**
	 * پوشه‌ی موقت داخل ریشه‌ی پشتیبان.
	 */
	private const TMP_DIR = 'tmp';

	/* ---------------------------------------------------------------------
	 * تنظیمات
	 * ------------------------------------------------------------------ */

	/**
	 * خواندن تنظیمات ذخیره‌شده.
	 *
	 * @return array
	 */
	public static function get_settings(): array {
		return array(
			'repo'   => (string) get_option( self::OPTION_REPO, '' ),
			'branch' => (string) get_option( self::OPTION_BRANCH, 'main' ),
			'has_token' => '' !== (string) get_option( self::OPTION_TOKEN, '' ),
		);
	}

	/**
	 * ذخیره‌ی تنظیمات با اعتبارسنجی.
	 *
	 * توکن خالی به معنی «حفظ مقدار قبلی» است.
	 *
	 * @param string $repo       مخزن.
	 * @param string $branch     شاخه.
	 * @param string $token      توکن جدید (خالی = بدون تغییر).
	 * @param bool   $clear_token حذف توکن ذخیره‌شده.
	 * @return true|\WP_Error
	 */
	public static function save_settings( string $repo, string $branch, string $token, bool $clear_token ) {
		$repo   = trim( $repo );
		$branch = trim( $branch );

		if ( ! self::is_valid_repo( $repo ) ) {
			return new \WP_Error(
				'falnic_invalid_repo',
				__( 'قالب مخزن درست نیست؛ باید به شکل owner/repo باشد (مثلاً ahmad/repo).', 'falnic-secure-hls' )
			);
		}

		if ( ! self::is_valid_branch( $branch ) ) {
			return new \WP_Error(
				'falnic_invalid_branch',
				__( 'نام شاخه نامعتبر است. فقط حروف، رقم، خط تیره، زیرخط، نقطه و اسلش مجاز است.', 'falnic-secure-hls' )
			);
		}

		update_option( self::OPTION_REPO, $repo, false );
		update_option( self::OPTION_BRANCH, $branch, false );

		if ( $clear_token ) {
			delete_option( self::OPTION_TOKEN );
		} elseif ( '' !== trim( $token ) ) {
			update_option( self::OPTION_TOKEN, trim( $token ), false );
		}

		return true;
	}

	/**
	 * توکن ذخیره‌شده (فقط برای استفاده‌ی داخلی).
	 *
	 * @return string
	 */
	private static function token(): string {
		return (string) get_option( self::OPTION_TOKEN, '' );
	}

	/* ---------------------------------------------------------------------
	 * اعتبارسنجی‌های خالص (قابل تست بدون وردپرس)
	 * ------------------------------------------------------------------ */

	/**
	 * اعتبارسنجی قالب owner/repo.
	 *
	 * @param string $repo مقدار ورودی.
	 * @return bool
	 */
	public static function is_valid_repo( string $repo ): bool {
		if ( strlen( $repo ) > 140 || substr_count( $repo, '/' ) !== 1 ) {
			return false;
		}

		if ( false !== strpos( $repo, '..' ) ) {
			return false;
		}

		return (bool) preg_match( '#^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,38})/[A-Za-z0-9._-]{1,100}$#', $repo );
	}

	/**
	 * اعتبارسنجی نام شاخه.
	 *
	 * @param string $branch مقدار ورودی.
	 * @return bool
	 */
	public static function is_valid_branch( string $branch ): bool {
		if ( '' === $branch || strlen( $branch ) > 255 ) {
			return false;
		}

		if ( false !== strpos( $branch, '..' ) || false !== strpos( $branch, '//' ) ) {
			return false;
		}

		if ( '/' === $branch[0] || '/' === substr( $branch, -1 ) ) {
			return false;
		}

		return (bool) preg_match( '#^[A-Za-z0-9._/-]+$#', $branch );
	}

	/**
	 * اعتبارسنجی نام فایل پشتیبان.
	 *
	 * @param string $name نام پیشنهادی.
	 * @return bool
	 */
	public static function is_valid_backup_name( string $name ): bool {
		if ( '' === $name || strlen( $name ) > 120 ) {
			return false;
		}

		if ( basename( $name ) !== $name ) {
			return false;
		}

		if ( false !== strpos( $name, "\0" ) ) {
			return false;
		}

		return (bool) preg_match( '/^falnic-[a-z0-9-]+\.zip$/', $name );
	}

	/**
	 * امن بودن مسیر یک entry داخل ZIP.
	 *
	 * @param string $name نام entry.
	 * @return bool
	 */
	public static function is_safe_zip_entry( string $name ): bool {
		if ( '' === $name ) {
			return false;
		}

		if ( false !== strpos( $name, "\0" ) ) {
			return false;
		}

		$normalized = str_replace( '\\', '/', $name );

		if ( '/' === $normalized[0] ) {
			return false;
		}

		if ( preg_match( '#^[A-Za-z]:#', $normalized ) ) {
			return false;
		}

		foreach ( explode( '/', trim( $normalized, '/' ) ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * یافتن فایل اصلی فعال افزونه.
	 *
	 * @param array  $active_plugins لیست افزونه‌های فعال.
	 * @param string $dir_name       نام پوشه‌ی افزونه.
	 * @param string $fallback       فایل پیش‌فرض (relative).
	 * @return string
	 */
	public static function detect_main_file( array $active_plugins, string $dir_name, string $fallback ): string {
		foreach ( $active_plugins as $plugin ) {
			if ( ! is_string( $plugin ) ) {
				continue;
			}

			$plugin = str_replace( '\\', '/', $plugin );

			if ( basename( dirname( $plugin ) ) === $dir_name ) {
				return $plugin;
			}
		}

		return $fallback;
	}

	/**
	 * انتخاب پشتیبان‌هایی که باید حذف شوند.
	 *
	 * @param array $backups لیست ['file' => string, 'mtime' => int].
	 * @param int   $keep    تعداد نگهداری.
	 * @return array نام فایل‌هایی که باید حذف شوند.
	 */
	public static function select_backups_to_delete( array $backups, int $keep ): array {
		usort(
			$backups,
			static function ( $a, $b ) {
				return (int) $b['mtime'] <=> (int) $a['mtime'];
			}
		);

		$excess = array_slice( $backups, max( 0, $keep ) );

		return array_values( array_map( static fn( $item ) => $item['file'], $excess ) );
	}

	/* ---------------------------------------------------------------------
	 * محیط و مسیرها
	 * ------------------------------------------------------------------ */

	/**
	 * مسیر واقعی ریشه‌ی افزونه (از محل همین فایل).
	 *
	 * @return string
	 */
	public static function plugin_dir(): string {
		$dir = realpath( dirname( __DIR__ ) );

		return false === $dir ? untrailingslashit( FALNIC_DIR ) : $dir;
	}

	/**
	 * نام پوشه‌ی فعلی افزونه.
	 *
	 * @return string
	 */
	public static function plugin_dir_name(): string {
		return basename( self::plugin_dir() );
	}

	/**
	 * نام فایل اصلی فعال (نسبت به پوشه‌ی plugins).
	 *
	 * @return string
	 */
	public static function main_file_relative(): string {
		$dir_name = self::plugin_dir_name();
		$fallback = $dir_name . '/falnic-secure-hls.php';

		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active  = array_merge( $active, array_keys( $network ) );
		}

		return self::detect_main_file( $active, $dir_name, $fallback );
	}

	/**
	 * نام فایل اصلی بدون مسیر.
	 *
	 * @return string
	 */
	public static function main_file_name(): string {
		return basename( self::main_file_relative() );
	}

	/**
	 * مسیر مطلق فایل اصلی.
	 *
	 * @return string
	 */
	public static function main_file_path(): string {
		return trailingslashit( self::plugin_dir() ) . self::main_file_name();
	}

	/**
	 * نسخه‌ی نصب‌شده از هدر افزونه.
	 *
	 * @return string
	 */
	public static function installed_version(): string {
		$path = self::main_file_path();

		if ( ! is_readable( $path ) ) {
			return '';
		}

		$data = get_file_data( $path, array( 'Version' => 'Version' ) );

		return isset( $data['Version'] ) ? (string) $data['Version'] : '';
	}

	/**
	 * ریشه‌ی پوشه‌ی پشتیبان (خارج از پوشه‌ی افزونه).
	 *
	 * @return string
	 */
	public static function backup_root(): string {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . 'falnic-backups/';
	}

	/**
	 * آماده‌سازی پوشه‌ی پشتیبان با محافظت از دسترسی وب.
	 *
	 * @return true|\WP_Error
	 */
	private static function ensure_backup_root() {
		$root = self::backup_root();

		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return new \WP_Error(
				'falnic_backup_dir',
				__( 'پوشهٔ پشتیبان ساخته نشد. مجوز پوشهٔ uploads را بررسی کنید.', 'falnic-secure-hls' )
			);
		}

		if ( ! wp_is_writable( $root ) ) {
			return new \WP_Error(
				'falnic_backup_not_writable',
				__( 'پوشهٔ پشتیبان قابل‌نوشتن نیست.', 'falnic-secure-hls' )
			);
		}

		$guard = $root . '.htaccess';
		if ( ! file_exists( $guard ) ) {
			file_put_contents( $guard, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$index = $root . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return true;
	}

	/**
	 * بررسی پیش‌نیازهای سرور.
	 *
	 * @return array لیست ['label'=>string,'ok'=>bool,'message'=>string]
	 */
	public static function requirements(): array {
		return array(
			array(
				'label'   => __( 'افزونهٔ ZipArchive', 'falnic-secure-hls' ),
				'ok'      => class_exists( 'ZipArchive' ),
				'message' => __( 'ZipArchive روی سرور فعال نیست.', 'falnic-secure-hls' ),
			),
			array(
				'label'   => __( 'پارسر PHP (TOKEN_PARSE)', 'falnic-secure-hls' ),
				'ok'      => defined( 'TOKEN_PARSE' ),
				'message' => __( 'امکان بررسی syntax بدون اجرا وجود ندارد (TOKEN_PARSE).', 'falnic-secure-hls' ),
			),
			array(
				'label'   => __( 'قابل‌نوشتن بودن پوشهٔ افزونه', 'falnic-secure-hls' ),
				'ok'      => wp_is_writable( self::plugin_dir() ),
				'message' => __( 'پوشهٔ افزونه قابل‌نوشتن نیست.', 'falnic-secure-hls' ),
			),
			array(
				'label'   => __( 'قابل‌نوشتن بودن پوشهٔ پشتیبان', 'falnic-secure-hls' ),
				'ok'      => is_dir( self::backup_root() ) ? wp_is_writable( self::backup_root() ) : wp_is_writable( dirname( self::backup_root() ) ),
				'message' => __( 'پوشهٔ پشتیبان قابل‌نوشتن نیست.', 'falnic-secure-hls' ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * قفل هم‌زمانی
	 * ------------------------------------------------------------------ */

	/**
	 * آیا قفل فعال است؟
	 *
	 * @return bool
	 */
	public static function is_locked(): bool {
		$lock = get_transient( self::LOCK_KEY );

		return is_array( $lock ) && ! empty( $lock['time'] ) && ( time() - (int) $lock['time'] ) < self::LOCK_TTL;
	}

	/**
	 * گرفتن قفل.
	 *
	 * @return true|\WP_Error
	 */
	private static function lock() {
		if ( self::is_locked() ) {
			return new \WP_Error(
				'falnic_locked',
				__( 'یک عملیات به‌روزرسانی یا بازگردانی دیگر در حال اجراست. چند دقیقه بعد تلاش کنید.', 'falnic-secure-hls' )
			);
		}

		set_transient(
			self::LOCK_KEY,
			array(
				'time' => time(),
				'user' => get_current_user_id(),
			),
			self::LOCK_TTL
		);

		return true;
	}

	/**
	 * آزادسازی قفل.
	 *
	 * @return void
	 */
	public static function unlock(): void {
		delete_transient( self::LOCK_KEY );
	}

	/* ---------------------------------------------------------------------
	 * درخواست‌های GitHub
	 * ------------------------------------------------------------------ */

	/**
	 * هدرهای استاندارد درخواست.
	 *
	 * @return array
	 */
	private static function headers(): array {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'Falnic-Secure-HLS-Updater/' . FALNIC_VERSION,
		);

		$token = self::token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * درخواست GET به API گیت‌هاب.
	 *
	 * @param string $path مسیر نسبی.
	 * @return array|\WP_Error
	 */
	private static function api_get( string $path ) {
		$url = self::API_BASE . $path;

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'sslverify'   => true,
				'redirection' => 3,
				'headers'     => self::headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::map_wp_error( $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 === $code ) {
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				return new \WP_Error(
					'falnic_unexpected',
					__( 'پاسخ گیت‌هاب غیرمنتظره بود.', 'falnic-secure-hls' )
				);
			}

			return $data;
		}

		return self::map_http_error( $code, $response );
	}

	/**
	 * تبدیل خطای وردپرس به پیام فارسی.
	 *
	 * @param \WP_Error $error خطا.
	 * @return \WP_Error
	 */
	private static function map_wp_error( \WP_Error $error ): \WP_Error {
		$code    = (string) $error->get_error_code();
		$message = (string) $error->get_error_message();

		if ( false !== stripos( $code, 'timeout' ) || false !== stripos( $message, 'timed out' ) ) {
			return new \WP_Error(
				'falnic_timeout',
				__( 'اتصال به گیت‌هاب با تایم‌اوت مواجه شد. اتصال اینترنت سرور را بررسی کنید.', 'falnic-secure-hls' )
			);
		}

		if ( false !== stripos( $message, 'ssl' ) || false !== stripos( $message, 'certificate' ) ) {
			return new \WP_Error(
				'falnic_ssl',
				__( 'خطای گواهی SSL هنگام اتصال به گیت‌هاب. تنظیمات گواهی سرور را بررسی کنید.', 'falnic-secure-hls' )
			);
		}

		return new \WP_Error(
			'falnic_network',
			__( 'اتصال به گیت‌هاب برقرار نشد (خطای شبکه یا cURL).', 'falnic-secure-hls' )
		);
	}

	/**
	 * تبدیل کد وضعیت HTTP به پیام قابل‌اقدام.
	 *
	 * @param int   $code     کد وضعیت.
	 * @param mixed $response پاسخ خام.
	 * @return \WP_Error
	 */
	private static function map_http_error( int $code, $response = null ): \WP_Error {
		switch ( $code ) {
			case 401:
				return new \WP_Error(
					'falnic_bad_token',
					__( 'توکن گیت‌هاب معتبر نیست یا منقضی شده است.', 'falnic-secure-hls' )
				);

			case 403:
				$remaining = null;
				if ( $response && ! is_wp_error( $response ) ) {
					$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
				}

				if ( null !== $remaining && '0' === (string) $remaining ) {
					return new \WP_Error(
						'falnic_rate_limited',
						__( 'سقف درخواست‌های گیت‌هاب پر شده است. یک توکن اضافه کنید یا بعداً تلاش کنید.', 'falnic-secure-hls' )
					);
				}

				return new \WP_Error(
					'falnic_no_access',
					__( 'دسترسی به این مخزن وجود ندارد. برای مخزن خصوصی، توکن با مجوز مناسب لازم است.', 'falnic-secure-hls' )
				);

			case 404:
				return new \WP_Error(
					'falnic_not_found',
					__( 'مخزن پیدا نشد. نشانی را بررسی کنید؛ اگر مخزن خصوصی است توکن لازم است.', 'falnic-secure-hls' )
				);

			case 429:
				return new \WP_Error(
					'falnic_rate_limited',
					__( 'درخواست‌ها بیش از حد مجاز است. کمی بعد تلاش کنید.', 'falnic-secure-hls' )
				);

			default:
				return new \WP_Error(
					'falnic_http',
					sprintf(
						/* translators: %d: کد وضعیت HTTP */
						__( 'گیت‌هاب پاسخ ناموفق برگرداند (کد %d).', 'falnic-secure-hls' ),
						$code
					)
				);
		}
	}

	/* ---------------------------------------------------------------------
	 * بررسی نسخه
	 * ------------------------------------------------------------------ */

	/**
	 * بررسی آخرین commit شاخه و مقایسه با نسخه‌ی نصب‌شده.
	 *
	 * @return array|\WP_Error
	 */
	public static function check_for_update() {
		$settings = self::get_settings();

		if ( ! self::is_valid_repo( $settings['repo'] ) ) {
			return new \WP_Error(
				'falnic_repo_missing',
				__( 'ابتدا مخزن گیت‌هاب را در کارت تنظیمات ذخیره کنید.', 'falnic-secure-hls' )
			);
		}

		if ( ! self::is_valid_branch( $settings['branch'] ) ) {
			return new \WP_Error(
				'falnic_branch_invalid',
				__( 'نام شاخه نامعتبر است.', 'falnic-secure-hls' )
			);
		}

		$repo = self::api_get( '/repos/' . $settings['repo'] );

		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		if ( empty( $repo['full_name'] ) ) {
			return new \WP_Error(
				'falnic_unexpected',
				__( 'پاسخ گیت‌هاب برای مخزن غیرمنتظره بود.', 'falnic-secure-hls' )
			);
		}

		$commit = self::api_get(
			'/repos/' . $settings['repo'] . '/commits/' . rawurlencode( $settings['branch'] )
		);

		if ( is_wp_error( $commit ) ) {
			if ( 'falnic_not_found' === $commit->get_error_code() ) {
				return new \WP_Error(
					'falnic_branch_missing',
					__( 'برنچ موردنظر در مخزن پیدا نشد.', 'falnic-secure-hls' )
				);
			}

			return $commit;
		}

		$sha = isset( $commit['sha'] ) ? (string) $commit['sha'] : '';

		if ( '' === $sha ) {
			return new \WP_Error(
				'falnic_unexpected',
				__( 'اطلاعات commit از گیت‌هاب دریافت نشد.', 'falnic-secure-hls' )
			);
		}

		$message = '';
		if ( isset( $commit['commit']['message'] ) ) {
			$message = (string) $commit['commit']['message'];
		}

		$date = '';
		if ( isset( $commit['commit']['committer']['date'] ) ) {
			$date = (string) $commit['commit']['committer']['date'];
		}

		$author = '';
		if ( isset( $commit['commit']['author']['name'] ) ) {
			$author = (string) $commit['commit']['author']['name'];
		}

		return array(
			'repo'            => isset( $repo['full_name'] ) ? (string) $repo['full_name'] : $settings['repo'],
			'private'         => ! empty( $repo['private'] ),
			'branch'          => $settings['branch'],
			'sha'             => $sha,
			'short_sha'       => substr( $sha, 0, 7 ),
			'date'            => $date,
			'date_local'      => $date ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $date ) ), 'Y-m-d H:i' ) : '',
			'message'         => self::first_line( $message, 160 ),
			'author'          => $author,
			'local_version'   => self::installed_version(),
			'main_file'       => self::main_file_relative(),
			'plugin_dir'      => self::plugin_dir(),
		);
	}

	/**
	 * اولین خط یک متن، کوتاه‌شده.
	 *
	 * @param string $text  متن.
	 * @param int    $limit حداکثر طول.
	 * @return string
	 */
	private static function first_line( string $text, int $limit ): string {
		$line = strtok( $text, "\r\n" );
		$line = false === $line ? '' : $line;

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $line, 0, $limit );
		}

		return substr( $line, 0, $limit );
	}

	/* ---------------------------------------------------------------------
	 * دانلود و اعتبارسنجی ZIP
	 * ------------------------------------------------------------------ */

	/**
	 * دانلود zipball شاخه به فایل موقت.
	 *
	 * @return string|\WP_Error مسیر فایل موقت.
	 */
	private static function download_zipball() {
		$settings = self::get_settings();
		$url      = self::API_BASE . '/repos/' . $settings['repo'] . '/zipball/' . rawurlencode( $settings['branch'] );

		$root = self::ensure_backup_root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$tmp_dir = self::backup_root() . self::TMP_DIR . '/';
		if ( ! is_dir( $tmp_dir ) && ! wp_mkdir_p( $tmp_dir ) ) {
			return new \WP_Error(
				'falnic_tmp_dir',
				__( 'پوشهٔ موقت برای دانلود ساخته نشد.', 'falnic-secure-hls' )
			);
		}

		$file = $tmp_dir . 'download-' . wp_generate_password( 12, false, false ) . '.zip';

		if ( function_exists( 'curl_init' ) ) {
			$result = self::curl_download( $url, $file );
		} else {
			$result = self::http_download( $url, $file );
		}

		if ( is_wp_error( $result ) ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}

			return $result;
		}

		if ( ! is_readable( $file ) || filesize( $file ) < 64 ) {
			wp_delete_file( $file );

			return new \WP_Error(
				'falnic_empty_download',
				__( 'فایل دانلودشده خالی یا ناقص است.', 'falnic-secure-hls' )
			);
		}

		$magic = (string) file_get_contents( $file, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( "PK\x03\x04" !== $magic ) {
			wp_delete_file( $file );

			return new \WP_Error(
				'falnic_not_zip',
				__( 'فایل ZIP معتبر نیست یا ناقص دانلود شده است.', 'falnic-secure-hls' )
			);
		}

		return $file;
	}

	/**
	 * دانلود جریانی با cURL (مصرف حافظه‌ی کم).
	 *
	 * @param string $url  آدرس.
	 * @param string $file مسیر خروجی.
	 * @return true|\WP_Error
	 */
	private static function curl_download( string $url, string $file ) {
		$handle = fopen( $file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			return new \WP_Error( 'falnic_tmp_write', __( 'نوشتن فایل موقت ناموفق بود.', 'falnic-secure-hls' ) );
		}

		$headers = array();
		foreach ( self::headers() as $key => $value ) {
			$headers[] = $key . ': ' . $value;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_FILE           => $handle,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_TIMEOUT        => 300,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_USERAGENT      => 'Falnic-Secure-HLS-Updater/' . FALNIC_VERSION,
				CURLOPT_HTTPHEADER     => $headers,
			)
		);
		$ok       = curl_exec( $ch );
		$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curl_err = curl_error( $ch );
		curl_close( $ch );
		// phpcs:enable

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $ok ) {
			return new \WP_Error(
				'falnic_download_failed',
				sprintf(
					/* translators: %s: متن خطای cURL (بدون توکن) */
					__( 'دانلود از گیت‌هاب ناموفق بود: %s', 'falnic-secure-hls' ),
					sanitize_text_field( $curl_err )
				)
			);
		}

		if ( 200 !== $code ) {
			return self::map_http_error( $code );
		}

		return true;
	}

	/**
	 * دانلود با HTTP API وردپرس (وقتی cURL نیست).
	 *
	 * @param string $url  آدرس.
	 * @param string $file مسیر خروجی.
	 * @return true|\WP_Error
	 */
	private static function http_download( string $url, string $file ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 300,
				'sslverify'   => true,
				'redirection' => 5,
				'headers'     => self::headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::map_wp_error( $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return self::map_http_error( $code, $response );
		}

		$body = wp_remote_retrieve_body( $response );
		$ok   = file_put_contents( $file, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $ok ) {
			return new \WP_Error( 'falnic_tmp_write', __( 'نوشتن فایل موقت ناموفق بود.', 'falnic-secure-hls' ) );
		}

		return true;
	}

	/**
	 * اعتبارسنجی ساختار ZIP و یافتن ریشه.
	 *
	 * دو حالت پشتیبانی می‌شود:
	 *  - آرشیو گیت‌هاب: همهٔ فایل‌ها داخل یک پوشهٔ ریشه (owner-repo-sha/).
	 *  - پشتیبان داخلی: فایل‌ها از ریشهٔ افزونه و بدون پوشهٔ اضافی.
	 *
	 * @param string $zip_path مسیر فایل ZIP.
	 * @return array|\WP_Error آرایه‌ی ['root' => string, 'entries' => int]
	 */
	public static function validate_zip( string $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error(
				'falnic_no_zip',
				__( 'ZipArchive روی سرور فعال نیست.', 'falnic-secure-hls' )
			);
		}

		if ( ! is_readable( $zip_path ) ) {
			return new \WP_Error(
				'falnic_zip_missing',
				__( 'فایل ZIP پیدا نشد.', 'falnic-secure-hls' )
			);
		}

		$zip  = new \ZipArchive();
		$open = $zip->open( $zip_path );

		if ( true !== $open ) {
			return new \WP_Error(
				'falnic_zip_invalid',
				__( 'فایل ZIP معتبر نیست یا ناقص دانلود شده است.', 'falnic-secure-hls' )
			);
		}

		$count = (int) $zip->numFiles;

		if ( $count < 1 ) {
			$zip->close();

			return new \WP_Error( 'falnic_zip_empty', __( 'فایل ZIP خالی است.', 'falnic-secure-hls' ) );
		}

		$main_name = self::main_file_name();
		$entries   = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );

			if ( ! is_array( $stat ) || empty( $stat['name'] ) ) {
				$zip->close();

				return new \WP_Error(
					'falnic_zip_entry',
					__( 'یکی از فایل‌های ZIP نامعتبر است.', 'falnic-secure-hls' )
				);
			}

			$name = (string) $stat['name'];

			if ( ! self::is_safe_zip_entry( $name ) ) {
				$zip->close();

				return new \WP_Error(
					'falnic_zip_traversal',
					__( 'ZIP شامل مسیر ناامن است و برای جلوگیری از آسیب‌پذیری رد شد.', 'falnic-secure-hls' )
				);
			}

			if ( self::entry_is_symlink( $zip, $i ) ) {
				$zip->close();

				return new \WP_Error(
					'falnic_zip_symlink',
					__( 'ZIP شامل symlink است و رد شد.', 'falnic-secure-hls' )
				);
			}

			$entries[] = $name;

			if ( '/' === substr( $name, -1 ) ) {
				continue;
			}

		}

		// یافتن پیشوندی که فایل اصلی در آن قرار دارد.
		$prefix = '';

		if ( in_array( $main_name, $entries, true ) ) {
			$prefix = '';
		} else {
			$candidates = array();

			foreach ( $entries as $entry ) {
				if ( substr( $entry, -strlen( '/' . $main_name ) ) === '/' . $main_name ) {
					$candidates[ substr( $entry, 0, strlen( $entry ) - strlen( $main_name ) ) ] = true;
				}
			}

			$keys = array_keys( $candidates );

			if ( 1 !== count( $keys ) ) {
				$zip->close();

				return new \WP_Error(
					'falnic_zip_main_missing',
					sprintf(
						/* translators: %s: نام فایل اصلی */
						__( 'فایل اصلی افزونه (%s) در نسخهٔ جدید پیدا نشد.', 'falnic-secure-hls' ),
						$main_name
					)
				);
			}

			$prefix = rtrim( (string) $keys[0], '/' );

			if ( substr_count( $prefix, '/' ) > 0 ) {
				$zip->close();

				return new \WP_Error(
					'falnic_zip_depth',
					__( 'ساختار ZIP بیش از حد تودرتو است؛ آپدیت متوقف شد.', 'falnic-secure-hls' )
				);
			}
		}

		// اگر پوشهٔ ریشه داریم، همهٔ entry ها باید داخل همان باشند.
		if ( '' !== $prefix ) {
			$root_dir = $prefix . '/';

			foreach ( $entries as $entry ) {
				if ( $entry === $root_dir || 0 === strpos( $entry, $root_dir ) ) {
					continue;
				}

				$zip->close();

				return new \WP_Error(
					'falnic_zip_multiroot',
					__( 'ساختار ZIP غیرمنتظره است (باید یک پوشهٔ ریشه داشته باشد).', 'falnic-secure-hls' )
				);
			}
		}

		$header_path = '' === $prefix ? $main_name : $prefix . '/' . $main_name;
		$header      = (string) $zip->getFromName( $header_path );

		if ( '' === trim( $header ) ) {
			$zip->close();

			return new \WP_Error(
				'falnic_zip_header',
				__( 'هدر افزونه در فایل اصلی نسخهٔ جدید خوانده نشد.', 'falnic-secure-hls' )
			);
		}

		if ( false === strpos( $header, 'Plugin Name:' ) ) {
			$zip->close();

			return new \WP_Error(
				'falnic_zip_not_plugin',
				__( 'فایل اصلی نسخهٔ جدید هدر «Plugin Name» ندارد؛ آپدیت متوقف شد.', 'falnic-secure-hls' )
			);
		}

		$zip->close();

		return array(
			'root'    => $prefix,
			'entries' => $count,
		);
	}

	/**
	 * تشخیص symlink در entry های ZIP.
	 *
	 * @param \ZipArchive $zip فایل ZIP.
	 * @param int         $index شماره‌ی entry.
	 * @return bool
	 */
	private static function entry_is_symlink( \ZipArchive $zip, int $index ): bool {
		$opsys = 0;
		$attr  = 0;

		if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attr ) ) {
			return false;
		}

		// UNIX = 3 در استاندارد ZIP.
		if ( 3 !== (int) $opsys ) {
			return false;
		}

		$type = ( (int) $attr >> 16 ) & 0170000;

		return 0120000 === $type;
	}

	/**
	 * استخراج ZIP به پوشه‌ی مقصد با بررسی مجدد مسیرها.
	 *
	 * @param string $zip_path مسیر ZIP.
	 * @param string $dest     پوشه‌ی مقصد.
	 * @return true|\WP_Error
	 */
	private static function extract_zip( string $zip_path, string $dest ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'falnic_no_zip', __( 'ZipArchive روی سرور فعال نیست.', 'falnic-secure-hls' ) );
		}

		if ( ! is_dir( $dest ) && ! wp_mkdir_p( $dest ) ) {
			return new \WP_Error( 'falnic_extract_dir', __( 'ساخت پوشهٔ موقت ناموفق بود.', 'falnic-secure-hls' ) );
		}

		$zip  = new \ZipArchive();
		$open = $zip->open( $zip_path );

		if ( true !== $open ) {
			return new \WP_Error( 'falnic_zip_invalid', __( 'فایل ZIP معتبر نیست.', 'falnic-secure-hls' ) );
		}

		// حذف مسیرهای ناامن پیش از استخراج.
		for ( $i = $zip->numFiles - 1; $i >= 0; $i-- ) {
			$stat = $zip->statIndex( $i );
			$name = is_array( $stat ) && isset( $stat['name'] ) ? (string) $stat['name'] : '';

			if ( ! self::is_safe_zip_entry( $name ) || self::entry_is_symlink( $zip, $i ) ) {
				$zip->deleteIndex( $i );
			}
		}

		$extracted = $zip->extractTo( $dest );
		$zip->close();

		if ( ! $extracted ) {
			return new \WP_Error( 'falnic_extract_failed', __( 'استخراج فایل ZIP ناموفق بود.', 'falnic-secure-hls' ) );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Preflight: بررسی syntax بدون اجرا
	 * ------------------------------------------------------------------ */

	/**
	 * بررسی syntax همه‌ی فایل‌های PHP یک پوشه.
	 *
	 * از token_get_all با TOKEN_PARSE استفاده می‌کند؛ فایل اجرا نمی‌شود.
	 *
	 * @param string $dir پوشه.
	 * @return array لیست خطاها (حداکثر ۱۰ مورد).
	 */
	public static function lint_php_files( string $dir ): array {
		$errors = array();

		if ( ! defined( 'TOKEN_PARSE' ) ) {
			return array( __( 'بررسی syntax ممکن نیست: TOKEN_PARSE در دسترس نیست.', 'falnic-secure-hls' ) );
		}

		if ( ! is_dir( $dir ) ) {
			return array( __( 'پوشهٔ نسخهٔ جدید پیدا نشد.', 'falnic-secure-hls' ) );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
				continue;
			}

			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$path = $file->getPathname();

			if ( ! is_readable( $path ) ) {
				continue;
			}

			$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			try {
				token_get_all( $source, TOKEN_PARSE );
			} catch ( \Throwable $e ) {
				$errors[] = sprintf(
					/* translators: 1: مسیر فایل، 2: پیام خطا، 3: شماره خط */
					__( '%1$s — خط %3$d: %2$s', 'falnic-secure-hls' ),
					str_replace( trailingslashit( $dir ), '', $path ),
					$e->getMessage(),
					(int) $e->getLine()
				);
			}

			if ( count( $errors ) >= 10 ) {
				break;
			}
		}

		return $errors;
	}

	/* ---------------------------------------------------------------------
	 * پشتیبان‌گیری
	 * ------------------------------------------------------------------ */

	/**
	 * ساخت پشتیبان ZIP از پوشه‌ی افزونه.
	 *
	 * @param string $prefix پیشوند نام فایل.
	 * @return string|\WP_Error مسیر فایل پشتیبان.
	 */
	public static function create_backup( string $prefix = 'backup' ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'falnic_no_zip', __( 'ZipArchive روی سرور فعال نیست.', 'falnic-secure-hls' ) );
		}

		$root = self::ensure_backup_root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$plugin_dir = self::plugin_dir();

		if ( ! is_dir( $plugin_dir ) ) {
			return new \WP_Error( 'falnic_no_plugin_dir', __( 'پوشهٔ افزونه پیدا نشد.', 'falnic-secure-hls' ) );
		}

		$name = sprintf(
			'falnic-%s-%s-%s.zip',
			sanitize_key( $prefix ),
			gmdate( 'Ymd-His' ),
			strtolower( wp_generate_password( 6, false, false ) )
		);

		$path = self::backup_root() . $name;
		$zip  = new \ZipArchive();

		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'falnic_backup_create', __( 'ساخت فایل پشتیبان ناموفق بود.', 'falnic-secure-hls' ) );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $plugin_dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		$added = 0;

		foreach ( $iterator as $item ) {
			if ( ! $item instanceof \SplFileInfo ) {
				continue;
			}

			$relative = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $plugin_dir ) ) ), '/' );

			if ( '' === $relative || self::is_excluded_path( $relative ) ) {
				continue;
			}

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $relative );
				continue;
			}

			if ( $item->isFile() ) {
				$zip->addFile( $item->getPathname(), $relative );
				$added++;
			}
		}

		$zip->close();

		if ( 0 === $added || ! is_readable( $path ) ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}

			return new \WP_Error( 'falnic_backup_empty', __( 'پشتیبان خالی ساخته شد؛ آپدیت متوقف شد.', 'falnic-secure-hls' ) );
		}

		self::prune_backups();

		return $path;
	}

	/**
	 * مسیرهایی که نباید در پشتیبان یا همگام‌سازی باشند.
	 *
	 * @param string $relative مسیر نسبی.
	 * @return bool
	 */
	private static function is_excluded_path( string $relative ): bool {
		$relative = str_replace( '\\', '/', $relative );

		$excluded = array( '.git/', 'node_modules/', '.falnic-tmp/', '.DS_Store', '__MACOSX/' );

		foreach ( $excluded as $needle ) {
			if ( 0 === strpos( $relative, $needle ) || $needle === $relative ) {
				return true;
			}
		}

		return (bool) preg_match( '#(^|/)\.git(/|$)#', $relative );
	}

	/**
	 * حذف پشتیبان‌های مازاد بر سقف نگهداری.
	 *
	 * @return void
	 */
	public static function prune_backups(): void {
		$backups = self::list_backups();

		$files = array();
		foreach ( $backups as $backup ) {
			$files[] = array(
				'file'  => $backup['name'],
				'mtime' => $backup['mtime'],
			);
		}

		$to_delete = self::select_backups_to_delete( $files, self::max_backups() );

		foreach ( $to_delete as $name ) {
			if ( self::is_valid_backup_name( $name ) ) {
				$path = self::backup_root() . $name;
				if ( file_exists( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}
	}

	/**
	 * سقف تعداد پشتیبان (قابل تغییر با فیلتر).
	 *
	 * @return int
	 */
	private static function max_backups(): int {
		$max = (int) apply_filters( 'falnic_git_max_backups', self::MAX_BACKUPS );

		return max( 1, min( 20, $max ) );
	}

	/**
	 * لیست پشتیبان‌های موجود (جدیدترین اول).
	 *
	 * @return array
	 */
	public static function list_backups(): array {
		$root = self::backup_root();

		if ( ! is_dir( $root ) ) {
			return array();
		}

		$items = glob( $root . 'falnic-*.zip' );

		if ( ! is_array( $items ) ) {
			return array();
		}

		$out = array();

		foreach ( $items as $path ) {
			$name = basename( $path );

			if ( ! self::is_valid_backup_name( $name ) ) {
				continue;
			}

			$out[] = array(
				'name'   => $name,
				'mtime'  => (int) filemtime( $path ),
				'size'   => (int) filesize( $path ),
				'path'   => $path,
				'date'   => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) filemtime( $path ) ), 'Y-m-d H:i' ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['mtime'] <=> $a['mtime'];
			}
		);

		return $out;
	}

	/**
	 * مسیر یک پشتیبان معتبر.
	 *
	 * @param string $name نام فایل.
	 * @return string|\WP_Error
	 */
	private static function backup_path( string $name ) {
		if ( ! self::is_valid_backup_name( $name ) ) {
			return new \WP_Error( 'falnic_bad_backup', __( 'نام فایل پشتیبان نامعتبر است.', 'falnic-secure-hls' ) );
		}

		$path = realpath( self::backup_root() . $name );
		$base = realpath( self::backup_root() );

		if ( false === $path || false === $base || 0 !== strpos( $path, $base ) ) {
			return new \WP_Error( 'falnic_bad_backup_path', __( 'مسیر پشتیبان معتبر نیست.', 'falnic-secure-hls' ) );
		}

		if ( ! is_file( $path ) ) {
			return new \WP_Error( 'falnic_backup_missing', __( 'فایل پشتیبان پیدا نشد.', 'falnic-secure-hls' ) );
		}

		return $path;
	}

	/**
	 * حذف کنترل‌شده‌ی پشتیبان.
	 *
	 * @param string $name نام فایل.
	 * @return true|\WP_Error
	 */
	public static function delete_backup( string $name ) {
		$path = self::backup_path( $name );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! wp_delete_file( $path ) && file_exists( $path ) ) {
			return new \WP_Error( 'falnic_delete_failed', __( 'حذف فایل پشتیبان ناموفق بود.', 'falnic-secure-hls' ) );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * همگام‌سازی فایل‌ها
	 * ------------------------------------------------------------------ */

	/**
	 * فهرست فایل‌های یک پوشه به‌صورت نسبی.
	 *
	 * @param string $dir پوشه.
	 * @return array
	 */
	private static function list_files( string $dir ): array {
		$files = array();

		if ( ! is_dir( $dir ) ) {
			return $files;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( ! $item instanceof \SplFileInfo || ! $item->isFile() ) {
				continue;
			}

			$relative = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $dir ) ) ), '/' );

			if ( self::is_excluded_path( $relative ) ) {
				continue;
			}

			$files[ $relative ] = array(
				'size' => (int) $item->getSize(),
				'hash' => (string) md5_file( $item->getPathname() ),
			);
		}

		return $files;
	}

	/**
	 * کپی فایل‌های نسخهٔ جدید روی نصب فعلی.
	 *
	 * @param string $source پوشه‌ی نسخهٔ جدید.
	 * @param string $dest   پوشه‌ی افزونه.
	 * @return array|\WP_Error
	 */
	private static function apply( string $source, string $dest ) {
		$new_files = self::list_files( $source );
		$old_files = self::list_files( $dest );

		if ( empty( $new_files ) ) {
			return new \WP_Error( 'falnic_no_files', __( 'نسخهٔ جدید هیچ فایلی ندارد.', 'falnic-secure-hls' ) );
		}

		$main_name = self::main_file_name();
		$added     = array();
		$changed   = array();
		$skipped   = array();

		foreach ( $new_files as $relative => $meta ) {
			$target = $dest . '/' . $relative;

			if ( isset( $old_files[ $relative ] ) ) {
				if ( $old_files[ $relative ]['hash'] === $meta['hash'] ) {
					continue;
				}
				$changed[] = $relative;
			} else {
				$added[] = $relative;
			}

			$dir = dirname( $target );

			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return new \WP_Error(
					'falnic_copy_failed',
					sprintf(
						/* translators: %s: مسیر */
						__( 'ساخت پوشه ناموفق بود: %s — از بخش پشتیبان‌ها Rollback را اجرا کنید.', 'falnic-secure-hls' ),
						esc_html( $relative )
					),
					array( 'partial' => true )
				);
			}

			if ( ! copy( $source . '/' . $relative, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new \WP_Error(
					'falnic_copy_failed',
					sprintf(
						/* translators: %s: مسیر */
						__( 'در مرحلهٔ جایگزینی فایل خطا رخ داد (%s)؛ از بخش پشتیبان‌ها Rollback را اجرا کنید.', 'falnic-secure-hls' ),
						esc_html( $relative )
					),
					array( 'partial' => true )
				);
			}
		}

		$removed = array();

		foreach ( array_keys( $old_files ) as $relative ) {
			if ( isset( $new_files[ $relative ] ) ) {
				continue;
			}

			if ( $relative === $main_name ) {
				$skipped[] = $relative;
				continue;
			}

			$path = $dest . '/' . $relative;

			if ( is_file( $path ) ) {
				if ( ! wp_delete_file( $path ) ) {
					$skipped[] = $relative;
					continue;
				}
				$removed[] = $relative;
			}
		}

		self::prune_empty_dirs( $dest );

		return array(
			'added'   => $added,
			'changed' => $changed,
			'removed' => $removed,
			'skipped' => $skipped,
		);
	}

	/**
	 * حذف پوشه‌های خالی (بدون لمس ریشه).
	 *
	 * @param string $dir پوشه‌ی افزونه.
	 * @return void
	 */
	private static function prune_empty_dirs( string $dir ): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( ! $item instanceof \SplFileInfo || ! $item->isDir() ) {
				continue;
			}

			$path = $item->getPathname();

			if ( wp_normalize_path( $path ) === wp_normalize_path( $dir ) ) {
				continue;
			}

			$entries = glob( $path . '/*' );
			if ( is_array( $entries ) && empty( $entries ) ) {
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * عملیات اصلی: Update و Rollback
	 * ------------------------------------------------------------------ */

	/**
	 * اجرای کامل به‌روزرسانی.
	 *
	 * @return array|\WP_Error گزارش نهایی.
	 */
	public static function run_update() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'falnic_no_zip', __( 'ZipArchive روی سرور فعال نیست.', 'falnic-secure-hls' ) );
		}

		if ( ! defined( 'TOKEN_PARSE' ) ) {
			return new \WP_Error(
				'falnic_no_parser',
				__( 'بررسی syntax فایل‌های PHP روی این سرور ممکن نیست؛ برای ایمنی آپدیت متوقف شد.', 'falnic-secure-hls' )
			);
		}

		if ( ! wp_is_writable( self::plugin_dir() ) ) {
			return new \WP_Error( 'falnic_not_writable', __( 'پوشهٔ افزونه قابل‌نوشتن نیست.', 'falnic-secure-hls' ) );
		}

		$lock = self::lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$zip_path = '';
		$extract  = '';
		$backup   = '';

		try {
			$info = self::check_for_update();
			if ( is_wp_error( $info ) ) {
				return $info;
			}

			$zip_path = self::download_zipball();
			if ( is_wp_error( $zip_path ) ) {
				return $zip_path;
			}

			$valid = self::validate_zip( $zip_path );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			$extract = self::backup_root() . self::TMP_DIR . '/extract-' . wp_generate_password( 10, false, false ) . '/';
			$result  = self::extract_zip( $zip_path, $extract );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$source = trailingslashit( $extract . $valid['root'] );

			if ( ! is_dir( $source ) ) {
				return new \WP_Error( 'falnic_extract_root', __( 'ریشهٔ نسخهٔ جدید پیدا نشد.', 'falnic-secure-hls' ) );
			}

			$new_main = $source . self::main_file_name();

			if ( ! is_readable( $new_main ) ) {
				return new \WP_Error(
					'falnic_main_mismatch',
					__( 'فایل اصلی نسخهٔ جدید با فایل فعال این نصب یکسان نیست؛ برای جلوگیری از غیرفعال‌شدن افزونه، آپدیت متوقف شد.', 'falnic-secure-hls' )
				);
			}

			$lint_errors = self::lint_php_files( $source );

			if ( ! empty( $lint_errors ) ) {
				return new \WP_Error(
					'falnic_lint_failed',
					__( 'چک syntax فایل‌های PHP ناموفق بود؛ هیچ تغییری اعمال نشد.', 'falnic-secure-hls' ) . "\n" . implode( "\n", $lint_errors ),
					array( 'errors' => $lint_errors )
				);
			}

			$backup = self::create_backup( 'before-update' );
			if ( is_wp_error( $backup ) ) {
				return $backup;
			}

			$applied = self::apply( $source, self::plugin_dir() );

			if ( is_wp_error( $applied ) ) {
				$data                     = (array) $applied->get_error_data();
				$data['backup']           = basename( $backup );
				$data['backup_available'] = true;

				return new \WP_Error( $applied->get_error_code(), $applied->get_error_message(), $data );
			}

			$warnings = array();

			if ( ! is_readable( self::main_file_path() ) ) {
				$warnings[] = __( 'فایل اصلی افزونه پس از آپدیت خوانده نشد؛ از Rollback استفاده کنید.', 'falnic-secure-hls' );
			}

			if ( ! empty( $applied['skipped'] ) ) {
				$warnings[] = sprintf(
					/* translators: %d: تعداد فایل */
					__( '%d فایل حذف نشد و دست‌نخورده باقی ماند.', 'falnic-secure-hls' ),
					count( $applied['skipped'] )
				);
			}

			return array(
				'branch'         => $info['branch'],
				'sha'            => $info['sha'],
				'short_sha'      => $info['short_sha'],
				'commit_message' => $info['message'],
				'previous_version' => $info['local_version'],
				'new_version'    => self::installed_version(),
				'added'          => $applied['added'],
				'changed'        => $applied['changed'],
				'removed'        => $applied['removed'],
				'backup'         => basename( $backup ),
				'warnings'       => $warnings,
			);
		} finally {
			if ( $zip_path && file_exists( $zip_path ) ) {
				wp_delete_file( $zip_path );
			}

			if ( $extract && is_dir( $extract ) ) {
				self::remove_dir( $extract );
			}

			self::unlock();
		}
	}

	/**
	 * بازگردانی از یک پشتیبان.
	 *
	 * @param string $name نام فایل پشتیبان.
	 * @return array|\WP_Error گزارش.
	 */
	public static function run_rollback( string $name ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'falnic_no_zip', __( 'ZipArchive روی سرور فعال نیست.', 'falnic-secure-hls' ) );
		}

		$path = self::backup_path( $name );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$lock = self::lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$extract = '';

		try {
			$valid = self::validate_zip( $path );

			if ( is_wp_error( $valid ) ) {
				// پشتیبان‌ها ممکن است فایل اصلی را با نام قدیمی داشته باشند؛ پیام واضح بده.
				return new \WP_Error(
					'falnic_backup_invalid',
					__( 'فایل پشتیبان معتبر نیست یا ساختار آن با این نصب هم‌خوان نیست.', 'falnic-secure-hls' ),
					array( 'original' => $valid->get_error_message() )
				);
			}

			$extract = self::backup_root() . self::TMP_DIR . '/rollback-' . wp_generate_password( 10, false, false ) . '/';
			$result  = self::extract_zip( $path, $extract );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$source = trailingslashit( $extract . $valid['root'] );

			if ( ! is_readable( $source . self::main_file_name() ) ) {
				return new \WP_Error(
					'falnic_rollback_main',
					__( 'فایل اصلی افزونه در این پشتیبان پیدا نشد؛ بازگردانی متوقف شد.', 'falnic-secure-hls' )
				);
			}

			// پشتیبان از وضعیت فعلی، پیش از بازگردانی.
			$safety = self::create_backup( 'before-rollback' );

			if ( is_wp_error( $safety ) ) {
				return new \WP_Error(
					'falnic_rollback_no_backup',
					__( 'ساخت پشتیبان از وضعیت فعلی ناموفق بود؛ برای ایمنی بازگردانی انجام نشد.', 'falnic-secure-hls' )
				);
			}

			$applied = self::apply( $source, self::plugin_dir() );

			if ( is_wp_error( $applied ) ) {
				return $applied;
			}

			if ( ! is_readable( self::main_file_path() ) ) {
				return new \WP_Error(
					'falnic_rollback_broken',
					__( 'فایل اصلی افزونه پس از بازگردانی خوانده نشد.', 'falnic-secure-hls' )
				);
			}

			return array(
				'restored_from'  => $name,
				'safety_backup'  => basename( $safety ),
				'version'        => self::installed_version(),
				'added'          => $applied['added'],
				'changed'        => $applied['changed'],
				'removed'        => $applied['removed'],
			);
		} finally {
			if ( $extract && is_dir( $extract ) ) {
				self::remove_dir( $extract );
			}

			self::unlock();
		}
	}

	/**
	 * حذف بازگشتی یک پوشه‌ی موقت.
	 *
	 * @param string $dir پوشه.
	 * @return void
	 */
	private static function remove_dir( string $dir ): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( ! $item instanceof \SplFileInfo ) {
				continue;
			}

			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * پاک‌سازی پوشه‌ی موقت.
	 *
	 * @return void
	 */
	public static function cleanup_tmp(): void {
		$tmp = self::backup_root() . self::TMP_DIR;

		if ( is_dir( $tmp ) ) {
			self::remove_dir( $tmp );
		}
	}
}
