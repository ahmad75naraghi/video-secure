<?php
/**
 * رندر پلیر و شورت‌کد.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ساخت مارک‌آپ پلیر بر اساس قالب.
 */
final class Player {

	/**
	 * ثبت شورت‌کد.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( 'secure_video', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * پردازش شورت‌کد.
	 *
	 * @param array $atts پارامترها.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array( 'name' => '' ),
			$atts,
			'secure_video'
		);

		return self::render( (string) $atts['name'] );
	}

	/**
	 * رندر پلیر برای یک ویدیو.
	 *
	 * @param string $name نام ویدیو.
	 * @return string
	 */
	public static function render( string $name ): string {
		$name = sanitize_text_field( $name );

		if ( '' === $name || ! Storage::is_valid_name( $name ) ) {
			return '';
		}

		$reason = Access::reason( $name );

		Assets::enqueue();

		if ( 'ok' !== $reason ) {
			return self::render_notice( $reason, $name );
		}

		$html_id = wp_unique_id( 'secure-vid-' );

		$template = Template_Settings::get();

		$config = array(
			'id'           => $html_id,
			'name'         => $name,
			'sessionUrl'   => admin_url( 'admin-ajax.php?action=' . Stream::ACTION_SESSION ),
			'heartbeatUrl' => admin_url( 'admin-ajax.php?action=' . Stream::ACTION_HEARTBEAT ),
			'endUrl'       => admin_url( 'admin-ajax.php?action=' . Stream::ACTION_END ),
			// متن واترمارک عمداً در HTML نیست؛ از پاسخ نشست (مخصوص همان کاربر) می‌آید.
			'watermark'  => array_merge(
				array(
					'line1' => '',
					'line2' => '',
				),
				array(
					'opacity'  => (float) $template['wm_opacity'],
					'interval' => (int) $template['wm_interval'],
					'scale'    => (float) $template['wm_font_scale'],
				)
			),
			'i18n'       => array(
				'resume'       => __( 'برای ادامه پخش روی صفحه کلیک کنید', 'falnic-secure-hls' ),
				'error'        => __( 'خطا در پخش ویدیو. لطفاً صفحه را دوباره بارگذاری کنید.', 'falnic-secure-hls' ),
				'unsupported'  => __( 'مرورگر شما از پخش این ویدیو پشتیبانی نمی‌کند.', 'falnic-secure-hls' ),
				'sessionError' => __( 'ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی کنید.', 'falnic-secure-hls' ),
				'expired'      => __( 'زمان دسترسی پخش تمام شد؛ صفحه را دوباره بارگذاری کنید.', 'falnic-secure-hls' ),
				'sessionEnded' => __( 'این نشست پخش پایان یافت. صفحه را دوباره بارگذاری کنید.', 'falnic-secure-hls' ),
				'multiDevice'  => __( 'این حساب در دستگاه دیگری در حال پخش است. ابتدا پخش قبلی را ببندید.', 'falnic-secure-hls' ),
			),
		);

		/**
		 * فیلتر پیکربندی پلیر.
		 *
		 * @param array  $config پیکربندی.
		 * @param string $name   نام ویدیو.
		 */
		$config = apply_filters( 'falnic_player_config', $config, $name );

		$args = array(
			'html_id'     => $html_id,
			'name'        => $name,
			'config_json' => (string) wp_json_encode(
				$config,
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			),
		);

		ob_start();
		$template_path = FALNIC_DIR . 'templates/player.php';

		if ( is_readable( $template_path ) ) {
			/**
			 * متغیرهای موجود در قالب: $args
			 *
			 * @var array $args
			 */
			include $template_path;
		}

		return (string) ob_get_clean();
	}

	/**
	 * خطوط واترمارک برای کاربر جاری.
	 *
	 * @return array
	 */
	public static function watermark_lines(): array {
		$lines = array(
			'line1' => '',
			'line2' => '',
		);

		if ( ! is_user_logged_in() ) {
			return $lines;
		}

		$user = wp_get_current_user();
		$full = trim( $user->first_name . ' ' . $user->last_name );

		$template = Template_Settings::get();

		$lines['line1'] = '' !== $full ? sprintf( '%s (%s)', $full, $user->user_login ) : $user->user_login;
		$lines['line2'] = 'no' === $template['wm_show_email'] ? '' : (string) $user->user_email;

		/**
		 * فیلتر خطوط واترمارک.
		 *
		 * @param array $lines خطوط.
		 * @param int   $user_id شناسه‌ی کاربر.
		 */
		return (array) apply_filters( 'falnic_watermark_lines', $lines, $user->ID );
	}

	/**
	 * پیام حرفه‌ای عدم دسترسی (به‌جای پلیر).
	 *
	 * @param string $reason دلیل.
	 * @param string $name   نام ویدیو.
	 * @return string
	 */
	private static function render_notice( string $reason, string $name ): string {
		$message  = Access::message( $reason );
		$courses  = in_array( $reason, array( 'not_enrolled', 'locked' ), true ) ? Access::courses_for( $name ) : array();
		$login    = 'guest' === $reason ? wp_login_url( self::current_url() ) : '';
		$icons    = array(
			'guest'        => '🔐',
			'not_ready'    => '⏳',
			'not_linked'   => '⚠️',
			'not_enrolled' => '🔒',
			'locked'       => '⏰',
			'invalid'      => '⚠️',
		);
		$titles   = array(
			'guest'        => __( 'ورود لازم است', 'falnic-secure-hls' ),
			'not_ready'    => __( 'ویدیو آماده نیست', 'falnic-secure-hls' ),
			'not_linked'   => __( 'ویدیو به دوره متصل نشده', 'falnic-secure-hls' ),
			'not_enrolled' => __( 'دسترسی محدود', 'falnic-secure-hls' ),
			'locked'       => __( 'درس هنوز باز نشده', 'falnic-secure-hls' ),
			'invalid'      => __( 'درخواست نامعتبر', 'falnic-secure-hls' ),
		);
		$icon     = isset( $icons[ $reason ] ) ? $icons[ $reason ] : '🔒';
		$title    = isset( $titles[ $reason ] ) ? $titles[ $reason ] : __( 'دسترسی محدود', 'falnic-secure-hls' );

		ob_start();
		?>
		<div class="falnic-secure-container falnic-denied" dir="rtl" data-falnic-denied>
			<div class="falnic-denied-inner">
				<span class="falnic-denied-icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
				<h3 class="falnic-denied-title"><?php echo esc_html( $title ); ?></h3>
				<p class="falnic-denied-text"><?php echo esc_html( $message ); ?></p>

				<?php if ( ! empty( $courses ) ) : ?>
					<p class="falnic-denied-links">
						<?php foreach ( $courses as $course ) : ?>
							<?php if ( ! empty( $course['url'] ) ) : ?>
								<a class="falnic-denied-btn" href="<?php echo esc_url( $course['url'] ); ?>">
									<?php
									printf(
										/* translators: %s: عنوان دوره */
										esc_html__( 'مشاهده دوره: %s', 'falnic-secure-hls' ),
										esc_html( (string) $course['title'] )
									);
									?>
								</a>
							<?php endif; ?>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>

				<?php if ( '' !== $login ) : ?>
					<p class="falnic-denied-links">
						<a class="falnic-denied-btn" href="<?php echo esc_url( $login ); ?>">
							<?php esc_html_e( 'ورود به حساب کاربری', 'falnic-secure-hls' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * نشانی صفحه‌ی جاری برای بازگشت پس از ورود.
	 *
	 * @return string
	 */
	private static function current_url(): string {
		$post_id = (int) get_the_ID();

		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );

			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		return home_url( '/' );
	}
}
