<?php
/**
 * رجیستری نشست‌های پخش (محدودیت پخش هم‌زمان).
 *
 * هر پخش یک شناسه‌ی کوتاه (sid) می‌گیرد که در توکن‌ها هم می‌آید. اگر کاربر
 * بیش از سقف مجاز پخش هم‌زمان داشته باشد، نشست جدید رد می‌شود؛ نشست‌های
 * بی‌فعالیت پس از چند دقیقه خودبه‌خود آزاد می‌شوند.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * مدیریت نشست‌های فعال پخش برای هر کاربر.
 */
final class Play_Registry {

	/**
	 * عمر نشست بدون ضربان (ثانیه).
	 */
	public const TTL = 150;

	/**
	 * بازه‌ی به‌روزرسانی «آخرین فعالیت» (ثانیه).
	 */
	private const TOUCH_INTERVAL = 20;

	/**
	 * سقف پخش هم‌زمان (۰ = بی‌نهایت).
	 *
	 * @return int
	 */
	public static function max(): int {
		return max( 0, (int) get_option( 'falnic_max_concurrent', 1 ) );
	}

	/**
	 * آیا محدودیت فعال است؟
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return self::max() > 0;
	}

	/**
	 * فهرست نشست‌های فعال یک کاربر.
	 *
	 * @param int $user_id کاربر.
	 * @return array<string, array>
	 */
	public static function sessions( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$data = get_transient( self::key( $user_id ) );

		if ( ! is_array( $data ) ) {
			return array();
		}

		$now = time();
		$out = array();

		foreach ( $data as $sid => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$seen = (int) ( $row['seen'] ?? 0 );

			if ( $seen + self::TTL < $now ) {
				continue;
			}

			$out[ (string) $sid ] = $row;
		}

		return $out;
	}

	/**
	 * آغاز یک نشست پخش.
	 *
	 * @param int    $user_id کاربر.
	 * @param string $video   نام ویدیو.
	 * @return string|\WP_Error شناسه‌ی نشست یا خطا.
	 */
	public static function start( int $user_id, string $video ) {
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'falnic_guest', __( 'برای پخش باید وارد شوید.', 'falnic-secure-hls' ), array( 'status' => 401 ) );
		}

		$sessions = self::sessions( $user_id );
		$is_admin = function_exists( 'user_can' ) && user_can( $user_id, 'manage_options' );

		if ( self::enabled() && ! $is_admin && count( $sessions ) >= self::max() ) {
			return new \WP_Error(
				'falnic_too_many_plays',
				__( 'این حساب در دستگاه دیگری در حال پخش است. برای تماشا، پخش قبلی را ببندید.', 'falnic-secure-hls' ),
				array(
					'status'   => 409,
					'max'      => self::max(),
					'sessions' => array_values( array_map( static function ( $row ) {
						return array(
							'video'  => (string) ( $row['video'] ?? '' ),
							'agent'  => (string) ( $row['agent'] ?? '' ),
							'seen'   => (int) ( $row['seen'] ?? 0 ),
						);
					}, $sessions ) ),
				)
			);
		}

		$sid = self::new_id();

		$sessions[ $sid ] = array(
			'video' => $video,
			'start' => time(),
			'seen'  => time(),
			'agent' => self::agent_label(),
		);

		self::persist( $user_id, $sessions );

		return $sid;
	}

	/**
	 * ثبت ضربان یا فعالیت یک نشست.
	 *
	 * @param int    $user_id کاربر.
	 * @param string $sid     شناسه‌ی نشست.
	 * @return bool آیا نشست معتبر است.
	 */
	public static function touch( int $user_id, string $sid ): bool {
		if ( $user_id <= 0 || '' === $sid ) {
			return false;
		}

		$sessions = self::sessions( $user_id );

		if ( ! isset( $sessions[ $sid ] ) ) {
			return false;
		}

		$now  = time();
		$last = (int) ( $sessions[ $sid ]['seen'] ?? 0 );

		if ( $now - $last < self::TOUCH_INTERVAL ) {
			// نوشتن مکرر لازم نیست؛ نشست هنوز معتبر است.
			return true;
		}

		$sessions[ $sid ]['seen'] = $now;
		self::persist( $user_id, $sessions );

		return true;
	}

	/**
	 * بررسی اعتبار نشست.
	 *
	 * @param int    $user_id کاربر.
	 * @param string $sid     شناسه.
	 * @return bool
	 */
	public static function check( int $user_id, string $sid ): bool {
		if ( ! self::enabled() ) {
			return true;
		}

		$sessions = self::sessions( $user_id );

		return isset( $sessions[ $sid ] );
	}

	/**
	 * پایان دادن به یک نشست (بستن پلیر یا قطع مدیر).
	 *
	 * @param int    $user_id کاربر.
	 * @param string $sid     شناسه.
	 * @return bool
	 */
	public static function end( int $user_id, string $sid ): bool {
		return self::terminate( $user_id, $sid );
	}

	/**
	 * قطع همه‌ی پخش‌های فعال یک کاربر (دکمه‌ی «قطع پخش»).
	 *
	 * @param int $user_id کاربر.
	 * @return int تعداد نشست‌های قطع‌شده.
	 */
	public static function terminate_all( int $user_id ): int {
		$sessions = self::sessions( $user_id );

		if ( empty( $sessions ) ) {
			return 0;
		}

		foreach ( array_keys( $sessions ) as $sid ) {
			Watch_Log::end( (string) $sid );
		}

		delete_transient( self::key( $user_id ) );

		return count( $sessions );
	}

	/**
	 * حذف یک نشست.
	 *
	 * @param int    $user_id کاربر.
	 * @param string $sid     شناسه.
	 * @return bool
	 */
	public static function terminate( int $user_id, string $sid ): bool {
		$sessions = self::sessions( $user_id );

		if ( ! isset( $sessions[ $sid ] ) ) {
			return false;
		}

		unset( $sessions[ $sid ] );
		self::persist( $user_id, $sessions );

		return true;
	}

	/**
	 * جزئیات یک نشست.
	 *
	 * @param int    $user_id کاربر.
	 * @param string $sid     شناسه.
	 * @return array|null
	 */
	public static function get( int $user_id, string $sid ): ?array {
		$sessions = self::sessions( $user_id );

		return $sessions[ $sid ] ?? null;
	}

	/**
	 * تولید شناسه‌ی تصادفی امن.
	 *
	 * @return string
	 */
	public static function new_id(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	/**
	 * ذخیره‌ی فهرست نشست‌ها.
	 *
	 * @param int   $user_id کاربر.
	 * @param array $sessions فهرست.
	 * @return void
	 */
	private static function persist( int $user_id, array $sessions ): void {
		if ( empty( $sessions ) ) {
			delete_transient( self::key( $user_id ) );

			return;
		}

		set_transient( self::key( $user_id ), $sessions, 12 * HOUR_IN_SECONDS );
	}

	/**
	 * کلید ترنزینت.
	 *
	 * @param int $user_id کاربر.
	 * @return string
	 */
	private static function key( int $user_id ): string {
		return 'falnic_plays_' . $user_id;
	}

	/**
	 * برچسب کوتاه مرورگر برای نمایش در گزارش.
	 *
	 * @return string
	 */
	private static function agent_label(): string {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		$label = __( 'نامشخص', 'falnic-secure-hls' );

		foreach ( array(
			'iPhone'  => 'iPhone',
			'iPad'    => 'iPad',
			'Android' => 'Android',
			'Windows' => 'Windows',
			'Mac OS'  => 'macOS',
			'Linux'   => 'Linux',
		) as $needle => $name ) {
			if ( false !== stripos( $agent, $needle ) ) {
				$label = $name;
				break;
			}
		}

		return $label;
	}
}
