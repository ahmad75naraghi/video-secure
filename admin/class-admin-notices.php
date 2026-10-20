<?php
/**
 * نمایش نوتیس‌های موفقیت و خطای انکود.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * نوتیس‌های مدیریت.
 */
final class Admin_Notices {

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
	}

	/**
	 * چاپ نوتیس‌ها.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id = get_current_user_id();

		$error = get_transient( 'falnic_encode_error_' . $user_id );
		if ( $error ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s<br>%s</p></div>',
				esc_html__( 'خطا در پردازش ویدیو:', 'falnic-secure-hls' ),
				nl2br( esc_html( $error ) )
			);
			delete_transient( 'falnic_encode_error_' . $user_id );
		}

		$success = get_transient( 'falnic_encode_success_' . $user_id );
		if ( $success ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s <strong>%s</strong> %s</p></div>',
				esc_html__( '✅ ویدیوی', 'falnic-secure-hls' ),
				esc_html( $success ),
				esc_html__( 'آماده پخش است.', 'falnic-secure-hls' )
			);
			delete_transient( 'falnic_encode_success_' . $user_id );
		}
	}
}
