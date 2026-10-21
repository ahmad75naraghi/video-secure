<?php
/**
 * انکود و رمزنگاری ویدیو با ffmpeg.
 *
 * اجرای ffmpeg با proc_open (آرایه‌ی آرگومان، بدون shell) انجام می‌شود تا
 * امکان لغو، خواندن پیشرفت و جلوگیری از تزریق فرمان فراهم باشد.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * تبدیل MP4 به HLS رمزنگاری‌شده با AES-128 (تک‌کیفیت یا چندکیفیتی).
 */
final class Encoder {

	/**
	 * نردبان کیفیت: ارتفاع (پیکسل) => بیتریت ویدیو (کیلوبیت بر ثانیه).
	 */
	public const LADDER = array(
		1080 => 5000,
		720  => 2800,
		480  => 1400,
		360  => 800,
	);

	/**
	 * حداقل طول هر سگمنت.
	 */
	private const SEGMENT_SECONDS = 6;

	/**
	 * وضعیت آخرین اجرا: done|failed|canceled.
	 *
	 * @var string
	 */
	private static $last_status = 'done';

	/**
	 * پیام خطای آخرین اجرا.
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * مسیر باینری ffmpeg.
	 *
	 * @return string
	 */
	public static function ffmpeg_bin(): string {
		$bin = defined( 'FALNIC_FFMPEG_BIN' ) ? (string) FALNIC_FFMPEG_BIN : self::detect_binary( 'ffmpeg' );

		/**
		 * فیلتر مسیر باینری ffmpeg.
		 *
		 * @param string $bin مسیر پیش‌فرض.
		 */
		return (string) apply_filters( 'falnic_ffmpeg_bin', $bin );
	}

	/**
	 * یافتن خودکار باینری در مسیرهای رایج هاست.
	 *
	 * روی هاست‌های اشتراکی معمولاً PATH سرویس PHP شامل ffmpeg نیست و
	 * نوشتن مسیر کامل در wp-config لازم می‌شود؛ این متد آن را خودکار می‌کند.
	 *
	 * @param string $name نام باینری (ffmpeg|ffprobe).
	 * @return string مسیر یا نام خالی‌نشده.
	 */
	public static function detect_binary( string $name ): string {
		// در محیط تست/بدون دسترسی shell، جست‌وجو انجام نمی‌شود.
		if ( defined( 'FALNIC_SKIP_BINARY_PROBE' ) && FALNIC_SKIP_BINARY_PROBE ) {
			return $name;
		}

		$cached = get_option( 'falnic_bin_' . $name, '' );

		if ( is_string( $cached ) && '' !== $cached && ( false === strpos( $cached, '/' ) || is_executable( $cached ) ) ) {
			return $cached;
		}

		$candidates = array(
			'/usr/bin/' . $name,
			'/usr/local/bin/' . $name,
			'/opt/bin/' . $name,
			'/opt/' . $name . '/bin/' . $name,
			'/usr/local/ffmpeg/bin/' . $name,
			'/home/*/bin/' . $name,
		);

		foreach ( $candidates as $path ) {
			if ( false === strpos( $path, '*' ) && is_executable( $path ) ) {
				update_option( 'falnic_bin_' . $name, $path, false );

				return $path;
			}
		}

		// نتیجه‌ی منفی یک ساعت کش می‌شود تا در هر درخواست جست‌وجو تکرار نشود.
		$missing = (int) get_option( 'falnic_bin_missing_' . $name, 0 );

		if ( $missing > 0 && ( time() - $missing ) < HOUR_IN_SECONDS ) {
			return $name;
		}

		// جست‌وجوی shell فقط اگر php اجازه دهد (در برخی محیط‌ها exec خطا می‌دهد).
		if ( function_exists( 'exec' ) ) {
			try {
				$output = array();
				$code   = 1;

				@exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec, WordPress.PHP.NoSilencedErrors.Discouraged

				if ( 0 === $code && ! empty( $output[0] ) && is_executable( trim( (string) $output[0] ) ) ) {
					$path = trim( (string) $output[0] );
					update_option( 'falnic_bin_' . $name, $path, false );

					return $path;
				}
			} catch ( \Throwable $error ) {
				// محیطی که exec را نمی‌پذیرد؛ به نام باینری برمی‌گردیم.
				unset( $error );
			}
		}

		update_option( 'falnic_bin_missing_' . $name, time(), false );

		return $name;
	}

	/**
	 * مسیر باینری ffprobe (همراه ffmpeg نصب می‌شود).
	 *
	 * @return string
	 */
	public static function ffprobe_bin(): string {
		$bin = defined( 'FALNIC_FFPROBE_BIN' ) ? (string) FALNIC_FFPROBE_BIN : self::detect_binary( 'ffprobe' );

		/**
		 * فیلتر مسیر باینری ffprobe.
		 *
		 * @param string $bin مسیر پیش‌فرض.
		 */
		return (string) apply_filters( 'falnic_ffprobe_bin', $bin );
	}

	/**
	 * وضعیت آخرین اجرا.
	 *
	 * @return string
	 */
	public static function last_status(): string {
		return self::$last_status;
	}

	/**
	 * پیام خطای آخرین اجرا.
	 *
	 * @return string
	 */
	public static function last_error(): string {
		return self::$last_error;
	}

	/**
	 * انکود همگام (سازگاری با فراخوانی‌های قبلی).
	 *
	 * @param string $input_path مسیر فایل ورودی.
	 * @param string $name       نام ویدیو.
	 * @return bool
	 */
	public static function encode( string $input_path, string $name ): bool {
		$result = self::process( $input_path, $name );

		return (bool) $result['ok'];
	}

	/**
	 * انکود کامل با گزارش پیشرفت و امکان لغو.
	 *
	 * @param string        $input_path مسیر فایل ورودی.
	 * @param string        $name       نام ویدیو.
	 * @param callable|null $progress   تابع دریافت پیشرفت: function( int $percent, array $meta ): bool (false = لغو).
	 * @return array{ok: bool, status: string, error: string, variants: array}
	 */
	public static function process( string $input_path, string $name, $progress = null ): array {
		self::$last_status = 'failed';
		self::$last_error  = '';

		if ( ! Storage::is_valid_name( $name ) ) {
			return self::result( false, __( 'نام ویدیو نامعتبر است.', 'falnic-secure-hls' ) );
		}

		if ( ! self::can_exec() ) {
			return self::result( false, __( 'اجرای فرمان در سرور مسدود است (exec/proc_open).', 'falnic-secure-hls' ) );
		}

		if ( ! is_readable( $input_path ) ) {
			return self::result( false, __( 'فایل ورودی قابل خواندن نیست.', 'falnic-secure-hls' ) );
		}

		Storage::ensure_dirs();

		// پاک‌سازی خروجی قبلی، ولی زیرنویس‌ها حفظ می‌شوند.
		Storage::purge( $name, array( 'captions' ) );

		$key_dir   = Storage::key_dir( $name );
		$video_dir = Storage::video_dir( $name );

		foreach ( array( $key_dir, $video_dir ) as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return self::result( false, __( 'ساخت پوشه‌ی خروجی ناموفق بود.', 'falnic-secure-hls' ) );
			}
		}

		$key_file = Storage::key_path( $name );

		if ( ! self::write_key( $key_file ) ) {
			return self::result( false, __( 'نوشتن فایل کلید ناموفق بود. مجوز پوشه را بررسی کنید.', 'falnic-secure-hls' ) );
		}

		$keyinfo_file = Storage::tmp_dir() . $name . '-' . wp_generate_password( 8, false ) . '.keyinfo';
		$key_url      = set_url_scheme( admin_url( 'admin-ajax.php?action=secure_hls_key&video=' . rawurlencode( $name ) ) );
		$iv           = bin2hex( random_bytes( 16 ) );
		$keyinfo      = $key_url . "\n" . $key_file . "\n" . $iv;

		if ( false === file_put_contents( $keyinfo_file, $keyinfo, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return self::result( false, __( 'نوشتن فایل keyinfo ناموفق بود.', 'falnic-secure-hls' ) );
		}

		@chmod( $keyinfo_file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$probe    = self::probe( $input_path );
		$variants = self::variants( $probe );
		$note     = '';

		try {
			$run = self::run( $input_path, $name, $variants, $keyinfo_file, $video_dir, $probe, $progress );

			// اگر اجرای چندکیفیتی شکست خورد، یک‌بار تک‌کیفیتی امتحان می‌شود.
			if ( ! $run['ok'] && 'canceled' !== $run['status'] && count( $variants ) > 1 ) {
				Storage::purge( $name, array( 'captions' ) );
				wp_mkdir_p( $video_dir );

				$note     = __( 'کیفیت چندگانه روی این نسخه‌ی ffmpeg ساخته نشد؛ ویدیو با یک کیفیت آماده شد.', 'falnic-secure-hls' );
				$variants = self::single_variant( $probe );
				$run      = self::run( $input_path, $name, $variants, $keyinfo_file, $video_dir, $probe, $progress );
			}
		} finally {
			if ( file_exists( $keyinfo_file ) ) {
				wp_delete_file( $keyinfo_file );
			}
		}

		if ( ! $run['ok'] ) {
			if ( 'canceled' === $run['status'] ) {
				Storage::purge( $name, array( 'captions' ) );

				return self::result( false, __( 'انکود توسط مدیر لغو شد.', 'falnic-secure-hls' ), 'canceled' );
			}

			return self::result( false, $run['error'] );
		}

		$playlist = Storage::playlist_path( $name );

		if ( ! is_readable( $playlist ) || ! Storage::is_encoded( $name ) ) {
			return self::result( false, __( 'خروجی ffmpeg قابل استفاده نیست.', 'falnic-secure-hls' ) );
		}

		/**
		 * پس از انکود موفق.
		 *
		 * @param string $name     نام ویدیو.
		 * @param array  $variants کیفیت‌های ساخته‌شده.
		 */
		do_action( 'falnic_video_encoded', $name, $variants );

		self::$last_status = 'done';

		return array(
			'ok'       => true,
			'status'   => 'done',
			'error'    => '',
			'variants' => $variants,
			'note'     => $note,
		);
	}

	/**
	 * آیا امکان اجرای فرمان وجود دارد؟
	 *
	 * @return bool
	 */
	public static function can_exec(): bool {
		return function_exists( 'proc_open' ) || function_exists( 'exec' );
	}

	/**
	 * نسخه‌ی ffmpeg نصب‌شده (کش‌شده)؛ '' یعنی نامشخص.
	 *
	 * @return string
	 */
	public static function ffmpeg_version(): string {
		$cached = get_option( 'falnic_ffmpeg_version', '' );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		if ( defined( 'FALNIC_SKIP_BINARY_PROBE' ) && FALNIC_SKIP_BINARY_PROBE ) {
			return '';
		}

		if ( ! self::can_exec() ) {
			return '';
		}

		$output = self::capture( array( self::ffmpeg_bin(), '-version' ), 10 );
		$version = self::parse_version( $output );

		update_option( 'falnic_ffmpeg_version', $version, false );

		return $version;
	}

	/**
	 * استخراج نسخه از خروجی `ffmpeg -version`.
	 *
	 * @param string $output خروجی فرمان.
	 * @return string نسخه (مثل 4.4.2) یا ''.
	 */
	public static function parse_version( string $output ): string {
		if ( preg_match( '/ffmpeg version (\d+)\.(\d+)(?:\.(\d+))?/', $output, $matches ) ) {
			return $matches[1] . '.' . $matches[2] . ( isset( $matches[3] ) && '' !== $matches[3] ? '.' . $matches[3] : '' );
		}

		return '';
	}

	/**
	 * آیا ffmpeg از `name:` در var_stream_map پشتیبانی می‌کند؟ (۴.۳+)
	 *
	 * نسخه‌های قدیمی‌تر با چند کیفیت خطا می‌دهند؛ در آن حالت تک‌کیفیتی ساخته می‌شود.
	 *
	 * @return bool
	 */
	public static function supports_named_variants(): bool {
		$version = self::ffmpeg_version();

		if ( '' === $version ) {
			// نامشخص: مسیر چندکیفیتی امتحان می‌شود و در صورت خطا تک‌کیفیتی جایگزین می‌شود.
			return true;
		}

		return version_compare( $version, '4.3', '>=' );
	}

	/**
	 * تعیین کیفیت‌های خروجی بر اساس منبع و تنظیمات.
	 *
	 * @param array $probe نتیجه‌ی probe.
	 * @return array
	 */
	public static function variants( array $probe ): array {
		$mode = (string) get_option( 'falnic_hls_quality', 'auto' );

		if ( 'single' === $mode ) {
			return self::single_variant( $probe );
		}

		if ( ! self::supports_named_variants() ) {
			return self::single_variant( $probe );
		}

		$height = (int) ( $probe['height'] ?? 0 );

		if ( $height <= 0 ) {
			return self::single_variant( $probe );
		}

		$max = (int) get_option( 'falnic_hls_max_renditions', 2 );
		$max = max( 1, min( 3, $max ) );

		$variants = array();

		foreach ( self::LADDER as $ladder_height => $kbps ) {
			if ( $ladder_height > $height + 8 ) {
				continue;
			}

			$variants[] = array(
				'height' => (int) $ladder_height,
				'kbps'   => (int) $kbps,
				'name'   => (int) $ladder_height . 'p',
			);

			if ( count( $variants ) >= $max ) {
				break;
			}
		}

		if ( empty( $variants ) ) {
			return self::single_variant( $probe );
		}

		return $variants;
	}

	/**
	 * یک کیفیت هم‌سطح منبع.
	 *
	 * @param array $probe نتیجه‌ی probe.
	 * @return array
	 */
	private static function single_variant( array $probe ): array {
		$height = (int) ( $probe['height'] ?? 0 );

		if ( $height <= 0 ) {
			$height = 720;
		}

		$kbps = 2800;

		foreach ( self::LADDER as $ladder_height => $ladder_kbps ) {
			if ( $height >= $ladder_height ) {
				$kbps = $ladder_kbps;
				$height = $ladder_height;
				break;
			}
		}

		return array(
			array(
				'height' => (int) $height,
				'kbps'   => (int) $kbps,
				'name'   => '',
			),
		);
	}

	/**
	 * خواندن مشخصات فایل ورودی با ffprobe (در صورت نبود، مقادیر صفر).
	 *
	 * @param string $input مسیر ورودی.
	 * @return array{width:int,height:int,duration:int,audio:bool}
	 */
	public static function probe( string $input ): array {
		$info = array(
			'width'    => 0,
			'height'   => 0,
			'duration' => 0,
			'audio'    => false,
		);

		if ( ! self::can_exec() ) {
			return $info;
		}

		$command = array(
			self::ffprobe_bin(),
			'-v',
			'error',
			'-show_entries',
			'format=duration:stream=codec_type,width,height',
			'-of',
			'json',
			$input,
		);

		$output = self::capture( $command, 20 );

		if ( '' === $output ) {
			return $info;
		}

		return self::parse_probe( $output );
	}

	/**
	 * تبدیل خروجی JSON ی ffprobe به مشخصات ویدیو.
	 *
	 * جدا شده تا بتوان با خروجی واقعی ffprobe تستش کرد (بدون اجرای فرمان).
	 *
	 * @param string $json خروجی ffprobe با `-of json`.
	 * @return array{width:int,height:int,duration:int,audio:bool}
	 */
	public static function parse_probe( string $json ): array {
		$info = array(
			'width'    => 0,
			'height'   => 0,
			'duration' => 0,
			'audio'    => false,
		);

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return $info;
		}

		$info['duration'] = (int) round( (float) ( $data['format']['duration'] ?? 0 ) );

		foreach ( (array) ( $data['streams'] ?? array() ) as $stream ) {
			if ( ! is_array( $stream ) ) {
				continue;
			}

			if ( 'audio' === ( $stream['codec_type'] ?? '' ) ) {
				$info['audio'] = true;
				continue;
			}

			if ( 'video' === ( $stream['codec_type'] ?? '' ) && empty( $info['height'] ) ) {
				$info['width']  = (int) ( $stream['width'] ?? 0 );
				$info['height'] = (int) ( $stream['height'] ?? 0 );
			}
		}

		return $info;
	}

	/**
	 * اجرای ffprobe و برگرداندن خروجی استاندارد.
	 *
	 * @param array $command فرمان.
	 * @param int   $timeout ثانیه.
	 * @return string
	 */
	private static function capture( array $command, int $timeout ): string {
		if ( ! function_exists( 'proc_open' ) ) {
			return '';
		}

		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = @proc_open( $command, $descriptors, $pipes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_resource( $process ) ) {
			return '';
		}

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$output   = '';
		$deadline = time() + $timeout;

		while ( true ) {
			$output .= (string) stream_get_contents( $pipes[1] );

			$status = proc_get_status( $process );

			if ( ! $status['running'] || time() > $deadline ) {
				break;
			}

			usleep( 50000 );
		}

		$output .= (string) stream_get_contents( $pipes[1] );

		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		proc_close( $process );

		return $output;
	}

	/**
	 * اجرای یک دور ffmpeg.
	 *
	 * @param string        $input       ورودی.
	 * @param string        $name        نام ویدیو.
	 * @param array         $variants    کیفیت‌ها.
	 * @param string        $keyinfo     فایل keyinfo.
	 * @param string        $video_dir   پوشه‌ی خروجی.
	 * @param array         $probe       مشخصات منبع.
	 * @param callable|null $progress    تابع پیشرفت.
	 * @return array{ok:bool,status:string,error:string}
	 */
	private static function run( string $input, string $name, array $variants, string $keyinfo, string $video_dir, array $probe, $progress ): array {
		$command   = self::build_command( $input, $name, $variants, $keyinfo, $video_dir, ! empty( $probe['audio'] ) );
		$duration  = (int) ( $probe['duration'] ?? 0 );

		// پوشه‌ی هر کیفیت باید پیش از اجرا وجود داشته باشد.
		foreach ( $variants as $variant ) {
			if ( '' !== (string) $variant['name'] ) {
				wp_mkdir_p( $video_dir . $variant['name'] );
			}
		}

		if ( ! function_exists( 'proc_open' ) ) {
			return self::run_exec( $command, $duration, $progress );
		}

		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = @proc_open( $command, $descriptors, $pipes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_resource( $process ) ) {
			return array(
				'ok'     => false,
				'status' => 'failed',
				'error'  => __( 'اجرای ffmpeg ممکن نشد.', 'falnic-secure-hls' ),
			);
		}

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$buffer   = '';
		$errors   = '';
		$deadline = time() + max( 300, (int) get_option( 'falnic_encode_timeout', 6 * HOUR_IN_SECONDS ) );
		$canceled = false;

		while ( true ) {
			$status = proc_get_status( $process );

			foreach ( array( 1, 2 ) as $index ) {
				$chunk = (string) stream_get_contents( $pipes[ $index ] );

				if ( '' === $chunk ) {
					continue;
				}

				if ( 1 === $index ) {
					$buffer .= $chunk;
				} else {
					$errors .= $chunk;

					// طول ویدیو ممکن است فقط در خروجی خطا باشد.
					if ( 0 === $duration && preg_match( '/Duration:\s*(\d+):(\d+):(\d+)/', $errors, $matches ) ) {
						$duration = ( (int) $matches[1] * 3600 ) + ( (int) $matches[2] * 60 ) + (int) $matches[3];
					}
				}
			}

			$percent = self::percent_from_progress( $buffer, $duration );

			if ( $percent > 0 && is_callable( $progress ) ) {
				$keep_going = call_user_func(
					$progress,
					$percent,
					array(
						'errors'   => substr( $errors, -2000 ),
						'duration' => $duration,
					)
				);

				if ( false === $keep_going ) {
					$canceled = true;
					proc_terminate( $process, 9 );
				}
			}

			if ( ! $status['running'] ) {
				break;
			}

			if ( time() > $deadline ) {
				proc_terminate( $process, 9 );
				$errors .= "\n" . __( 'زمان انکود بیش از حد مجاز شد.', 'falnic-secure-hls' );
				break;
			}

			if ( $canceled ) {
				break;
			}

			usleep( 200000 );
		}

		$buffer .= (string) stream_get_contents( $pipes[1] );
		$errors .= (string) stream_get_contents( $pipes[2] );

		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		$exit = proc_close( $process );

		if ( $canceled ) {
			return array(
				'ok'     => false,
				'status' => 'canceled',
				'error'  => __( 'انکود لغو شد.', 'falnic-secure-hls' ),
			);
		}

		if ( 0 !== (int) $exit ) {
			$tail = implode( "\n", array_slice( preg_split( '/\R/', trim( $errors ) ) ?: array(), -25 ) );

			return array(
				'ok'     => false,
				'status' => 'failed',
				'error'  => '' !== $tail ? $tail : __( 'ffmpeg با خطا پایان یافت.', 'falnic-secure-hls' ),
			);
		}

		return array(
			'ok'     => true,
			'status' => 'done',
			'error'  => '',
		);
	}

	/**
	 * مسیر جایگزین برای سرورهایی که proc_open ندارند (بدون پیشرفت/لغو).
	 *
	 * @param array    $command  فرمان.
	 * @param int      $duration طول ویدیو.
	 * @param callable $progress تابع پیشرفت.
	 * @return array{ok:bool,status:string,error:string}
	 */
	private static function run_exec( array $command, int $duration, $progress ): array {
		$line   = implode( ' ', array_map( 'escapeshellarg', $command ) ) . ' 2>&1';
		$output = array();
		$code   = 1;

		try {
			exec( $line, $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		} finally {
			if ( is_callable( $progress ) ) {
				call_user_func( $progress, 100, array( 'errors' => implode( "\n", array_slice( $output, -20 ) ), 'duration' => $duration ) );
			}
		}

		if ( 0 !== (int) $code ) {
			return array(
				'ok'     => false,
				'status' => 'failed',
				'error'  => implode( "\n", array_slice( $output, -25 ) ),
			);
		}

		return array(
			'ok'     => true,
			'status' => 'done',
			'error'  => '',
		);
	}

	/**
	 * ساخت آرایه‌ی فرمان ffmpeg (بدون shell).
	 *
	 * @param string $input     ورودی.
	 * @param string $name      نام ویدیو.
	 * @param array  $variants  کیفیت‌ها.
	 * @param string $keyinfo   فایل keyinfo.
	 * @param string $video_dir پوشه‌ی خروجی.
	 * @param bool   $has_audio آیا منبع صدا دارد.
	 * @return array
	 */
	public static function build_command( string $input, string $name, array $variants, string $keyinfo, string $video_dir, bool $has_audio = true ): array {
		/**
		 * فیلتر آرگومان‌های پایه‌ی ffmpeg.
		 *
		 * @param array  $args آرگومان‌ها.
		 * @param string $name نام ویدیو.
		 */
		$base = apply_filters(
			'falnic_ffmpeg_args',
			array(
				'-c:v'               => 'libx264',
				'-preset'            => 'fast',
				'-crf'               => '23',
				'-c:a'               => 'aac',
				'-b:a'               => '128k',
				'-hls_time'          => (string) max( 2, (int) get_option( 'falnic_hls_segment_seconds', self::SEGMENT_SECONDS ) ),
				'-hls_playlist_type' => 'vod',
			),
			$name
		);

		$common = array(
			self::ffmpeg_bin(),
			'-y',
			'-hide_banner',
			'-nostdin',
			'-i',
			$input,
			'-progress',
			'pipe:1',
			'-nostats',
		);

		$hls = array(
			'-hls_key_info_file',
			$keyinfo,
			'-hls_flags',
			'independent_segments',
		);

		$audio_args = self::pair_args( $base, array( '-c:a', '-b:a' ) );

		if ( count( $variants ) <= 1 ) {
			$variant  = $variants[0];
			$video    = self::pair_args( $base, array( '-c:v', '-preset', '-crf', '-profile:v', '-pix_fmt' ) );
			$segments = $video_dir . 'segment_%05d.ts';
			$playlist = $video_dir . $name . '.m3u8';

			return array_merge(
				$common,
				$video,
				array(
					'-maxrate',
					$variant['kbps'] . 'k',
					'-bufsize',
					( $variant['kbps'] * 2 ) . 'k',
					'-vf',
					// یک بک‌اسلش کافی است: در proc_open (argv بدون shell) همان‌طور به ffmpeg می‌رسد
					// و در run_exec داخل کوتیشن تک‌نقلب escapeshellarg هم دست‌نخورده می‌ماند.
					'scale=-2:min(' . (int) $variant['height'] . '\,ih)',
				),
				$has_audio ? array_merge( $audio_args, array( '-ac', '2' ) ) : array(),
				self::pair_args( $base, array( '-hls_time', '-hls_playlist_type' ) ),
				$hls,
				array(
					'-hls_segment_filename',
					$segments,
					$playlist,
				)
			);
		}

		$count      = count( $variants );
		$split      = '[0:v]split=' . $count;
		$labels     = array();
		$scale      = array();
		$maps       = array();
		$stream_map = array();
		$video_enc  = array();
		$audio_maps = array();

		foreach ( $variants as $index => $variant ) {
			$labels[] = '[v' . $index . ']';
			$scale[]  = '[v' . $index . ']scale=-2:' . (int) $variant['height'] . '[v' . $index . 'out]';
			$maps[]   = array( '-map', '[v' . $index . 'out]' );

			if ( $has_audio ) {
				$maps[] = array( '-map', '0:a:0' );
			}

			$video_enc[] = array(
				'-c:v:' . $index,
				'libx264',
				'-b:v:' . $index,
				$variant['kbps'] . 'k',
				'-maxrate:v:' . $index,
				( $variant['kbps'] * 1.15 ) . 'k',
				'-bufsize:v:' . $index,
				( $variant['kbps'] * 2 ) . 'k',
				'-preset:v:' . $index,
				'fast',
			);

			$audio_maps[] = $has_audio
				? 'v:' . $index . ',a:' . $index . ',name:' . $variant['name']
				: 'v:' . $index . ',name:' . $variant['name'];
		}

		$filter = $split . implode( '', $labels ) . ';' . implode( ';', $scale );

		$command = $common;

		foreach ( $maps as $map ) {
			$command = array_merge( $command, $map );
		}

		foreach ( $video_enc as $encoder_args ) {
			$command = array_merge( $command, $encoder_args );
		}

		$command = array_merge(
			$command,
			array( '-filter_complex', $filter ),
			$audio_args,
			array(
				'-var_stream_map',
				implode( ' ', $audio_maps ),
				'-master_pl_name',
				'master.m3u8',
			),
			self::pair_args( $base, array( '-hls_time', '-hls_playlist_type' ) ),
			$hls,
			array(
				'-hls_segment_filename',
				$video_dir . '%v/segment_%05d.ts',
				$video_dir . '%v/index.m3u8',
			)
		);

		return $command;
	}

	/**
	 * استخراج آرگومان‌های مشخص از فیلتر پایه.
	 *
	 * @param array $base فیلتر.
	 * @param array $keys کلیدهای مورد نظر.
	 * @return array
	 */
	private static function pair_args( array $base, array $keys ): array {
		$out = array();

		foreach ( $keys as $key ) {
			if ( isset( $base[ $key ] ) ) {
				$out[] = $key;
				$out[] = (string) $base[ $key ];
			}
		}

		return $out;
	}

	/**
	 * محاسبه‌ی درصد پیشرفت از خروجی `-progress`.
	 *
	 * @param string $buffer   بافر خروجی.
	 * @param int    $duration طول ویدیو به ثانیه.
	 * @return int
	 */
	public static function percent_from_progress( string $buffer, int $duration ): int {
		if ( $duration <= 0 || '' === $buffer ) {
			return 0;
		}

		$matches = array();

		if ( preg_match_all( '/out_time_us=(\d+)/', $buffer, $matches ) < 1 ) {
			return 0;
		}

		$micro = (int) end( $matches[1] );
		$ratio = ( $micro / 1000000 ) / $duration;

		return max( 0, min( 100, (int) round( $ratio * 100 ) ) );
	}

	/**
	 * نوشتن کلید تصادفی با مجوز محدود.
	 *
	 * @param string $path مسیر فایل کلید.
	 * @return bool
	 */
	private static function write_key( string $path ): bool {
		try {
			$bytes = random_bytes( 16 );
		} catch ( \Exception $e ) {
			return false;
		}

		$written = file_put_contents( $path, $bytes, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( false === $written ) {
			return false;
		}

		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return true;
	}

	/**
	 * ساخت نتیجه‌ی استاندارد شکست.
	 *
	 * @param bool   $ok     نتیجه.
	 * @param string $error  پیام.
	 * @param string $status وضعیت.
	 * @return array
	 */
	private static function result( bool $ok, string $error, string $status = 'failed' ): array {
		self::$last_status = $ok ? 'done' : $status;
		self::$last_error  = $error;

		if ( '' !== $error ) {
			/**
			 * پس از شکست انکود.
			 *
			 * @param string $error پیام خطا.
			 */
			do_action( 'falnic_video_failed', $error );
		}

		return array(
			'ok'       => $ok,
			'status'   => self::$last_status,
			'error'    => $error,
			'variants' => array(),
		);
	}
}
