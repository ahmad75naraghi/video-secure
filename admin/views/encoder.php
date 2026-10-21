<?php
/**
 * نمای انکودر دستی.
 *
 * @package Falnic\SecureHLS
 */

use Falnic\SecureHLS\Admin_Tabs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- فقط نمایش پیام.
$encoded = isset( $_GET['encoded'] ) ? sanitize_text_field( wp_unslash( $_GET['encoded'] ) ) : '';
$queued  = isset( $_GET['queued'] ) ? sanitize_text_field( wp_unslash( $_GET['queued'] ) ) : '';
$error   = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
$ran       = isset( $_GET['ran'] ) ? absint( wp_unslash( $_GET['ran'] ) ) : -1;
$rechecked = isset( $_GET['rechecked'] ) ? absint( wp_unslash( $_GET['rechecked'] ) ) : 0;
// phpcs:enable

$falnic_states = array(
	'queued'   => __( 'در صف', 'falnic-secure-hls' ),
	'running'  => __( 'در حال انکود', 'falnic-secure-hls' ),
	'done'     => __( 'تمام‌شده', 'falnic-secure-hls' ),
	'failed'   => __( 'ناموفق', 'falnic-secure-hls' ),
	'canceled' => __( 'لغوشده', 'falnic-secure-hls' ),
	'idle'     => __( 'بدون کار', 'falnic-secure-hls' ),
);
?>
<div class="wrap falnic-admin" dir="rtl">
	<h1>🎬 <?php esc_html_e( 'انکودر دستی سرور', 'falnic-secure-hls' ); ?></h1>

	<?php Admin_Tabs::render( 'falnic_hls_encoder' ); ?>

	<?php if ( 'file_not_found' === $error ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'خطا: فایل در مسیر داده‌شده یافت نشد.', 'falnic-secure-hls' ); ?></p></div>
	<?php endif; ?>

	<?php if ( 'file_type' === $error ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'خطا: فقط فایل‌های MP4/M4V/MOV به صف اضافه می‌شوند.', 'falnic-secure-hls' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $rechecked ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '✅ کش تشخیص ffmpeg/ffprobe پاک شد و بررسی دوباره انجام شد.', 'falnic-secure-hls' ); ?></p></div>
	<?php endif; ?>

	<?php if ( 'queue_failed' === $error ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'خطا: افزودن به صف ناموفق بود. مجوز پوشه‌ی موقت و مسیر فایل را بررسی کنید.', 'falnic-secure-hls' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $queued ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php esc_html_e( '✅ در صف انکود قرار گرفت:', 'falnic-secure-hls' ); ?>
				<strong><?php echo esc_html( $queued ); ?></strong>
			</p>
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

	<?php if ( $ran >= 0 ) : ?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %d: تعداد کار اجراشده */
					esc_html__( 'اجرای فوری صف انجام شد؛ %d کار پردازش شد. وضعیت هر کار در جدول پایین است.', 'falnic-secure-hls' ),
					(int) $ran
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="falnic-card">
		<h2><?php esc_html_e( 'وضعیت پیش‌نیازها', 'falnic-secure-hls' ); ?></h2>
		<table class="widefat striped falnic-table">
			<tbody>
				<?php foreach ( $encoder_checks as $falnic_check ) : ?>
					<tr>
						<td style="width:40px;"><?php echo $falnic_check['ok'] ? '✅' : '⛔'; ?></td>
						<td><?php echo esc_html( $falnic_check['label'] ); ?></td>
						<td>
							<?php if ( ! $falnic_check['ok'] ) : ?>
								<small><?php echo esc_html( $falnic_check['hint'] ); ?></small>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
			<input type="hidden" name="action" value="falnic_recheck_binaries">
			<?php wp_nonce_field( 'falnic_recheck_binaries' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'بررسی دوباره‌ی ffmpeg/ffprobe', 'falnic-secure-hls' ); ?></button>
		</form>
	</div>

	<div class="falnic-card">
		<h2><?php esc_html_e( 'صف انکود', 'falnic-secure-hls' ); ?></h2>

		<?php if ( empty( $encoder_jobs ) ) : ?>
			<p><?php esc_html_e( 'صف خالی است.', 'falnic-secure-hls' ); ?></p>
		<?php else : ?>
			<table class="widefat striped falnic-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ویدیو', 'falnic-secure-hls' ); ?></th>
						<th><?php esc_html_e( 'وضعیت', 'falnic-secure-hls' ); ?></th>
						<th><?php esc_html_e( 'پیشرفت', 'falnic-secure-hls' ); ?></th>
						<th><?php esc_html_e( 'پیام', 'falnic-secure-hls' ); ?></th>
						<th><?php esc_html_e( 'به‌روزرسانی', 'falnic-secure-hls' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $encoder_jobs as $falnic_name => $falnic_job ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $falnic_name ); ?></code></td>
							<td>
								<?php
								$falnic_state = (string) ( $falnic_job['state'] ?? 'idle' );
								echo esc_html( $falnic_states[ $falnic_state ] ?? $falnic_state );
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $falnic_job['percent'] ?? 0 ) ) . '٪' ); ?></td>
							<td>
								<?php echo esc_html( (string) ( $falnic_job['message'] ?? '' ) ); ?>
								<?php if ( ! empty( $falnic_job['errors'] ) ) : ?>
									<details>
										<summary><?php esc_html_e( 'خطا', 'falnic-secure-hls' ); ?></summary>
										<pre style="direction:ltr;text-align:left;white-space:pre-wrap;max-height:160px;overflow:auto;"><?php echo esc_html( (string) $falnic_job['errors'] ); ?></pre>
									</details>
								<?php endif; ?>
							</td>
							<td>
								<?php
								$falnic_updated = (int) ( $falnic_job['updated'] ?? 0 );
								echo esc_html( $falnic_updated > 0 ? human_time_diff( $falnic_updated ) . ' ' . __( 'پیش', 'falnic-secure-hls' ) : '—' );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<input type="hidden" name="action" value="falnic_run_queue">
				<?php wp_nonce_field( 'falnic_run_queue' ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'اجرای فوری صف', 'falnic-secure-hls' ); ?></button>
			</form>
		<?php endif; ?>
	</div>

	<div class="falnic-card">
		<h2><?php esc_html_e( 'افزودن ویدیو از سرور', 'falnic-secure-hls' ); ?></h2>

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
				<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'افزودن به صف', 'falnic-secure-hls' ); ?>">
			</p>
		</form>
	</div>
</div>
