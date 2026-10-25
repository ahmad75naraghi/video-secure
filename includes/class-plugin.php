<?php
/**
 * راه‌انداز اصلی و ثبت سرویس‌ها.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس اصلی پلاگین (Singleton).
 */
final class Plugin {

	/**
	 * نمونه‌ی یکتا.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * آیا سرویس‌ها راه‌اندازی شده‌اند.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * دریافت نمونه.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * سازنده‌ی خصوصی.
	 */
	private function __construct() {}

	/**
	 * ثبت هوک‌های همه‌ی سرویس‌ها.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		Installer::maybe_upgrade();

		Assets::init();
		Post_Type::init();
		Meta_Box::init();
		Player::init();
		Embed::init();
		Learn_Dash::init();
		Stream::init();
		Key_Server::init();
		Session::init();
		Encode_Queue::init();
		Watch_Log::init();

		if ( is_admin() ) {
			Admin_Menu::init();
			Admin_Notices::init();
			Git_Update_Page::init();
		}
	}

	/**
	 * بارگذاری فایل ترجمه.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'falnic-secure-hls', false, dirname( FALNIC_BASENAME ) . '/languages' );
	}
}
