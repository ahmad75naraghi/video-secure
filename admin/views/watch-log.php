<?php
/**
 * نمای گزارش تماشا.
 *
 * @package Falnic\SecureHLS
 *
 * @var array  $watch_rows    ردیف‌های گزارش.
 * @var int    $watch_total   تعداد کل.
 * @var array  $watch_summary آمار کلی.
 * @var array  $watch_shared  حساب‌های مشکوک به اشتراک.
 * @var array  $watch_denied  دلایل رد دسترسی امروز.
 * @var bool   $watch_exists  آیا جدول ساخته شده است.
 * @var int    $watch_days    فیلتر روزها.
 * @var string $watch_video   فیلتر ویدیو.
 * @var int    $watch_user    فیلتر کاربر.
 * @var int    $watch_page    صفحه‌ی جاری.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- نمایش پیام.
$falnic_flag  = isset( $_GET['falnic'] ) ? sanitize_key( wp_unslash( $_GET['falnic'] ) ) : '';
$falnic_count = isset( $_GET['count'] ) ? absint( wp_unslash( $_GET['count'] ) ) : 0;
// phpcs:enable

$falnic_pages = (int) ceil( $watch_total / 25 );
$falnic_base  = add_query_arg(
	array(
		'post_type' => Post_Type::SLUG,
		'page'      => 'falnic_watch_log',
	),
	admin_url( 'edit.php' )
);
?>
<div class="wrap falnic-admin" dir="rtl">
	<h1>👁 <?php esc_html_e( 'گزارش تماشا', 'falnic-secure-hls' ); ?></h1>

	<?php Admin_Tabs::render( 'falnic_watch_log' ); ?>

	<?php if ( 'revoked' === $falnic_flag ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %d: تعداد نشست‌ها */
					esc_html__( '✅ %d پخش فعال قطع شد. اگر دستگاه کاربر صفحه را باز نگه داشته باشد، حداکثر تا ۳۰ ثانیه بعد پخش متوقف می‌شود.', 'falnic-secure-hls' ),
					(int) $falnic_count
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! $watch_exists ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'جدول گزارش هنوز ساخته نشده است. افزونه را یک‌بار غیرفعال و دوباره فعال کنید تا ساختار دیتابیس ساخته شود.', 'falnic-secure-hls' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="falnic-cards">
		<div class="falnic-card">
			<span class="falnic-card-value"><?php echo esc_html( number_format_i18n( (int) $watch_summary['today'] ) ); ?></span>
			<span class="falnic-card-label"><?php esc_html_e( 'پخش امروز', 'falnic-secure-hls' ); ?></span>
		</div>
		<div class="falnic-card">
			<span class="falnic-card-value"><?php echo esc_html( number_format_i18n( (int) $watch_summary['week'] ) ); ?></span>
			<span class="falnic-card-label"><?php esc_html_e( 'پخش ۷ روز اخیر', 'falnic-secure-hls' ); ?></span>
		</div>
		<div class="falnic-card">
			<span class="falnic-card-value"><?php echo esc_html( number_format_i18n( (int) $watch_summary['users'] ) ); ?></span>
			<span class="falnic-card-label"><?php esc_html_e( 'کاربران یکتا', 'falnic-secure-hls' ); ?></span>
		</div>
		<div class="falnic-card">
			<span class="falnic-card-value"><?php echo esc_html( number_format_i18n( (int) $watch_summary['segments'] ) ); ?></span>
			<span class="falnic-card-label"><?php esc_html_e( 'سگمنت تحویل‌شده', 'falnic-secure-hls' ); ?></span>
		</div>
	</div>

	<?php if ( ! empty( $watch_shared ) ) : ?>
		<h2><?php esc_html_e( '⚠️ حساب‌های مشکوک به اشتراک‌گذاری', 'falnic-secure-hls' ); ?></h2>
		<p class="description"><?php esc_html_e( 'این حساب‌ها در ۲۴ ساعت گذشته از سه IP یا بیشتر پخش کرده‌اند.', 'falnic-secure-hls' ); ?></p>
		<table class="widefat striped falnic-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'کاربر', 'falnic-secure-hls' ); ?></th>
					<th><?php esc_html_e( 'تعداد IP', 'falnic-secure-hls' ); ?></th>
					<th><?php esc_html_e( 'تعداد نشست', 'falnic-secure-hls' ); ?></th>
					<th><?php esc_html_e( 'آخرین پخش', 'falnic-secure-hls' ); ?></th>
					<th><?php esc_html_e( 'اقدام', 'falnic-secure-hls' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $watch_shared as $falnic_row ) : ?>
					<?php $falnic_account = get_userdata( (int) $falnic_row['user_id'] ); ?>
					<tr>
						<td>
							<?php if ( $falnic_account ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( (int) $falnic_row['user_id'] ) ); ?>">
									<?php echo esc_html( $falnic_account->user_login ); ?>
								</a>
								<br><small><?php echo esc_html( $falnic_account->user_email ); ?></small>
							<?php else : ?>
								<?php echo esc_html( sprintf( '#%d', (int) $falnic_row['user_id'] ) ); ?>
							<?php endif; ?>
						</td>
						<td><strong><?php echo esc_html( number_format_i18n( (int) $falnic_row['ips'] ) ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( (int) $falnic_row['sessions'] ) ); ?></td>
						<td><?php echo esc_html( (string) $falnic_row['last_seen'] ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
								<input type="hidden" name="action" value="falnic_watch_log_action">
								<input type="hidden" name="falnic_op" value="revoke">
								<input type="hidden" name="user_id" value="<?php echo esc_attr( (int) $falnic_row['user_id'] ); ?>">
								<?php wp_nonce_field( 'falnic_watch_log_action' ); ?>
								<button type="submit" class="button button-small">
									<?php esc_html_e( 'قطع پخش', 'falnic-secure-hls' ); ?>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( ! empty( $watch_denied ) ) : ?>
		<h2><?php esc_html_e( '🚫 تلاش‌های ردشده امروز', 'falnic-secure-hls' ); ?></h2>
		<ul class="falnic-denials">
			<?php foreach ( $watch_denied as $falnic_deny ) : ?>
				<li>
					<code><?php echo esc_html( $falnic_deny['reason'] ); ?></code>
					<?php echo esc_html( $falnic_deny['video'] ); ?>
					—
					<?php echo esc_html( number_format_i18n( (int) $falnic_deny['count'] ) ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<h2><?php esc_html_e( 'نشست‌های پخش', 'falnic-secure-hls' ); ?></h2>

	<form method="get" class="falnic-filters">
		<input type="hidden" name="post_type" value="<?php echo esc_attr( Post_Type::SLUG ); ?>">
		<input type="hidden" name="page" value="falnic_watch_log">

		<label>
			<?php esc_html_e( 'بازه:', 'falnic-secure-hls' ); ?>
			<select name="days">
				<?php foreach ( array( 1, 7, 30, 90 ) as $falnic_day ) : ?>
					<option value="<?php echo esc_attr( (int) $falnic_day ); ?>" <?php selected( (int) $watch_days, $falnic_day ); ?>>
						<?php
						printf(
							/* translators: %d: تعداد روز */
							esc_html__( '%d روز', 'falnic-secure-hls' ),
							(int) $falnic_day
						);
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label>
			<?php esc_html_e( 'ویدیو:', 'falnic-secure-hls' ); ?>
			<input type="text" name="video" value="<?php echo esc_attr( $watch_video ); ?>" placeholder="lesson-108">
		</label>

		<label>
			<?php esc_html_e( 'شناسه کاربر:', 'falnic-secure-hls' ); ?>
			<input type="number" name="user_id" value="<?php echo esc_attr( $watch_user ? (int) $watch_user : '' ); ?>" min="0" style="width:90px;">
		</label>

		<button type="submit" class="button"><?php esc_html_e( 'فیلتر', 'falnic-secure-hls' ); ?></button>

		<button type="submit" form="falnic-export-form" class="button button-secondary">
			<?php esc_html_e( 'خروجی CSV', 'falnic-secure-hls' ); ?>
		</button>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="falnic-export-form">
		<input type="hidden" name="action" value="falnic_watch_log_action">
		<input type="hidden" name="falnic_op" value="export">
		<input type="hidden" name="video" value="<?php echo esc_attr( $watch_video ); ?>">
		<?php wp_nonce_field( 'falnic_watch_log_action' ); ?>
	</form>

	<table class="widefat striped falnic-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'کاربر', 'falnic-secure-hls' ); ?></th>
				<th><?php esc_html_e( 'ویدیو', 'falnic-secure-hls' ); ?></th>
				<th><?php esc_html_e( 'شروع (UTC)', 'falnic-secure-hls' ); ?></th>
				<th><?php esc_html_e( 'مدت', 'falnic-secure-hls' ); ?></th>
				<th><?php esc_html_e( 'سگمنت', 'falnic-secure-hls' ); ?></th>
				<th><?php esc_html_e( 'حجم', 'falnic-secure-hls' ); ?></th>
				<th><?php esc_html_e( 'دستگاه', 'falnic-secure-hls' ); ?></th>
				<th><?php esc_html_e( 'اثر انگشت IP', 'falnic-secure-hls' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $watch_rows ) ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'داده‌ای برای نمایش نیست.', 'falnic-secure-hls' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $watch_rows as $falnic_row ) : ?>
					<?php $falnic_account = get_userdata( (int) $falnic_row['user_id'] ); ?>
					<tr>
						<td>
							<?php if ( $falnic_account ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( (int) $falnic_row['user_id'] ) ); ?>">
									<?php echo esc_html( $falnic_account->user_login ); ?>
								</a>
							<?php else : ?>
								<?php echo esc_html( sprintf( '#%d', (int) $falnic_row['user_id'] ) ); ?>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( (string) $falnic_row['video'] ); ?></code></td>
						<td><?php echo esc_html( (string) $falnic_row['started_at'] ); ?></td>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: مدت زمان */
									__( '%s دقیقه', 'falnic-secure-hls' ),
									number_format_i18n( max( 1, (int) round( (int) $falnic_row['seconds'] / 60 ) ) )
								)
							);
							?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (int) $falnic_row['segments'] ) ); ?></td>
						<td><?php echo esc_html( size_format( (int) $falnic_row['bytes'], 1 ) ); ?></td>
						<td><?php echo esc_html( (string) $falnic_row['agent'] ); ?></td>
						<td><code title="<?php echo esc_attr( (string) $falnic_row['ip_hash'] ); ?>"><?php echo esc_html( substr( (string) $falnic_row['ip_hash'], 0, 12 ) ); ?>…</code></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $falnic_pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', $falnic_base ),
						'format'    => '',
						'current'   => (int) $watch_page,
						'total'     => $falnic_pages,
						'prev_text' => '‹',
						'next_text' => '›',
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'IP فقط به‌صورت هش یک‌طرفه ذخیره می‌شود؛ دو ردیف با هش یکسان یعنی یک IP. دوره‌ی نگه‌داری پیش‌فرض ۹۰ روز است.', 'falnic-secure-hls' ); ?>
	</p>
</div>
