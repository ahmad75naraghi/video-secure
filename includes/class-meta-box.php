<?php
/**
 * متاباکس آپلود، دسترسی و گزارش بینندگان در صفحه‌ی ویرایش ویدیو.
 *
 * هدف این متاباکس: همه‌ی کارهای مهم یک ویدیو — آپلود، اتصال به دوره،
 * پیش‌نمایش دسترسی و «چه کسی دیده» — در یک صفحه قابل انجام باشد.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * آپلود فایل MP4، اتصال به دوره و نمایش بینندگان.
 */
final class Meta_Box {

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'post_edit_form_tag', array( __CLASS__, 'form_enctype' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post_' . Post_Type::SLUG, array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * بارگذاری اسکریپت وضعیت صف و تعاملات ویرایش ویدیو.
	 *
	 * @param string $hook صفحه‌ی جاری.
	 * @return void
	 */
	public static function enqueue( $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || Post_Type::SLUG !== (string) $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'falnic-admin', FALNIC_URL . 'assets/css/admin.css', array(), FALNIC_VERSION );
		wp_enqueue_script( 'falnic-admin-encode', FALNIC_URL . 'assets/js/admin-encode.js', array(), FALNIC_VERSION, true );
		wp_enqueue_script( 'falnic-admin-video', FALNIC_URL . 'assets/js/admin-video-edit.js', array(), FALNIC_VERSION, true );
	}

	/**
	 * افزودن enctype به فرم ویرایش.
	 *
	 * @return void
	 */
	public static function form_enctype(): void {
		global $post;

		if ( $post && Post_Type::SLUG === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	/**
	 * ثبت متاباکس.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_meta_box(
			'falnic_video_upload_box',
			__( '🎬 ویدیوی امن — آپلود، دسترسی و بینندگان', 'falnic-secure-hls' ),
			array( __CLASS__, 'render' ),
			Post_Type::SLUG,
			'normal',
			'high'
		);
	}

	/**
	 * نمایش متاباکس.
	 *
	 * @param \WP_Post $post پست جاری.
	 * @return void
	 */
	public static function render( $post ): void {
		wp_nonce_field( 'falnic_video_upload', 'falnic_video_upload_nonce' );

		$name   = Post_Type::video_name( $post );
		$course = (int) get_post_meta( $post->ID, Access::COURSE_META, true );
		$lesson = (int) get_post_meta( $post->ID, Access::STEP_META, true );

		echo '<div class="falnic-video-edit" dir="rtl">';

		self::render_overview( $post, $name, $course, $lesson );
		self::render_status( $post, $name );
		self::render_upload_field();
		self::render_access( $post, $course, $lesson, $name );
		self::render_viewers( $name );
		self::render_subtitles( $name );

		echo '</div>';
	}

	/**
	 * کارت خلاصه‌ی بالای متاباکس: وضعیت، دسترسی و ابزار کپی.
	 *
	 * @param \WP_Post $post   پست.
	 * @param string   $name   نام ویدیو.
	 * @param int      $course شناسه‌ی دوره.
	 * @param int      $lesson شناسه‌ی درس.
	 * @return void
	 */
	private static function render_overview( $post, string $name, int $course, int $lesson ): void {
		if ( '' === $name && 0 === $course && 0 === $lesson ) {
			return;
		}

		$encoded = '' !== $name && Storage::is_encoded( $name );
		$has_access = $course > 0 || $lesson > 0;
		$shortcode = '' !== $name ? '[secure_video name="' . $name . '"]' : '';
		$embed_url = '' !== $name ? add_query_arg( 'falnic_embed', $name, home_url( '/' ) ) : '';

		echo '<div class="falnic-ve-overview">';

		// ردیف وضعیت‌ها.
		echo '<div class="falnic-ve-pills">';
		if ( '' !== $name ) {
			printf(
				'<span class="falnic-pill">%s <code>%s</code></span>',
				esc_html__( 'شناسه:', 'falnic-secure-hls' ),
				esc_html( $name )
			);
		}

		if ( '' !== $name ) {
			if ( $encoded ) {
				echo '<span class="falnic-pill falnic-pill-ok">✔ ' . esc_html__( 'آماده‌ی پخش', 'falnic-secure-hls' ) . '</span>';
			} else {
				echo '<span class="falnic-pill falnic-pill-warn">⏳ ' . esc_html__( 'در انتظار انکود', 'falnic-secure-hls' ) . '</span>';
			}
		}

		if ( $has_access ) {
			$title = $course > 0 ? get_the_title( $course ) : get_the_title( $lesson );
			if ( '' !== (string) $title ) {
				printf(
					'<span class="falnic-pill falnic-pill-lock">🔒 %s</span>',
					esc_html( $title )
				);
			} else {
				echo '<span class="falnic-pill falnic-pill-lock">🔒 ' . esc_html__( 'محدود به دوره', 'falnic-secure-hls' ) . '</span>';
			}
		} else {
			echo '<span class="falnic-pill falnic-pill-muted">👁 ' . esc_html__( 'فعلاً فقط مدیران', 'falnic-secure-hls' ) . '</span>';
		}

		echo '</div>';

		// ابزارهای کپی.
		if ( '' !== $shortcode ) {
			echo '<div class="falnic-ve-tools">';
			printf(
				'<label class="falnic-ve-tool"><span>%s</span><span class="falnic-copy-row"><input type="text" readonly value="%s" class="falnic-copy-input" data-falnic-copy><button type="button" class="button falnic-copy-btn" data-falnic-copy-btn>%s</button></span></label>',
				esc_html__( 'شورت‌کد', 'falnic-secure-hls' ),
				esc_attr( $shortcode ),
				esc_html__( 'کپی', 'falnic-secure-hls' )
			);
			printf(
				'<label class="falnic-ve-tool"><span>%s</span><span class="falnic-copy-row"><input type="text" readonly value="%s" class="falnic-copy-input" data-falnic-copy><button type="button" class="button falnic-copy-btn" data-falnic-copy-btn>%s</button></span></label>',
				esc_html__( 'لینک امن', 'falnic-secure-hls' ),
				esc_attr( $embed_url ),
				esc_html__( 'کپی', 'falnic-secure-hls' )
			);
			echo '</div>';
			echo '<p class="falnic-ve-hint">' . esc_html__( 'این لینک/شورت‌کد فقط برای کسانی که در دوره‌ی متصل ثبت‌نام کرده‌اند کار می‌کند. در مرورگر ناشناس بدون لاگین، خطای «برای تماشا ثبت‌نام کنید» می‌دهد.', 'falnic-secure-hls' ) . '</p>';
		}

		// لینک پیش‌نمایش سریع وقتی آماده است.
		if ( $encoded && current_user_can( 'read' ) ) {
			$preview = add_query_arg(
				array(
					'falnic_preview' => $name,
					'post'           => (int) $post->ID,
				),
				admin_url( 'post.php' )
			);
			// فعلاً فقط شورت‌کد کافی است؛ پیش‌نمایش داخل ادمین در فاز بعد فعال می‌شود.
		}

		echo '</div>';
	}

	/**
	 * فیلد آپلود با ظاهر درست.
	 *
	 * @return void
	 */
	private static function render_upload_field(): void {
		echo '<div class="falnic-ve-section">';
		echo '<h3 class="falnic-ve-title">📤 ' . esc_html__( 'آپلود ویدیو', 'falnic-secure-hls' ) . '</h3>';
		echo '<label for="falnic_video_file" class="falnic-ve-drop">';
		echo '<input type="file" name="falnic_video_file" id="falnic_video_file" accept="video/mp4,video/x-m4v,video/quicktime"> ';
		echo '<span class="falnic-ve-drop-text">' . esc_html__( 'فایل MP4/M4V/MOV را بکشید یا کلیک کنید — پس از ذخیره، انکود در پس‌زمینه انجام می‌شود.', 'falnic-secure-hls' ) . '</span>';
		echo '</label>';
		echo '<p class="falnic-ve-hint">' . esc_html__( 'عنوان فارسی مشکلی ندارد؛ شناسه‌ی فنی ویدیو جدا ذخیره می‌شود و با تغییر عنوان، فایل‌ها گم نمی‌شوند. حداکثر حجم از تنظیمات (پیش‌فرض ۲ گیگ) خوانده می‌شود.', 'falnic-secure-hls' ) . '</p>';
		echo '</div>';
	}

	/**
	 * انتخاب دوره و درس LearnDash برای دسترسی — بازطراحی‌شده.
	 *
	 * @param \WP_Post $post پست.
	 * @param int      $course دوره‌ی فعلی.
	 * @param int      $lesson درس فعلی.
	 * @param string   $name نام ویدیو برای یافتن اتصال‌های معکوس.
	 * @return void
	 */
	private static function render_target( $post ): void {
		// برای سازگاری: تست‌ها این متد را با یک آرگومان صدا می‌زنند.
		$args = func_get_args();
		$post = $args[0];
		$course = isset( $args[1] ) ? (int) $args[1] : (int) get_post_meta( $post->ID, Access::COURSE_META, true );
		$lesson = isset( $args[2] ) ? (int) $args[2] : (int) get_post_meta( $post->ID, Access::STEP_META, true );
		$name   = isset( $args[3] ) ? (string) $args[3] : (string) Post_Type::video_name( $post );

		self::render_access( $post, $course, $lesson, $name );
	}

	/**
	 * بخش دسترسی — چه کسی می‌بیند؟ چه درس‌هایی این ویدیو را دارند؟
	 *
	 * @param \WP_Post $post پست.
	 * @param int      $course دوره.
	 * @param int      $lesson درس.
	 * @param string   $name نام ویدیو.
	 * @return void
	 */
	private static function render_access( $post, int $course, int $lesson, string $name ): void {
		$has_access = $course > 0 || $lesson > 0;
		$ld_active  = Learn_Dash::is_active();

		echo '<div class="falnic-ve-section falnic-ve-access">';
		echo '<h3 class="falnic-ve-title">🔐 ' . esc_html__( 'دسترسی — چه کسی می‌تواند ببیند؟', 'falnic-secure-hls' ) . '</h3>';

		if ( ! $ld_active ) {
			echo '<div class="falnic-ve-notice falnic-ve-notice-warn">⚠️ ' . esc_html__( 'LearnDash فعال نیست. تا فعال‌سازی، ویدیو فقط برای مدیران قابل پخش است و اتصال به دوره ذخیره می‌شود ولی اعمال نمی‌شود.', 'falnic-secure-hls' ) . '</div>';
		}

		// خلاصه‌ی وضعیت دسترسی.
		echo '<div class="falnic-ve-summary">';

		if ( $lesson > 0 ) {
			$lesson_title = get_the_title( $lesson );
			$owner        = (int) get_post_meta( $lesson, 'course_id', true );
			$course_title = $owner > 0 ? get_the_title( $owner ) : ( $course > 0 ? get_the_title( $course ) : '' );

			echo '<p class="falnic-ve-summary-line">🔒 ' . esc_html__( 'این ویدیو فقط برای ثبت‌نام‌شدگان', 'falnic-secure-hls' ) . ' <strong>' . esc_html( $lesson_title ? $lesson_title . ( $course_title ? ' — ' . $course_title : '' ) : __( 'درس انتخاب‌شده', 'falnic-secure-hls' ) ) . '</strong> ' . esc_html__( 'قابل پخش است (مدیران همیشه دسترسی دارند).', 'falnic-secure-hls' ) . '</p>';
			if ( $owner > 0 ) {
				printf(
					'<p class="falnic-ve-hint">%s</p>',
					esc_html__( 'دوره به‌صورت خودکار از درس انتخاب‌شده خوانده می‌شود؛ نیازی به انتخاب جداگانه نیست.', 'falnic-secure-hls' )
				);
			}
		} elseif ( $course > 0 ) {
			$course_title = get_the_title( $course );
			echo '<p class="falnic-ve-summary-line">🔒 ' . esc_html__( 'این ویدیو فقط برای دانشجویان', 'falnic-secure-hls' ) . ' <strong>' . esc_html( $course_title ? $course_title : __( 'دوره‌ی انتخاب‌شده', 'falnic-secure-hls' ) ) . '</strong> ' . esc_html__( 'قابل پخش است.', 'falnic-secure-hls' ) . '</p>';
			echo '<p class="falnic-ve-hint">' . esc_html__( 'برای محدود کردن به یک درس خاص (Drip/پیش‌نیاز)، همان درس را هم انتخاب کنید.', 'falnic-secure-hls' ) . '</p>';
		} else {
			echo '<div class="falnic-ve-notice">';
			echo '👁 ' . esc_html__( 'هنوز به هیچ دوره‌ای وصل نیست — فقط مدیران می‌بینند. برای فعال‌سازی محدودیت، پایین یک دوره (یا درس) انتخاب و ذخیره کنید.', 'falnic-secure-hls' );
			echo '</div>';
		}

		echo '</div>';

		if ( ! $ld_active ) {
			echo '</div>';
			return;
		}

		$courses = self::courses();
		$lessons = self::lessons();

		// انتخاب فعلی همیشه در فهرست می‌ماند؛ وگرنه در سایت‌های بزرگ با فهرست
		// محدود، ذخیره‌ی دوباره بی‌صدا اتصال را پاک می‌کرد.
		if ( $course > 0 && ! self::in_list( $courses, $course ) ) {
			$extra = get_post( $course );

			if ( $extra && 'sfwd-courses' === get_post_type( $extra ) ) {
				array_unshift( $courses, $extra );
			}
		}

		if ( $lesson > 0 && ! self::in_list( $lessons, $lesson ) ) {
			$extra = get_post( $lesson );

			if ( $extra && in_array( (string) get_post_type( $extra ), Access::STEP_TYPES, true ) ) {
				array_unshift( $lessons, $extra );
			}
		}

		echo '<div class="falnic-ve-grid">';

		echo '<label class="falnic-ve-field">';
		echo '<span class="falnic-ve-label">' . esc_html__( 'دوره', 'falnic-secure-hls' ) . '</span>';
		echo '<span class="falnic-ve-hint">' . esc_html__( 'برای «تمام دوره» آن را روی “کل دوره” بگذارید و فقط دوره را پر کنید.', 'falnic-secure-hls' ) . '</span>';
		echo '<span class="falnic-ve-select-wrap" data-falnic-search>';
		echo '<input type="search" class="falnic-ve-search" placeholder="' . esc_attr__( 'جست‌وجوی دوره…', 'falnic-secure-hls' ) . '" aria-label="' . esc_attr__( 'جست‌وجوی دوره', 'falnic-secure-hls' ) . '">';
		echo '<select name="falnic_course_id" id="falnic_course_id" data-falnic-select>';
		echo '<option value="0">' . esc_html__( '— انتخاب دوره —', 'falnic-secure-hls' ) . '</option>';

		foreach ( $courses as $item ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $item->ID,
				selected( $course, (int) $item->ID, false ),
				esc_html( get_the_title( $item ) )
			);
		}

		echo '</select></span>';
		echo '</label>';

		echo '<label class="falnic-ve-field">';
		echo '<span class="falnic-ve-label">' . esc_html__( 'درس', 'falnic-secure-hls' ) . '</span>';
		echo '<span class="falnic-ve-hint">' . esc_html__( 'اختیاری — اگر خالی بماند، تمام دروس دوره دسترسی دارند.', 'falnic-secure-hls' ) . '</span>';
		echo '<span class="falnic-ve-select-wrap" data-falnic-search>';
		echo '<input type="search" class="falnic-ve-search" placeholder="' . esc_attr__( 'جست‌وجوی درس…', 'falnic-secure-hls' ) . '" aria-label="' . esc_attr__( 'جست‌وجوی درس', 'falnic-secure-hls' ) . '">';
		echo '<select name="falnic_lesson_id" id="falnic_lesson_id" data-falnic-select>';
		echo '<option value="0">' . esc_html__( '— کل دوره —', 'falnic-secure-hls' ) . '</option>';

		foreach ( $lessons as $item ) {
			$label = get_the_title( $item );
			$owner = (int) get_post_meta( $item->ID, 'course_id', true );

			if ( $owner > 0 ) {
				$label .= ' — ' . get_the_title( $owner );
			}

			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $item->ID,
				selected( $lesson, (int) $item->ID, false ),
				esc_html( $label )
			);
		}

		echo '</select></span>';
		echo '</label>';

		echo '</div>';

		// لینک‌های مدیریتی.
		if ( $course > 0 ) {
			$edit_course = get_edit_post_link( $course );
			if ( $edit_course ) {
				printf(
					'<p class="falnic-ve-hint">↗ <a href="%s">%s</a></p>',
					esc_url( $edit_course ),
					esc_html__( 'ویرایش دوره در LearnDash', 'falnic-secure-hls' )
				);
			}
		}

		if ( $lesson > 0 ) {
			$edit_lesson = get_edit_post_link( $lesson );
			if ( $edit_lesson ) {
				printf(
					'<p class="falnic-ve-hint">↗ <a href="%s">%s</a></p>',
					esc_url( $edit_lesson ),
					esc_html__( 'ویرایش درس', 'falnic-secure-hls' )
				);
			}
		}

		// درس‌هایی که این ویدیو را دارند (اتصال معکوس از LearnDash).
		if ( '' !== $name ) {
			$linked = Access::linked_lesson_ids( $name );

			if ( ! empty( $linked ) ) {
				echo '<div class="falnic-ve-linked">';
				echo '<strong>' . esc_html__( 'درس‌هایی که همین ویدیو را نمایش می‌دهند:', 'falnic-secure-hls' ) . '</strong>';
				echo '<ul class="falnic-ve-linked-list">';
				foreach ( array_slice( $linked, 0, 10 ) as $lesson_id ) {
					$title = get_the_title( $lesson_id );
					$link  = get_edit_post_link( $lesson_id );
					printf(
						'<li><a href="%s">%s</a> <code>#%d</code></li>',
						esc_url( $link ? $link : get_permalink( $lesson_id ) ),
						esc_html( $title ? $title : sprintf( __( 'درس %d', 'falnic-secure-hls' ), (int) $lesson_id ) ),
						(int) $lesson_id
					);
				}
				if ( count( $linked ) > 10 ) {
					printf(
						'<li class="falnic-ve-hint">%s</li>',
						esc_html( sprintf( __( 'و %d مورد دیگر…', 'falnic-secure-hls' ), count( $linked ) - 10 ) )
					);
				}
				echo '</ul>';
				echo '<p class="falnic-ve-hint">' . esc_html__( 'این‌ها از کادر «ویدیوی امن این درس» در ویرایشگر درس وصل شده‌اند. برای حذف، همان درس را ویرایش کنید.', 'falnic-secure-hls' ) . '</p>';
				echo '</div>';
			} else {
				if ( '' !== $name && $has_access ) {
					echo '<p class="falnic-ve-hint">' . esc_html__( 'هنوز هیچ درسی این ویدیو را به‌صورت مستقیم embed نکرده؛ دسترسی از همین صفحه (دوره/درس بالا) اعمال می‌شود.', 'falnic-secure-hls' ) . '</p>';
				}
			}
		}

		echo '</div>';
	}

	/**
	 * آیا پستی با این شناسه در فهرست هست؟
	 *
	 * @param array $posts فهرست پست‌ها.
	 * @param int   $id    شناسه.
	 * @return bool
	 */
	private static function in_list( array $posts, int $id ): bool {
		foreach ( $posts as $post ) {
			if ( (int) $post->ID === $id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * دوره‌های LearnDash.
	 *
	 * @return array
	 */
	private static function courses(): array {
		return (array) get_posts(
			array(
				'post_type'        => 'sfwd-courses',
				'post_status'      => array( 'publish', 'private' ),
				'posts_per_page'   => 200,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
	}

	/**
	 * درس‌ها و مباحث LearnDash.
	 *
	 * @return array
	 */
	private static function lessons(): array {
		return (array) get_posts(
			array(
				'post_type'        => Access::STEP_TYPES,
				'post_status'      => array( 'publish', 'private' ),
				'posts_per_page'   => 500,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
	}

	/**
	 * نمایش وضعیت صف انکود با نوار پیشرفت.
	 *
	 * @param \WP_Post $post پست.
	 * @param string   $name نام ویدیو.
	 * @return void
	 */
	private static function render_status( $post, string $name ): void {
		if ( '' === $name ) {
			echo '<div class="falnic-ve-notice">' . esc_html__( 'این ویدیو هنوز شناسه ندارد — عنوان را بنویسید و یک‌بار ذخیره کنید تا شناسه ساخته شود.', 'falnic-secure-hls' ) . '</div>';
			return;
		}

		$status = Encode_Queue::status( $name );
		$active = in_array( $status['state'], array( 'queued', 'running' ), true );

		printf(
			'<div class="falnic-encode" data-falnic-encode data-post="%1$d" data-nonce="%2$s" data-status-url="%3$s" data-cancel-url="%4$s" data-retry-url="%5$s" data-active="%6$s">',
			(int) $post->ID,
			esc_attr( wp_create_nonce( 'falnic_encode_status' ) ),
			esc_url( admin_url( 'admin-ajax.php?action=falnic_encode_status' ) ),
			esc_url( admin_url( 'admin-ajax.php?action=falnic_encode_cancel' ) ),
			esc_url( admin_url( 'admin-ajax.php?action=falnic_encode_retry' ) ),
			$active ? '1' : '0'
		);

		printf(
			'<div class="falnic-encode-bar"><span style="width:%d%%"></span></div>',
			(int) $status['percent']
		);

		printf(
			'<p class="falnic-encode-message">%s</p>',
			esc_html( (string) $status['message'] )
		);

		echo '<p class="falnic-encode-actions">';
		printf(
			'<button type="button" class="button falnic-encode-cancel"%s>%s</button> ',
			$active ? '' : ' hidden',
			esc_html__( 'لغو انکود', 'falnic-secure-hls' )
		);
		printf(
			'<button type="button" class="button falnic-encode-retry"%s>%s</button>',
			in_array( $status['state'], array( 'failed', 'canceled' ), true ) ? '' : ' hidden',
			esc_html__( 'تلاش دوباره', 'falnic-secure-hls' )
		);
		echo '</p>';

		if ( ! empty( $status['errors'] ) && 'failed' === $status['state'] ) {
			printf(
				'<details class="falnic-encode-log"><summary>%s</summary><pre>%s</pre></details>',
				esc_html__( 'جزئیات خطا', 'falnic-secure-hls' ),
				esc_html( (string) $status['errors'] )
			);
		}

		echo '</div>';
	}

	/**
	 * کارت بینندگان — چه کسی این ویدیو را دیده؟
	 *
	 * @param string $name نام ویدیو.
	 * @return void
	 */
	private static function render_viewers( string $name ): void {
		echo '<div class="falnic-ve-section falnic-ve-viewers">';
		echo '<h3 class="falnic-ve-title">👁 ' . esc_html__( 'کسانی که این ویدیو را دیده‌اند', 'falnic-secure-hls' ) . ' <span class="falnic-ve-badge">' . esc_html__( '۳۰ روز اخیر', 'falnic-secure-hls' ) . '</span></h3>';

		if ( '' === $name ) {
			echo '<p class="falnic-ve-hint">' . esc_html__( 'پس از ذخیره و اولین پخش، این بخش پر می‌شود — بدون شناسه، گزارشی وجود ندارد.', 'falnic-secure-hls' ) . '</p>';
			echo '</div>';
			return;
		}

		if ( ! Watch_Log::exists() ) {
			echo '<div class="falnic-ve-notice falnic-ve-notice-warn">' . esc_html__( 'جدول گزارش هنوز ساخته نشده است. افزونه را یک‌بار غیرفعال و دوباره فعال کنید.', 'falnic-secure-hls' ) . '</div>';
			echo '</div>';
			return;
		}

		$stats = Watch_Log::stats_for_video( $name, 30 );
		$rows  = Watch_Log::recent_for_video( $name, 30, 12 );

		echo '<div class="falnic-ve-stats">';
		printf(
			'<div class="falnic-ve-stat"><strong>%s</strong><span>%s</span></div>',
			esc_html( number_format_i18n( (int) $stats['unique'] ) ),
			esc_html__( 'نفر یکتا', 'falnic-secure-hls' )
		);
		printf(
			'<div class="falnic-ve-stat"><strong>%s</strong><span>%s</span></div>',
			esc_html( number_format_i18n( (int) $stats['total'] ) ),
			esc_html__( 'پخش کل', 'falnic-secure-hls' )
		);
		printf(
			'<div class="falnic-ve-stat"><strong>%s</strong><span>%s</span></div>',
			esc_html( number_format_i18n( (int) $stats['today'] ) ),
			esc_html__( 'امروز', 'falnic-secure-hls' )
		);
		if ( $stats['avg_seconds'] > 0 ) {
			printf(
				'<div class="falnic-ve-stat"><strong>%s</strong><span>%s</span></div>',
				esc_html( self::human_minutes( (int) $stats['avg_seconds'] ) ),
				esc_html__( 'میانگین تماشا', 'falnic-secure-hls' )
			);
		}
		echo '</div>';

		if ( empty( $rows ) ) {
			echo '<div class="falnic-ve-empty">';
			echo '<p>' . esc_html__( 'هنوز کسی این ویدیو را در ۳۰ روز اخیر ندیده است. پس از اولین پخش واقعی (با لاگین)، همین‌جا نام، ایمیل و زمان تماشا نمایش داده می‌شود.', 'falnic-secure-hls' ) . '</p>';
			echo '<p class="falnic-ve-hint">' . esc_html__( 'نکتهٔ حریم خصوصی: IP فقط به‌صورت هش یک‌طرفه ذخیره می‌شود — مقدار خام IP هرگز نگه داشته نمی‌شود.', 'falnic-secure-hls' ) . '</p>';
			echo '</div>';
		} else {
			echo '<div class="falnic-ve-table-wrap">';
			echo '<table class="widefat striped falnic-ve-table"><thead><tr>';
			echo '<th>' . esc_html__( 'کاربر', 'falnic-secure-hls' ) . '</th>';
			echo '<th>' . esc_html__( 'آخرین تماشا', 'falnic-secure-hls' ) . '</th>';
			echo '<th>' . esc_html__( 'مدت', 'falnic-secure-hls' ) . '</th>';
			echo '<th>' . esc_html__( 'سگمنت', 'falnic-secure-hls' ) . '</th>';
			echo '<th>' . esc_html__( 'دستگاه', 'falnic-secure-hls' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				$user_id = (int) ( $row['user_id'] ?? 0 );
				$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
				$seconds = (int) ( $row['seconds'] ?? 0 );
				$ago     = isset( $row['last_seen'] ) ? self::human_diff( (string) $row['last_seen'] ) : '';

				echo '<tr>';
				echo '<td class="falnic-ve-user">';
				if ( $user ) {
					$profile = get_edit_user_link( $user_id );
					printf(
						'<a href="%s"><strong>%s</strong></a><br><small>%s</small>',
						esc_url( $profile ),
						esc_html( $user->user_login ),
						esc_html( $user->user_email )
					);
				} else {
					printf( '<code>#%d</code>', (int) $user_id );
				}
				echo '</td>';
				printf( '<td>%s</td>', esc_html( $ago ? $ago : (string) ( $row['started_at'] ?? '' ) ) );
				printf( '<td>%s</td>', esc_html( self::human_minutes( $seconds ) ) );
				printf( '<td>%s</td>', esc_html( number_format_i18n( (int) ( $row['segments'] ?? 0 ) ) ) );
				printf( '<td>%s</td>', esc_html( (string) ( $row['agent'] ?? '' ) ) );
				echo '</tr>';
			}

			echo '</tbody></table>';
			echo '</div>';

			$report_url = add_query_arg(
				array(
					'post_type' => Post_Type::SLUG,
					'page'      => 'falnic_watch_log',
					'video'     => $name,
					'days'      => 30,
				),
				admin_url( 'edit.php' )
			);
			printf(
				'<p class="falnic-ve-actions"><a href="%s" class="button">%s</a> <span class="falnic-ve-hint">%s</span></p>',
				esc_url( $report_url ),
				esc_html__( 'مشاهدهٔ گزارش کامل این ویدیو', 'falnic-secure-hls' ),
				esc_html__( 'با فیلتر، صفحه‌بندی و خروجی CSV', 'falnic-secure-hls' )
			);
		}

		echo '</div>';
	}

	/**
	 * تبدیل ثانیه به «X دقیقه».
	 *
	 * @param int $seconds ثانیه.
	 * @return string
	 */
	private static function human_minutes( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return '—';
		}

		$min = max( 1, (int) round( $seconds / 60 ) );

		return sprintf(
			/* translators: %s: دقیقه */
			__( '%s دقیقه', 'falnic-secure-hls' ),
			number_format_i18n( $min )
		);
	}

	/**
	 * فاصله‌ی زمانی خوانا نسبت به الان.
	 *
	 * @param string $mysql تاریخ MySQL UTC.
	 * @return string
	 */
	private static function human_diff( string $mysql ): string {
		$time = strtotime( $mysql . ' +00:00' );

		if ( ! $time ) {
			return $mysql;
		}

		if ( function_exists( 'human_time_diff' ) ) {
			return human_time_diff( $time, time() ) . ' ' . __( 'پیش', 'falnic-secure-hls' );
		}

		return $mysql;
	}

	/**
	 * بخش زیرنویس‌ها.
	 *
	 * @param string $name نام ویدیو.
	 * @return void
	 */
	private static function render_subtitles( string $name ): void {
		if ( '' === $name ) {
			return;
		}

		$existing = Storage::subtitles( $name );

		echo '<div class="falnic-ve-section">';
		echo '<h3 class="falnic-ve-title">💬 ' . esc_html__( 'زیرنویس (اختیاری)', 'falnic-secure-hls' ) . '</h3>';

		if ( ! empty( $existing ) ) {
			echo '<ul class="falnic-subtitle-list">';

			foreach ( $existing as $item ) {
				printf(
					'<li><label><input type="checkbox" name="falnic_subtitle_delete[]" value="%1$s"> %2$s</label></li>',
					esc_attr( $item['lang'] ),
					esc_html( $item['label'] . ' — ' . $item['lang'] . '.vtt' )
				);
			}

			echo '</ul>';
		}

		echo '<p><label for="falnic-subtitle-lang">' . esc_html__( 'زبان', 'falnic-secure-hls' ) . '</label> ';
		echo '<input type="text" id="falnic-subtitle-lang" name="falnic_subtitle_lang" value="fa" size="5" placeholder="fa"> ';
		echo '<input type="file" name="falnic_subtitle" accept=".vtt,text/vtt,text/plain"></p>';
		echo '</div>';
	}

	/**
	 * پردازش ذخیره‌ی پست.
	 *
	 * @param int $post_id شناسه‌ی پست.
	 * @return void
	 */
	public static function save( $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// عنوان پست هرگز بازنویسی نمی‌شود؛ شناسه‌ی پایدار در متا نگه داشته می‌شود (F-11).
		// این کار به nonce نیاز ندارد چون فقط از عنوانِ خودِ پست مشتق می‌شود.
		$name = self::resolve_name( $post );

		Post_Type::set_video_name( $post_id, $name );

		if ( ! isset( $_POST['falnic_video_upload_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['falnic_video_upload_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'falnic_video_upload' ) ) {
			return;
		}

		self::save_target( (int) $post_id );

		self::save_subtitles( $post, $name );

		if ( ! isset( $_FILES['falnic_video_file'] ) ) {
			return;
		}

		$file = $_FILES['falnic_video_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return;
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			self::fail_upload(
				sprintf(
					/* translators: %d: کد خطای آپلود */
					__( 'آپلود فایل ناموفق بود (کد خطا: %d).', 'falnic-secure-hls' ),
					$error
				)
			);

			return;
		}

		$tmp_name = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';

		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return;
		}

		$max_mb = max( 1, (int) get_option( 'falnic_max_upload_mb', 2048 ) );
		$size   = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( $size > $max_mb * MB_IN_BYTES ) {
			self::fail_upload(
				sprintf(
					/* translators: %s: حداکثر حجم به مگابایت */
					__( 'حجم فایل بیش از حد مجاز است (حداکثر %s مگابایت).', 'falnic-secure-hls' ),
					number_format_i18n( $max_mb )
				)
			);

			return;
		}

		$allowed = array(
			'mp4' => 'video/mp4',
			'm4v' => 'video/x-m4v',
			'mov' => 'video/quicktime',
		);

		$type = wp_check_filetype_and_ext( $tmp_name, (string) ( $file['name'] ?? '' ), $allowed );

		if ( empty( $type['ext'] ) || ! isset( $allowed[ $type['ext'] ] ) ) {
			self::fail_upload( __( 'فقط فایل‌های ویدیویی MP4/M4V/MOV پذیرفته می‌شوند.', 'falnic-secure-hls' ) );

			return;
		}

		$queued = Encode_Queue::enqueue( $name, $tmp_name, (int) $post_id, (int) get_current_user_id() );

		if ( empty( $queued['ok'] ) ) {
			self::fail_upload( $queued['message'] );
		}
	}

	/**
	 * ذخیره‌ی اتصال ویدیو به دوره و درس.
	 *
	 * @param int $post_id شناسه‌ی پست.
	 * @return void
	 */
	private static function save_target( int $post_id ): void {
		if ( ! isset( $_POST['falnic_course_id'] ) && ! isset( $_POST['falnic_lesson_id'] ) ) {
			return;
		}

		$course = isset( $_POST['falnic_course_id'] ) ? absint( wp_unslash( $_POST['falnic_course_id'] ) ) : 0;
		$lesson = isset( $_POST['falnic_lesson_id'] ) ? absint( wp_unslash( $_POST['falnic_lesson_id'] ) ) : 0;

		$course = 'sfwd-courses' === get_post_type( $course ) ? $course : 0;
		$lesson = in_array( (string) get_post_type( $lesson ), Access::STEP_TYPES, true ) ? $lesson : 0;

		// اگر درس انتخاب شده باشد، دوره‌ی همان درس مرجع است تا هرگز
		// ترکیب ناسازگار «دوره‌ی الف + درس دوره‌ی ب» ذخیره نشود.
		if ( $lesson > 0 ) {
			$owner = (int) get_post_meta( $lesson, 'course_id', true );

			if ( $owner > 0 ) {
				$course = $owner;
			}
		}

		if ( $course > 0 ) {
			update_post_meta( $post_id, Access::COURSE_META, $course );
		} else {
			delete_post_meta( $post_id, Access::COURSE_META );
		}

		if ( $lesson > 0 ) {
			update_post_meta( $post_id, Access::STEP_META, $lesson );
		} else {
			delete_post_meta( $post_id, Access::STEP_META );
		}

		Access::flush();
	}

	/**
	 * ذخیره یا حذف زیرنویس‌های ویدیو.
	 *
	 * @param \WP_Post $post پست.
	 * @param string   $name نام ویدیو.
	 * @return void
	 */
	private static function save_subtitles( $post, string $name ): void {
		if ( '' === $name ) {
			return;
		}

		$dir = Storage::subtitle_dir( $name );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( ! empty( $_POST['falnic_subtitle_delete'] ) ) {
			foreach ( (array) wp_unslash( $_POST['falnic_subtitle_delete'] ) as $lang ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$lang = Storage::sanitize_lang( (string) $lang );
				$path = Storage::subtitle_path( $name, $lang );

				if ( '' !== $path && file_exists( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}

		if ( empty( $_FILES['falnic_subtitle'] ) || ! isset( $_FILES['falnic_subtitle']['tmp_name'] ) ) {
			return;
		}

		$file = $_FILES['falnic_subtitle']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tmp  = (string) $file['tmp_name'];

		if ( ! empty( $file['error'] ) || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return;
		}

		if ( (int) ( $file['size'] ?? 0 ) > 5 * MB_IN_BYTES ) {
			self::fail_upload( __( 'حجم فایل زیرنویس بیش از ۵ مگابایت است.', 'falnic-secure-hls' ) );

			return;
		}

		$ext = strtolower( (string) pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );

		if ( 'vtt' !== $ext ) {
			self::fail_upload( __( 'فقط فایل زیرنویس با پسوند .vtt پذیرفته می‌شود.', 'falnic-secure-hls' ) );

			return;
		}

		$lang = Storage::sanitize_lang( (string) ( $_POST['falnic_subtitle_lang'] ?? 'fa' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$lang = '' !== $lang ? $lang : 'fa';

		$content = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( 0 !== strpos( ltrim( $content ), 'WEBVTT' ) ) {
			self::fail_upload( __( 'ساختار فایل زیرنویس معتبر نیست (باید با WEBVTT شروع شود).', 'falnic-secure-hls' ) );

			return;
		}

		file_put_contents( Storage::subtitle_path( $name, $lang ), $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		wp_delete_file( $tmp );
	}

	/**
	 * ثبت خطای آپلود برای نمایش به ادمین.
	 *
	 * @param string $message پیام.
	 * @return void
	 */
	private static function fail_upload( string $message ): void {
		set_transient( 'falnic_encode_error_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * تبدیل عنوان پست به نام امن برای ffmpeg و پوشه.
	 *
	 * @param string $title   عنوان پست.
	 * @param int    $post_id شناسه‌ی پست.
	 * @return string
	 */
	public static function normalize_name( string $title, int $post_id ): string {
		$name = Storage::sanitize_name( $title );

		if ( '' === $name ) {
			$name = 'video-' . $post_id;
		}

		return $name;
	}

	/**
	 * تعیین نام قطعی ویدیو هنگام ذخیره.
	 *
	 * قاعده: اگر ویدیو قبلاً انکود شده باشد، شناسه‌ی موجود حفظ می‌شود تا با
	 * تغییر عنوان، فایل‌های رمزنگاری‌شده گم نشوند. در غیر این صورت شناسه از
	 * روی عنوان ساخته می‌شود.
	 *
	 * @param \WP_Post $post پست.
	 * @return string
	 */
	private static function resolve_name( $post ): string {
		$existing = Post_Type::video_name( $post );

		if ( '' !== $existing && Storage::is_encoded( $existing ) ) {
			return $existing;
		}

		if ( '' !== $existing && '' === Storage::sanitize_name( $post->post_title ) ) {
			return $existing;
		}

		return self::normalize_name( (string) $post->post_title, (int) $post->ID );
	}
}
