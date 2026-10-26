<?php
/**
 * WooCommerce product integration: suggestion box on the edit screen,
 * automatic filling of empty stuff IDs when products are saved, and the batch filler.
 */

if (!defined('ABSPATH')) {
    exit;
}

class HYS_Product {
    const META_ID = '_hy_stuff_id';     // field owned by Hesabyar
    const META_VAT = '_hy_vat_rate';    // field owned by Hesabyar
    const META_AUTO = '_hys_auto';      // set when the ID was filled by this plugin

    private static $busy = false;

    public static function init() {
        add_action('woocommerce_product_options_general_product_data', array(__CLASS__, 'box'), 20);
        add_action('woocommerce_admin_process_product_object', array(__CLASS__, 'on_admin_save'), 20);
        add_action('woocommerce_new_product', array(__CLASS__, 'on_any_save'), 20);
        add_action('woocommerce_update_product', array(__CLASS__, 'on_any_save'), 20);
    }

    /** Suggestion shown under Hesabyar's fields in the product's General tab. */
    public static function box() {
        global $post;
        if (!$post) {
            return;
        }
        $s = HYS_Classifier::suggest($post->post_title);
        $current = (string) get_post_meta($post->ID, self::META_ID, true);
        $auto = (string) get_post_meta($post->ID, self::META_AUTO, true);
        echo '<div class="options_group hys-box"><p class="form-field"><label>شناسه‌یاب</label><span class="hys-inner">';
        if ($current !== '' && $auto !== '' && $auto === $current) {
            echo '<span class="hys-tag">پر شده به‌صورت خودکار</span> ';
        }
        if ($s) {
            $origin = $s['origin'] === 'I' ? 'وارداتی' : 'تولید داخل';
            if ($s['origin_source'] === 'default') {
                $origin .= ' (برند نامشخص)';
            }
            printf(
                '<strong dir="ltr">%s</strong> — %s<br><small>%s · نرخ %s٪</small> <button type="button" class="button button-small hys-use" data-id="%s" data-vat="%s">استفاده از این شناسه</button>',
                esc_html($s['id']), esc_html($s['desc']), esc_html($origin), esc_html(rtrim(rtrim(number_format($s['vat'], 2, '.', ''), '0'), '.')),
                esc_attr($s['id']), esc_attr($s['vat'])
            );
        } else {
            echo 'برای این نام قاعده‌ای پیدا نشد. از جستجوی زیر استفاده کنید.';
        }
        echo '<br><input type="search" class="hys-q" placeholder="جستجو در شناسه‌های عمومی، مثلاً: شیر توپی" style="width:60%;margin-top:6px"> ';
        echo '<span class="hys-results"></span></span></p></div>';
    }

    private static function fill_object($product) {
        if (!HYS_Settings::get('auto_fill') || $product->is_type('variation')) {
            return false;
        }
        if ((string) $product->get_meta(self::META_ID, true) !== '') {
            return false;
        }
        $s = HYS_Classifier::suggest($product->get_name());
        if (!$s) {
            return false;
        }
        $product->update_meta_data(self::META_ID, $s['id']);
        $product->update_meta_data(self::META_AUTO, $s['id']);
        if (HYS_Settings::get('fill_vat') && (string) $product->get_meta(self::META_VAT, true) === '') {
            $product->update_meta_data(self::META_VAT, $s['vat']);
        }
        return true;
    }

    /** Admin product form: runs after Hesabyar copied its fields from the form. */
    public static function on_admin_save($product) {
        self::fill_object($product);
    }

    /** Products created or changed elsewhere (REST, import, quick edit). */
    public static function on_any_save($product_id) {
        if (self::$busy) {
            return;
        }
        $id = (string) get_post_meta($product_id, self::META_ID, true);
        if ($id !== '' || !HYS_Settings::get('auto_fill')) {
            return;
        }
        self::$busy = true;
        self::apply($product_id, false);
        self::$busy = false;
    }

    /**
     * Fills one product. $replace_auto: also replace IDs this plugin filled before.
     * @return string 'filled' | 'none' | 'skipped'
     */
    public static function apply($product_id, $replace_auto) {
        $current = (string) get_post_meta($product_id, self::META_ID, true);
        $auto = (string) get_post_meta($product_id, self::META_AUTO, true);
        if ($current !== '' && !($replace_auto && $auto !== '' && $auto === $current)) {
            return 'skipped';
        }
        $s = HYS_Classifier::suggest(get_the_title($product_id));
        if (!$s) {
            return 'none';
        }
        update_post_meta($product_id, self::META_ID, $s['id']);
        update_post_meta($product_id, self::META_AUTO, $s['id']);
        $vat = (string) get_post_meta($product_id, self::META_VAT, true);
        if (HYS_Settings::get('fill_vat') && ($vat === '' || ($replace_auto && $auto !== ''))) {
            update_post_meta($product_id, self::META_VAT, $s['vat']);
        }
        self::sync_hesabyar($product_id);
        return 'filled';
    }

    /** Sets a manually chosen ID (clears the auto flag). */
    public static function set_manual($product_id, $stuff_id) {
        $row = HYS_Data::get($stuff_id);
        update_post_meta($product_id, self::META_ID, $stuff_id);
        delete_post_meta($product_id, self::META_AUTO);
        if ($row && HYS_Settings::get('fill_vat')) {
            update_post_meta($product_id, self::META_VAT, (float) $row['vat']);
        }
        self::sync_hesabyar($product_id);
    }

    /** Keeps Hesabyar's own product table in step with the WooCommerce meta. */
    public static function sync_hesabyar($product_id) {
        if (!class_exists('HY_WooCommerce') || !method_exists('HY_WooCommerce', 'sync_product')) {
            return;
        }
        $p = wc_get_product($product_id);
        if (!$p) {
            return;
        }
        HY_WooCommerce::sync_product($p);
        if ($p->is_type('variable')) {
            foreach ($p->get_children() as $child) {
                HY_WooCommerce::sync_product(wc_get_product($child));
            }
        }
    }

    /**
     * Processes the next batch of products after $after_id.
     * @return array next cursor and counters
     */
    public static function batch($after_id, $replace_auto, $size = 100) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish','private','draft','pending') AND ID > %d ORDER BY ID LIMIT %d",
            $after_id, $size
        ));
        $out = array('filled' => 0, 'none' => 0, 'skipped' => 0, 'last' => $after_id, 'done' => count($ids) < $size);
        foreach ($ids as $id) {
            $r = self::apply((int) $id, $replace_auto);
            $out[$r]++;
            $out['last'] = (int) $id;
        }
        return $out;
    }
}
