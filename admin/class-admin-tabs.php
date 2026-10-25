<?php
/**
 * نوار تب مشترک صفحه‌های مدیریت افزونه.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ساخت تب‌های ناوبری بین زیرمنوها.
 */
final class Admin_Tabs {

	/**
	 * فهرست تب‌ها.
	 *
	 * @return array
	 */
	public static function items(): array {
		return array(
			'falnic_hls_settings'      => array(
				'label' => __( 'تنظیمات امنیتی', 'falnic-secure-hls' ),
				'cap'   => 'manage_options',
			),
			'falnic_template_settings' => array(
				'label' => __( 'تنظیمات قالب', 'falnic-secure-hls' ),
				'cap'   => 'manage_options',
			),
			'falnic_hls_encoder'       => array(
				'label' => __( 'انکودر دستی', 'falnic-secure-hls' ),
				'cap'   => 'manage_options',
			),
			'falnic_watch_log'         => array(
				'label' => __( 'گزارش تماشا', 'falnic-secure-hls' ),
				'cap'   => 'manage_options',
			),
			'falnic-git-update'        => array(
				'label' => __( 'آپدیت از گیت', 'falnic-secure-hls' ),
				'cap'   => 'update_plugins',
			),
		);
	}

	/**
	 * چاپ نوار تب.
	 *
	 * @param string $current اسلاگ صفحه‌ی جاری.
	 * @return void
	 */
	public static function render( string $current ): void {
		$items = array();

		foreach ( self::items() as $slug => $item ) {
			if ( ! current_user_can( $item['cap'] ) ) {
				continue;
			}

			$items[ $slug ] = $item;
		}

		if ( empty( $items ) ) {
			return;
		}

		echo '<nav class="nav-tab-wrapper falnic-tabs" style="margin-bottom:18px;">';

		foreach ( $items as $slug => $item ) {
			$url = add_query_arg(
				array(
					'post_type' => Post_Type::SLUG,
					'page'      => $slug,
				),
				admin_url( 'edit.php' )
			);

			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( $url ),
				$slug === $current ? ' nav-tab-active' : '',
				esc_html( $item['label'] )
			);
		}

		echo '</nav>';
	}
}
