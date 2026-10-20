<?php
/**
 * انکود و رمزنگاری ویدیو با ffmpeg.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * تبدیل MP4 به HLS رمزنگاری‌شده با AES-128.
 */
final class Encoder {

	/**
	 * مسیر باینری ffmpeg.
	 *
	 * @return string
	 */
	public static function ffmpeg_bin(): string {
		$bin = defined( 'FALNIC_FFMPEG_BIN' ) ? FALNIC_FFMPEG_BIN : 'ffmpeg';

		/**
		 * فیلتر مسیر باینری ffmpeg.
		 *
		 * @param string $bin مسیر پیش‌فرض.
		 */
		return (string) apply_filters( 'falnic_ffmpeg_bin', $bin );
	}

	/**
	 * انکود یک فایل ورودی.
	 *
	 * @param string $input_path مسیر فایل ورودی.
	 * @param string $name       نام ویدیو (شناسه‌ی پوشه).
	 * @return bool
	 */
	public static function encode( string $input_path, string $name ): bool {
		if ( ! Storage::is_valid_name( $name ) ) {
			self::fail( __( 'نام ویدیو نامعتبر است.', 'falnic-secure-hls' ) );

			return false;
		}

		if ( ! function_exists( 'exec' ) ) {
			self::fail( __( 'تابع exec() در سرور مسدود است.', 'falnic-secure-hls' ) );

			return false;
		}

		if ( ! is_readable( $input_path ) ) {
			self::fail( __( 'فایل ورودی قابل خواندن نیست.', 'falnic-secure-hls' ) );

			return false;
		}

		Storage::ensure_dirs();

		$key_dir  = Storage::key_dir( $name );
		$video_dir = Storage::video_dir( $name );

		if ( ! is_dir( $key_dir ) ) {
			wp_mkdir_p( $key_dir );
		}
		if ( ! is_dir( $video_dir ) ) {
			wp_mkdir_p( $video_dir );
		}

		// پاک‌سازی خروجی قبلی.
		Storage::purge( $name );
		wp_mkdir_p( $key_dir );
		wp_mkdir_p( $video_dir );

		$key_file = Storage::key_path( $name );

		if ( ! self::write_key( $key_file ) ) {
			self::fail( __( 'نوشتن فایل کلید ناموفق بود. مجوز پوشه را بررسی کنید.', 'falnic-secure-hls' ) );

			return false;
		}

		$iv           = bin2hex( random_bytes( 16 ) );
		$keyinfo_file = $key_dir . $name . '.keyinfo';
		$key_url      = set_url_scheme( admin_url( 'admin-ajax.php?action=secure_hls_key&video=' . rawurlencode( $name ) ) );

		$keyinfo = $key_url . "\n" . $key_file . "\n" . $iv;
		if ( false === file_put_contents( $keyinfo_file, $keyinfo ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			self::fail( __( 'نوشتن فایل keyinfo ناموفق بود.', 'falnic-secure-hls' ) );

			return false;
		}
		@chmod( $keyinfo_file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$segments = $video_dir . '%05d.ts';
		$playlist = $video_dir . $name . '.m3u8';

		/**
		 * فیلتر آرگومان‌های ffmpeg.
		 *
		 * @param array  $args آرگومان‌ها.
		 * @param string $name نام ویدیو.
		 */
		$args = apply_filters(
			'falnic_ffmpeg_args',
			array(
				'-c:v'                 => 'libx264',
				'-preset'              => 'fast',
				'-crf'                 => '23',
				'-c:a'                 => 'aac',
				'-b:a'                 => '128k',
				'-hls_time'            => '10',
				'-hls_playlist_type'   => 'vod',
			),
			$name
		);

		$command = sprintf(
			'%s -y -i %s %s -hls_key_info_file %s -hls_segment_filename %s %s 2>&1',
			escapeshellarg( self::ffmpeg_bin() ),
			escapeshellarg( $input_path ),
			self::build_args( $args ),
			escapeshellarg( $keyinfo_file ),
			escapeshellarg( $segments ),
			escapeshellarg( $playlist )
		);

		$output     = array();
		$return_var = 1;

		try {
			exec( $command, $output, $return_var ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		} finally {
			if ( file_exists( $keyinfo_file ) ) {
				wp_delete_file( $keyinfo_file );
			}
		}

		if ( 0 !== (int) $return_var || ! is_readable( $playlist ) ) {
			$tail = array_slice( (array) $output, -40 );
			self::fail( implode( "\n", $tail ) );

			return false;
		}

		/**
		 * پس از انکود موفق.
		 *
		 * @param string $name نام ویدیو.
		 */
		do_action( 'falnic_video_encoded', $name );

		return true;
	}

	/**
	 * ساخت رشته‌ی آرگومان‌ها با escape.
	 *
	 * @param array $args آرگومان‌ها.
	 * @return string
	 */
	private static function build_args( array $args ): string {
		$out = array();

		foreach ( $args as $flag => $value ) {
			$out[] = escapeshellarg( (string) $flag ) . ' ' . escapeshellarg( (string) $value );
		}

		return implode( ' ', $out );
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
	 * ثبت خطای انکود برای نمایش به ادمین.
	 *
	 * @param string $message پیام خطا.
	 * @return void
	 */
	private static function fail( string $message ): void {
		set_transient( 'falnic_encode_error_' . get_current_user_id(), $message, 60 );

		/**
		 * پس از شکست انکود.
		 *
		 * @param string $message پیام خطا.
		 */
		do_action( 'falnic_video_failed', $message );
	}
}
