<?php
/**
 * متاباکس آپلود و پردازش ذخیره‌ی پست.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * آپلود فایل MP4 و اجرای انکود.
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
	 * بارگذاری اسکریپت وضعیت صف در صفحه‌ی ویرایش ویدیو.
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
			__( '🎬 آپلود و انکود ویدیو (سیستم اتوماتیک)', 'falnic-secure-hls' ),
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

		$name = Post_Type::video_name( $post );

		if ( '' !== $name && Storage::is_encoded( $name ) ) {
			echo '<div style="background:#e7f5ea;border-left:4px solid #46b450;padding:12px;margin-bottom:15px;">';
			echo '<h4 style="margin:0 0 10px;color:#0a4b16;">' . esc_html__( '✅ ویدیو با موفقیت انکود شده است.', 'falnic-secure-hls' ) . '</h4>';
			printf(
				'<p><strong>%1$s</strong> <code>[secure_video name="%2$s"]</code></p>',
				esc_html__( 'شورت‌کد:', 'falnic-secure-hls' ),
				esc_html( $name )
			);
			echo '</div><hr><p>' . esc_html__( 'برای جایگزینی، می‌توانید فایل جدیدی آپلود کنید:', 'falnic-secure-hls' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'فایل MP4 خود را انتخاب کنید تا به صورت خودکار رمزنگاری شود:', 'falnic-secure-hls' ) . '</p>';
		}

		self::render_status( $post, $name );

		echo '<input type="file" name="falnic_video_file" accept="video/mp4,video/x-m4v" style="width:100%;padding:10px;border:1px dashed #ccc;">';
		echo '<p class="description">' . esc_html__( 'پس از ذخیره، فایل در پس‌زمینه پردازش می‌شود و می‌توانید صفحه را ببندید. پیشرفت کار همین‌جا نمایش داده می‌شود.', 'falnic-secure-hls' ) . '</p>';

		self::render_subtitles( $name );
		if ( '' !== $name ) {
			printf(
				'<p class="description">%1$s <code>%2$s</code></p>',
				esc_html__( 'شناسه‌ی این ویدیو (نام فایل‌ها):', 'falnic-secure-hls' ),
				esc_html( $name )
			);
		}

		echo '<p class="description" style="color:#d63638;font-weight:bold;">';
		echo esc_html__( '⚠️ عنوان ویدیو (کادر بالا) می‌تواند فارسی باشد؛ اما اگر انگلیسی و بدون فاصله بنویسید، شناسه‌ی خواناتری ساخته می‌شود. شناسه پس از اولین ذخیره تغییر نمی‌کند.', 'falnic-secure-hls' );
		echo '</p>';
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

		echo '<hr><h4>' . esc_html__( 'زیرنویس (اختیاری)', 'falnic-secure-hls' ) . '</h4>';

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
			echo '<p class="description">' . esc_html__( 'برای حذف، تیک بزنید و ذخیره کنید.', 'falnic-secure-hls' ) . '</p>';
		}

		echo '<p><label for="falnic-subtitle-lang">' . esc_html__( 'زبان:', 'falnic-secure-hls' ) . '</label> ';
		echo '<input type="text" id="falnic-subtitle-lang" name="falnic_subtitle_lang" value="fa" size="5" placeholder="fa"></p>';
		echo '<input type="file" name="falnic_subtitle" accept=".vtt,text/vtt,text/plain">';
		echo '<p class="description">' . esc_html__( 'فایل WebVTT (.vtt) را انتخاب کنید؛ دسترسی به آن هم از همان دروازه‌ی امن انجام می‌شود.', 'falnic-secure-hls' ) . '</p>';
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
		set_transient( 'falnic_encode_error_' . get_current_user_id(), $message, 60 );
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
