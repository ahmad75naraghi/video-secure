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

		echo '<input type="file" name="falnic_video_file" accept="video/mp4,video/x-m4v" style="width:100%;padding:10px;border:1px dashed #ccc;">';
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

		if ( ! isset( $_POST['falnic_video_upload_nonce'], $_FILES['falnic_video_file'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['falnic_video_upload_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'falnic_video_upload' ) ) {
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

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		ignore_user_abort( true );

		if ( Encoder::encode( $tmp_name, $name ) ) {
			set_transient( 'falnic_encode_success_' . get_current_user_id(), $name, 60 );
		}

		wp_delete_file( $tmp_name );
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
