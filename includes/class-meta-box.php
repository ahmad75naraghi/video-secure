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

		$name = $post->post_title;

		if ( $name && Storage::is_encoded( $name ) ) {
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
		echo '<p class="description" style="color:#d63638;font-weight:bold;">';
		echo esc_html__( '⚠️ عنوان ویدیو (کادر بالا) باید کاملاً انگلیسی و بدون فاصله باشد (مثال: lesson-108). اگر فارسی تایپ کنید، سیستم آن را به یک کد اتوماتیک تبدیل می‌کند.', 'falnic-secure-hls' );
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

		if ( ! isset( $_POST['falnic_video_upload_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['falnic_video_upload_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'falnic_video_upload' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$name = self::normalize_name( $post->post_title, $post_id );

		if ( $post->post_title !== $name ) {
			remove_action( 'save_post_' . Post_Type::SLUG, array( __CLASS__, 'save' ) );
			wp_update_post(
				array(
					'ID'        => $post_id,
					'post_title' => $name,
					'post_name'  => $name,
				)
			);
			add_action( 'save_post_' . Post_Type::SLUG, array( __CLASS__, 'save' ) );
		}

		if ( ! isset( $_FILES['falnic_video_file'] ) ) {
			return;
		}

		$file = $_FILES['falnic_video_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return;
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			set_transient(
				'falnic_encode_error_' . get_current_user_id(),
				sprintf(
					/* translators: %d: کد خطای آپلود */
					__( 'آپلود فایل ناموفق بود (کد خطا: %d).', 'falnic-secure-hls' ),
					$error
				),
				60
			);

			return;
		}

		$tmp_name = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';

		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
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
	 * تبدیل عنوان پست به نام امن برای ffmpeg و پوشه.
	 *
	 * @param string $title   عنوان پست.
	 * @param int    $post_id شناسه‌ی پست.
	 * @return string
	 */
	public static function normalize_name( string $title, int $post_id ): string {
		$name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( urldecode( sanitize_title( $title ) ) ) );

		if ( ! is_string( $name ) || '' === $name ) {
			$name = 'video-' . $post_id;
		}

		return $name;
	}
}
