<?php
/**
 * نمای انکودر دستی.
 *
 * @package Falnic\SecureHLS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- فقط نمایش پیام.
$encoded      = isset( $_GET['encoded'] ) ? sanitize_text_field( wp_unslash( $_GET['encoded'] ) ) : '';
$queued       = isset( $_GET['queued'] ) ? sanitize_text_field( wp_unslash( $_GET['queued'] ) ) : '';
$error        = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
// phpcs:enable
?>
<div class="wrap falnic-admin" dir="rtl">
	<h1>🎬 <?php esc_html_e( 'انکودر دستی سرور', 'falnic-secure-hls' ); ?></h1>

	<?php Admin_Tabs::render( 'falnic_hls_encoder' ); ?>

	<?php if ( 'file_not_found' === $error ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'خطا: فایل در مسیر داده‌شده یافت نشد.', 'falnic-secure-hls' ); ?></p></div>
	<?php endif; ?>

	<?php if ( 'queue_failed' === $error ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'خطا: افزودن به صف انکود ناموفق بود. مجوز پوشه‌ی موقت و مسیر فایل را بررسی کنید.', 'falnic-secure-hls' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $queued ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php esc_html_e( '✅ در صف انکود قرار گرفت:', 'falnic-secure-hls' ); ?>
				<strong><?php echo esc_html( $queued ); ?></strong>
			</p>
			<p><?php esc_html_e( 'پردازش در پس‌زمینه انجام می‌شود؛ وضعیت را در صفحه‌ی ویدیوهای امن ببینید.', 'falnic-secure-hls' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $encoded ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php esc_html_e( '✅ ویدیو رمزنگاری شد:', 'falnic-secure-hls' ); ?>
				<strong><?php echo esc_html( $encoded ); ?></strong>
			</p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="falnic_run_encoder">
		<?php wp_nonce_field( 'falnic_encoder' ); ?>

		<table class="form-table">
			<tr>
				<th scope="row"><label for="input_file"><?php esc_html_e( 'مسیر کامل فایل MP4 در سرور', 'falnic-secure-hls' ); ?></label></th>
				<td><input name="input_file" id="input_file" type="text" class="regular-text" required></td>
			</tr>
			<tr>
				<th scope="row"><label for="video_name"><?php esc_html_e( 'نام اختصاصی (فقط حروف انگلیسی، رقم و خط تیره)', 'falnic-secure-hls' ); ?></label></th>
				<td><input name="video_name" id="video_name" type="text" class="regular-text" required></td>
			</tr>
		</table>

		<p class="submit">
			<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'شروع انکودینگ', 'falnic-secure-hls' ); ?>">
		</p>
	</form>

	<p class="description">
		<?php esc_html_e( 'فایل روی سرور کپی می‌شود و انکود در صف پس‌زمینه (WP-Cron) انجام می‌گیرد؛ صفحه بلافاصله برمی‌گردد. برای صف مطمئن، WP-Cron را با یک cron سیستمی هر دقیقه اجرا کنید.', 'falnic-secure-hls' ); ?>
	</p>
</div>
