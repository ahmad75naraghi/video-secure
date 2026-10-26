<?php
/**
 * یکپارچه‌سازی با LearnDash.
 *
 * ویدیو از داخل ویرایشگر درس یا مبحث به آن مرحله متصل می‌شود و پس از آن
 * فقط دانشجویان ثبت‌نام‌کرده‌ی همان دوره به آن دسترسی دارند.
 * همچنین از 3.5.13 اتصال از سمت ویدیو (falnic_course_id / falnic_lesson_id)
 * هم در نمایش خودکار پشتیبانی می‌شود — هم روی صفحه‌ی دوره و هم روی درس.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * متاباکس، نمایش خودکار و شورت‌کد درس.
 */
final class Learn_Dash {

	/**
	 * نام nonce فرم متاباکس.
	 */
	private const NONCE = 'falnic_lesson_video';

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_box' ) );

		foreach ( Access::STEP_TYPES as $type ) {
			add_action( 'save_post_' . $type, array( __CLASS__, 'save_box' ) );
		}

		add_filter( 'the_content', array( __CLASS__, 'auto_embed' ), 20 );
		add_shortcode( 'falnic_lesson_video', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/**
	 * آیا LearnDash روی این سایت فعال است؟
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return Access::learn_dash_active();
	}

	/**
	 * نام ویدیوی متصل به یک درس/مبحث (متای مستقیم).
	 *
	 * @param int $post_id شناسه.
	 * @return string
	 */
	public static function video_name( int $post_id ): string {
		$name = (string) get_post_meta( $post_id, Access::LESSON_META, true );

		return Storage::is_valid_name( $name ) ? $name : '';
	}

	/**
	 * ثبت متاباکس روی درس و مبحث.
	 *
	 * @param string $post_type نوع پست.
	 * @return void
	 */
	public static function register_box( $post_type ): void {
		if ( ! in_array( (string) $post_type, Access::STEP_TYPES, true ) ) {
			return;
		}

		add_meta_box(
			'falnic_lesson_video_box',
			__( '🎬 ویدیوی امن این درس', 'falnic-secure-hls' ),
			array( __CLASS__, 'render_box' ),
			(string) $post_type,
			'side',
			'default'
		);
	}

	/**
	 * نمایش متاباکس.
	 *
	 * @param \WP_Post $post پست جاری.
	 * @return void
	 */
	public static function render_box( $post ): void {
		wp_nonce_field( self::NONCE, 'falnic_lesson_nonce' );

		$current = self::video_name( (int) $post->ID );
		$videos  = self::video_choices();
		?>
		<p>
			<label for="falnic-lesson-video" class="screen-reader-text">
				<?php esc_html_e( 'ویدیوی این درس', 'falnic-secure-hls' ); ?>
			</label>
			<select name="falnic_lesson_video" id="falnic-lesson-video" style="width:100%;">
				<option value=""><?php esc_html_e( '— بدون ویدیو —', 'falnic-secure-hls' ); ?></option>
				<?php foreach ( $videos as $video ) : ?>
					<option value="<?php echo esc_attr( $video['name'] ); ?>" <?php selected( $current, $video['name'] ); ?>>
						<?php
						echo esc_html(
							$video['title'] !== $video['name'] && '' !== $video['title']
								? $video['title'] . ' (' . $video['name'] . ')'
								: $video['name']
						);
						?>
						<?php echo $video['ready'] ? '✔' : '⏳'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- نماد ثابت. ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">
			<?php esc_html_e( 'فقط دانشجویانی که در دوره‌ی این درس ثبت‌نام کرده‌اند ویدیو را می‌بینند. مدیر سایت همیشه دسترسی دارد.', 'falnic-secure-hls' ); ?>
		</p>
		<?php if ( $current && ! Storage::is_encoded( $current ) ) : ?>
			<p class="description" style="color:#b32d2e;">
				<?php esc_html_e( 'هشدار: فایل این ویدیو هنوز انکود نشده است و پخش نمی‌شود.', 'falnic-secure-hls' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * ذخیره‌ی اتصال.
	 *
	 * @param int $post_id شناسه‌ی درس/مبحث.
	 * @return void
	 */
	public static function save_box( $post_id ): void {
		$post_id = (int) $post_id;

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['falnic_lesson_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['falnic_lesson_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$name = isset( $_POST['falnic_lesson_video'] ) ? sanitize_text_field( wp_unslash( $_POST['falnic_lesson_video'] ) ) : '';

		if ( '' !== $name && Storage::is_valid_name( $name ) ) {
			update_post_meta( $post_id, Access::LESSON_META, $name );
			return;
		}

		delete_post_meta( $post_id, Access::LESSON_META );
	}

	/**
	 * نمایش خودکار بالای محتوای درس/دوره.
	 *
	 * از 3.5.3 فقط _falnic_video_name روی درس/مبحث چک می‌شد؛ از 3.5.13
	 * اتصال معکوس از سمت ویدیو (falnic_lesson_id / falnic_course_id) هم
	 * پشتیبانی می‌شود و صفحه‌ی دوره (sfwd-courses) هم کاور می‌شود.
	 *
	 * @param string $content محتوا.
	 * @return string
	 */
	public static function auto_embed( $content ): string {
		$content = (string) $content;

		if ( ! is_main_query() ) {
			return $content;
		}

		if ( 'yes' !== get_option( 'falnic_ld_auto_embed', 'yes' ) ) {
			return $content;
		}

		$post_id = (int) get_the_ID();

		if ( $post_id <= 0 ) {
			return $content;
		}

		// صفحه‌ی دوره هرگز خودکار ویدیو نمایش نمی‌دهد — فقط شورت‌کد دستی [secure_video] یا [falnic_lesson_video]
		if ( is_singular( 'sfwd-courses' ) ) {
			return $content;
		}

		// --- درس / مبحث ---
		if ( ! is_singular( Access::STEP_TYPES ) ) {
			return $content;
		}

		$name = self::video_name( $post_id );

		// fallback: اتصال از سمت ویدیو (ویدیو → این درس)
		if ( '' === $name ) {
			$name = self::video_for_lesson( $post_id );
		}

		// fallback دوم: ویدیوی سطح دوره (ویدیو → دوره‌ی این درس)
		if ( '' === $name ) {
			$course_id = Access::course_id( $post_id );

			if ( $course_id > 0 ) {
				$course_videos = self::videos_for_course( $course_id );

				// فقط ویدیوهایی که lesson خاص ندارند (کل دوره) روی درس بیاید
				// تا درس‌هایی که ویدیوی اختصاصی دارند، تداخل نکنند
				foreach ( $course_videos as $candidate ) {
					$vid_post = Post_Type::find_by_name( $candidate );

					if ( $vid_post > 0 ) {
						$lesson_meta = (int) get_post_meta( $vid_post, Access::STEP_META, true );

						if ( 0 === $lesson_meta ) {
							$name = $candidate;
							break;
						}
					}
				}
			}
		}

		if ( '' === $name ) {
			return $content;
		}

		$html = Player::render( $name );

		if ( '' === $html ) {
			return $content;
		}

		return 'after' === get_option( 'falnic_ld_position', 'before' )
			? $content . $html
			: $html . $content;
	}

	/**
	 * شورت‌کد ویدیوی درس جاری.
	 *
	 * @return string
	 */
	public static function shortcode(): string {
		$post_id = (int) get_the_ID();

		if ( $post_id <= 0 ) {
			$post_id = (int) get_queried_object_id();
		}

		$name = self::video_name( $post_id );

		if ( '' === $name ) {
			$name = self::video_for_lesson( $post_id );
		}

		return '' === $name ? '' : Player::render( $name );
	}

	/**
	 * ویدیوهای متصل به یک دوره از سمت ویدیو (falnic_course_id).
	 *
	 * @param int $course_id شناسه‌ی دوره.
	 * @return string[] نام ویدیوها
	 */
	public static function videos_for_course( int $course_id ): array {
		if ( $course_id <= 0 ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'        => Post_Type::SLUG,
				'post_status'      => array( 'publish', 'private', 'draft' ),
				'posts_per_page'   => 20,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_key'         => Access::COURSE_META,
				'meta_value'       => $course_id,
			)
		);

		$names = array();

		foreach ( (array) $posts as $pid ) {
			$name = Post_Type::video_name( $pid );

			if ( '' !== $name && Storage::is_encoded( $name ) ) {
				$names[] = $name;
			} elseif ( '' !== $name ) {
				// حتی اگر هنوز انکود نشده، نام را برگردان تا پیام "آماده نیست" نمایش داده شود
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * ویدیوی متصل به یک درس از سمت ویدیو (falnic_lesson_id).
	 *
	 * @param int $lesson_id شناسه‌ی درس/مبحث.
	 * @return string
	 */
	public static function video_for_lesson( int $lesson_id ): string {
		if ( $lesson_id <= 0 ) {
			return '';
		}

		$posts = get_posts(
			array(
				'post_type'        => Post_Type::SLUG,
				'post_status'      => array( 'publish', 'private', 'draft' ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_key'         => Access::STEP_META,
				'meta_value'       => $lesson_id,
			)
		);

		if ( empty( $posts ) ) {
			return '';
		}

		$name = Post_Type::video_name( (int) $posts[0] );

		return Storage::is_valid_name( $name ) ? $name : '';
	}

	/**
	 * فهرست ویدیوهای قابل انتخاب.
	 *
	 * @return array
	 */
	private static function video_choices(): array {
		$posts = get_posts(
			array(
				'post_type'        => Post_Type::SLUG,
				'post_status'      => 'publish',
				'posts_per_page'   => 200,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);

		$choices = array();

		foreach ( $posts as $post ) {
			// شناسه‌ی پایدار (متا) مبنای انتخاب است، نه عنوان؛ عنوان ممکن است فارسی باشد.
			$name = Post_Type::video_name( $post );

			if ( '' === $name ) {
				continue;
			}

			$choices[] = array(
				'name'  => $name,
				'title' => (string) $post->post_title,
				'ready' => Storage::is_encoded( $name ),
			);
		}

		return $choices;
	}

	/**
	 * هشدار وقتی LearnDash نصب نیست.
	 *
	 * @return void
	 */
	public static function admin_notice(): void {
		if ( self::is_active() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( (string) $screen->post_type, array_merge( Access::STEP_TYPES, array( 'sfwd-courses' ) ), true ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'LearnDash روی این سایت فعال نیست. ویدیوهایی که به درس متصل شده‌اند تا فعال شدن LearnDash فقط برای مدیران قابل مشاهده‌اند.', 'falnic-secure-hls' );
		echo '</p></div>';
	}
}
