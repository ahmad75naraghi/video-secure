<?php
/**
 * کنترل نشست کاربران.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * محدودسازی نشست‌های هم‌زمان.
 */
final class Session {

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
	}

	/**
	 * ثبت IP و بستن نشست‌های قبلی در صورت تنظیم.
	 *
	 * @param string   $user_login نام کاربری.
	 * @param \WP_User $user       شیء کاربر.
	 * @return void
	 */
	public static function on_login( $user_login, $user ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' !== $ip ) {
			update_user_meta( $user->ID, 'falnic_last_login_ip', $ip );
		}

		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return;
		}

		$max_sessions = (int) get_option( 'falnic_hls_max_sessions', 1 );

		if ( 1 === $max_sessions && function_exists( 'wp_destroy_other_sessions' ) ) {
			wp_destroy_other_sessions();
		}
	}
}
