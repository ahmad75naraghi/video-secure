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

		if ( ! is_user_logged_in() ) {
			wp_die(
				esc_html__( 'شما دسترسی ندارید.', 'falnic-secure-hls' ),
				esc_html__( 'عدم دسترسی', 'falnic-secure-hls' ),
				array( 'response' => 403 )
			);
		}

		$player = Player::render( $name );

		if ( '' === $player ) {
			wp_die(
				esc_html__( 'ویدیویی با این نام یافت نشد یا هنوز آماده نیست.', 'falnic-secure-hls' ),
				esc_html__( 'ویدیو یافت نشد', 'falnic-secure-hls' ),
				array( 'response' => 404 )
			);
		}

		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );

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
