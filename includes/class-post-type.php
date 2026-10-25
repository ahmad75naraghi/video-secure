<?php
/**
 * پست‌تایپ اختصاصی ویدیوهای امن.
 *
 * @package Falnic\SecureHLS
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ثبت CPT و ستون‌های لیست مدیریت.
 */
final class Post_Type {

	/**
	 * نام پست‌تایپ.
	 */
	public const SLUG = 'secure_video';

	/**
	 * کلید متایی که نام پایدار ویدیو (نام پوشه/شناسه‌ی استریم) را نگه می‌دارد.
	 */
	public const META_NAME = 'falnic_video_name';

	/**
	 * ثبت هوک‌ها.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'manage_' . self::SLUG . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::SLUG . '_posts_custom_column', array( __CLASS__, 'column_data' ), 10, 2 );
	}

	/**
	 * ثبت پست‌تایپ.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_post_type(
			self::SLUG,
			array(
				'labels'        => array(
					'name'          => __( 'ویدیوهای امن', 'falnic-secure-hls' ),
					'singular_name' => __( 'ویدیوی امن', 'falnic-secure-hls' ),
					'add_new'       => __( 'افزودن ویدیوی جدید', 'falnic-secure-hls' ),
					'add_new_item'  => __( 'افزودن ویدیوی امن', 'falnic-secure-hls' ),
					'edit_item'     => __( 'ویرایش ویدیوی امن', 'falnic-secure-hls' ),
					'search_items'  => __( 'جست‌وجوی ویدیو', 'falnic-secure-hls' ),
					'not_found'     => __( 'ویدیویی یافت نشد', 'falnic-secure-hls' ),
				),
				'public'        => false,
				'show_ui'       => true,
				'menu_position' => 6,
				'menu_icon'     => 'dashicons-video-alt3',
				'supports'      => array( 'title' ),
			)
		);
	}

	/**
	 * افزودن ستون‌های اختصاصی.
	 *
	 * @param array $columns ستون‌های فعلی.
	 * @return array
	 */
	public static function columns( $columns ) {
		$columns['falnic_shortcode'] = __( 'شورت‌کد', 'falnic-secure-hls' );
		$columns['falnic_embed']     = __( 'لینک Embed (مخصوص LearnDash)', 'falnic-secure-hls' );

		return $columns;
	}

	/**
	 * محتوای ستون‌ها.
	 *
	 * @param string $column  نام ستون.
	 * @param int    $post_id شناسه‌ی پست.
	 * @return void
	 */
	public static function column_data( $column, $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$name = self::video_name( $post );

		if ( 'falnic_shortcode' === $column ) {
			printf(
				'<code>[secure_video name="%s"]</code>',
				esc_html( $name )
			);
		}

		if ( 'falnic_embed' === $column ) {
			$url = add_query_arg( 'falnic_embed', $name, home_url( '/' ) );
			printf(
				'<input type="text" readonly value="%s" style="width:100%%;font-size:11px;" title="%s" onclick="this.select();">',
				esc_url( $url ),
				esc_attr__( 'این لینک فقط برای دانشجویان ثبت‌نام‌شده در دوره‌ی مرتبط باز می‌شود.', 'falnic-secure-hls' )
			);
		}
	}

	/**
	 * نام پایدار ویدیو (شناسه‌ی پوشه و توکن‌ها).
	 *
	 * اگر متا موجود باشد از آن استفاده می‌شود تا تغییر عنوان پست، فایل‌های
	 * رمزنگاری‌شده را از دسترس خارج نکند. برای نصب‌های قدیمی که متا ندارند،
	 * عنوان پست مبنای محاسبه است (سازگاری کامل با ویدیوهای موجود).
	 *
	 * @param \WP_Post|int $post پست یا شناسه‌ی آن.
	 * @return string
	 */
	public static function video_name( $post ): string {
		$post = get_post( $post );

		if ( ! $post ) {
			return '';
		}

		$stored = (string) get_post_meta( $post->ID, self::META_NAME, true );
		$stored = Storage::sanitize_name( $stored );

		if ( '' !== $stored ) {
			return $stored;
		}

		return Storage::sanitize_name( $post->post_title );
	}

	/**
	 * یافتن پست ویدیو از روی نام پایدار.
	 *
	 * @param string $name نام ویدیو.
	 * @return int شناسه‌ی پست یا صفر.
	 */
	public static function find_by_name( string $name ): int {
		$name = Storage::sanitize_name( $name );

		if ( '' === $name ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'        => self::SLUG,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'         => self::META_NAME,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => $name,
			)
		);

		if ( ! empty( $posts ) ) {
			return (int) $posts[0];
		}

		// Fallback برای ویدیوهای قدیمی که META_NAME هنوز ذخیره نشده و نام از post_title مشتق است.
		$all = get_posts(
			array(
				'post_type'        => self::SLUG,
				'post_status'      => 'any',
				'posts_per_page'   => 200,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		foreach ( $all as $pid ) {
			if ( $name === self::video_name( $pid ) ) {
				return (int) $pid;
			}
		}

		return 0;
	}

	/**
	 * ذخیره‌ی نام پایدار ویدیو.
	 *
	 * @param int    $post_id شناسه‌ی پست.
	 * @param string $name    نام ویدیو.
	 * @return void
	 */
	public static function set_video_name( int $post_id, string $name ): void {
		$name = Storage::sanitize_name( $name );

		if ( '' === $name ) {
			return;
		}

		if ( $name !== (string) get_post_meta( $post_id, self::META_NAME, true ) ) {
			update_post_meta( $post_id, self::META_NAME, $name );
		}
	}
}
