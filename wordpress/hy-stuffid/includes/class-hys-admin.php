<?php
/**
 * Admin page «شناسه‌یاب» under the Hesabyar menu, plus AJAX endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

class HYS_Admin {

    public static function cap() {
        return current_user_can('hy_manage') ? 'hy_manage' : 'manage_woocommerce';
    }

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 20);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_post_hys_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_hys_reimport', array(__CLASS__, 'reimport'));
        add_action('wp_ajax_hys_batch', array(__CLASS__, 'ajax_batch'));
        add_action('wp_ajax_hys_search', array(__CLASS__, 'ajax_search'));
        add_action('wp_ajax_hys_set', array(__CLASS__, 'ajax_set'));
    }

    public static function menu() {
        $parent = class_exists('HY_Admin') ? 'hy-dashboard' : 'woocommerce';
        add_submenu_page($parent, 'شناسه‌یاب', 'شناسه‌یاب', self::cap(), 'hys', array(__CLASS__, 'page'));
    }

    public static function assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_product = $screen && $screen->id === 'product';
        if (!$is_product && strpos((string) $hook, 'hys') === false) {
            return;
        }
        wp_enqueue_style('hys', HYS_URL . 'assets/hys.css', array(), HYS_VERSION);
        wp_enqueue_script('hys', HYS_URL . 'assets/hys.js', array('jquery'), HYS_VERSION, true);
        wp_localize_script('hys', 'HYS', array(
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hys'),
            'total' => (int) wp_count_posts('product')->publish,
        ));
    }

    private static function check() {
        if (!current_user_can(self::cap()) || !check_ajax_referer('hys', 'nonce', false)) {
            wp_send_json_error(array('message' => 'دسترسی ندارید.'), 403);
        }
    }

    public static function ajax_batch() {
        self::check();
        @set_time_limit(120);
        $after = isset($_POST['after']) ? (int) $_POST['after'] : 0;
        $replace = !empty($_POST['replace_auto']);
        wp_send_json_success(HYS_Product::batch($after, $replace));
    }

    public static function ajax_search() {
        self::check();
        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $rows = HYS_Data::search($q, 25);
        wp_send_json_success(array_map(function ($r) {
            return array('id' => $r['id'], 'desc' => $r['descr'], 'origin' => $r['origin'], 'vat' => (float) $r['vat']);
        }, $rows));
    }

    public static function ajax_set() {
        self::check();
        $pid = isset($_POST['product']) ? (int) $_POST['product'] : 0;
        $sid = isset($_POST['stuff_id']) ? preg_replace('/\D+/', '', wp_unslash($_POST['stuff_id'])) : '';
        if (!$pid || get_post_type($pid) !== 'product' || !preg_match('/^\d{13}$/', $sid)) {
            wp_send_json_error(array('message' => 'ورودی نامعتبر است.'), 400);
        }
        HYS_Product::set_manual($pid, $sid);
        $row = HYS_Data::get($sid);
        wp_send_json_success(array('id' => $sid, 'desc' => $row ? $row['descr'] : ''));
    }

    public static function save_settings() {
        if (!current_user_can(self::cap()) || !check_admin_referer('hys_settings')) {
            wp_die('دسترسی ندارید.');
        }
        HYS_Settings::save(wp_unslash($_POST));
        wp_safe_redirect(admin_url('admin.php?page=hys&saved=1'));
        exit;
    }

    public static function reimport() {
        if (!current_user_can(self::cap()) || !check_admin_referer('hys_reimport')) {
            wp_die('دسترسی ندارید.');
        }
        HYS_Data::import();
        wp_safe_redirect(admin_url('admin.php?page=hys&imported=1'));
        exit;
    }

    private static function stats() {
        global $wpdb;
        $st = "('publish','private','draft','pending')";
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN $st");
        $with = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_hy_stuff_id' AND m.meta_value<>'' WHERE p.post_type='product' AND p.post_status IN $st");
        $auto = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} a ON a.post_id=p.ID AND a.meta_key='_hys_auto' JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_hy_stuff_id' AND m.meta_value=a.meta_value WHERE p.post_type='product' AND p.post_status IN $st");
        return array('total' => $total, 'with' => $with, 'auto' => $auto, 'manual' => $with - $auto, 'empty' => $total - $with);
    }

    private static function list_rows($tab, $page, $per) {
        global $wpdb;
        $st = "('publish','private','draft','pending')";
        $off = ($page - 1) * $per;
        if ($tab === 'empty') {
            $sql = "SELECT SQL_CALC_FOUND_ROWS p.ID, p.post_title, '' AS sid FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_hy_stuff_id'
                WHERE p.post_type='product' AND p.post_status IN $st AND (m.meta_value IS NULL OR m.meta_value='')
                ORDER BY p.post_title LIMIT %d OFFSET %d";
        } else {
            $sql = "SELECT SQL_CALC_FOUND_ROWS p.ID, p.post_title, m.meta_value AS sid FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} a ON a.post_id=p.ID AND a.meta_key='_hys_auto'
                JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_hy_stuff_id' AND m.meta_value=a.meta_value
                WHERE p.post_type='product' AND p.post_status IN $st
                ORDER BY p.post_title LIMIT %d OFFSET %d";
        }
        $rows = $wpdb->get_results($wpdb->prepare($sql, $per, $off), ARRAY_A);
        $found = (int) $wpdb->get_var('SELECT FOUND_ROWS()');
        return array($rows, $found);
    }

    public static function page() {
        $s = self::stats();
        $tab = (isset($_GET['tab']) && $_GET['tab'] === 'empty') ? 'empty' : 'auto';
        $pg = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        list($rows, $found) = self::list_rows($tab, $pg, 50);
        $base = admin_url('admin.php?page=hys');
        ?>
        <div class="wrap hys-wrap" dir="rtl">
            <h1>شناسه‌یاب — شناسه کالای مودیان</h1>
            <?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div><?php endif; ?>
            <?php if (!empty($_GET['imported'])) : ?><div class="notice notice-success"><p>فهرست شناسه‌های عمومی دوباره بارگذاری شد.</p></div><?php endif; ?>

            <div class="hys-cards">
                <div><b><?php echo esc_html(number_format_i18n($s['total'])); ?></b>محصول</div>
                <div><b><?php echo esc_html(number_format_i18n($s['manual'])); ?></b>شناسه دستی</div>
                <div><b><?php echo esc_html(number_format_i18n($s['auto'])); ?></b>شناسه خودکار</div>
                <div class="warn"><b><?php echo esc_html(number_format_i18n($s['empty'])); ?></b>بدون شناسه</div>
            </div>

            <div class="hys-panel">
                <h2>پر کردن خودکار</h2>
                <p>برای همه محصولاتی که شناسه کالا ندارند، شناسه عمومی مناسب پیدا و ثبت می‌شود. شناسه‌هایی که دستی وارد کرده‌اید دست نمی‌خورند.</p>
                <label><input type="checkbox" id="hys-replace"> شناسه‌هایی را هم که قبلاً به‌صورت خودکار پر شده‌اند دوباره محاسبه کن</label>
                <p><button class="button button-primary" id="hys-run">شروع</button> <span id="hys-progress"></span></p>
                <div class="hys-bar"><span></span></div>
            </div>

            <div class="hys-panel">
                <h2>بررسی</h2>
                <p class="subsubsub">
                    <a href="<?php echo esc_url($base . '&tab=auto'); ?>" class="<?php echo $tab === 'auto' ? 'current' : ''; ?>">پر شده خودکار</a> |
                    <a href="<?php echo esc_url($base . '&tab=empty'); ?>" class="<?php echo $tab === 'empty' ? 'current' : ''; ?>">بدون شناسه</a>
                </p>
                <table class="widefat striped hys-table">
                    <thead><tr><th>محصول</th><th>شناسه فعلی</th><th>تغییر</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r) :
                        $g = $r['sid'] ? HYS_Data::get($r['sid']) : null; ?>
                        <tr data-product="<?php echo (int) $r['ID']; ?>">
                            <td><a href="<?php echo esc_url(get_edit_post_link($r['ID'])); ?>"><?php echo esc_html($r['post_title']); ?></a></td>
                            <td class="hys-cur"><?php if ($g) : ?><b dir="ltr"><?php echo esc_html($g['id']); ?></b><br><small><?php echo esc_html($g['descr']); ?> · <?php echo $g['origin'] === 'I' ? 'وارداتی' : 'تولید داخل'; ?></small><?php else : ?>—<?php endif; ?></td>
                            <td><input type="search" class="hys-q" placeholder="جستجو…"> <span class="hys-results"></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows) : ?><tr><td colspan="3">موردی نیست.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                <?php
                $pages = (int) ceil($found / 50);
                if ($pages > 1) {
                    echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $pg, 'total' => $pages,
                    )) . '</div></div>';
                }
                ?>
            </div>

            <div class="hys-panel">
                <h2>تنظیمات</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="hys_settings">
                    <?php wp_nonce_field('hys_settings'); ?>
                    <p><label><input type="checkbox" name="auto_fill" value="1" <?php checked(HYS_Settings::get('auto_fill')); ?>> وقتی محصولی ذخیره می‌شود و شناسه ندارد، خودکار پر شود</label></p>
                    <p><label><input type="checkbox" name="fill_vat" value="1" <?php checked(HYS_Settings::get('fill_vat')); ?>> نرخ مالیات خالی هم از شناسه پر شود</label></p>
                    <p>اگر برند کالا شناخته نشد، فرض کن کالا:
                        <select name="default_origin">
                            <option value="D" <?php selected(HYS_Settings::get('default_origin'), 'D'); ?>>تولید داخل</option>
                            <option value="I" <?php selected(HYS_Settings::get('default_origin'), 'I'); ?>>وارداتی</option>
                        </select></p>
                    <p><button class="button">ذخیره</button></p>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="hys_reimport">
                    <?php wp_nonce_field('hys_reimport'); ?>
                    <p class="description"><?php echo esc_html(number_format_i18n(HYS_Data::count())); ?> شناسه عمومی بارگذاری شده (نسخه داده <?php echo esc_html(HYS_DATA_VERSION); ?>). <button class="button button-small">بارگذاری دوباره</button></p>
                </form>
            </div>
        </div>
        <?php
    }
}
