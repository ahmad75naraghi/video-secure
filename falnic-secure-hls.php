<?php
/**
 * Plugin Name: Falnic Secure HLS Player (Pro)
 * Description: Enterprise HLS AES-128, LearnDash Embed Support, Direct Upload & Encode (Safe URL).
 * Version: 3.1.0
 * Author: Ahmad Naraghi
 * Requires PHP: 7.4
 */

namespace Falnic\SecureHLS;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Core {

    private static ?Core $instance = null;

    private function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'wp_login', [ $this, 'safe_session_control' ], 10, 2 );
        add_action( 'wp_ajax_secure_hls_key', [ $this, 'deliver_key' ] );
        add_shortcode( 'secure_video', [ $this, 'render_player' ] );
        add_action( 'template_redirect', [ $this, 'render_embed_endpoint' ] );

        add_action( 'admin_menu', [ $this, 'register_admin_menus' ] );
        add_action( 'manage_secure_video_posts_custom_column', [ $this, 'custom_video_column_data' ], 10, 2 );
        add_filter( 'manage_secure_video_posts_columns', [ $this, 'custom_video_columns' ] );
        
        add_action( 'post_edit_form_tag', [ $this, 'add_enctype_to_post_form' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_video_meta_box' ] );
        add_action( 'save_post_secure_video', [ $this, 'save_post_video' ] );
        add_action( 'admin_notices', [ $this, 'admin_notices' ] );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'hls encode', __NAMESPACE__ . '\CLI_Command' );
        }
    }

    public static function get_instance(): Core {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function get_secure_dir(): string { return dirname( ABSPATH ) . '/secure_keys/'; }
    public static function get_videos_dir(): string { return wp_upload_dir()['basedir'] . '/secure_videos/'; }
    public static function get_videos_url(): string { return set_url_scheme( wp_upload_dir()['baseurl'], 'https' ) . '/secure_videos/'; }

    public function register_cpt(): void {
        register_post_type( 'secure_video', [
            'labels'      => [ 'name' => 'ویدیوهای امن', 'singular_name' => 'ویدیوی امن', 'add_new' => 'افزودن ویدیوی جدید' ],
            'public'      => false,
            'show_ui'     => true,
            'menu_position' => 6,
            'supports'    => [ 'title', 'editor' ],
            'menu_icon'   => 'dashicons-video-alt3',
        ] );
    }

    public function custom_video_columns( $columns ) {
        $columns['shortcode'] = 'شورت‌کد';
        $columns['embed'] = 'لینک Embed (مخصوص LearnDash)';
        return $columns;
    }

    public function custom_video_column_data( $column, $post_id ) {
        $post = get_post($post_id);
        $video_name = esc_attr($post->post_title);
        
        if ( $column === 'shortcode' ) {
            echo '<code>[secure_video name="' . $video_name . '"]</code>';
        }
        if ( $column === 'embed' ) {
            $embed_url = home_url('/?falnic_embed=' . $video_name);
            echo '<input type="text" readonly value="' . esc_url($embed_url) . '" style="width:100%; font-size:11px;" onclick="this.select();">';
        }
    }

    public function add_enctype_to_post_form() {
        global $post;
        if ( $post && $post->post_type === 'secure_video' ) echo ' enctype="multipart/form-data"';
    }

    public function add_video_meta_box() {
        add_meta_box( 'falnic_video_upload_box', '🎬 آپلود و انکود ویدیو (سیستم اتوماتیک)', [ $this, 'render_video_meta_box' ], 'secure_video', 'normal', 'high' );
    }

    public function render_video_meta_box( $post ) {
        wp_nonce_field( 'falnic_video_upload', 'falnic_video_upload_nonce' );
        $video_name = $post->post_title;
        $video_dir = self::get_videos_dir() . $video_name;
        
        if ( ! empty( $video_name ) && is_dir( $video_dir ) && file_exists( "$video_dir/$video_name.m3u8" ) ) {
            echo '<div style="background:#e7f5ea; border-left:4px solid #46b450; padding:12px; margin-bottom:15px;">';
            echo '<h4 style="margin:0 0 10px 0; color:#0a4b16;">✅ ویدیو با موفقیت انکود شده است.</h4>';
            echo '<p><strong>شورت‌کد:</strong> <code>[secure_video name="' . esc_attr( $video_name ) . '"]</code></p>';
            echo '</div><hr><p>برای جایگزینی، می‌توانید فایل جدیدی آپلود کنید:</p>';
        } else {
            echo '<p>فایل MP4 خود را انتخاب کنید تا به صورت خودکار رمزنگاری شود:</p>';
        }
        
        echo '<input type="file" name="falnic_video_file" accept="video/mp4,video/x-m4v" style="width:100%; padding:10px; border:1px dashed #ccc;">';
        echo '<p class="description" style="color:#d63638; font-weight:bold;">⚠️ عنوان ویدیو (کادر بالا) باید کاملاً انگلیسی و بدون فاصله باشد (مثال: lesson-108). اگر فارسی تایپ کنید، سیستم آن را به یک کد اتوماتیک تبدیل می‌کند.</p>';
    }

    public function save_post_video( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['falnic_video_upload_nonce'] ) || ! wp_verify_nonce( $_POST['falnic_video_upload_nonce'], 'falnic_video_upload' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $post = get_post($post_id);
        
        // جلوگیری از باگ %d در FFmpeg: نام ویدیو باید فقط شامل حروف انگلیسی، عدد و خط تیره باشد
        $raw_title = urldecode( sanitize_title( $post->post_title ) );
        $video_name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $raw_title ) );
        
        // اگر نام بعد از حذف حروف فارسی خالی شد، یک شناسه جایگزین می‌سازیم
        if ( empty( $video_name ) ) {
            $video_name = 'video-' . $post_id;
        }

        // آپدیت نام پست برای همگام‌سازی شورت‌کدها
        if ( $post->post_title !== $video_name ) {
            remove_action( 'save_post_secure_video', [ $this, 'save_post_video' ] );
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $video_name, 'post_name' => $video_name ] );
            add_action( 'save_post_secure_video', [ $this, 'save_post_video' ] );
        }

        if ( isset( $_FILES['falnic_video_file'] ) && $_FILES['falnic_video_file']['error'] === UPLOAD_ERR_OK ) {
            set_time_limit( 0 ); ignore_user_abort( true );
            $tmp_name = $_FILES['falnic_video_file']['tmp_name'];
            
            $secure_dir = self::get_secure_dir() . $video_name;
            $video_dir  = self::get_videos_dir() . $video_name;
            if ( is_dir($video_dir) ) { array_map('unlink', glob("$video_dir/*.*")); }
            if ( is_dir($secure_dir) ) { array_map('unlink', glob("$secure_dir/*.*")); }

            if ( $this->process_encoding( $tmp_name, $video_name ) ) {
                set_transient( 'falnic_encode_success_' . get_current_user_id(), $video_name, 60 );
            }
            @unlink( $tmp_name );
        }
    }

    public function admin_notices() {
        $user_id = get_current_user_id();
        if ( $error = get_transient( 'falnic_encode_error_' . $user_id ) ) {
            echo '<div class="notice notice-error is-dismissible"><p>خطا در پردازش ویدیو:<br>' . esc_html($error) . '</p></div>';
            delete_transient( 'falnic_encode_error_' . $user_id );
        }
        if ( $success = get_transient( 'falnic_encode_success_' . $user_id ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ ویدیوی <strong>' . esc_html($success) . '</strong> آماده پخش است.</p></div>';
            delete_transient( 'falnic_encode_success_' . $user_id );
        }
    }

    private function process_encoding( $input_path, $name ) {
        if ( ! function_exists( 'exec' ) ) {
            set_transient( 'falnic_encode_error_' . get_current_user_id(), 'تابع exec() مسدود است.', 60 );
            return false;
        }

        $secure_dir = self::get_secure_dir() . $name;
        $video_dir  = self::get_videos_dir() . $name;

        wp_mkdir_p( $secure_dir ); wp_mkdir_p( $video_dir );
        
        $key_file = "$secure_dir/$name.key"; 
        $iv = bin2hex( random_bytes( 16 ) );
        file_put_contents( $key_file, random_bytes( 16 ) );

        $keyinfo_file = "$secure_dir/$name.keyinfo";
        $key_url = admin_url( "admin-ajax.php?action=secure_hls_key&video=$name" );
        $key_url = str_replace( 'http://', 'https://', $key_url );
        file_put_contents( $keyinfo_file, "$key_url\n$key_file\n$iv" );

        $ffmpeg_cmd = sprintf(
            'ffmpeg -y -i %s -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k -hls_time 10 -hls_key_info_file %s -hls_playlist_type vod -hls_segment_filename %s %s 2>&1',
            escapeshellarg( $input_path ), escapeshellarg( $keyinfo_file ), escapeshellarg( "$video_dir/%03d.ts" ), escapeshellarg( "$video_dir/$name.m3u8" )
        );

        exec( $ffmpeg_cmd, $output, $return_var );
        unlink( $keyinfo_file );

        if ( $return_var !== 0 ) {
            set_transient( 'falnic_encode_error_' . get_current_user_id(), implode( "\n", $output ), 60 );
            return false;
        }
        return true;
    }

    public function register_admin_menus() {
        add_submenu_page( 'edit.php?post_type=secure_video', 'انکودر دستی', 'انکودر دستی', 'manage_options', 'falnic_hls_encoder', [ $this, 'render_encoder_page' ] );
        add_submenu_page( 'edit.php?post_type=secure_video', 'تنظیمات امنیتی', 'تنظیمات', 'manage_options', 'falnic_hls_settings', [ $this, 'render_settings_page' ] );
    }

    public function render_settings_page() {
        if ( isset( $_POST['falnic_save_settings'] ) ) {
            update_option( 'falnic_hls_max_sessions', intval( $_POST['max_sessions'] ) );
            update_option( 'falnic_hls_bind_ip', isset( $_POST['bind_ip'] ) ? 'yes' : 'no' );
            echo '<div class="updated"><p>تنظیمات ذخیره شد.</p></div>';
        }
        $max_sessions = get_option( 'falnic_hls_max_sessions', 1 );
        $bind_ip = get_option( 'falnic_hls_bind_ip', 'no' );
        ?>
        <div class="wrap" style="font-family: Tahoma, sans-serif;">
            <h1>⚙️ تنظیمات امنیتی پلیر</h1><hr>
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="max_sessions">حداکثر لاگین همزمان (برای کاربران)</label></th>
                        <td><input name="max_sessions" type="number" step="1" min="1" id="max_sessions" value="<?php echo esc_attr($max_sessions); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bind_ip">قفل کردن استریم روی IP کاربر</label></th>
                        <td><input name="bind_ip" type="checkbox" id="bind_ip" value="yes" <?php checked( $bind_ip, 'yes' ); ?>></td>
                    </tr>
                </table>
                <p class="submit"><input type="submit" name="falnic_save_settings" class="button button-primary" value="ذخیره تغییرات"></p>
            </form>
        </div>
        <?php
    }

    public function render_encoder_page() {
        if ( isset( $_POST['falnic_encode_video'] ) && current_user_can( 'manage_options' ) ) {
            $input_file = sanitize_text_field( $_POST['input_file'] );
            // پاکسازی نام دستی
            $raw_name = urldecode( sanitize_title( $_POST['video_name'] ) );
            $video_name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $raw_name ) );
            if (empty($video_name)) $video_name = 'video-' . time();

            set_time_limit( 0 ); ignore_user_abort( true );
            
            if ( ! file_exists( $input_file ) ) {
                echo '<div class="error"><p>خطا: فایل یافت نشد.</p></div>';
            } else {
                if ( $this->process_encoding( $input_file, $video_name ) ) {
                    if ( ! post_exists( $video_name ) ) { wp_insert_post( [ 'post_type' => 'secure_video', 'post_title' => $video_name, 'post_status' => 'publish' ] ); }
                    echo '<div class="updated"><p>✅ ویدیو رمزنگاری شد!</p></div>';
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>🎬 انکودر دستی سرور</h1><hr>
            <form method="post" action="">
                <table class="form-table">
                    <tr><th scope="row">مسیر فایل در سرور</th><td><input name="input_file" type="text" class="regular-text" required></td></tr>
                    <tr><th scope="row">نام اختصاصی (فقط انگلیسی)</th><td><input name="video_name" type="text" class="regular-text" required></td></tr>
                </table>
                <p class="submit"><input type="submit" name="falnic_encode_video" class="button button-primary" value="شروع انکودینگ"></p>
            </form>
        </div>
        <?php
    }

    public function render_embed_endpoint() {
        if ( isset( $_GET['falnic_embed'] ) ) {
            $video_name = sanitize_text_field( $_GET['falnic_embed'] );
            if ( ! is_user_logged_in() ) { wp_die('شما دسترسی ندارید.', 'عدم دسترسی', ['response' => 403]); }
            echo '<!DOCTYPE html><html lang="fa"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
            echo '<style>body{margin:0;padding:0;background:#000;height:100vh;display:flex;align-items:center;justify-content:center;}</style>';
            echo '</head><body>' . $this->render_player( ['name' => $video_name] ) . '</body></html>';
            exit;
        }
    }

    public function safe_session_control( $user_login, $user ) {
        update_user_meta( $user->ID, 'falnic_last_login_ip', $_SERVER['REMOTE_ADDR'] );
        if ( in_array( 'administrator', (array) $user->roles ) ) return; 
        
        $max_sessions = (int) get_option( 'falnic_hls_max_sessions', 1 );
        if ( $max_sessions === 1 ) wp_destroy_other_sessions(); 
    }

    public function deliver_key() {
        if ( ! is_user_logged_in() ) wp_die( 'Unauthorized', '', [ 'response' => 401 ] ); 

        if ( get_option( 'falnic_hls_bind_ip', 'no' ) === 'yes' ) {
            $current_ip = $_SERVER['REMOTE_ADDR'];
            $session_ip = get_user_meta( get_current_user_id(), 'falnic_last_login_ip', true );
            $user = wp_get_current_user();
            if ( ! in_array( 'administrator', (array) $user->roles ) && $session_ip && $current_ip !== $session_ip ) {
                wp_die( 'IP Mismatch', '', [ 'response' => 403 ] );
            }
        }

        $video_name = sanitize_text_field( $_GET['video'] ?? '' );
        if ( empty( $video_name ) ) wp_die( 'Missing video', '', [ 'response' => 400 ] ); 

        $key_path = self::get_secure_dir() . $video_name . '/' . $video_name . '.key';
        if ( ! file_exists( $key_path ) ) wp_die( 'Key not found', '', [ 'response' => 404 ] ); 

        header( 'Content-Type: application/octet-stream' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        echo file_get_contents( $key_path );
        exit;
    }

    public function render_player( array $atts ): string {
        $a = shortcode_atts( [ 'name' => '' ], $atts );
        $video_name = sanitize_text_field( $a['name'] );
        if ( empty( $video_name ) ) return '';

        $m3u8_url = self::get_videos_url() . $video_name . '/' . $video_name . '.m3u8';
        $html_id  = 'secure-vid-' . uniqid();

        $w1 = ''; $w2 = '';
        if ( is_user_logged_in() ) {
            $cu = wp_get_current_user();
            $n = trim( $cu->first_name . ' ' . $cu->last_name );
            $w1 = ! empty( $n ) ? "{$n} ({$cu->user_login})" : $cu->user_login;
            $w2 = $cu->user_email;
        }

        $obf_js = base64_encode("
            document.addEventListener('keydown',function(e){if(e.key==='PrintScreen'||e.keyCode===44){e.preventDefault();navigator.clipboard.writeText('');alert('Screen recording blocked.');}if(e.shiftKey&&e.metaKey&&e.key==='s')e.preventDefault();if(e.keyCode===123||(e.ctrlKey&&e.shiftKey&&(e.key==='I'||e.key==='i')))e.preventDefault();});
            const c=document.querySelector('.falnic-secure-container');
            const b=document.createElement('div');
            b.style.cssText='position:absolute;top:0;left:0;width:100%;height:100%;background:#000;color:#fff;display:none;align-items:center;justify-content:center;z-index:9999;font-family:Tahoma;';
            b.innerText='برای ادامه پخش روی صفحه کلیک کنید';
            if(c) c.appendChild(b);
            window.addEventListener('blur',function(){if(window.falnicVid&&!window.falnicVid.paused){window.falnicVid.pause();b.style.display='flex';}});
            window.addEventListener('focus',function(){b.style.display='none';});
            setInterval(function(){const s=new Date().getTime();debugger;if(new Date().getTime()-s>100){document.body.innerHTML='<h1 style=\"color:red;text-align:center;margin-top:20%;\">Access Denied</h1>';window.location.replace('about:blank');}},1000);
        ");

        ob_start();
        ?>
        <link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css" />
        <style>
            .falnic-secure-container { max-width: 100%; width: 100%; height: 100%; margin: 0 auto; border-radius: 8px; overflow: hidden; position:relative; background:#000;}
            .falnic-hidden-video { opacity: 0 !important; width: 100%; height: 100%; }
            .falnic-canvas-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; background: #000; z-index: 0; object-fit: contain; }
            .plyr { height: 100%; width: 100%; }
        </style>
        <div class="falnic-secure-container">
            <video id="<?php echo esc_attr( $html_id ); ?>" class="falnic-hidden-video" crossorigin="anonymous" playsinline></video>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
        <script src="https://cdn.plyr.io/3.7.8/plyr.js"></script>
        <script>
            (function(){ eval(atob('<?php echo $obf_js; ?>')); })();
            document.addEventListener('DOMContentLoaded', function() {
                const video = document.getElementById('<?php echo esc_js( $html_id ); ?>');
                window.falnicVid = video;
                const videoSrc = '<?php echo esc_url( $m3u8_url ); ?>';
                const w1 = '<?php echo esc_js( $w1 ); ?>';
                const w2 = '<?php echo esc_js( $w2 ); ?>';
                const player = new Plyr(video, { controls: ['play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'fullscreen'], hideControls: true });
                const canvas = document.createElement('canvas');
                canvas.className = 'falnic-canvas-overlay';
                const ctx = canvas.getContext('2d');
                player.on('ready', () => {
                    const wrapper = video.closest('.plyr__video-wrapper');
                    if(wrapper) wrapper.insertBefore(canvas, video);
                });
                let wx = 30, wy = 40;
                if (Hls.isSupported()) {
                    const hls = new Hls({ xhrSetup: function(xhr, url) { if(url.includes('secure_hls_key')) xhr.withCredentials = true; } });
                    hls.loadSource(videoSrc);
                    hls.attachMedia(video);
                } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                    video.src = videoSrc;
                }
                video.addEventListener('loadedmetadata', function() {
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                });
                function rFrame() {
                    if (video.paused || video.ended) return;
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    if (w1) {
                        ctx.font = "12px Tahoma, Arial, sans-serif";
                        ctx.fillStyle = "rgba(255, 255, 255, 0.45)";
                        ctx.shadowColor = "rgba(0,0,0,0.8)";
                        ctx.shadowBlur = 3;
                        ctx.fillText(w1, wx, wy);
                        if (w2) { ctx.font = "10px Tahoma, Arial, sans-serif"; ctx.fillText(w2, wx, wy + 16); }
                    }
                    requestAnimationFrame(rFrame);
                }
                video.addEventListener('play', () => { requestAnimationFrame(rFrame); });
                function moveW() {
                    if (!video.paused && canvas.width > 0) {
                        ctx.font = "12px Tahoma, Arial";
                        const wMax = Math.max(ctx.measureText(w1).width, w2 ? ctx.measureText(w2).width : 0);
                        const maxX = canvas.width - wMax - 30;
                        const maxY = canvas.height - 70;
                        const z = Math.random(); 
                        let nx, ny;
                        if (z < 0.20) { nx = 20 + Math.random()*40; ny = 30 + Math.random()*40; }
                        else if (z < 0.40) { nx = maxX - Math.random()*40; ny = 30 + Math.random()*40; }
                        else if (z < 0.60) { nx = 20 + Math.random()*40; ny = maxY - Math.random()*40; }
                        else if (z < 0.80) { nx = maxX - Math.random()*40; ny = maxY - Math.random()*40; }
                        else { nx = 20 + Math.random()*(maxX-20); ny = 30 + Math.random()*(maxY-30); }
                        wx = Math.max(20, Math.min(nx, maxX));
                        wy = Math.max(30, Math.min(ny, maxY));
                    }
                    setTimeout(moveW, Math.floor(Math.random()*(40000-25000+1))+25000);
                }
                setTimeout(moveW, 10000);
                canvas.addEventListener('contextmenu', e => e.preventDefault());
                video.addEventListener('contextmenu', e => e.preventDefault());
            });
        </script>
        <?php
        return ob_get_clean();
    }
}
Core::get_instance();