<?php
/**
 * گزارش تماشا و ردیابی اشتراک‌گذاری حساب.
 *
 * هدف: اگر ویدیویی بیرون رفت، بتوان دقیقاً گفت چه کسی، کِی، از چه IP و
 * با چه دستگاهی آن را دیده است. هیچ محتوای ویدیویی اینجا ذخیره نمی‌شود؛
 * فقط فراداده‌ی نشست.
 *
 * حریم خصوصی: IP و User-Agent فقط به‌صورت هش ذخیره می‌شوند؛ IP خام هرگز.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ثبت و گزارش نشست‌های پخش.
 */
final class Watch_Log {

	/**
	 * نسخه‌ی ساختار جدول.
	 */
	public const DB_VERSION = '1.0.0';

	/**
	 * نام آپشن نگه‌داری روزهای لاگ.
	 */
	public const RETENTION_OPTION = 'falnic_watch_log_days';

	/**
	 * هوک پاک‌سازی روزانه.
	 */
	public const PURGE_HOOK = 'falnic_watch_log_purge';

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::PURGE_HOOK, array( __CLASS__, 'purge' ) );
	}

	/**
	 * نام جدول.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'falnic_watch_log';
	}

	/**
	 * ساخت یا به‌روزرسانی جدول.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			$file = ABSPATH . 'wp-admin/includes/upgrade.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}

		$table   = self::table();
		$collate = '';

		if ( ! empty( $wpdb->charset ) ) {
			$collate = 'DEFAULT CHARACTER SET ' . $wpdb->charset;

			if ( ! empty( $wpdb->collate ) ) {
				$collate .= ' COLLATE ' . $wpdb->collate;
			}
		}

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			video varchar(100) NOT NULL DEFAULT '',
			course_id bigint(20) unsigned NOT NULL DEFAULT 0,
			session_id varchar(40) NOT NULL DEFAULT '',
			ip_hash char(32) NOT NULL DEFAULT '',
			agent varchar(40) NOT NULL DEFAULT '',
			started_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			seconds int(10) unsigned NOT NULL DEFAULT 0,
			segments int(10) unsigned NOT NULL DEFAULT 0,
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY user_video (user_id,video),
			KEY started_at (started_at),
			KEY session_id (session_id)
		) {$collate};";

		dbDelta( $sql );

		update_option( 'falnic_log_db_version', self::DB_VERSION, false );
	}

	/**
	 * آیا جدول ساخته شده است؟
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * ثبت شروع یک نشست پخش.
	 *
	 * @param array $args داده‌های نشست.
	 * @return void
	 */
	public static function record( array $args ): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$row = wp_parse_args(
			$args,
			array(
				'user_id'    => 0,
				'video'      => '',
				'course_id'  => 0,
				'session_id' => '',
			)
		);

		if ( '' === (string) $row['session_id'] || '' === (string) $row['video'] ) {
			return;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::table(),
			array(
				'user_id'    => (int) $row['user_id'],
				'video'      => substr( (string) $row['video'], 0, 100 ),
				'course_id'  => (int) $row['course_id'],
				'session_id' => substr( (string) $row['session_id'], 0, 40 ),
				'ip_hash'    => self::ip_hash(),
				'agent'      => self::agent_family(),
				'started_at' => $now,
				'last_seen'  => $now,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * به‌روزرسانی نشست با هر سگمنت.
	 *
	 * برای جلوگیری از نوشتن به ازای هر سگمنت، فراخواننده باید این متد را
	 * محدودشده صدا بزند (Stream این کار را با transient انجام می‌دهد).
	 *
	 * @param string $session_id شناسه‌ی نشست.
	 * @param int    $bytes      حجم تقریبی سگمنت.
	 * @return void
	 */
	public static function touch( string $session_id, int $bytes = 0 ): void {
		global $wpdb;

		if ( '' === $session_id || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$table = self::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table}
				 SET segments = segments + 1,
				     bytes = bytes + %d,
				     last_seen = %s,
				     seconds = GREATEST(seconds, TIMESTAMPDIFF(SECOND, started_at, %s))
				 WHERE session_id = %s",
				max( 0, $bytes ),
				$now,
				$now,
				$session_id
			)
		);
	}

	/**
	 * بستن یک نشست.
	 *
	 * @param string $session_id شناسه.
	 * @return void
	 */
	public static function end( string $session_id ): void {
		global $wpdb;

		if ( '' === $session_id || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$table = self::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET last_seen = %s, seconds = GREATEST(seconds, TIMESTAMPDIFF(SECOND, started_at, %s)) WHERE session_id = %s",
				$now,
				$now,
				$session_id
			)
		);
	}

	/**
	 * ساخت شرط‌های فیلتر (تابع خالص برای تست).
	 *
	 * @param array $args فیلترها: user_id، video، days.
	 * @return array{sql: string, params: array}
	 */
	public static function build_filters( array $args ): array {
		$where  = array( '1=1' );
		$params = array();

		$user_id = (int) ( $args['user_id'] ?? 0 );

		if ( $user_id > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}

		$video = (string) ( $args['video'] ?? '' );

		if ( '' !== $video ) {
			$where[]  = 'video = %s';
			$params[] = substr( $video, 0, 100 );
		}

		$days = (int) ( $args['days'] ?? 0 );

		if ( $days > 0 ) {
			$where[]  = 'started_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		}

		return array(
			'sql'    => implode( ' AND ', $where ),
			'params' => $params,
		);
	}

	/**
	 * فهرست نشست‌های اخیر.
	 *
	 * @param array $args فیلترها و صفحه‌بندی.
	 * @return array
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'days'     => 7,
				'per_page' => 25,
				'page'     => 1,
			)
		);

		$filter   = self::build_filters( $args );
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );
		$table    = self::table();

		$sql = "SELECT * FROM {$table} WHERE {$filter['sql']} ORDER BY id DESC LIMIT %d OFFSET %d";

		$params   = $filter['params'];
		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * شمارش کل ردیف‌ها با فیلتر.
	 *
	 * @param array $args فیلترها.
	 * @return int
	 */
	public static function count( array $args = array() ): int {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}

		$filter = self::build_filters( $args );
		$table  = self::table();
		$sql    = "SELECT COUNT(*) FROM {$table} WHERE {$filter['sql']}";

		if ( empty( $filter['params'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $filter['params'] ) );
	}

	/**
	 * آمار کلی برای کارت‌های بالای گزارش.
	 *
	 * @return array
	 */
	public static function summary(): array {
		global $wpdb;

		$empty = array(
			'today'    => 0,
			'week'     => 0,
			'users'    => 0,
			'segments' => 0,
		);

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return $empty;
		}

		$table = self::table();
		$today = gmdate( 'Y-m-d 00:00:00', time() );
		$week  = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM( started_at >= %s ) AS today,
					SUM( started_at >= %s ) AS week,
					COUNT( DISTINCT user_id ) AS users,
					SUM( segments ) AS segments
				 FROM {$table}",
				$today,
				$week
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'today'    => (int) ( $row['today'] ?? 0 ),
			'week'     => (int) ( $row['week'] ?? 0 ),
			'users'    => (int) ( $row['users'] ?? 0 ),
			'segments' => (int) ( $row['segments'] ?? 0 ),
		);
	}

	/**
	 * حساب‌هایی که در ۲۴ ساعت گذشته از IP های متعدد پخش کرده‌اند.
	 *
	 * @param int $min_ips حداقل تعداد IP.
	 * @return array
	 */
	public static function shared_accounts( int $min_ips = 3 ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COUNT( DISTINCT ip_hash ) AS ips, COUNT(*) AS sessions, MAX( started_at ) AS last_seen
				 FROM {$table}
				 WHERE started_at >= %s
				 GROUP BY user_id
				 HAVING ips >= %d
				 ORDER BY ips DESC
				 LIMIT 20",
				$since,
				max( 2, $min_ips )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * ثبت تلاش ردشده (تجمیعی، بدون نوشتن در دیتابیس).
	 *
	 * @param string $reason دلیل.
	 * @param string $video  نام ویدیو.
	 * @return void
	 */
	public static function log_denial( string $reason, string $video ): void {
		$day   = gmdate( 'Y-m-d' );
		$stats = get_option( 'falnic_deny_stats', array() );
		$stats = is_array( $stats ) ? $stats : array();

		// فقط ۱۴ روز اخیر نگه داشته می‌شود.
		foreach ( array_keys( $stats ) as $key ) {
			if ( $key < gmdate( 'Y-m-d', time() - ( 14 * DAY_IN_SECONDS ) ) ) {
				unset( $stats[ $key ] );
			}
		}

		$bucket = substr( $reason, 0, 20 ) . '|' . substr( $video, 0, 60 );

		$stats[ $day ][ $bucket ] = (int) ( $stats[ $day ][ $bucket ] ?? 0 ) + 1;

		update_option( 'falnic_deny_stats', $stats, false );
	}

	/**
	 * پرکارترین دلایل رد دسترسی امروز.
	 *
	 * @param int $limit تعداد.
	 * @return array
	 */
	public static function denials_today( int $limit = 5 ): array {
		$stats = get_option( 'falnic_deny_stats', array() );
		$today = is_array( $stats ) && isset( $stats[ gmdate( 'Y-m-d' ) ] ) ? (array) $stats[ gmdate( 'Y-m-d' ) ] : array();

		arsort( $today );

		$out = array();

		foreach ( array_slice( $today, 0, $limit, true ) as $bucket => $count ) {
			$parts = explode( '|', (string) $bucket, 2 );

			$out[] = array(
				'reason' => $parts[0] ?? '',
				'video'  => $parts[1] ?? '',
				'count'  => (int) $count,
			);
		}

		return $out;
	}

	/**
	 * آمار فشرده‌ی یک ویدیو برای نمایش در صفحه‌ی ویرایش.
	 *
	 * برای تست‌پذیری، منطق خالص در `stats_for_video_rows()` جدا شده است.
	 *
	 * @param string $name نام ویدیو.
	 * @param int    $days بازه‌ی روز.
	 * @return array{total:int,unique:int,today:int,avg_seconds:int}
	 */
	public static function stats_for_video( string $name, int $days = 30 ): array {
		if ( '' === $name || ! self::exists() ) {
			return array(
				'total'       => 0,
				'unique'      => 0,
				'today'       => 0,
				'avg_seconds' => 0,
			);
		}

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array(
				'total'       => 0,
				'unique'      => 0,
				'today'       => 0,
				'avg_seconds' => 0,
			);
		}

		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
		$today = gmdate( 'Y-m-d 00:00:00', time() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) AS total, COUNT(DISTINCT user_id) AS uniq, AVG(seconds) AS avg_sec FROM {$table} WHERE video = %s AND started_at >= %s",
				substr( $name, 0, 100 ),
				$since
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$today_count = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE video = %s AND started_at >= %s",
				substr( $name, 0, 100 ),
				$today
			)
		);

		if ( ! is_array( $row ) ) {
			$row = array();
		}

		return array(
			'total'       => (int) ( $row['total'] ?? 0 ),
			'unique'      => (int) ( $row['uniq'] ?? 0 ),
			'today'       => (int) $today_count,
			'avg_seconds' => (int) round( (float) ( $row['avg_sec'] ?? 0 ) ),
		);
	}

	/**
	 * نشست‌های اخیر یک ویدیو (برای متاباکس ویرایش).
	 *
	 * @param string $name     نام ویدیو.
	 * @param int    $days     بازه.
	 * @param int    $per_page تعداد.
	 * @return array
	 */
	public static function recent_for_video( string $name, int $days = 30, int $per_page = 15 ): array {
		if ( '' === $name ) {
			return array();
		}

		return self::query(
			array(
				'video'    => $name,
				'days'     => $days,
				'per_page' => max( 1, min( 50, $per_page ) ),
				'page'     => 1,
			)
		);
	}

	/**
	 * حذف ردیف‌های قدیمی‌تر از دوره‌ی نگه‌داری.
	 *
	 * @return void
	 */
	public static function purge(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$days = max( 7, (int) get_option( self::RETENTION_OPTION, 90 ) );
		$date = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE started_at < %s",
				$date
			)
		);
	}

	/**
	 * هش IP (بدون ذخیره‌ی مقدار خام).
	 *
	 * @return string
	 */
	public static function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( defined( 'FALNIC_TRUSTED_PROXY_HEADER' ) ) {
			$header = (string) FALNIC_TRUSTED_PROXY_HEADER;

			if ( '' !== $header && ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			}
		}

		return substr( hash_hmac( 'sha256', $ip, self::salt() ), 0, 32 );
	}

	/**
	 * خانواده‌ی دستگاه از User-Agent (فقط برچسب کوتاه).
	 *
	 * @return string
	 */
	public static function agent_family(): string {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		$families = array(
			'iPhone'  => 'iPhone',
			'iPad'    => 'iPad',
			'Android' => 'Android',
			'Windows' => 'Windows',
			'Mac OS'  => 'macOS',
			'Linux'   => 'Linux',
		);

		foreach ( $families as $needle => $label ) {
			if ( false !== stripos( $agent, $needle ) ) {
				return $label;
			}
		}

		return '' === $agent ? '' : __( 'سایر', 'falnic-secure-hls' );
	}

	/**
	 * نمک هش (ثابت برای همان سایت تا امکان تشخیص چند IP بماند).
	 *
	 * @return string
	 */
	private static function salt(): string {
		$salt = (string) get_option( 'falnic_log_salt', '' );

		if ( strlen( $salt ) < 32 ) {
			$salt = wp_generate_password( 40, false, false );
			update_option( 'falnic_log_salt', $salt, false );
		}

		return $salt;
	}
}
