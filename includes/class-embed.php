<?php
/**
 * صفحه‌ی مستقل پخش برای استفاده در iframe / LearnDash.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * اندپوینت `/?falnic_embed=name`.
 */
final class Embed {

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	/**
	 * رندر صفحه‌ی embed در صورت درخواست.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		if ( ! isset( $_GET['falnic_embed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$name = sanitize_text_field( wp_unslash( $_GET['falnic_embed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$reason = Access::reason( $name );
		$player = Player::render( $name );

		if ( '' === $player ) {
			wp_die(
				esc_html__( 'ویدیویی با این نام یافت نشد.', 'falnic-secure-hls' ),
				esc_html__( 'ویدیو یافت نشد', 'falnic-secure-hls' ),
				array( 'response' => 404 )
			);
		}

		if ( 'ok' !== $reason ) {
			// پیام عدم دسترسی با همان قالب و استایل پلیر نمایش داده می‌شود.
			status_header( 'guest' === $reason ? 401 : 403 );
		}

		/**
		 * اجازه‌ی embed از دامنه‌های دیگر.
		 *
		 * @param string $ancestors مقدار دایرکتیو frame-ancestors.
		 */
		$ancestors = (string) apply_filters( 'falnic_embed_frame_ancestors', "'self'" );

		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );

		if ( '' !== $ancestors ) {
			header( "Content-Security-Policy: frame-ancestors {$ancestors}" );
		}

		ob_start();
		$template = FALNIC_DIR . 'templates/embed.php';

		if ( is_readable( $template ) ) {
			/**
			 * متغیرهای موجود در قالب: $player, $name
			 *
			 * @var string $player
			 * @var string $name
			 */
			include $template;
		}

		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- قالب داخلی و امن.
		exit;
	}
}
