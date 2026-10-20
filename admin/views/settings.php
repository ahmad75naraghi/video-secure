<?php
/**
 * نمای صفحه‌ی تنظیمات.
 *
 * @package Falnic\SecureHLS
 *
 * @var int    $max_sessions
 * @var string $bind_ip
 * @var string $auto_embed
 * @var string $ld_position
 * @var string $require_enrol
 * @var int    $max_upload_mb
 * @var string $hls_quality
 * @var int    $max_renditions
 * @var int    $segment_secs
 * @var int    $max_concurrent
 * @var int    $watch_log_days
 * @var bool   $ld_active
 * @var array  $legacy_pending
 * @var string $storage_root
 * @var bool   $outside_root
 * @var array  $status
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$falnic_migrated = isset( $_GET['migrated'] ) ? absint( wp_unslash( $_GET['migrated'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$falnic_failed   = isset( $_GET['failed'] ) ? absint( wp_unslash( $_GET['failed'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap falnic-admin" dir="rtl">
	<h1>⚙️ <?php esc_html_e( 'تنظیمات امنیتی پلیر', 'falnic-secure-hls' ); ?></h1>

	<?php Admin_Tabs::render( $page_slug ); ?>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'تنظیمات ذخیره شد.', 'falnic-secure-hls' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['migrated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice <?php echo $falnic_failed > 0 ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
			<p>
				<?php
				printf(
					/* translators: 1: تعداد منتقل‌شده، 2: تعداد ناموفق، 3: باقی‌مانده */
					esc_html__( '%1$d ویدیو به پوشه‌ی امن منتقل شد؛ %2$d ناموفق. باقی‌مانده: %3$d. اگر باقی‌مانده دارید، دوباره روی دکمه بزنید.', 'falnic-secure-hls' ),
					(int) $falnic_migrated,
					(int) $falnic_failed,
					(int) count( $legacy_pending )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! $ld_active ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'LearnDash روی این سایت فعال نیست. ویدیوهایی که به درس متصل شده‌اند تا فعال شدن LearnDash فقط برای مدیران قابل مشاهده‌اند.', 'falnic-secure-hls' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="falnic_save_settings">
		<?php wp_nonce_field( 'falnic_settings' ); ?>

		<div class="falnic-card">
			<h2><?php esc_html_e( 'نشست و اتصال', 'falnic-secure-hls' ); ?></h2>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="max_sessions"><?php esc_html_e( 'حداکثر لاگین هم‌زمان (برای کاربران)', 'falnic-secure-hls' ); ?></label></th>
					<td>
						<input name="max_sessions" type="number" step="1" min="1" id="max_sessions" value="<?php echo esc_attr( (string) $max_sessions ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'مقدار ۱ یعنی نشست‌های قبلی هنگام ورود جدید بسته می‌شوند.', 'falnic-secure-hls' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="max_upload_mb"><?php esc_html_e( 'حداکثر حجم فایل آپلود (مگابایت)', 'falnic-secure-hls' ); ?></label></th>
					<td>
						<input name="max_upload_mb" type="number" step="1" min="1" id="max_upload_mb" value="<?php echo esc_attr( (string) $max_upload_mb ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'فایل‌های بزرگ‌تر رد می‌شوند. این مقدار باید از محدودیت PHP (upload_max_filesize) بیشتر نباشد.', 'falnic-secure-hls' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bind_ip"><?php esc_html_e( 'قفل کردن استریم روی IP کاربر', 'falnic-secure-hls' ); ?></label></th>
					<td>
						<input name="bind_ip" type="checkbox" id="bind_ip" value="yes" <?php checked( $bind_ip, 'yes' ); ?>>
						<p class="description"><?php esc_html_e( 'توکن پخش به IP گره می‌خورد. برای کاربران موبایل توصیه نمی‌شود.', 'falnic-secure-hls' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="falnic-card">
			<h2><?php esc_html_e( 'کیفیت پخش و پخش هم‌زمان', 'falnic-secure-hls' ); ?></h2>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="hls_quality"><?php esc_html_e( 'حالت کیفیت', 'falnic-secure-hls' ); ?></label></th>
					<td>
						<select name="hls_quality" id="hls_quality">
							<option value="auto" <?php selected( $hls_quality, 'auto' ); ?>><?php esc_html_e( 'تطبیقی (چند کیفیت + انتخاب خودکار)', 'falnic-secure-hls' ); ?></option>
							<option value="single" <?php selected( $hls_quality, 'single' ); ?>><?php esc_html_e( 'تک‌کیفیت (سبک‌تر، مصرف CPU کمتر)', 'falnic-secure-hls' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'در حالت تطبیقی، یک پلی‌لیست اصلی (master) ساخته می‌شود و پلیر بر اساس سرعت اینترنت کیفیت را عوض می‌کند.', 'falnic-secure-hls' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="max_renditions"><?php esc_html_e( 'حداکثر تعداد کیفیت', 'falnic-secure-hls' ); ?></label></th>
					<td>
						<input name="max_renditions" type="number" min="1" max="3" step="1" id="max_renditions" value="<?php echo esc_attr( (string) $max_renditions ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'از بالاترین کیفیت موجود در فایل منبع انتخاب می‌شود (۱۰۸۰ / ۷۲۰ / ۴۸۰ / ۳۶۰). هر کیفیت بیشتر یعنی زمان انکود بیشتر.', 'falnic-secure-hls' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="segment_seconds"><?php esc_html_e( 'طول هر سگمنت (ثانیه)', 'falnic-secure-hls' ); ?></label></th>
					<td>
						<input name="segment_seconds" type="number" min="2" max="10" step="1" id="segment_seconds" value="<?php echo esc_attr( (string) $segment_secs ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'مقدار کمتر = شروع سریع‌تر و تعویض کیفیت روان‌تر، اما درخواست‌های بیشتر. پیشنهاد: ۶.', 'falnic-secure-hls' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="max_concurrent"><?php esc_html_e( 'حداکثر پخش هم‌زمان هر حساب', 'falnic-secure-hls' ); ?></label></th>
					<td>
						<input name="max_concurrent" type="number" min="0" max="10" step="1" id="max_concurrent" value="<?php echo esc_attr( (string) $max_concurrent ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'مقدار ۰ یعنی بدون محدودیت. با ۱، پخش دوم همان حساب تا بسته‌شدن پخش اول خطای ۴۰۹ می‌گیرد. مدیران سایت مستثنا هستند.', 'falnic-secure-hls' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="watch_log_days"><?php esc_html_e( 'نگه‌داری گزارش تماشا (روز)', 'falnic-secure-hls' ); ?></label></th>
					<td>
						<input name="watch_log_days" type="number" min="7" max="365" step="1" id="watch_log_days" value="<?php echo esc_attr( (string) $watch_log_days ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'ردیف‌های قدیمی‌تر روزانه به‌صورت خودکار حذف می‌شوند.', 'falnic-secure-hls' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="falnic-card">
			<h2><?php esc_html_e( 'دسترسی LearnDash', 'falnic-secure-hls' ); ?></h2>
			<p class="falnic-muted">
				<?php esc_html_e( 'ویدیو را از داخل ویرایشگر درس یا مبحث (کادر «ویدیوی امن این درس») به آن مرحله متصل کنید. دسترسی فقط برای دانشجویان ثبت‌نام‌کرده‌ی همان دوره فعال می‌شود.', 'falnic-secure-hls' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'الزام ثبت‌نام در دوره', 'falnic-secure-hls' ); ?></th>
					<td>
						<input type="hidden" name="ld_require_enrollment_present" value="1">
						<label>
							<input name="ld_require_enrollment" type="checkbox" value="yes" <?php checked( $require_enrol, 'yes' ); ?>>
							<?php esc_html_e( 'ویدیوهای بدون دوره فقط با قابلیت falnic_watch_videos قابل مشاهده باشند', 'falnic-secure-hls' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'با فعال بودن این گزینه (پیشنهادی)، ویدیویی که به هیچ درسی متصل نشده برای کاربران عادی قابل پخش نیست.', 'falnic-secure-hls' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'نمایش خودکار در درس', 'falnic-secure-hls' ); ?></th>
					<td>
						<input type="hidden" name="ld_auto_embed_present" value="1">
						<label>
							<input name="ld_auto_embed" type="checkbox" value="yes" <?php checked( $auto_embed, 'yes' ); ?>>
							<?php esc_html_e( 'پلیر بالای محتوای درس نمایش داده شود', 'falnic-secure-hls' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ld_position"><?php esc_html_e( 'محل نمایش پلیر', 'falnic-secure-hls' ); ?></label></th>
					<td>
						<select name="ld_position" id="ld_position">
							<option value="before" <?php selected( $ld_position, 'before' ); ?>><?php esc_html_e( 'بالای محتوای درس', 'falnic-secure-hls' ); ?></option>
							<option value="after" <?php selected( $ld_position, 'after' ); ?>><?php esc_html_e( 'پایین محتوای درس', 'falnic-secure-hls' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'برای جای‌گذاری دستی از شورت‌کد [falnic_lesson_video] داخل محتوای درس استفاده کنید.', 'falnic-secure-hls' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<p class="submit">
			<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'ذخیره تغییرات', 'falnic-secure-hls' ); ?>">
		</p>
	</form>

	<div class="falnic-card">
		<h2><?php esc_html_e( 'محل ذخیره‌ی رسانه', 'falnic-secure-hls' ); ?></h2>
		<table class="falnic-meta">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'مسیر فعلی', 'falnic-secure-hls' ); ?></th>
					<td><code dir="ltr"><?php echo esc_html( $storage_root ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'وضعیت', 'falnic-secure-hls' ); ?></th>
					<td>
						<?php if ( $outside_root ) : ?>
							<span class="falnic-badge falnic-badge-ok"><?php esc_html_e( 'خارج از web root — امن', 'falnic-secure-hls' ); ?></span>
						<?php else : ?>
							<span class="falnic-badge falnic-badge-warn"><?php esc_html_e( 'داخل web root — دسترسی مستقیم باید در وب‌سرور بسته شود', 'falnic-secure-hls' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'ویدیوهای باقی‌مانده در uploads', 'falnic-secure-hls' ); ?></th>
					<td>
						<?php if ( empty( $legacy_pending ) ) : ?>
							<span class="falnic-badge falnic-badge-ok"><?php esc_html_e( 'هیچ — همه منتقل شده‌اند', 'falnic-secure-hls' ); ?></span>
						<?php else : ?>
							<span class="falnic-badge falnic-badge-warn"><?php echo esc_html( (string) count( $legacy_pending ) ); ?></span>
							<?php esc_html_e( 'برای امنیت کامل، این فایل‌ها را منتقل کنید.', 'falnic-secure-hls' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
			<input type="hidden" name="action" value="falnic_migrate_storage">
			<?php wp_nonce_field( 'falnic_migrate_storage' ); ?>
			<button type="submit" class="button" <?php disabled( empty( $legacy_pending ) ); ?>>
				<?php esc_html_e( 'انتقال ویدیوهای قدیمی به پوشه‌ی امن', 'falnic-secure-hls' ); ?>
			</button>
			<p class="description">
				<?php esc_html_e( 'فایل‌ها از uploads/secure_videos به پوشه‌ی خارج از web root منتقل می‌شوند. پیش از اجرا از سایت بکاپ بگیرید.', 'falnic-secure-hls' ); ?>
			</p>
		</form>
	</div>

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
