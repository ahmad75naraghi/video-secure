<?php
/**
 * صف انکود پس‌زمینه.
 *
 * آپلود ویدیو دیگر درخواست HTTP را باز نگه نمی‌دارد: فایل به پوشه‌ی موقت
 * منتقل می‌شود، یک رویداد WP-Cron زمان‌بندی می‌شود و پیشرفت کار در یک آپشن
 * ذخیره می‌گردد تا پنل بتواند آن را زنده نشان دهد.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * مدیریت صف، وضعیت و لغو انکود.
 */
final class Encode_Queue {

	/**
	 * هوک اجرای انکود.
	 */
	public const CRON_HOOK = 'falnic_encode_run';

	/**
	 * هوک پاک‌سازی روزانه.
	 */
	public const CLEANUP_HOOK = 'falnic_encode_cleanup';

	/**
	 * نام آپشنی که وضعیت کارها را نگه می‌دارد.
	 */
	public const JOBS_OPTION = 'falnic_encode_jobs';

	/**
	 * حداکثر تعداد کار نگه‌داری‌شده در تاریخچه.
	 */
	private const MAX_JOBS = 50;

	/**
	 * حداکثر عمر فایل ورودی در پوشه‌ی موقت (ثانیه).
	 */
	private const INCOMING_TTL = DAY_IN_SECONDS;

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ), 10, 4 );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );

		add_action( 'wp_ajax_falnic_encode_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_falnic_encode_cancel', array( __CLASS__, 'ajax_cancel' ) );
		add_action( 'wp_ajax_falnic_encode_retry', array( __CLASS__, 'ajax_retry' ) );
		add_action( 'admin_init', array( __CLASS__, 'watchdog' ) );
	}

	/**
	 * نگهبان صف: اگر رویداد Cron گم شده باشد، دوباره زمان‌بندی می‌کند.
	 *
	 * روی هاست‌هایی که loopback مسدود است یا کرون دیر اجرا می‌شود، کارهای
	 * «در صف» بی‌پایان می‌مانند؛ این متد در هر ورود به پنل آن‌ها را نجات می‌دهد.
	 *
	 * @return void
	 */
	public static function watchdog(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( self::jobs() as $name => $job ) {
			if ( 'queued' !== ( $job['state'] ?? '' ) ) {
				continue;
			}

			$source = (string) ( $job['source'] ?? '' );

			if ( '' === $source || ! is_readable( $source ) ) {
				continue;
			}

			$updated = (int) ( $job['updated'] ?? 0 );

			if ( $updated > 0 && ( time() - $updated ) < MINUTE_IN_SECONDS ) {
				continue;
			}

			$post_id = (int) ( $job['post_id'] ?? 0 );
			$user_id = (int) ( $job['user_id'] ?? 0 );

			if ( wp_next_scheduled( self::CRON_HOOK, array( $name, $source, $post_id, $user_id ) ) ) {
				continue;
			}

			wp_schedule_single_event( time() + 5, self::CRON_HOOK, array( $name, $source, $post_id, $user_id ) );
			self::spawn_runner();
		}
	}

	/**
	 * پوشه‌ی فایل‌های ورودی در انتظار انکود.
	 *
	 * @return string
	 */
	public static function incoming_dir(): string {
		$dir = Storage::tmp_dir() . 'incoming/';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir;
	}

	/**
	 * افزودن یک ویدیو به صف.
	 *
	 * @param string $name    نام ویدیو.
	 * @param string $source  مسیر فایل آپلودشده.
	 * @param int    $post_id شناسه‌ی پست (برای گزارش).
	 * @param int    $user_id کاربر شروع‌کننده.
	 * @param bool   $copy    فایل مبدأ حفظ شود (برای فایل‌های روی سرور).
	 * @return array{ok: bool, message: string, state: string}
	 */
	public static function enqueue( string $name, string $source, int $post_id = 0, int $user_id = 0, bool $copy = false ): array {
		if ( ! Storage::is_valid_name( $name ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'نام ویدیو نامعتبر است.', 'falnic-secure-hls' ),
				'state'   => 'invalid',
			);
		}

		if ( ! is_readable( $source ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'فایل ورودی در دسترس نیست.', 'falnic-secure-hls' ),
				'state'   => 'missing',
			);
		}

		$current = self::status( $name );

		if ( in_array( $current['state'], array( 'queued', 'running' ), true ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'این ویدیو همین حالا در صف انکود است.', 'falnic-secure-hls' ),
				'state'   => $current['state'],
			);
		}

		$destination = self::incoming_dir() . $name . '-' . wp_generate_password( 10, false ) . self::extension( $source );

		if ( ! ( $copy ? self::copy_file( $source, $destination ) : self::move_file( $source, $destination ) ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'انتقال فایل به پوشه‌ی موقت ناموفق بود. مجوز پوشه را بررسی کنید.', 'falnic-secure-hls' ),
				'state'   => 'error',
			);
		}

		self::save_status(
			$name,
			array(
				'state'    => 'queued',
				'percent'  => 0,
				'message'  => __( 'در صف انکود…', 'falnic-secure-hls' ),
				'post_id'  => $post_id,
				'user_id'  => $user_id,
				'source'   => $destination,
				'started'  => 0,
				'errors'   => '',
				'variants' => array(),
			)
		);

		wp_schedule_single_event( time() + 1, self::CRON_HOOK, array( $name, $destination, $post_id, $user_id ) );
		self::spawn_runner();

		return array(
			'ok'      => true,
			'message' => __( 'ویدیو در صف انکود قرار گرفت. می‌توانید این صفحه را ببندید؛ پردازش در پس‌زمینه ادامه دارد.', 'falnic-secure-hls' ),
			'state'   => 'queued',
		);
	}

	/**
	 * اجرای کار صف (فراخوانی‌شده توسط WP-Cron).
	 *
	 * @param string $name    نام ویدیو.
	 * @param string $source  مسیر فایل ورودی.
	 * @param int    $post_id شناسه‌ی پست.
	 * @param int    $user_id کاربر.
	 * @return void
	 */
	public static function run( $name, $source, $post_id = 0, $user_id = 0 ): void {
		$name   = (string) $name;
		$source = (string) $source;

		if ( ! Storage::is_valid_name( $name ) ) {
			return;
		}

		if ( ! is_readable( $source ) ) {
			self::save_status(
				$name,
				array(
					'state'   => 'failed',
					'message' => __( 'فایل ورودی پیدا نشد؛ لطفاً دوباره آپلود کنید.', 'falnic-secure-hls' ),
				)
			);

			return;
		}

		$lock = 'falnic_encode_lock_' . md5( $name );

		if ( false !== get_transient( $lock ) ) {
			// کار دیگری همین ویدیو را در دست دارد.
			return;
		}

		set_transient( $lock, time(), 6 * HOUR_IN_SECONDS );

		self::save_status(
			$name,
			array(
				'state'   => 'running',
				'percent' => 0,
				'message' => __( 'در حال انکود…', 'falnic-secure-hls' ),
				'started' => time(),
			)
		);

		$last_written = -1;

		$progress = static function ( $percent, $meta ) use ( $name, &$last_written ) {
			$percent = max( 0, min( 100, (int) $percent ) );

			// نوشتن بهینه: هر ۲ درصد یک‌بار.
			if ( $percent - $last_written >= 2 || 100 <= $percent ) {
				$last_written = $percent;

				self::save_status(
					$name,
					array(
						'percent' => $percent,
						'message' => sprintf(
							/* translators: %d: درصد پیشرفت */
							__( 'در حال انکود… (%d%%)', 'falnic-secure-hls' ),
							$percent
						),
					)
				);
			}

			return ! self::is_canceled( $name );
		};

		$result = array(
			'ok'     => false,
			'status' => 'failed',
			'error'  => '',
		);

		try {
			$result = Encoder::process( $source, $name, $progress );
		} catch ( \Throwable $error ) {
			$result = array(
				'ok'     => false,
				'status' => 'failed',
				'error'  => $error->getMessage(),
			);
		} finally {
			delete_transient( $lock );
		}

		if ( ! empty( $result['ok'] ) ) {
			wp_delete_file( $source );

			self::save_status(
				$name,
				array(
					'state'    => 'done',
					'percent'  => 100,
					'message'  => __( 'انکود با موفقیت تمام شد.', 'falnic-secure-hls' ),
					'variants' => (array) ( $result['variants'] ?? array() ),
					'errors'   => '',
				)
			);

			if ( $user_id > 0 ) {
				set_transient( 'falnic_encode_success_' . (int) $user_id, $name, 60 );
			}

			return;
		}

		$canceled = isset( $result['status'] ) && 'canceled' === $result['status'];

		// در شکست، فایل ورودی نگه داشته می‌شود تا «تلاش دوباره» ممکن باشد؛
		// پاک‌سازی روزانه پس از یک روز آن را حذف می‌کند.
		if ( $canceled ) {
			wp_delete_file( $source );
		}

		self::save_status(
			$name,
			array(
				'state'   => $canceled ? 'canceled' : 'failed',
				'message' => $canceled
					? __( 'انکود لغو شد.', 'falnic-secure-hls' )
					: (string) ( $result['error'] ?? __( 'انکود ناموفق بود.', 'falnic-secure-hls' ) ),
				'errors'  => (string) ( $result['error'] ?? '' ),
			)
		);

		self::clear_cancel( $name );

		if ( ! $canceled && $user_id > 0 ) {
			set_transient( 'falnic_encode_error_' . (int) $user_id, (string) ( $result['error'] ?? '' ), 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * وضعیت یک ویدیو.
	 *
	 * @param string $name نام.
	 * @return array
	 */
	public static function status( string $name ): array {
		$jobs = get_option( self::JOBS_OPTION, array() );
		$job  = is_array( $jobs ) && isset( $jobs[ $name ] ) ? (array) $jobs[ $name ] : array();

		return wp_parse_args(
			$job,
			array(
				'state'    => 'idle',
				'percent'  => 0,
				'message'  => '',
				'post_id'  => 0,
				'user_id'  => 0,
				'source'   => '',
				'started'  => 0,
				'updated'  => 0,
				'errors'   => '',
				'variants' => array(),
			)
		);
	}

	/**
	 * فهرست کارهای صف (تازه‌ترین اول).
	 *
	 * @return array<string, array>
	 */
	public static function jobs(): array {
		$jobs = get_option( self::JOBS_OPTION, array() );

		if ( ! is_array( $jobs ) ) {
			return array();
		}

		uasort(
			$jobs,
			static function ( $a, $b ) {
				return (int) ( $b['updated'] ?? 0 ) <=> (int) ( $a['updated'] ?? 0 );
			}
		);

		return $jobs;
	}

	/**
	 * اجرای فوری کارهای در صف، بدون انتظار برای WP-Cron.
	 *
	 * @param int $limit حداکثر تعداد کار.
	 * @return int تعداد کارهای اجراشده.
	 */
	public static function run_now( int $limit = 3 ): int {
		$done = 0;

		foreach ( self::jobs() as $name => $job ) {
			if ( $done >= max( 1, $limit ) ) {
				break;
			}

			if ( 'queued' !== ( $job['state'] ?? '' ) ) {
				continue;
			}

			$source = (string) ( $job['source'] ?? '' );

			if ( '' === $source || ! is_readable( $source ) ) {
				continue;
			}

			wp_clear_scheduled_hook( self::CRON_HOOK, array( $name, $source, (int) ( $job['post_id'] ?? 0 ), (int) ( $job['user_id'] ?? 0 ) ) );
			self::run( $name, $source, (int) ( $job['post_id'] ?? 0 ), (int) ( $job['user_id'] ?? 0 ) );

			$done++;
		}

		return $done;
	}

	/**
	 * آیا ویدیو در صف یا در حال اجراست؟
	 *
	 * @param string $name نام.
	 * @return bool
	 */
	public static function is_active( string $name ): bool {
		return in_array( self::status( $name )['state'], array( 'queued', 'running' ), true );
	}

	/**
	 * لغو یک کار.
	 *
	 * @param string $name نام.
	 * @return bool
	 */
	public static function cancel( string $name ): bool {
		if ( ! Storage::is_valid_name( $name ) ) {
			return false;
		}

		$status = self::status( $name );

		if ( ! in_array( $status['state'], array( 'queued', 'running' ), true ) ) {
			return false;
		}

		set_transient( self::cancel_key( $name ), 1, HOUR_IN_SECONDS );

		if ( 'running' === $status['state'] ) {
			self::save_status(
				$name,
				array(
					'message' => __( 'در حال لغو…', 'falnic-secure-hls' ),
				)
			);

			return true;
		}

		if ( 'queued' === $status['state'] ) {
			wp_clear_scheduled_hook( self::CRON_HOOK, array( $name, $status['source'], (int) $status['post_id'], (int) $status['user_id'] ) );

			if ( '' !== (string) $status['source'] && file_exists( (string) $status['source'] ) ) {
				wp_delete_file( (string) $status['source'] );
			}

			self::save_status(
				$name,
				array(
					'state'   => 'canceled',
					'message' => __( 'انکود پیش از شروع لغو شد.', 'falnic-secure-hls' ),
				)
			);
			self::clear_cancel( $name );
		}

		return true;
	}

	/**
	 * تلاش دوباره برای کار ناموفق.
	 *
	 * @param string $name نام.
	 * @return array{ok: bool, message: string}
	 */
	public static function retry( string $name ): array {
		$status = self::status( $name );

		if ( ! in_array( $status['state'], array( 'failed', 'canceled', 'idle' ), true ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'این کار در حال اجراست.', 'falnic-secure-hls' ),
			);
		}

		$source = (string) $status['source'];

		if ( '' === $source || ! is_readable( $source ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'فایل ورودی این کار دیگر موجود نیست؛ لطفاً ویدیو را دوباره آپلود کنید.', 'falnic-secure-hls' ),
			);
		}

		self::clear_cancel( $name );

		self::save_status(
			$name,
			array(
				'state'   => 'queued',
				'percent' => 0,
				'message' => __( 'در صف انکود…', 'falnic-secure-hls' ),
				'errors'  => '',
			)
		);

		wp_schedule_single_event(
			time() + 1,
			self::CRON_HOOK,
			array( $name, $source, (int) $status['post_id'], (int) $status['user_id'] )
		);
		self::spawn_runner();

		return array(
			'ok'      => true,
			'message' => __( 'کار دوباره در صف قرار گرفت.', 'falnic-secure-hls' ),
		);
	}

	/**
	 * AJAX: وضعیت کار برای یک پست.
	 *
	 * @return void
	 */
	public static function ajax_status(): void {
		$post_id = self::guard_ajax();

		if ( 0 === $post_id ) {
			return;
		}

		$name   = Post_Type::video_name( $post_id );
		$status = '' === $name ? array() : self::status( $name );

		wp_send_json_success(
			array(
				'name'    => $name,
				'state'   => $status['state'] ?? 'idle',
				'percent' => (int) ( $status['percent'] ?? 0 ),
				'message' => (string) ( $status['message'] ?? '' ),
				'errors'  => (string) ( $status['errors'] ?? '' ),
				'encoded' => '' !== $name && Storage::is_encoded( $name ),
			)
		);
	}

	/**
	 * AJAX: لغو کار.
	 *
	 * @return void
	 */
	public static function ajax_cancel(): void {
		$post_id = self::guard_ajax();

		if ( 0 === $post_id ) {
			return;
		}

		$name = Post_Type::video_name( $post_id );

		if ( '' === $name || ! self::cancel( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'کار فعالی برای لغو پیدا نشد.', 'falnic-secure-hls' ) ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'درخواست لغو ثبت شد.', 'falnic-secure-hls' ) ) );
	}

	/**
	 * AJAX: تلاش دوباره.
	 *
	 * @return void
	 */
	public static function ajax_retry(): void {
		$post_id = self::guard_ajax();

		if ( 0 === $post_id ) {
			return;
		}

		$name = Post_Type::video_name( $post_id );

		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'شناسه‌ی ویدیو پیدا نشد.', 'falnic-secure-hls' ) ), 400 );
		}

		$result = self::retry( $name );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ), 400 );
		}

		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	/**
	 * پاک‌سازی فایل‌های موقت کهنه و تاریخچه‌ی قدیمی.
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		$dir   = Storage::tmp_dir() . 'incoming/';
		$files = is_dir( $dir ) ? glob( $dir . '*' ) : array();

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}

			if ( filemtime( $file ) < ( time() - self::INCOMING_TTL ) ) {
				wp_delete_file( $file );
			}
		}

		$jobs = get_option( self::JOBS_OPTION, array() );

		if ( ! is_array( $jobs ) || count( $jobs ) <= self::MAX_JOBS ) {
			return;
		}

		uasort(
			$jobs,
			static function ( $a, $b ) {
				return (int) ( $a['updated'] ?? 0 ) < (int) ( $b['updated'] ?? 0 ) ? 1 : -1;
			}
		);

		update_option( self::JOBS_OPTION, array_slice( $jobs, 0, self::MAX_JOBS, true ), false );
	}

	/**
	 * باز کردن یک اجرای WP-Cron بدون مسدود کردن درخواست.
	 *
	 * @return void
	 */
	private static function spawn_runner(): void {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return;
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		if ( ! function_exists( 'wp_remote_post' ) ) {
			return;
		}

		wp_remote_post(
			site_url( 'wp-cron.php?doing_wp_cron=' . rawurlencode( (string) microtime( true ) ) ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'cookies'   => array(),
			)
		);
	}

	/**
	 * بررسی و محافظت از درخواست‌های AJAX.
	 *
	 * @return int شناسه‌ی پست یا ۰ در صورت خطا.
	 */
	private static function guard_ajax(): int {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی ندارید.', 'falnic-secure-hls' ) ), 403 );
		}

		check_ajax_referer( 'falnic_encode_status', 'nonce' );

		return $post_id;
	}

	/**
	 * ذخیره‌ی وضعیت (ادغام با مقدار قبلی).
	 *
	 * @param string $name   نام.
	 * @param array  $fields فیلدهای تغییرکرده.
	 * @return void
	 */
	private static function save_status( string $name, array $fields ): void {
		$jobs = get_option( self::JOBS_OPTION, array() );
		$jobs = is_array( $jobs ) ? $jobs : array();
		$job  = isset( $jobs[ $name ] ) && is_array( $jobs[ $name ] ) ? $jobs[ $name ] : array();

		$job = array_merge(
			$job,
			$fields,
			array(
				'updated' => time(),
			)
		);

		$jobs[ $name ] = $job;

		if ( count( $jobs ) > self::MAX_JOBS ) {
			$jobs = array_slice( $jobs, -self::MAX_JOBS, null, true );
		}

		update_option( self::JOBS_OPTION, $jobs, false );
	}

	/**
	 * کلید transient لغو.
	 *
	 * @param string $name نام.
	 * @return string
	 */
	private static function cancel_key( string $name ): string {
		return 'falnic_encode_cancel_' . md5( $name );
	}

	/**
	 * آیا درخواست لغو ثبت شده است؟
	 *
	 * @param string $name نام.
	 * @return bool
	 */
	private static function is_canceled( string $name ): bool {
		return false !== get_transient( self::cancel_key( $name ) );
	}

	/**
	 * پاک‌کردن پرچم لغو.
	 *
	 * @param string $name نام.
	 * @return void
	 */
	private static function clear_cancel( string $name ): void {
		delete_transient( self::cancel_key( $name ) );
	}

	/**
	 * پسوند مجاز فایل ورودی.
	 *
	 * @param string $path مسیر.
	 * @return string
	 */
	private static function extension( string $path ): string {
		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $ext, array( 'mp4', 'm4v', 'mov' ), true ) ? '.' . $ext : '.mp4';
	}

	/**
	 * کپی امن فایل بدون حذف مبدأ.
	 *
	 * @param string $source مبدأ.
	 * @param string $target مقصد.
	 * @return bool
	 */
	private static function copy_file( string $source, string $target ): bool {
		if ( ! copy( $source, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_copy
			return false;
		}

		return is_readable( $target );
	}

	/**
	 * انتقال فایل با سازگاری بین فایل‌سیستم‌ها.
	 *
	 * @param string $source مبدأ.
	 * @param string $target مقصد.
	 * @return bool
	 */
	private static function move_file( string $source, string $target ): bool {
		if ( @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return true;
		}

		if ( ! copy( $source, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_copy
			return false;
		}

		wp_delete_file( $source );

		return true;
	}
}
