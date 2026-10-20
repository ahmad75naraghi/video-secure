<?php
/**
 * ثبت و بارگذاری دارایی‌های سمت مرورگر.
 *
 * همه‌ی فایل‌ها محلی هستند؛ هیچ درخواستی به CDN یا دامنه‌ی بیرونی زده نمی‌شود.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * مدیریت CSS و JS پلیر.
 */
final class Assets {

	/**
	 * نسخه‌ی hls.js باندل‌شده.
	 */
	public const HLS_VERSION = '1.5.17';

	/**
	 * نسخه‌ی Plyr باندل‌شده.
	 */
	public const PLYR_VERSION = '3.7.8';

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ) );
	}

	/**
	 * ثبت دارایی‌ها (بدون بارگذاری).
	 *
	 * @return void
	 */
	public static function register(): void {
		wp_register_style(
			'falnic-plyr',
			FALNIC_URL . 'assets/vendor/plyr/plyr.css',
			array(),
			self::PLYR_VERSION
		);

		wp_register_script(
			'falnic-plyr',
			FALNIC_URL . 'assets/vendor/plyr/plyr.min.js',
			array(),
			self::PLYR_VERSION,
			true
		);

		wp_register_script(
			'falnic-hls',
			FALNIC_URL . 'assets/vendor/hls/hls.min.js',
			array(),
			self::HLS_VERSION,
			true
		);

		wp_register_style(
			'falnic-player',
			FALNIC_URL . 'assets/css/player.css',
			array( 'falnic-plyr' ),
			FALNIC_VERSION
		);

		wp_register_script(
			'falnic-player',
			FALNIC_URL . 'assets/js/player.js',
			array( 'falnic-plyr', 'falnic-hls' ),
			FALNIC_VERSION,
			true
		);
	}

	/**
	 * بارگذاری دارایی‌ها در صفحه.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! wp_style_is( 'falnic-player', 'registered' ) ) {
			self::register();
		}

		wp_enqueue_style( 'falnic-player' );
		wp_enqueue_script( 'falnic-player' );

		$css = Template_Settings::css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'falnic-player', $css );
		}
	}

	/**
	 * چاپ مستقیم تگ‌ها؛ برای صفحه‌ی embed که wp_head اجرا نمی‌شود.
	 *
	 * @return void
	 */
	public static function print_tags(): void {
		$styles  = array(
			'falnic-plyr'   => array( 'assets/vendor/plyr/plyr.css', self::PLYR_VERSION ),
			'falnic-player' => array( 'assets/css/player.css', FALNIC_VERSION ),
		);
		$scripts = array(
			'falnic-plyr'   => array( 'assets/vendor/plyr/plyr.min.js', self::PLYR_VERSION ),
			'falnic-hls'    => array( 'assets/vendor/hls/hls.min.js', self::HLS_VERSION ),
			'falnic-player' => array( 'assets/js/player.js', FALNIC_VERSION ),
		);

		foreach ( $styles as $handle => $asset ) {
			printf(
				'<link rel="stylesheet" id="%1$s-css" href="%2$s" media="all" />' . "\n",
				esc_attr( $handle ),
				esc_url( FALNIC_URL . $asset[0] . '?ver=' . $asset[1] )
			);
		}

		foreach ( $scripts as $handle => $asset ) {
			printf(
				'<script id="%1$s-js" src="%2$s"></script>' . "\n",
				esc_attr( $handle ),
				esc_url( FALNIC_URL . $asset[0] . '?ver=' . $asset[1] )
			);
		}
	}
}
