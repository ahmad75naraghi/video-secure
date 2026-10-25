<?php
/**
 * نمای «تنظیمات قالب» پلیر.
 *
 * @package Falnic\SecureHLS
 *
 * @var array $settings
 */

use Falnic\SecureHLS\Admin_Tabs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap falnic-admin" dir="rtl">
	<h1><?php esc_html_e( 'تنظیمات قالب', 'falnic-secure-hls' ); ?></h1>

	<?php Admin_Tabs::render( 'falnic_template_settings' ); ?>

	<?php if ( isset( $_GET['falnic'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['falnic'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'تنظیمات قالب ذخیره شد.', 'falnic-secure-hls' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="falnic_save_template">
		<?php wp_nonce_field( 'falnic_template_settings' ); ?>

		<div class="falnic-card">
			<h2><?php esc_html_e( 'ظاهر پلیر', 'falnic-secure-hls' ); ?></h2>

			<div class="falnic-field">
				<label for="falnic-radius"><?php esc_html_e( 'گردی گوشه‌ها (پیکسل)', 'falnic-secure-hls' ); ?></label>
				<input type="number" id="falnic-radius" name="radius" min="0" max="40" step="1" value="<?php echo esc_attr( (string) $settings['radius'] ); ?>" class="small-text">
			</div>

			<div class="falnic-field">
				<label for="falnic-ratio"><?php esc_html_e( 'نسبت تصویر کانتینر', 'falnic-secure-hls' ); ?></label>
				<select id="falnic-ratio" name="ratio">
					<?php foreach ( array( '16:9', '4:3', '21:9', '1:1', 'auto' ) as $ratio ) : ?>
						<option value="<?php echo esc_attr( $ratio ); ?>" <?php selected( $settings['ratio'], $ratio ); ?>>
							<?php echo esc_html( 'auto' === $ratio ? __( 'خودکار (بدون محدودیت)', 'falnic-secure-hls' ) : $ratio ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="falnic-field">
				<label for="falnic-accent"><?php esc_html_e( 'رنگ تأکیدی', 'falnic-secure-hls' ); ?></label>
				<input type="color" id="falnic-accent" name="accent" value="<?php echo esc_attr( $settings['accent'] ); ?>">
			</div>
		</div>

		<div class="falnic-card">
			<h2><?php esc_html_e( 'واترمارک', 'falnic-secure-hls' ); ?></h2>

			<div class="falnic-field">
				<label for="falnic-wm-opacity"><?php esc_html_e( 'شفافیت واترمارک (۰.۱ تا ۰.۹)', 'falnic-secure-hls' ); ?></label>
				<input type="number" id="falnic-wm-opacity" name="wm_opacity" min="0.1" max="0.9" step="0.05" value="<?php echo esc_attr( (string) $settings['wm_opacity'] ); ?>" class="small-text">
				<p class="description"><?php esc_html_e( 'مقدار بالاتر = دیده‌شدن بیشتر و بازدارندگی بیشتر.', 'falnic-secure-hls' ); ?></p>
			</div>

			<div class="falnic-field">
				<label for="falnic-wm-interval"><?php esc_html_e( 'فاصلهٔ جابه‌جایی واترمارک (ثانیه)', 'falnic-secure-hls' ); ?></label>
				<input type="number" id="falnic-wm-interval" name="wm_interval" min="5" max="300" step="5" value="<?php echo esc_attr( (string) $settings['wm_interval'] ); ?>" class="small-text">
			</div>

			<div class="falnic-field">
				<label for="falnic-wm-font"><?php esc_html_e( 'مقیاس اندازهٔ فونت واترمارک', 'falnic-secure-hls' ); ?></label>
				<input type="number" id="falnic-wm-font" name="wm_font_scale" min="0.6" max="2" step="0.1" value="<?php echo esc_attr( (string) $settings['wm_font_scale'] ); ?>" class="small-text">
			</div>

			<div class="falnic-field">
				<label class="falnic-check">
					<input type="checkbox" name="wm_show_email" value="yes" <?php checked( $settings['wm_show_email'], 'yes' ); ?>>
					<?php esc_html_e( 'نمایش ایمیل کاربر در واترمارک', 'falnic-secure-hls' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'اگر خاموش باشد، فقط نام کاربری و نام نمایشی نشان داده می‌شود.', 'falnic-secure-hls' ); ?></p>
			</div>
		</div>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'ذخیره تنظیمات قالب', 'falnic-secure-hls' ); ?></button>
		</p>
	</form>
</div>
