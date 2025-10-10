<?php
/*
Plugin Name: SIMPLATE VideoUpload
Plugin URI: https://urachahub-videos.free.nf/
Description: いいね機能、透かし、reCAPTCHA hCAPTCHA cloudflare turnstile 対応 設定機能を搭載した高機能動画アップロードプラグイン 無料で使える
Version: 1.10
Author: honkitamc
Author URI: https://profiles.wordpress.org/honkitamc/
License: GPL v2 or later
Text Domain: enhanced-video-upload
*/

if (!defined('ABSPATH')) exit;

class Enhanced_Video_Upload_Pro {
    
    private $max_file_size = 52428800; // 50MB
    
    private $allowed_video_types = array(
        'mp4'  => 'video/mp4',
        'mov'  => 'video/quicktime',
        'avi'  => 'video/x-msvideo',
        'wmv'  => 'video/x-ms-wmv',
        'webm' => 'video/webm',
        'mkv'  => 'video/x-matroska',
        'flv'  => 'video/x-flv'
    );
    
    public function __construct() {
        // 基本機能
        add_filter('upload_mimes', array($this, 'allow_video_uploads'));
        add_filter('wp_handle_upload_prefilter', array($this, 'check_file_size'));
        add_filter('wp_check_filetype_and_ext', array($this, 'validate_video_file'), 10, 4);
        
        // 管理画面
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_attachment', array($this, 'log_video_upload'));
        
        // ショートコード
        add_shortcode('video_upload_form', array($this, 'render_upload_form'));
        add_shortcode('video_gallery', array($this, 'render_video_gallery'));
        add_shortcode('video_search', array($this, 'render_search_form'));
        
        // Ajax処理
        add_action('wp_ajax_frontend_video_upload', array($this, 'handle_frontend_upload'));
        add_action('wp_ajax_nopriv_frontend_video_upload', array($this, 'handle_frontend_upload'));
        add_action('wp_ajax_video_like', array($this, 'handle_video_like'));
        add_action('wp_ajax_nopriv_video_like', array($this, 'handle_video_like'));
        add_action('wp_ajax_video_search', array($this, 'handle_video_search'));
        add_action('wp_ajax_nopriv_video_search', array($this, 'handle_video_search'));
        
        // データベーステーブル作成
        register_activation_hook(__FILE__, array($this, 'create_tables'));
    }
    
    /**
     * データベーステーブル作成
     */
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // いいねテーブル
        $table_name = $wpdb->prefix . 'video_likes';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            video_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            ip_address varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_like (video_id, user_id),
            KEY video_id (video_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function allow_video_uploads($mime_types) {
        foreach ($this->allowed_video_types as $ext => $mime) {
            $mime_types[$ext] = $mime;
        }
        return $mime_types;
    }
    
    public function check_file_size($file) {
        $max_size = get_option('evum_max_file_size', $this->max_file_size);
        if ($file['size'] > $max_size) {
            $max_mb = round($max_size / 1048576, 2);
            $file['error'] = sprintf('動画ファイルのサイズが大きすぎます。最大サイズは %s MB です。', $max_mb);
        }
        return $file;
    }
    
    public function validate_video_file($data, $file, $filename, $mimes) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (array_key_exists($ext, $this->allowed_video_types)) {
            $data['ext'] = $ext;
            $data['type'] = $this->allowed_video_types[$ext];
            $data['proper_filename'] = $filename;
        }
        return $data;
    }
    
    public function log_video_upload($attachment_id) {
        $file_type = get_post_mime_type($attachment_id);
        if (strpos($file_type, 'video/') === 0) {
            $file_path = get_attached_file($attachment_id);
            if (file_exists($file_path)) {
                $file_size = filesize($file_path);
                $user = wp_get_current_user();
                $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                
                update_post_meta($attachment_id, '_video_uploaded_by', $user->user_login);
                update_post_meta($attachment_id, '_video_file_size', $file_size);
                update_post_meta($attachment_id, '_video_upload_date', current_time('mysql'));
                update_post_meta($attachment_id, '_video_extension', $ext);
                update_post_meta($attachment_id, '_video_views', 0);
                update_post_meta($attachment_id, '_video_likes', 0);
                
                $count_key = 'evum_upload_count_' . $ext;
                $current_count = get_option($count_key, 0);
                update_option($count_key, $current_count + 1);
                
                // 透かし処理
                if (get_option('evum_watermark_enabled', 0)) {
                    $this->apply_watermark($attachment_id, $file_path);
                }
            }
        }
    }
    
    /**
     * 透かし処理（動画メタデータに記録）
     */
    private function apply_watermark($attachment_id, $file_path) {
        $watermark_text = get_option('evum_watermark_text', get_bloginfo('name'));
        $watermark_position = get_option('evum_watermark_position', 'bottom-right');
        
        update_post_meta($attachment_id, '_video_watermark', array(
            'text' => $watermark_text,
            'position' => $watermark_position,
            'applied' => current_time('mysql')
        ));
    }
    
    public function add_settings_page() {
        add_options_page(
            '動画アップロード設定',
            '動画アップロード Pro',
            'manage_options',
            'enhanced-video-upload-pro',
            array($this, 'render_settings_page')
        );
    }
    
    public function register_settings() {
        // 基本設定
        register_setting('evum_settings', 'evum_max_file_size');
        register_setting('evum_settings', 'evum_allowed_extensions');
        
        // CAPTCHA設定
        register_setting('evum_captcha_settings', 'evum_captcha_type');
        register_setting('evum_captcha_settings', 'evum_recaptcha_site_key');
        register_setting('evum_captcha_settings', 'evum_recaptcha_secret_key');
        register_setting('evum_captcha_settings', 'evum_hcaptcha_site_key');
        register_setting('evum_captcha_settings', 'evum_hcaptcha_secret_key');
        register_setting('evum_captcha_settings', 'evum_turnstile_site_key');
        register_setting('evum_captcha_settings', 'evum_turnstile_secret_key');
        
        // 透かし設定
        register_setting('evum_watermark_settings', 'evum_watermark_enabled');
        register_setting('evum_watermark_settings', 'evum_watermark_text');
        register_setting('evum_watermark_settings', 'evum_watermark_position');
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        ?>
        <div class="wrap">
            <h1>動画アップロード Pro 設定</h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=enhanced-video-upload-pro&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">基本設定</a>
                <a href="?page=enhanced-video-upload-pro&tab=captcha" class="nav-tab <?php echo $active_tab == 'captcha' ? 'nav-tab-active' : ''; ?>">CAPTCHA設定</a>
                <a href="?page=enhanced-video-upload-pro&tab=watermark" class="nav-tab <?php echo $active_tab == 'watermark' ? 'nav-tab-active' : ''; ?>">透かし設定</a>
                <a href="?page=enhanced-video-upload-pro&tab=stats" class="nav-tab <?php echo $active_tab == 'stats' ? 'nav-tab-active' : ''; ?>">統計</a>
            </h2>
            
            <?php
            switch($active_tab) {
                case 'captcha':
                    $this->render_captcha_settings();
                    break;
                case 'watermark':
                    $this->render_watermark_settings();
                    break;
                case 'stats':
                    $this->render_stats();
                    break;
                default:
                    $this->render_general_settings();
            }
            ?>
        </div>
        <?php
    }
    
    private function render_general_settings() {
        $max_size = get_option('evum_max_file_size', $this->max_file_size);
        $max_mb = round($max_size / 1048576, 2);
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('evum_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="evum_max_file_size">最大ファイルサイズ (MB)</label></th>
                    <td>
                        <input type="number" id="evum_max_file_size" name="evum_max_file_size" 
                               value="<?php echo esc_attr($max_mb); ?>" step="0.1" min="1" max="500" class="regular-text">
                        <p class="description">現在の設定: <?php echo $max_mb; ?> MB</p>
                    </td>
                </tr>
                <tr>
                    <th>許可されている動画形式</th>
                    <td>
                        <p><?php echo implode(', ', array_keys($this->allowed_video_types)); ?></p>
                        <p class="description">これらの形式の動画ファイルをアップロードできます。</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        
        <hr>
        <h2>ショートコード</h2>
        <table class="widefat">
            <tr><td><code>[video_upload_form]</code></td><td>アップロードフォーム</td></tr>
            <tr><td><code>[video_gallery]</code></td><td>動画ギャラリー</td></tr>
            <tr><td><code>[video_search]</code></td><td>検索フォーム</td></tr>
        </table>
        <?php
    }
    
    private function render_captcha_settings() {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('evum_captcha_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="evum_captcha_type">CAPTCHA タイプ</label></th>
                    <td>
                        <select id="evum_captcha_type" name="evum_captcha_type">
                            <option value="none" <?php selected(get_option('evum_captcha_type', 'none'), 'none'); ?>>無効</option>
                            <option value="recaptcha" <?php selected(get_option('evum_captcha_type'), 'recaptcha'); ?>>Google reCAPTCHA v2</option>
                            <option value="hcaptcha" <?php selected(get_option('evum_captcha_type'), 'hcaptcha'); ?>>hCaptcha</option>
                            <option value="turnstile" <?php selected(get_option('evum_captcha_type'), 'turnstile'); ?>>Cloudflare Turnstile</option>
                        </select>
                    </td>
                </tr>
            </table>
            
            <h3>Google reCAPTCHA v2</h3>
            <table class="form-table">
                <tr>
                    <th><label for="evum_recaptcha_site_key">サイトキー</label></th>
                    <td><input type="text" id="evum_recaptcha_site_key" name="evum_recaptcha_site_key" 
                               value="<?php echo esc_attr(get_option('evum_recaptcha_site_key')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="evum_recaptcha_secret_key">シークレットキー</label></th>
                    <td><input type="text" id="evum_recaptcha_secret_key" name="evum_recaptcha_secret_key" 
                               value="<?php echo esc_attr(get_option('evum_recaptcha_secret_key')); ?>" class="regular-text"></td>
                </tr>
            </table>
            
            <h3>hCaptcha</h3>
            <table class="form-table">
                <tr>
                    <th><label for="evum_hcaptcha_site_key">サイトキー</label></th>
                    <td><input type="text" id="evum_hcaptcha_site_key" name="evum_hcaptcha_site_key" 
                               value="<?php echo esc_attr(get_option('evum_hcaptcha_site_key')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="evum_hcaptcha_secret_key">シークレットキー</label></th>
                    <td><input type="text" id="evum_hcaptcha_secret_key" name="evum_hcaptcha_secret_key" 
                               value="<?php echo esc_attr(get_option('evum_hcaptcha_secret_key')); ?>" class="regular-text"></td>
                </tr>
            </table>
            
            <h3>Cloudflare Turnstile</h3>
            <table class="form-table">
                <tr>
                    <th><label for="evum_turnstile_site_key">サイトキー</label></th>
                    <td><input type="text" id="evum_turnstile_site_key" name="evum_turnstile_site_key" 
                               value="<?php echo esc_attr(get_option('evum_turnstile_site_key')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="evum_turnstile_secret_key">シークレットキー</label></th>
                    <td><input type="text" id="evum_turnstile_secret_key" name="evum_turnstile_secret_key" 
                               value="<?php echo esc_attr(get_option('evum_turnstile_secret_key')); ?>" class="regular-text"></td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
        <?php
    }
    
    private function render_watermark_settings() {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('evum_watermark_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="evum_watermark_enabled">透かしを有効化</label></th>
                    <td>
                        <input type="checkbox" id="evum_watermark_enabled" name="evum_watermark_enabled" 
                               value="1" <?php checked(get_option('evum_watermark_enabled'), 1); ?>>
                        <p class="description">アップロードされた動画に透かし情報を記録します</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="evum_watermark_text">透かしテキスト</label></th>
                    <td>
                        <input type="text" id="evum_watermark_text" name="evum_watermark_text" 
                               value="<?php echo esc_attr(get_option('evum_watermark_text', get_bloginfo('name'))); ?>" class="regular-text">
                        <p class="description">動画に表示される透かしテキスト</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="evum_watermark_position">透かし位置</label></th>
                    <td>
                        <select id="evum_watermark_position" name="evum_watermark_position">
                            <option value="top-left" <?php selected(get_option('evum_watermark_position'), 'top-left'); ?>>左上</option>
                            <option value="top-right" <?php selected(get_option('evum_watermark_position'), 'top-right'); ?>>右上</option>
                            <option value="bottom-left" <?php selected(get_option('evum_watermark_position'), 'bottom-left'); ?>>左下</option>
                            <option value="bottom-right" <?php selected(get_option('evum_watermark_position', 'bottom-right'), 'bottom-right'); ?>>右下</option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }
    
    private function render_stats() {
        global $wpdb;
        
        $video_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'video/%'"
        );
        
        $total_size = $wpdb->get_var(
            "SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_video_file_size'"
        );
        
        $total_likes = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}video_likes");
        $total_views = $wpdb->get_var(
            "SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_video_views'"
        );
        
        ?>
        <h2>動画アップロード統計</h2>
        <table class="widefat">
            <tr><td><strong>アップロード済み動画数:</strong></td><td><?php echo intval($video_count); ?> 件</td></tr>
            <tr><td><strong>使用容量:</strong></td><td><?php echo round($total_size / 1048576, 2); ?> MB</td></tr>
            <tr><td><strong>総いいね数:</strong></td><td><?php echo intval($total_likes); ?> 回</td></tr>
            <tr><td><strong>総視聴回数:</strong></td><td><?php echo intval($total_views); ?> 回</td></tr>
        </table>
        
        <h2>形式別アップロード回数</h2>
        <table class="widefat striped">
            <thead>
                <tr><th>動画形式</th><th>アップロード回数</th></tr>
            </thead>
            <tbody>
                <?php
                $formats = array('mp4', 'mov', 'avi', 'wmv', 'webm', 'mkv', 'flv');
                foreach ($formats as $format) {
                    $count = get_option('evum_upload_count_' . $format, 0);
                    echo "<tr><td><strong>.$format</strong></td><td>$count 回</td></tr>";
                }
                ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * アップロードフォーム
     */
    public function render_upload_form($atts) {
        if (!is_user_logged_in()) {
            return '<div class="evum-login-message"><p>動画をアップロードするには<a href="' . wp_login_url(get_permalink()) . '">ログイン</a>してください。</p></div>';
        }
        
        wp_enqueue_script('jquery');
        $captcha_type = get_option('evum_captcha_type', 'none');
        
        ob_start();
        ?>
        <div class="evum-upload-form">
            <h3>動画をアップロード</h3>
            <form id="evum-frontend-form" enctype="multipart/form-data">
                <?php wp_nonce_field('evum_upload_nonce', 'evum_nonce'); ?>
                
                <div class="evum-form-group">
                    <label for="evum-video-title">タイトル *</label>
                    <input type="text" id="evum-video-title" name="video_title" required>
                </div>
                
                <div class="evum-form-group">
                    <label for="evum-video-description">説明</label>
                    <textarea id="evum-video-description" name="video_description" rows="4"></textarea>
                </div>
                
                <div class="evum-form-group">
                    <label for="evum-video-file">動画ファイル *</label>
                    <input type="file" id="evum-video-file" name="video_file" accept="video/*" required>
                    <small>最大: <?php echo round(get_option('evum_max_file_size', $this->max_file_size) / 1048576, 2); ?> MB</small>
                </div>
                
                <?php echo $this->render_captcha($captcha_type); ?>
                
                <div class="evum-form-group">
                    <button type="submit" class="evum-submit-btn">アップロード</button>
                </div>
                
                <div id="evum-upload-progress" style="display: none;">
                    <div class="evum-progress-bar">
                        <div class="evum-progress-fill"></div>
                    </div>
                    <p class="evum-progress-text">アップロード中: <span id="evum-progress-percent">0</span>%</p>
                </div>
                
                <div id="evum-upload-message"></div>
            </form>
        </div>
        
        <?php echo $this->get_form_styles(); ?>
        <?php echo $this->get_upload_script($captcha_type); ?>
        <?php
        return ob_get_clean();
    }
    
    /**
     * 検索フォーム
     */
    public function render_search_form($atts) {
        wp_enqueue_script('jquery');
        ob_start();
        ?>
        <div class="evum-search-container">
            <div class="evum-search-form">
                <input type="text" id="evum-search-input" placeholder="動画を検索..." class="evum-search-input">
                <button id="evum-search-btn" class="evum-search-btn">🔍 検索</button>
            </div>
            <div id="evum-search-results"></div>
        </div>
        
        <style>
        .evum-search-container { max-width: 800px; margin: 20px auto; }
        .evum-search-form { display: flex; gap: 10px; margin-bottom: 20px; }
        .evum-search-input { flex: 1; padding: 12px; border: 2px solid #ddd; border-radius: 4px; font-size: 16px; }
        .evum-search-btn { padding: 12px 24px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .evum-search-btn:hover { background: #005177; }
        .evum-search-result-item { padding: 15px; background: white; margin-bottom: 10px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .evum-search-result-item h4 { margin: 0 0 10px 0; }
        .evum-search-result-item p { margin: 5px 0; color: #666; font-size: 14px; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#evum-search-btn, #evum-search-input').on('click keypress', function(e) {
                if (e.type === 'click' || e.which === 13) {
                    e.preventDefault();
                    var query = $('#evum-search-input').val();
                    
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'video_search',
                            query: query
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#evum-search-results').html(response.data.html);
                            }
                        }
                    });
                }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * 動画ギャラリー
     */
    public function render_video_gallery($atts) {
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'video',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        );
        
        $videos = get_posts($args);
        
        ob_start();
        ?>
        <div class="evum-gallery">
            <?php foreach ($videos as $video): 
                $likes = intval(get_post_meta($video->ID, '_video_likes', true));
                $views = intval(get_post_meta($video->ID, '_video_views', true));
                $video_url = wp_get_attachment_url($video->ID);
            ?>
            <div class="evum-gallery-item" data-video-id="<?php echo $video->ID; ?>">
                <video controls class="evum-video-player">
                    <source src="<?php echo esc_url($video_url); ?>" type="<?php echo get_post_mime_type($video->ID); ?>">
                </video>
                <div class="evum-video-info">
                    <h4><?php echo esc_html($video->post_title); ?></h4>
                    <p><?php echo esc_html($video->post_content); ?></p>
                    <div class="evum-video-meta">
                        <button class="evum-like-btn" data-video-id="<?php echo $video->ID; ?>">
                            ❤️ <span class="like-count"><?php echo $likes; ?></span>
                        </button>
                        <span>👁️ <?php echo $views; ?> 視聴</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php echo $this->get_gallery_styles(); ?>
        <?php echo $this->get_like_script(); ?>
        <?php
        return ob_get_clean();
    }
    
    /**
     * CAPTCHA レンダリング
     */
    private function render_captcha($type) {
        $html = '';
        
        switch($type) {
            case 'recaptcha':
                $site_key = get_option('evum_recaptcha_site_key');
                if ($site_key) {
                    wp_enqueue_script('recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true);
                    $html = '<div class="evum-form-group"><div class="g-recaptcha" data-sitekey="' . esc_attr($site_key) . '"></div></div>';
                }
                break;
                
            case 'hcaptcha':
                $site_key = get_option('evum_hcaptcha_site_key');
                if ($site_key) {
                    wp_enqueue_script('hcaptcha', 'https://js.hcaptcha.com/1/api.js', array(), null, true);
                    $html = '<div class="evum-form-group"><div class="h-captcha" data-sitekey="' . esc_attr($site_key) . '"></div></div>';
                }
                break;
                
            case 'turnstile':
                $site_key = get_option('evum_turnstile_site_key');
                if ($site_key) {
                    wp_enqueue_script('turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true);
                    $html = '<div class="evum-form-group"><div class="cf-turnstile" data-sitekey="' . esc_attr($site_key) . '"></div></div>';
                }
                break;
        }
        
        return $html;
    }
    
    /**
     * CAPTCHA検証
     */
    private function verify_captcha() {
        $captcha_type = get_option('evum_captcha_type', 'none');
        
        if ($captcha_type === 'none') {
            return true;
        }
        
        switch($captcha_type) {
            case 'recaptcha':
                $response = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';
                $secret = get_option('evum_recaptcha_secret_key');
                $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
                break;
                
            case 'hcaptcha':
                $response = isset($_POST['h-captcha-response']) ? $_POST['h-captcha-response'] : '';
                $secret = get_option('evum_hcaptcha_secret_key');
                $verify_url = 'https://hcaptcha.com/siteverify';
                break;
                
            case 'turnstile':
                $response = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';
                $secret = get_option('evum_turnstile_secret_key');
                $verify_url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
                break;
                
            default:
                return false;
        }
        
        if (empty($response) || empty($secret)) {
            return false;
        }
        
        $verify_response = wp_remote_post($verify_url, array(
            'body' => array(
                'secret' => $secret,
                'response' => $response
            )
        ));
        
        if (is_wp_error($verify_response)) {
            return false;
        }
        
        $response_body = json_decode(wp_remote_retrieve_body($verify_response), true);
        return isset($response_body['success']) && $response_body['success'] === true;
    }
    
    /**
     * フロントエンドアップロード処理
     */
    public function handle_frontend_upload() {
        check_ajax_referer('evum_upload_nonce', 'evum_nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'ログインが必要です。'));
        }
        
        // CAPTCHA検証
        if (!$this->verify_captcha()) {
            wp_send_json_error(array('message' => 'CAPTCHA認証に失敗しました。'));
        }
        
        if (empty($_FILES['video_file'])) {
            wp_send_json_error(array('message' => 'ファイルが選択されていません。'));
        }
        
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $file = $_FILES['video_file'];
        $title = sanitize_text_field($_POST['video_title']);
        $description = sanitize_textarea_field($_POST['video_description']);
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!array_key_exists($ext, $this->allowed_video_types)) {
            wp_send_json_error(array('message' => '許可されていないファイル形式です。'));
        }
        
        $upload = wp_handle_upload($file, array('test_form' => false));
        
        if (isset($upload['error'])) {
            wp_send_json_error(array('message' => $upload['error']));
        }
        
        $attachment = array(
            'post_mime_type' => $upload['type'],
            'post_title' => $title,
            'post_content' => $description,
            'post_status' => 'inherit'
        );
        
        $attachment_id = wp_insert_attachment($attachment, $upload['file']);
        
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(array('message' => 'アップロードに失敗しました。'));
        }
        
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
        
        wp_send_json_success(array(
            'message' => '動画が正常にアップロードされました！',
            'attachment_id' => $attachment_id
        ));
    }
    
    /**
     * いいね処理
     */
    public function handle_video_like() {
        global $wpdb;
        
        $video_id = intval($_POST['video_id']);
        $user_id = get_current_user_id();
        $ip_address = $_SERVER['REMOTE_ADDR'];
        
        if (!$video_id) {
            wp_send_json_error(array('message' => '無効な動画IDです。'));
        }
        
        $table_name = $wpdb->prefix . 'video_likes';
        
        // 既にいいねしているかチェック
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE video_id = %d AND (user_id = %d OR ip_address = %s)",
            $video_id, $user_id, $ip_address
        ));
        
        if ($existing) {
            // いいねを取り消し
            $wpdb->delete($table_name, array('id' => $existing));
            $likes = intval(get_post_meta($video_id, '_video_likes', true));
            $new_likes = max(0, $likes - 1);
            update_post_meta($video_id, '_video_likes', $new_likes);
            
            wp_send_json_success(array(
                'likes' => $new_likes,
                'liked' => false
            ));
        } else {
            // いいねを追加
            $wpdb->insert($table_name, array(
                'video_id' => $video_id,
                'user_id' => $user_id,
                'ip_address' => $ip_address,
                'created_at' => current_time('mysql')
            ));
            
            $likes = intval(get_post_meta($video_id, '_video_likes', true));
            $new_likes = $likes + 1;
            update_post_meta($video_id, '_video_likes', $new_likes);
            
            wp_send_json_success(array(
                'likes' => $new_likes,
                'liked' => true
            ));
        }
    }
    
    /**
     * 検索処理
     */
    public function handle_video_search() {
        $query = sanitize_text_field($_POST['query']);
        
        if (empty($query)) {
            wp_send_json_success(array('html' => '<p>検索キーワードを入力してください。</p>'));
        }
        
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'video',
            'post_status' => 'inherit',
            'posts_per_page' => 20,
            's' => $query
        );
        
        $videos = get_posts($args);
        
        if (empty($videos)) {
            wp_send_json_success(array('html' => '<p>検索結果が見つかりませんでした。</p>'));
        }
        
        $html = '';
        foreach ($videos as $video) {
            $likes = intval(get_post_meta($video->ID, '_video_likes', true));
            $views = intval(get_post_meta($video->ID, '_video_views', true));
            $video_url = wp_get_attachment_url($video->ID);
            $ext = get_post_meta($video->ID, '_video_extension', true);
            
            $html .= '<div class="evum-search-result-item">';
            $html .= '<h4>' . esc_html($video->post_title) . '</h4>';
            $html .= '<p>' . esc_html($video->post_content) . '</p>';
            $html .= '<p><small>形式: .' . esc_html($ext) . ' | ❤️ ' . $likes . ' | 👁️ ' . $views . '</small></p>';
            $html .= '<video controls style="max-width: 100%; height: auto;"><source src="' . esc_url($video_url) . '" type="' . get_post_mime_type($video->ID) . '"></video>';
            $html .= '<button class="evum-like-btn" data-video-id="' . $video->ID . '" style="margin-top: 10px;">❤️ いいね</button>';
            $html .= '</div>';
        }
        
        wp_send_json_success(array('html' => $html));
    }
    
    /**
     * フォームスタイル
     */
    private function get_form_styles() {
        return '
        <style>
        .evum-upload-form {
            max-width: 600px;
            margin: 20px auto;
            padding: 30px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .evum-upload-form h3 {
            margin-top: 0;
            color: #333;
        }
        .evum-form-group {
            margin-bottom: 20px;
        }
        .evum-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        .evum-form-group input[type="text"],
        .evum-form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .evum-form-group input[type="file"] {
            width: 100%;
            padding: 10px 0;
        }
        .evum-form-group small {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 12px;
        }
        .evum-submit-btn {
            background: #0073aa;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background 0.3s;
        }
        .evum-submit-btn:hover {
            background: #005177;
        }
        .evum-submit-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .evum-progress-bar {
            width: 100%;
            height: 30px;
            background: #e0e0e0;
            border-radius: 15px;
            overflow: hidden;
            margin: 15px 0 10px;
        }
        .evum-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #0073aa 0%, #00a0d2 100%);
            width: 0%;
            transition: width 0.3s;
        }
        .evum-progress-text {
            text-align: center;
            color: #666;
            font-weight: 600;
        }
        #evum-upload-message {
            margin-top: 15px;
            padding: 12px;
            border-radius: 4px;
            display: none;
        }
        #evum-upload-message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            display: block;
        }
        #evum-upload-message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            display: block;
        }
        .evum-login-message {
            padding: 20px;
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            margin: 20px 0;
        }
        </style>';
    }
    
    /**
     * ギャラリースタイル
     */
    private function get_gallery_styles() {
        return '
        <style>
        .evum-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .evum-gallery-item {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .evum-gallery-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .evum-video-player {
            width: 100%;
            height: auto;
            display: block;
        }
        .evum-video-info {
            padding: 15px;
        }
        .evum-video-info h4 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: #333;
        }
        .evum-video-info p {
            margin: 0 0 10px 0;
            color: #666;
            font-size: 14px;
        }
        .evum-video-meta {
            display: flex;
            gap: 15px;
            align-items: center;
            font-size: 14px;
            color: #888;
        }
        .evum-like-btn {
            background: none;
            border: 1px solid #ddd;
            padding: 6px 12px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        .evum-like-btn:hover {
            background: #ffe6e6;
            border-color: #ff6b6b;
        }
        .evum-like-btn.liked {
            background: #ff6b6b;
            color: white;
            border-color: #ff6b6b;
        }
        </style>';
    }
    
    /**
     * アップロードスクリプト
     */
    private function get_upload_script($captcha_type) {
        $captcha_field = '';
        switch($captcha_type) {
            case 'recaptcha':
                $captcha_field = "'g-recaptcha-response': grecaptcha.getResponse()";
                break;
            case 'hcaptcha':
                $captcha_field = "'h-captcha-response': hcaptcha.getResponse()";
                break;
            case 'turnstile':
                $captcha_field = "'cf-turnstile-response': turnstile.getResponse()";
                break;
        }
        
        return "
        <script>
        jQuery(document).ready(function($) {
            $('#evum-frontend-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData(this);
                formData.append('action', 'frontend_video_upload');
                " . ($captcha_field ? "formData.append($captcha_field);" : "") . "
                
                $('#evum-upload-progress').show();
                $('#evum-upload-message').hide().removeClass('success error');
                $('.evum-submit-btn').prop('disabled', true).text('アップロード中...');
                
                $.ajax({
                    url: '" . admin_url('admin-ajax.php') . "',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    xhr: function() {
                        var xhr = new window.XMLHttpRequest();
                        xhr.upload.addEventListener('progress', function(e) {
                            if (e.lengthComputable) {
                                var percentComplete = Math.round((e.loaded / e.total) * 100);
                                $('.evum-progress-fill').css('width', percentComplete + '%');
                                $('#evum-progress-percent').text(percentComplete);
                            }
                        }, false);
                        return xhr;
                    },
                    success: function(response) {
                        $('#evum-upload-progress').hide();
                        $('.evum-submit-btn').prop('disabled', false).text('アップロード');
                        
                        if (response.success) {
                            $('#evum-upload-message')
                                .addClass('success')
                                .text(response.data.message)
                                .show();
                            $('#evum-frontend-form')[0].reset();
                            $('.evum-progress-fill').css('width', '0%');
                        } else {
                            $('#evum-upload-message')
                                .addClass('error')
                                .text(response.data.message)
                                .show();
                        }
                    },
                    error: function() {
                        $('#evum-upload-progress').hide();
                        $('.evum-submit-btn').prop('disabled', false).text('アップロード');
                        $('#evum-upload-message')
                            .addClass('error')
                            .text('アップロード中にエラーが発生しました。')
                            .show();
                    }
                });
            });
        });
        </script>";
    }
    
    /**
     * いいねスクリプト
     */
    private function get_like_script() {
        return "
        <script>
        jQuery(document).ready(function($) {
            $('.evum-like-btn').on('click', function() {
                var btn = $(this);
                var videoId = btn.data('video-id');
                
                $.ajax({
                    url: '" . admin_url('admin-ajax.php') . "',
                    type: 'POST',
                    data: {
                        action: 'video_like',
                        video_id: videoId
                    },
                    success: function(response) {
                        if (response.success) {
                            btn.find('.like-count').text(response.data.likes);
                            if (response.data.liked) {
                                btn.addClass('liked');
                            } else {
                                btn.removeClass('liked');
                            }
                        }
                    }
                });
            });
        });
        </script>";
    }
}

// プラグインを初期化
new Enhanced_Video_Upload_Pro();

/**
 * アンインストール時の処理
 */
register_uninstall_hook(__FILE__, 'evum_pro_uninstall');

function evum_pro_uninstall() {
    global $wpdb;
    
    // オプション削除
    delete_option('evum_max_file_size');
    delete_option('evum_allowed_extensions');
    delete_option('evum_captcha_type');
    delete_option('evum_recaptcha_site_key');
    delete_option('evum_recaptcha_secret_key');
    delete_option('evum_hcaptcha_site_key');
    delete_option('evum_hcaptcha_secret_key');
    delete_option('evum_turnstile_site_key');
    delete_option('evum_turnstile_secret_key');
    delete_option('evum_watermark_enabled');
    delete_option('evum_watermark_text');
    delete_option('evum_watermark_position');
    
    // 形式別カウント削除
    $formats = array('mp4', 'mov', 'avi', 'wmv', 'webm', 'mkv', 'flv');
    foreach ($formats as $format) {
        delete_option('evum_upload_count_' . $format);
    }
    
    // テーブル削除
    $table_name = $wpdb->prefix . 'video_likes';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
}
?>