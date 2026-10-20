<?php
/**
 * تصمیم واحد دسترسی به ویدیو.
 *
 * همه‌ی مسیرهای پخش (رندر HTML، نشست، manifest، سگمنت و کلید) از همین
 * کلاس استفاده می‌کنند تا یک ویدیو نتواند از مسیری بدون بررسی عبور کند.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * بررسی دسترسی بر اساس ثبت‌نام در دوره (LearnDash).
 */
final class Access {

	/**
	 * قابلیت جایگزین برای ویدیوهای متصل‌نشده به دوره.
	 */
	public const CAP = 'falnic_watch_videos';

	/**
	 * متایی که روی درس/مبحث نام ویدیو را نگه می‌دارد.
	 */
	public const LESSON_META = '_falnic_video_name';

	/**
	 * پست‌تایپ‌هایی که ویدیو به آن‌ها متصل می‌شود.
	 */
	public const STEP_TYPES = array( 'sfwd-lessons', 'sfwd-topic' );

	/**
	 * کش نتیجه در طول یک درخواست.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * آیا LearnDash فعال است؟
	 *
	 * @return bool
	 */
	public static function learn_dash_active(): bool {
		return function_exists( 'sfwd_lms_has_access' )
			|| function_exists( 'learndash_get_course_id' )
			|| defined( 'LEARNDASH_VERSION' );
	}

	/**
	 * آیا ثبت‌نام در دوره الزامی است؟
	 *
	 * @return bool
	 */
	public static function enrollment_required(): bool {
		return 'yes' === get_option( 'falnic_ld_require_enrollment', 'yes' );
	}

	/**
	 * دلیل دسترسی؛ 'ok' یعنی مجاز.
	 *
	 * @param string   $name    نام ویدیو.
	 * @param int|null $user_id کاربر (پیش‌فرض: کاربر جاری).
	 * @return string ok|invalid|guest|not_ready|not_linked|not_enrolled|locked
	 */
	public static function reason( string $name, $user_id = null ): string {
		$user_id = null === $user_id ? (int) get_current_user_id() : (int) $user_id;

		if ( '' === $name || ! Storage::is_valid_name( $name ) ) {
			return 'invalid';
		}

		$cache_key = $user_id . '|' . $name;

		if ( isset( self::$memo[ $cache_key ] ) ) {
			return self::$memo[ $cache_key ];
		}

		$object_key = 'access_' . md5( $cache_key );

		// کش کوتاه (۶۰ ثانیه) برای درخواست‌های متعدد سگمنت در یک نشست.
		if ( function_exists( 'wp_cache_get' ) && wp_cache_get( $object_key, 'falnic' ) ) {
			self::$memo[ $cache_key ] = 'ok';

			return 'ok';
		}

		$reason = self::evaluate( $name, $user_id );

		/**
		 * فیلتر نهایی دسترسی.
		 *
		 * @param string $reason  دلیل.
		 * @param int    $user_id کاربر.
		 * @param string $name    نام ویدیو.
		 */
		$reason = (string) apply_filters( 'falnic_access_reason', $reason, $user_id, $name );

		self::$memo[ $cache_key ] = $reason;

		if ( 'ok' === $reason && function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( $object_key, 1, 'falnic', 60 );
		}

		return $reason;
	}

	/**
	 * آیا کاربر می‌تواند ویدیو را ببیند؟
	 *
	 * @param string   $name    نام ویدیو.
	 * @param int|null $user_id کاربر.
	 * @return bool
	 */
	public static function can_watch( string $name, $user_id = null ): bool {
		return 'ok' === self::reason( $name, $user_id );
	}

	/**
	 * محاسبه‌ی واقعی دسترسی.
	 *
	 * @param string $name    نام ویدیو.
	 * @param int    $user_id کاربر.
	 * @return string
	 */
	private static function evaluate( string $name, int $user_id ): string {
		if ( $user_id <= 0 ) {
			return 'guest';
		}

		if ( ! Storage::is_encoded( $name ) ) {
			return 'not_ready';
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return 'ok';
		}

		$lessons = self::linked_lesson_ids( $name );

		if ( empty( $lessons ) ) {
			if ( self::enrollment_required() ) {
				return self::course_agnostic_allowed( $user_id, $name ) ? 'ok' : 'not_linked';
			}

			return 'ok';
		}

		if ( ! self::learn_dash_active() ) {
			// ویدیو به درس وصل است اما LearnDash خاموش شده؛ محتوای پولی آزاد نمی‌شود.
			return 'not_enrolled';
		}

		$locked = false;

		foreach ( $lessons as $lesson_id ) {
			$course_id = self::course_id( (int) $lesson_id );

			if ( $course_id <= 0 ) {
				continue;
			}

			if ( ! self::is_enrolled( $course_id, $user_id ) ) {
				continue;
			}

			if ( self::is_locked( (int) $lesson_id, $course_id, $user_id ) ) {
				$locked = true;
				continue;
			}

			return 'ok';
		}

		return $locked ? 'locked' : 'not_enrolled';
	}

	/**
	 * اجازه‌ی ویدیوی بدون درس، بر اساس قابلیت یا فیلتر سفارشی.
	 *
	 * @param int    $user_id کاربر.
	 * @param string $name    نام ویدیو.
	 * @return bool
	 */
	private static function course_agnostic_allowed( int $user_id, string $name ): bool {
		$allowed = user_can( $user_id, self::CAP );

		/**
		 * اجازه‌ی دسترسی به ویدیوی متصل‌نشده به دوره.
		 *
		 * @param bool   $allowed اجازه.
		 * @param int    $user_id کاربر.
		 * @param string $name    نام ویدیو.
		 */
		return (bool) apply_filters( 'falnic_can_watch_unlinked', $allowed, $user_id, $name );
	}

	/**
	 * پاک‌کردن کش تصمیم‌ها (پس از تغییر ثبت‌نام یا نقش).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = array();

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'falnic' );
		}
	}

	/**
	 * لیست درس/مباحث متصل به یک ویدیو.
	 *
	 * @param string $name نام ویدیو.
	 * @return int[]
	 */
	public static function linked_lesson_ids( string $name ): array {
		$cache_key = 'links_' . md5( $name );

		if ( isset( self::$memo[ $cache_key ] ) ) {
			return (array) self::$memo[ $cache_key ];
		}

		$posts = get_posts(
			array(
				'post_type'        => self::STEP_TYPES,
				'post_status'      => 'any',
				'posts_per_page'   => 50,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'         => self::LESSON_META,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => $name,
			)
		);

		$ids = array_values( array_unique( array_map( 'absint', (array) $posts ) ) );

		self::$memo[ $cache_key ] = $ids;

		return $ids;
	}

	/**
	 * دوره‌ی والد یک درس/مبحث.
	 *
	 * @param int $lesson_id شناسه.
	 * @return int
	 */
	private static function course_id( int $lesson_id ): int {
		if ( function_exists( 'learndash_get_course_id' ) ) {
			$course_id = (int) learndash_get_course_id( $lesson_id );

			if ( $course_id > 0 ) {
				return $course_id;
			}
		}

		$meta = (int) get_post_meta( $lesson_id, 'course_id', true );

		return $meta > 0 ? $meta : 0;
	}

	/**
	 * آیا کاربر در دوره ثبت‌نام کرده است؟
	 *
	 * @param int $course_id شناسه‌ی دوره.
	 * @param int $user_id   کاربر.
	 * @return bool
	 */
	private static function is_enrolled( int $course_id, int $user_id ): bool {
		if ( function_exists( 'sfwd_lms_has_access' ) ) {
			return (bool) sfwd_lms_has_access( $course_id, $user_id );
		}

		if ( function_exists( 'learndash_user_get_enrolled_courses' ) ) {
			$courses = (array) learndash_user_get_enrolled_courses( $user_id, true );

			return in_array( $course_id, array_map( 'absint', $courses ), true );
		}

		// سازگاری حداقلی: تاریخ دسترسی ذخیره‌شده توسط LearnDash.
		return '' !== (string) get_user_meta( $user_id, 'course_' . $course_id . '_access_from', true );
	}

	/**
	 * آیا درس هنوز باز نشده است (Drip / پیش‌نیاز)؟
	 *
	 * @param int $lesson_id شناسه‌ی درس.
	 * @param int $course_id شناسه‌ی دوره.
	 * @param int $user_id   کاربر.
	 * @return bool
	 */
	private static function is_locked( int $lesson_id, int $course_id, int $user_id ): bool {
		if ( function_exists( 'learndash_is_lesson_not_available' ) ) {
			try {
				$state = learndash_is_lesson_not_available( $lesson_id, $user_id, $course_id );
			} catch ( \Throwable $error ) {
				$state = false;
			}

			if ( true === $state || is_wp_error( $state ) ) {
				return true;
			}
		}

		if ( ! function_exists( 'learndash_get_setting' ) ) {
			return false;
		}

		$settings = (array) learndash_get_setting( $lesson_id );

		if ( ! isset( $settings['lesson_schedule'] ) || 'visible_after' !== $settings['lesson_schedule'] ) {
			return false;
		}

		$days     = isset( $settings['visible_after'] ) ? (int) $settings['visible_after'] : 0;
		$enrolled = (int) get_user_meta( $user_id, 'course_' . $course_id . '_access_from', true );

		if ( $days <= 0 || $enrolled <= 0 ) {
			return false;
		}

		return ( $enrolled + ( $days * DAY_IN_SECONDS ) ) > time();
	}

	/**
	 * دوره‌های مرتبط با یک ویدیو (برای نمایش لینک در پیام رد دسترسی).
	 *
	 * @param string $name نام ویدیو.
	 * @return array
	 */
	public static function courses_for( string $name ): array {
		$courses = array();

		foreach ( self::linked_lesson_ids( $name ) as $lesson_id ) {
			$course_id = self::course_id( (int) $lesson_id );

			if ( $course_id <= 0 || isset( $courses[ $course_id ] ) ) {
				continue;
			}

			$courses[ $course_id ] = array(
				'id'    => $course_id,
				'title' => get_the_title( $course_id ),
				'url'   => (string) get_permalink( $course_id ),
			);
		}

		return array_values( $courses );
	}

	/**
	 * پیام فارسی قابل‌اقدام برای هر دلیل.
	 *
	 * @param string $reason دلیل.
	 * @return string
	 */
	public static function message( string $reason ): string {
		switch ( $reason ) {
			case 'guest':
				return __( 'برای تماشای این ویدیو ابتدا وارد حساب کاربری خود شوید.', 'falnic-secure-hls' );

			case 'not_ready':
				return __( 'این ویدیو هنوز آماده نیست. کمی بعد دوباره تلاش کنید.', 'falnic-secure-hls' );

			case 'not_linked':
				return __( 'این ویدیو به هیچ دوره‌ای متصل نشده است؛ دسترسی فقط برای مدیران است.', 'falnic-secure-hls' );

			case 'not_enrolled':
				return __( 'این ویدیو مخصوص دانشجویان دوره است. برای تماشا باید در دوره ثبت‌نام کنید.', 'falnic-secure-hls' );

			case 'locked':
				return __( 'این درس هنوز برای شما باز نشده است. زمان باز شدن را در صفحه‌ی دوره ببینید.', 'falnic-secure-hls' );

			case 'invalid':
				return __( 'درخواست نامعتبر است.', 'falnic-secure-hls' );

			default:
				return __( 'شما اجازه‌ی تماشای این ویدیو را ندارید.', 'falnic-secure-hls' );
		}
	}
}
