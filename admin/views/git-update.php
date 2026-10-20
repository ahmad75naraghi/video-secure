<?php
/**
 * نمای صفحه‌ی «آپدیت از گیت».
 *
 * @package Falnic\SecureHLS
 *
 * @var array  $settings
 * @var string $error
 * @var string $notice
 * @var string $version
 * @var string $main_file
 * @var string $plugin_dir
 * @var string $backup_root
 * @var array  $backups
 * @var array  $requirements
 * @var bool   $locked
 * @var int    $max_backups
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$falnic_blocked = array();

$falnic_token_placeholder = $settings['has_token']
	? __( 'ذخیره شده — برای تغییر مقدار جدید وارد کنید', 'falnic-secure-hls' )
	: __( 'ghp_…', 'falnic-secure-hls' );
foreach ( $requirements as $requirement ) {
	if ( empty( $requirement['ok'] ) ) {
		$falnic_blocked[] = $requirement;
	}
}
?>
<div class="wrap falnic-admin" dir="rtl">
	<h1><?php esc_html_e( 'آپدیت از گیت', 'falnic-secure-hls' ); ?></h1>

	<?php Admin_Tabs::render( Git_Update_Page::SLUG ); ?>

	<?php if ( 'saved' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'تنظیمات منبع ذخیره شد.', 'falnic-secure-hls' ); ?></p></div>
	<?php elseif ( 'error' === $notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'ذخیرهٔ تنظیمات ناموفق بود.', 'falnic-secure-hls' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<?php if ( $locked ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'یک عملیات به‌روزرسانی یا بازگردانی در حال اجراست. تا پایان آن صبر کنید.', 'falnic-secure-hls' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $falnic_blocked ) ) : ?>
		<div class="notice notice-error">
			<p><strong><?php esc_html_e( 'پیش‌نیازهای سرور کامل نیست؛ آپدیت غیرفعال است:', 'falnic-secure-hls' ); ?></strong></p>
			<ul class="falnic-list">
				<?php foreach ( $falnic_blocked as $requirement ) : ?>
					<li><?php echo esc_html( $requirement['message'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<!-- کارت ۱: تنظیمات منبع -->
	<div class="falnic-card">
		<h2><?php esc_html_e( '۱) تنظیمات منبع', 'falnic-secure-hls' ); ?></h2>
		<p class="falnic-muted">
			<?php esc_html_e( 'مخزن و شاخه‌ای که نسخهٔ افزونه از آن خوانده می‌شود. برای مخزن عمومی، توکن لازم نیست.', 'falnic-secure-hls' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="falnic-form">
			<input type="hidden" name="action" value="falnic_save_git_settings">
			<?php wp_nonce_field( 'falnic_git_settings' ); ?>

			<div class="falnic-field">
				<label for="falnic-repo"><?php esc_html_e( 'مخزن GitHub', 'falnic-secure-hls' ); ?></label>
				<input
					type="text"
					id="falnic-repo"
					name="repo"
					class="regular-text code"
					dir="ltr"
					placeholder="owner/repo"
					value="<?php echo esc_attr( $settings['repo'] ); ?>"
					required
				>
				<p class="description"><?php esc_html_e( 'به شکل owner/repo — مثال: ahmad75naraghi/video-secure', 'falnic-secure-hls' ); ?></p>
			</div>

			<div class="falnic-field">
				<label for="falnic-branch"><?php esc_html_e( 'شاخه (Branch)', 'falnic-secure-hls' ); ?></label>
				<input
					type="text"
					id="falnic-branch"
					name="branch"
					class="regular-text code"
					dir="ltr"
					value="<?php echo esc_attr( $settings['branch'] ); ?>"
					required
				>
			</div>

			<div class="falnic-field">
				<label for="falnic-token"><?php esc_html_e( 'توکن GitHub (اختیاری)', 'falnic-secure-hls' ); ?></label>
				<input
					type="password"
					id="falnic-token"
					name="token"
					class="regular-text code"
					dir="ltr"
					autocomplete="new-password"
					value=""
					placeholder="<?php echo esc_attr( $falnic_token_placeholder ); ?>"
				>
				<p class="description">
					<?php if ( $settings['has_token'] ) : ?>
						<span class="falnic-badge falnic-badge-ok"><?php esc_html_e( 'توکن ذخیره شده', 'falnic-secure-hls' ); ?></span>
						<?php esc_html_e( 'برای حفظ توکن فعلی، این فیلد را خالی بگذارید.', 'falnic-secure-hls' ); ?>
					<?php else : ?>
						<span class="falnic-badge"><?php esc_html_e( 'توکن ذخیره نشده', 'falnic-secure-hls' ); ?></span>
						<?php esc_html_e( 'برای مخزن خصوصی، توکن با دسترسی خواندن محتوا لازم است.', 'falnic-secure-hls' ); ?>
					<?php endif; ?>
				</p>
				<?php if ( $settings['has_token'] ) : ?>
					<label class="falnic-check">
						<input type="checkbox" name="clear_token" value="1">
						<?php esc_html_e( 'حذف توکن ذخیره‌شده', 'falnic-secure-hls' ); ?>
					</label>
				<?php endif; ?>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'ذخیره تنظیمات', 'falnic-secure-hls' ); ?></button>
			</p>
		</form>
	</div>

	<!-- کارت ۲: آپدیت -->
	<div class="falnic-card">
		<h2><?php esc_html_e( '۲) آپدیت افزونه', 'falnic-secure-hls' ); ?></h2>

		<table class="falnic-meta">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'نسخهٔ نصب‌شده', 'falnic-secure-hls' ); ?></th>
					<td><code><?php echo esc_html( $version ? $version : '—' ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'فایل اصلی فعال', 'falnic-secure-hls' ); ?></th>
					<td><code dir="ltr"><?php echo esc_html( $main_file ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'مسیر واقعی پوشهٔ افزونه', 'falnic-secure-hls' ); ?></th>
					<td><code dir="ltr"><?php echo esc_html( $plugin_dir ); ?></code></td>
				</tr>
			</tbody>
		</table>

		<div class="falnic-actions">
			<button type="button" class="button" id="falnic-git-check" <?php disabled( ! empty( $falnic_blocked ) ); ?>>
				<?php esc_html_e( '۱) بررسی آخرین نسخه', 'falnic-secure-hls' ); ?>
			</button>
			<button type="button" class="button button-primary" id="falnic-git-update" <?php disabled( ! empty( $falnic_blocked ) || $locked ); ?>>
				<?php esc_html_e( '۲) آپدیت همین حالا', 'falnic-secure-hls' ); ?>
			</button>
			<span class="falnic-spinner" id="falnic-git-spinner" aria-hidden="true"></span>
		</div>

		<div id="falnic-git-status" class="falnic-status" role="status" aria-live="polite"></div>

		<div id="falnic-git-info" class="falnic-info" hidden>
			<h3><?php esc_html_e( 'آخرین نسخهٔ موجود', 'falnic-secure-hls' ); ?></h3>
			<table class="falnic-meta">
				<tbody>
					<tr><th><?php esc_html_e( 'شاخه', 'falnic-secure-hls' ); ?></th><td><code data-field="branch"></code></td></tr>
					<tr><th><?php esc_html_e( 'کامیت', 'falnic-secure-hls' ); ?></th><td><code data-field="short_sha" dir="ltr"></code></td></tr>
					<tr><th><?php esc_html_e( 'تاریخ کامیت', 'falnic-secure-hls' ); ?></th><td data-field="date_local"></td></tr>
					<tr><th><?php esc_html_e( 'پیام کامیت', 'falnic-secure-hls' ); ?></th><td data-field="message"></td></tr>
					<tr><th><?php esc_html_e( 'نسخهٔ فعلی', 'falnic-secure-hls' ); ?></th><td><code data-field="local_version"></code></td></tr>
				</tbody>
			</table>
		</div>

		<div id="falnic-git-report" class="falnic-report" hidden>
			<h3><?php esc_html_e( 'گزارش آپدیت', 'falnic-secure-hls' ); ?></h3>
			<ul class="falnic-list" id="falnic-git-report-list"></ul>
			<div id="falnic-git-report-files"></div>
		</div>
	</div>

	<!-- کارت ۳: پشتیبان‌ها -->
	<div class="falnic-card">
		<h2><?php esc_html_e( '۳) پشتیبان‌ها', 'falnic-secure-hls' ); ?></h2>
		<p class="falnic-muted">
			<?php
			printf(
				/* translators: 1: مسیر پشتیبان، 2: تعداد نگهداری */
				esc_html__( 'محل ذخیره: %1$s — فقط %2$d پشتیبان آخر نگه داشته می‌شود.', 'falnic-secure-hls' ),
				'<code dir="ltr">' . esc_html( $backup_root ) . '</code>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				(int) $max_backups
			);
			?>
		</p>

		<?php if ( empty( $backups ) ) : ?>
			<p class="falnic-empty"><?php esc_html_e( 'هنوز پشتیبانی ساخته نشده است. پیش از هر آپدیت، به‌صورت خودکار پشتیبان گرفته می‌شود.', 'falnic-secure-hls' ); ?></p>
		<?php else : ?>
			<table class="widefat striped falnic-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'فایل', 'falnic-secure-hls' ); ?></th>
						<th><?php esc_html_e( 'تاریخ', 'falnic-secure-hls' ); ?></th>
						<th><?php esc_html_e( 'حجم', 'falnic-secure-hls' ); ?></th>
						<th><?php esc_html_e( 'عملیات', 'falnic-secure-hls' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $backups as $backup ) : ?>
						<tr>
							<td><code dir="ltr"><?php echo esc_html( $backup['name'] ); ?></code></td>
							<td><?php echo esc_html( $backup['date'] ); ?></td>
							<td><?php echo esc_html( size_format( $backup['size'], 1 ) ); ?></td>
							<td class="falnic-row-actions">
								<button
									type="button"
									class="button button-small falnic-rollback"
									data-backup="<?php echo esc_attr( $backup['name'] ); ?>"
									data-mtime="<?php echo esc_attr( (string) $backup['mtime'] ); ?>"
								>
									<?php esc_html_e( 'بازگردانی', 'falnic-secure-hls' ); ?>
								</button>
								<button
									type="button"
									class="button button-small falnic-delete"
									data-backup="<?php echo esc_attr( $backup['name'] ); ?>"
								>
									<?php esc_html_e( 'حذف', 'falnic-secure-hls' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<div id="falnic-git-backups-status" class="falnic-status" role="status" aria-live="polite"></div>
	</div>
</div>
