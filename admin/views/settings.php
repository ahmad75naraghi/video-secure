<?php
/**
 * نمای صفحه‌ی تنظیمات.
 *
 * @package Falnic\SecureHLS
 *
 * @var int    $max_sessions
 * @var string $bind_ip
 * @var array  $status
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap" style="font-family: Tahoma, sans-serif;">
	<h1>⚙️ <?php esc_html_e( 'تنظیمات امنیتی پلیر', 'falnic-secure-hls' ); ?></h1>
	<hr>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'تنظیمات ذخیره شد.', 'falnic-secure-hls' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="falnic_save_settings">
		<?php wp_nonce_field( 'falnic_settings' ); ?>

		<table class="form-table">
			<tr>
				<th scope="row"><label for="max_sessions"><?php esc_html_e( 'حداکثر لاگین هم‌زمان (برای کاربران)', 'falnic-secure-hls' ); ?></label></th>
				<td>
					<input name="max_sessions" type="number" step="1" min="1" id="max_sessions" value="<?php echo esc_attr( (string) $max_sessions ); ?>" class="regular-text">
					<p class="description"><?php esc_html_e( 'مقدار ۱ یعنی نشست‌های قبلی هنگام ورود جدید بسته می‌شوند.', 'falnic-secure-hls' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bind_ip"><?php esc_html_e( 'قفل کردن استریم روی IP کاربر', 'falnic-secure-hls' ); ?></label></th>
				<td>
					<input name="bind_ip" type="checkbox" id="bind_ip" value="yes" <?php checked( $bind_ip, 'yes' ); ?>>
					<p class="description"><?php esc_html_e( 'برای کاربران موبایل توصیه نمی‌شود؛ ممکن است پخش قطع شود.', 'falnic-secure-hls' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'ذخیره تغییرات', 'falnic-secure-hls' ); ?>">
		</p>
	</form>

	<hr>

	<h2><?php esc_html_e( 'وضعیت سیستم', 'falnic-secure-hls' ); ?></h2>
	<table class="widefat striped" style="max-width:720px;">
		<tbody>
		<?php foreach ( $status as $row ) : ?>
			<tr>
				<td><?php echo esc_html( $row['label'] ); ?></td>
				<td style="width:90px;text-align:center;">
					<?php if ( $row['ok'] ) : ?>
						<span style="color:#46b450;font-weight:bold;">✔</span>
					<?php else : ?>
						<span style="color:#d63638;font-weight:bold;">✘</span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<p class="description" style="margin-top:12px;">
		<?php esc_html_e( 'شورت‌کد ویدیو:', 'falnic-secure-hls' ); ?>
		<code>[secure_video name="lesson-1"]</code>
	</p>
</div>
