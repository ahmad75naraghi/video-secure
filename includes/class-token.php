<?php
/**
 * توکن‌های امضاشده برای دسترسی به استریم.
 *
 * توکن‌ها بدون وضعیت (stateless) هستند و با HMAC-SHA256 امضا می‌شوند؛
 * بنابراین هیچ جدول یا transient اضافه‌ای برای هر سگمنت لازم نیست.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ساخت و بررسی توکن دسترسی.
 */
final class Token {

	/**
	 * نسخه‌ی قالب توکن.
	 */
	private const VERSION = 'f1';

	/**
	 * کش آدرس پایه‌ی اندپوینت (برای پرهیز از صدها فراخوانی در یک manifest).
	 *
	 * @var string|null
	 */
	private static $base_url = null;

	/**
	 * دامنه‌ها (scope) مجاز.
	 */
	public const SCOPE_SESSION   = 'session';
	public const SCOPE_MANIFEST  = 'manifest';
	public const SCOPE_SEGMENT   = 'segment';
	public const SCOPE_KEY       = 'key';
	public const SCOPE_SUBTITLE  = 'subtitle';
	public const SCOPE_HEARTBEAT = 'heartbeat';

	/**
	 * ساخت توکن.
	 *
	 * @param string $scope دامنه.
	 * @param string $name  نام ویدیو.
	 * @param array  $extra ادعاهای اضافی.
	 * @param int    $ttl   عمر به ثانیه (۰ = پیش‌فرض دامنه؛ مقدار منفی برای تست انقضا).
	 * @return string
	 */
	public static function issue( string $scope, string $name, array $extra = array(), int $ttl = 0 ): string {
		$now = time();

		$lifetime = 0 === $ttl ? self::ttl( $scope ) : $ttl;

		$claims = array_merge(
			$extra,
			array(
				'sc'  => $scope,
				'vid' => $name,
				'uid' => (int) get_current_user_id(),
				'iat' => $now,
				'exp' => $now + $lifetime,
			)
		);

		if ( 'yes' === get_option( 'falnic_hls_bind_ip', 'no' ) ) {
			$claims['ip'] = self::ip_hash();
		}

		/**
		 * فیلتر ادعاهای توکن.
		 *
		 * @param array  $claims ادعاها.
		 * @param string $scope  دامنه.
		 */
		$claims = (array) apply_filters( 'falnic_token_claims', $claims, $scope );

		$json = (string) wp_json_encode( $claims );
		$body = self::VERSION . '.' . self::b64url( $json );

		return $body . '.' . self::b64url( hash_hmac( 'sha256', $body, self::secret(), true ) );
	}

	/**
	 * بررسی توکن.
	 *
	 * @param string $token توکن.
	 * @param string $scope دامنه‌ی مورد انتظار.
	 * @param string $name  نام ویدیوی مورد انتظار.
	 * @return array|\WP_Error
	 */
	public static function verify( string $token, string $scope, string $name ) {
		$token = trim( $token );

		if ( '' === $token || strlen( $token ) > 2048 ) {
			return self::error( 'falnic_token_missing', __( 'توکن دسترسی ارسال نشده است.', 'falnic-secure-hls' ) );
		}

		$parts = explode( '.', $token );

		if ( 3 !== count( $parts ) || self::VERSION !== $parts[0] ) {
			return self::error( 'falnic_token_format', __( 'توکن دسترسی معتبر نیست.', 'falnic-secure-hls' ) );
		}

		$body     = $parts[0] . '.' . $parts[1];
		$expected = hash_hmac( 'sha256', $body, self::secret(), true );

		if ( ! hash_equals( $expected, self::b64url_decode( $parts[2] ) ) ) {
			return self::error( 'falnic_token_signature', __( 'امضای توکن دسترسی معتبر نیست.', 'falnic-secure-hls' ) );
		}

		$claims = json_decode( self::b64url_decode( $parts[1] ), true );

		if ( ! is_array( $claims ) ) {
			return self::error( 'falnic_token_payload', __( 'محتوای توکن دسترسی خوانده نشد.', 'falnic-secure-hls' ) );
		}

		if ( empty( $claims['exp'] ) || (int) $claims['exp'] < time() ) {
			return self::error( 'falnic_token_expired', __( 'زمان توکن دسترسی تمام شده است؛ صفحه را دوباره بارگذاری کنید.', 'falnic-secure-hls' ) );
		}

		if ( ! isset( $claims['sc'] ) || $scope !== $claims['sc'] ) {
			return self::error( 'falnic_token_scope', __( 'این توکن برای این درخواست صادر نشده است.', 'falnic-secure-hls' ) );
		}

		if ( ! isset( $claims['vid'] ) || $name !== (string) $claims['vid'] ) {
			return self::error( 'falnic_token_video', __( 'توکن دسترسی با این ویدیو هم‌خوان نیست.', 'falnic-secure-hls' ) );
		}

		if ( ! isset( $claims['uid'] ) || (int) $claims['uid'] !== (int) get_current_user_id() ) {
			return self::error( 'falnic_token_user', __( 'این توکن به کاربر دیگری تعلق دارد.', 'falnic-secure-hls' ) );
		}

		if ( isset( $claims['ip'] ) && ! hash_equals( (string) $claims['ip'], self::ip_hash() ) ) {
			return self::error( 'falnic_token_ip', __( 'توکن دسترسی با این اتصال هم‌خوان نیست.', 'falnic-secure-hls' ) );
		}

		return $claims;
	}

	/**
	 * ساخت نشانی امضاشده برای اندپوینت استریم.
	 *
	 * @param string $action اکشن admin-ajax.
	 * @param string $name   نام ویدیو.
	 * @param string $scope  دامنه.
	 * @param array  $extra  ادعاهای اضافی.
	 * @param int    $ttl    عمر توکن.
	 * @return string
	 */
	public static function url( string $action, string $name, string $scope, array $extra = array(), int $ttl = 0 ): string {
		// add_query_arg خودش مقادیر را کدگذاری می‌کند؛ کدگذاری دوباره باعث خرابی توکن/نام می‌شود.
		return add_query_arg(
			array(
				'action' => $action,
				'video'  => $name,
				't'      => self::issue( $scope, $name, $extra, $ttl ),
			),
			self::base_url()
		);
	}

	/**
	 * آدرس پایه‌ی admin-ajax (یک‌بار در هر درخواست محاسبه می‌شود).
	 *
	 * @return string
	 */
	public static function base_url(): string {
		if ( null === self::$base_url ) {
			self::$base_url = admin_url( 'admin-ajax.php' );
		}

		return (string) self::$base_url;
	}

	/**
	 * عمر پیش‌فرض هر دامنه.
	 *
	 * @param string $scope دامنه.
	 * @return int
	 */
	private static function ttl( string $scope ): int {
		if ( self::SCOPE_MANIFEST === $scope ) {
			return max( 60, (int) get_option( 'falnic_token_ttl', 600 ) );
		}

		if ( self::SCOPE_SEGMENT === $scope ) {
			return max( 300, (int) get_option( 'falnic_segment_token_ttl', 7200 ) );
		}

		if ( self::SCOPE_KEY === $scope ) {
			return 300;
		}

		if ( self::SCOPE_SUBTITLE === $scope ) {
			return max( 60, (int) get_option( 'falnic_token_ttl', 600 ) );
		}

		if ( self::SCOPE_HEARTBEAT === $scope ) {
			return 900;
		}

		return 600;
	}

	/**
	 * کلید امضا.
	 *
	 * @return string
	 */
	private static function secret(): string {
		if ( defined( 'FALNIC_TOKEN_SECRET' ) && strlen( (string) FALNIC_TOKEN_SECRET ) >= 32 ) {
			return (string) FALNIC_TOKEN_SECRET;
		}

		return (string) wp_salt( 'falnic_secure_hls' );
	}

	/**
	 * هش IP برای گره‌زدن اختیاری توکن.
	 *
	 * @return string
	 */
	private static function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( defined( 'FALNIC_TRUSTED_PROXY_HEADER' ) ) {
			$header = (string) FALNIC_TRUSTED_PROXY_HEADER;

			if ( '' !== $header && ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			}
		}

		return substr( hash_hmac( 'sha256', $ip, self::secret() ), 0, 16 );
	}

	/**
	 * base64url بدون padding.
	 *
	 * @param string $bin داده‌ی خام.
	 * @return string
	 */
	private static function b64url( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	/**
	 * بازگشایی base64url.
	 *
	 * @param string $value مقدار.
	 * @return string
	 */
	private static function b64url_decode( string $value ): string {
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );

		return false === $decoded ? '' : $decoded;
	}

	/**
	 * ساخت خطا.
	 *
	 * @param string $code    کد.
	 * @param string $message پیام.
	 * @return \WP_Error
	 */
	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 403 ) );
	}
}
