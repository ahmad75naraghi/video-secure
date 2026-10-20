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
				'supports'      => array( 'title', 'editor' ),
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

		$name = $post->post_title;

		if ( 'falnic_shortcode' === $column ) {
			printf(
				'<code>[secure_video name="%s"]</code>',
				esc_html( $name )
			);
		}

		if ( 'falnic_embed' === $column ) {
			$url = add_query_arg( 'falnic_embed', $name, home_url( '/' ) );
			printf(
				'<input type="text" readonly value="%s" style="width:100%%;font-size:11px;" onclick="this.select();">',
				esc_url( $url )
			);
		}
	}
}
