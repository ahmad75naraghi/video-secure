<?php
/**
 * رندر پلیر و شورت‌کد.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ساخت مارک‌آپ پلیر بر اساس قالب.
 */
final class Player {

	/**
	 * ثبت شورت‌کد.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( 'secure_video', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * پردازش شورت‌کد.
	 *
	 * @param array $atts پارامترها.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array( 'name' => '' ),
			$atts,
			'secure_video'
		);

		return self::render( (string) $atts['name'] );
	}

	/**
	 * رندر پلیر برای یک ویدیو.
	 *
	 * @param string $name نام ویدیو.
	 * @return string
	 */
	public static function render( string $name ): string {
		$name = sanitize_text_field( $name );

		if ( '' === $name || ! Storage::is_valid_name( $name ) ) {
			return '';
		}

		if ( ! Storage::is_encoded( $name ) ) {
			return '';
		}

		Assets::enqueue();

		$html_id = wp_unique_id( 'secure-vid-' );

		$config = array(
			'id'        => $html_id,
			'src'       => Storage::playlist_url( $name ),
			'watermark' => self::watermark_lines(),
			'i18n'      => array(
				'resume'  => __( 'برای ادامه پخش روی صفحه کلیک کنید', 'falnic-secure-hls' ),
				'error'   => __( 'خطا در پخش ویدیو. لطفاً صفحه را دوباره بارگذاری کنید.', 'falnic-secure-hls' ),
				'unsupported' => __( 'مرورگر شما از پخش این ویدیو پشتیبانی نمی‌کند.', 'falnic-secure-hls' ),
			),
		);

		/**
		 * فیلتر پیکربندی پلیر.
		 *
		 * @param array  $config پیکربندی.
		 * @param string $name   نام ویدیو.
		 */
		$config = apply_filters( 'falnic_player_config', $config, $name );

		$args = array(
			'html_id'     => $html_id,
			'name'        => $name,
			'config_json' => (string) wp_json_encode(
				$config,
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			),
		);

		ob_start();
		$template = FALNIC_DIR . 'templates/player.php';

		if ( is_readable( $template ) ) {
			/**
			 * متغیرهای موجود در قالب: $args
			 *
			 * @var array $args
			 */
			include $template;
		}

		return (string) ob_get_clean();
	}

	/**
	 * خطوط واترمارک برای کاربر جاری.
	 *
	 * @return array
	 */
	private static function watermark_lines(): array {
		$lines = array(
			'line1' => '',
			'line2' => '',
		);

		if ( ! is_user_logged_in() ) {
			return $lines;
		}

		$user = wp_get_current_user();
		$full = trim( $user->first_name . ' ' . $user->last_name );

		$lines['line1'] = '' !== $full ? sprintf( '%s (%s)', $full, $user->user_login ) : $user->user_login;
		$lines['line2'] = (string) $user->user_email;

		/**
		 * فیلتر خطوط واترمارک.
		 *
		 * @param array $lines خطوط.
		 * @param int   $user_id شناسه‌ی کاربر.
		 */
		return (array) apply_filters( 'falnic_watermark_lines', $lines, $user->ID );
	}
}
