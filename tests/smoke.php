<?php
/**
 * تست دود (smoke test) برای بررسی سلامت نصب.
 *
 * اجرا:
 *   wp eval-file tests/smoke.php
 *
 * این فایل بخشی از پلاگین نیست و فقط برای عیب‌یابی روی سرور است.
 *
 * @package Falnic\SecureHLS
 */

use Falnic\SecureHLS\Access;
use Falnic\SecureHLS\Assets;
use Falnic\SecureHLS\Encoder;
use Falnic\SecureHLS\Learn_Dash;
use Falnic\SecureHLS\Storage;
use Falnic\SecureHLS\Stream;
use Falnic\SecureHLS\Token;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "این فایل را با wp eval-file اجرا کنید.\n" );
	exit( 1 );
}

$pass = 0;
$fail = 0;

/**
 * چاپ نتیجه.
 *
 * @param bool   $ok    نتیجه.
 * @param string $label برچسب.
 * @param string $note  توضیح.
 * @return void
 */
function falnic_smoke_assert( $ok, $label, $note = '' ) {
	global $pass, $fail;

	if ( $ok ) {
		$pass++;
		echo "PASS  {$label}\n";
	} else {
		$fail++;
		echo "FAIL  {$label}" . ( $note ? " :: {$note}" : '' ) . "\n";
	}
}

echo "\n=== Falnic Secure HLS — smoke test ===\n\n";

falnic_smoke_assert( class_exists( Storage::class ), 'کلاس Storage بارگذاری می‌شود' );
falnic_smoke_assert( class_exists( Encoder::class ), 'کلاس Encoder بارگذاری می‌شود' );
falnic_smoke_assert( class_exists( Assets::class ), 'کلاس Assets بارگذاری می‌شود' );

falnic_smoke_assert( function_exists( 'exec' ), 'تابع exec در دسترس است' );

$secure_dir = Storage::secure_dir();
falnic_smoke_assert(
	0 !== strpos( wp_normalize_path( $secure_dir ), wp_normalize_path( ABSPATH ) ),
	'پوشه‌ی کلیدها خارج از web root است',
	$secure_dir
);
falnic_smoke_assert( is_dir( $secure_dir ) && wp_is_writable( $secure_dir ), 'پوشه‌ی کلیدها قابل نوشتن است', $secure_dir );
falnic_smoke_assert( is_dir( Storage::videos_dir() ) && wp_is_writable( Storage::videos_dir() ), 'پوشه‌ی ویدیوها قابل نوشتن است', Storage::videos_dir() );

// دروازه‌ی استریم.
falnic_smoke_assert( class_exists( Stream::class ) && class_exists( Token::class ) && class_exists( Access::class ), 'کلاس‌های دروازه و دسترسی بارگذاری می‌شوند' );
falnic_smoke_assert( Storage::is_outside_webroot(), 'ریشه‌ی رسانه بیرون از web root است', Storage::root() );
falnic_smoke_assert( file_exists( Storage::root() . '.htaccess' ), 'فایل .htaccess محافظ ریشه‌ی رسانه موجود است' );

$token = Token::issue( Token::SCOPE_MANIFEST, 'smoke-test' );
falnic_smoke_assert( is_array( Token::verify( $token, Token::SCOPE_MANIFEST, 'smoke-test' ) ), 'صدور و بررسی توکن کار می‌کند' );
falnic_smoke_assert( is_wp_error( Token::verify( $token, Token::SCOPE_KEY, 'smoke-test' ) ), 'توکن با scope اشتباه رد می‌شود' );

if ( ! Learn_Dash::is_active() ) {
	echo "WARN  LearnDash فعال نیست؛ ویدیوهای متصل به درس فقط برای مدیران باز می‌شوند.\n";
}

$pending = Storage::legacy_pending();
falnic_smoke_assert( empty( $pending ), 'همه‌ی ویدیوها در پوشه‌ی امن هستند', implode( ', ', $pending ) );

// دارایی‌های محلی.
foreach ( array( 'assets/vendor/hls/hls.min.js', 'assets/vendor/plyr/plyr.min.js', 'assets/vendor/plyr/plyr.css', 'assets/js/player.js', 'assets/css/player.css' ) as $asset ) {
	falnic_smoke_assert( is_readable( FALNIC_DIR . $asset ), 'دارایی موجود است: ' . $asset );
}

// بدون eval.
$player_js = (string) file_get_contents( FALNIC_DIR . 'assets/js/player.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
falnic_smoke_assert( false === strpos( $player_js, 'eval(' ), 'کد پلیر بدون eval است' );
falnic_smoke_assert( false === strpos( $player_js, 'debugger' ), 'کد پلیر بدون debugger است' );

// ویدیوهای ثبت‌شده.
$posts = get_posts(
	array(
		'post_type'      => 'secure_video',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

echo "\n-- بررسی " . count( $posts ) . " ویدیو --\n";

foreach ( $posts as $post_id ) {
	$name = get_post_field( 'post_title', $post_id );

	if ( ! Storage::is_encoded( $name ) ) {
		echo "WARN  ویدیو انکود نشده یا فایل‌ها ناقص‌اند: {$name} (post {$post_id})\n";
		continue;
	}

	$key = Storage::key_path( $name );
	$perm = substr( sprintf( '%o', fileperms( $key ) ), -4 );
	falnic_smoke_assert( '600' === $perm || '640' === $perm, "مجوز کلید محدود است: {$name}", "mode={$perm}" );

	$playlist = Storage::video_dir( $name ) . $name . '.m3u8';
	$body     = (string) file_get_contents( $playlist ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	falnic_smoke_assert( false !== strpos( $body, '#EXT-X-KEY' ), "پلی‌لیست شامل EXT-X-KEY است: {$name}" );
}

echo "\nنتیجه: {$pass} موفق، {$fail} ناموفق\n";
exit( $fail > 0 ? 1 : 0 );
