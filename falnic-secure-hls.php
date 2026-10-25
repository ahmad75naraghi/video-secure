<?php
/**
 * Plugin Name:       Falnic Secure HLS Player
 * Plugin URI:        https://github.com/ahmad75naraghi/video-secure
 * Description:       پخش امن ویدیو با رمزنگاری HLS AES-128، واترمارک کاربر و پشتیبانی از LearnDash. تمام دارایی‌ها به‌صورت محلی باندل شده‌اند.
 * Version:           3.5.18
 * Author:            Ahmad Naraghi
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Text Domain:       falnic-secure-hls
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

// دسترسی مستقیم ممنوع.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FALNIC_VERSION', '3.5.18' );
define( 'FALNIC_FILE', __FILE__ );
define( 'FALNIC_DIR', plugin_dir_path( __FILE__ ) );
define( 'FALNIC_URL', plugin_dir_url( __FILE__ ) );
define( 'FALNIC_BASENAME', plugin_basename( __FILE__ ) );

require_once FALNIC_DIR . 'includes/class-autoloader.php';

Autoloader::register();

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );

/**
 * راه‌اندازی پلاگین پس از بارگذاری همه‌ی افزونه‌ها.
 *
 * @return void
 */
function bootstrap() {
	Plugin::instance()->boot();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );
