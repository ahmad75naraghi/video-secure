<?php
/**
 * تنظیمات ظاهری قالب پلیر.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ذخیره و خواندن تنظیمات نمایشی پلیر.
 */
final class Template_Settings {

	/**
	 * نام آپشن.
	 */
	public const OPTION = 'falnic_template_settings';

	/**
	 * مقادیر پیش‌فرض.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'radius'        => 8,
			'accent'        => '#2271b1',
			'ratio'         => '16:9',
			'wm_enabled'    => 'no',
			'wm_opacity'    => 0.45,
			'wm_interval'   => 30,
			'wm_show_email' => 'yes',
			'wm_font_scale' => 1.0,
		);
	}

	/**
	 * خواندن تنظیمات با مقادیر پیش‌فرض.
	 *
	 * @return array
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return self::sanitize( array_merge( self::defaults(), $saved ) );
	}

	/**
	 * پاک‌سازی و محدودسازی مقادیر.
	 *
	 * @param array $input ورودی.
	 * @return array
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();

		$radius = isset( $input['radius'] ) ? (int) $input['radius'] : $defaults['radius'];
		$radius = max( 0, min( 40, $radius ) );

		$accent = isset( $input['accent'] ) ? sanitize_hex_color( (string) $input['accent'] ) : '';
		if ( ! $accent ) {
			$accent = $defaults['accent'];
		}

		$ratio = isset( $input['ratio'] ) ? (string) $input['ratio'] : $defaults['ratio'];
		if ( ! in_array( $ratio, array( '16:9', '4:3', '21:9', '1:1', 'auto' ), true ) ) {
			$ratio = $defaults['ratio'];
		}

		$wm_enabled = isset( $input['wm_enabled'] ) && 'yes' === $input['wm_enabled'] ? 'yes' : 'no';

		$opacity = isset( $input['wm_opacity'] ) ? (float) $input['wm_opacity'] : $defaults['wm_opacity'];
		$opacity = max( 0.1, min( 0.9, $opacity ) );

		$interval = isset( $input['wm_interval'] ) ? (int) $input['wm_interval'] : $defaults['wm_interval'];
		$interval = max( 5, min( 300, $interval ) );

		$show_email = isset( $input['wm_show_email'] ) && 'yes' === $input['wm_show_email'] ? 'yes' : 'no';

		$font_scale = isset( $input['wm_font_scale'] ) ? (float) $input['wm_font_scale'] : $defaults['wm_font_scale'];
		$font_scale = max( 0.6, min( 2.0, $font_scale ) );

		return array(
			'radius'        => $radius,
			'accent'        => $accent,
			'ratio'         => $ratio,
			'wm_enabled'    => $wm_enabled,
			'wm_opacity'    => round( $opacity, 2 ),
			'wm_interval'   => $interval,
			'wm_show_email' => $show_email,
			'wm_font_scale' => round( $font_scale, 2 ),
		);
	}

	/**
	 * ذخیره‌ی تنظیمات.
	 *
	 * @param array $input ورودی خام.
	 * @return array مقدار ذخیره‌شده.
	 */
	public static function save( array $input ): array {
		$clean = self::sanitize( array_merge( self::defaults(), $input ) );
		update_option( self::OPTION, $clean, false );

		return $clean;
	}

	/**
	 * CSS متغیرهای قالب (scoped).
	 *
	 * @return string
	 */
	public static function css(): string {
		$settings = self::get();

		$ratio = 'auto' === $settings['ratio'] ? 'auto' : str_replace( ':', ' / ', $settings['ratio'] );

		return sprintf(
			'.falnic-secure-container{--falnic-radius:%1$dpx;--falnic-accent:%2$s;--falnic-ratio:%3$s;--falnic-wm-font:%4$s;}',
			(int) $settings['radius'],
			$settings['accent'],
			$ratio,
			(string) $settings['wm_font_scale']
		);
	}
}
