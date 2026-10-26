<?php
/**
 * WooCommerce bridge: product fields, order → invoice conversion, order
 * meta box and bulk action, optional automatic creation/sending.
 *
 * @package Hesabyar
 */

if (!defined('ABSPATH')) {
    exit;
}

class HY_WooCommerce {

    public static function active() {
        return class_exists('WooCommerce');
    }

    public static function init() {
        if (!self::active()) {
            return;
        }
        // Product fields.
        add_action('woocommerce_product_options_general_product_data', array(__CLASS__, 'product_fields'));
        add_action('woocommerce_admin_process_product_object', array(__CLASS__, 'save_product_fields'));
        add_action('woocommerce_variation_options_pricing', array(__CLASS__, 'variation_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array(__CLASS__, 'save_variation_fields'), 10, 2);

        // Orders.
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_box'));
        add_action('admin_post_hy_wc_create_invoice', array(__CLASS__, 'handle_create_from_order'));
        foreach (array('bulk_actions-edit-shop_order', 'bulk_actions-woocommerce_page_wc-orders') as $hook) {
            add_filter($hook, array(__CLASS__, 'register_bulk_action'));
        }
        add_filter('handle_bulk_actions-edit-shop_order', array(__CLASS__, 'handle_bulk_action'), 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array(__CLASS__, 'handle_bulk_action'), 10, 3);
        add_action('admin_notices', array(__CLASS__, 'bulk_notice'));

        // Automatic creation.
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'on_status_changed'), 20, 3);
        add_action('hy_wc_auto_send', array(__CLASS__, 'auto_send'));
    }

    /* ---------------- money ---------------- */

    /** Store currency → Rial multiplier. */
    public static function multiplier() {
        $m = (float) HY_Settings::get('wc_amount_multiplier');
        if ($m > 0) {
            return $m;
        }
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'IRR';
        switch ($currency) {
            case 'IRT':
                return 10;
            case 'IRHT':
                return 10000;
            case 'IRHR':
                return 1000;
            default:
                return 1;
        }
    }

    private static function rial($amount) {
        return (int) round(((float) $amount) * self::multiplier());
    }

    /* ---------------- product fields ---------------- */

    public static function product_fields() {
        echo '<div class="options_group">';
        woocommerce_wp_text_input(array(
            'id' => '_hy_stuff_id',
            'label' => 'شناسه کالا مودیان',
            'description' => 'شناسه ۱۳ رقمی کالا/خدمت از stuffid.tax.gov.ir',
            'desc_tip' => true,
            'custom_attributes' => array('maxlength' => 13, 'dir' => 'ltr'),
        ));
        woocommerce_wp_text_input(array(
            'id' => '_hy_unit_code',
            'label' => 'کد واحد مودیان',
            'placeholder' => HY_Settings::get('default_unit_code'),
            'description' => 'مثلاً 1627 برای «عدد»',
            'desc_tip' => true,
            'custom_attributes' => array('dir' => 'ltr'),
        ));
        woocommerce_wp_text_input(array(
            'id' => '_hy_vat_rate',
            'label' => 'نرخ مالیات (%)',
            'placeholder' => HY_Settings::get('default_vat'),
            'type' => 'number',
            'custom_attributes' => array('step' => '0.01', 'min' => 0, 'max' => 100),
        ));
        echo '</div>';
    }

    public static function save_product_fields($product) {
        foreach (array('_hy_stuff_id', '_hy_unit_code', '_hy_vat_rate') as $key) {
            if (isset($_POST[$key])) {
                $value = preg_replace('/[^\d.]/', '', HY_Jalali::latin_digits(wp_unslash($_POST[$key])));
                $product->update_meta_data($key, $value);
            }
        }
        add_action('woocommerce_after_product_object_save', function ($p) use ($product) {
            if ($p->get_id() === $product->get_id()) {
                self::sync_product($p);
            }
        });
    }

    public static function variation_fields($loop, $variation_data, $variation) {
        woocommerce_wp_text_input(array(
            'id' => "_hy_stuff_id_{$loop}",
            'name' => "_hy_stuff_id[{$loop}]",
            'value' => get_post_meta($variation->ID, '_hy_stuff_id', true),
            'label' => 'شناسه کالا مودیان (خالی = مانند محصول اصلی)',
            'wrapper_class' => 'form-row form-row-full',
        ));
    }

    public static function save_variation_fields($variation_id, $i) {
        if (isset($_POST['_hy_stuff_id'][$i])) {
            update_post_meta($variation_id, '_hy_stuff_id', preg_replace('/\D+/', '', HY_Jalali::latin_digits(wp_unslash($_POST['_hy_stuff_id'][$i]))));
        }
    }

    private static function product_meta($product, $key) {
        $v = (string) $product->get_meta($key, true);
        if ($v === '' && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $v = (string) $parent->get_meta($key, true);
            }
        }
        return $v;
    }

    /** Creates/updates the Hesabyar product linked to a WooCommerce product. */
    public static function sync_product($product) {
        if (!$product) {
            return 0;
        }
        $existing = HY_Products::by_wc_id($product->get_id());
        $vat = self::product_meta($product, '_hy_vat_rate');
        $data = array(
            'name' => wp_strip_all_tags($product->get_name()),
            'sku' => (string) $product->get_sku(),
            'unit_code' => self::product_meta($product, '_hy_unit_code') ?: HY_Settings::get('default_unit_code'),
            'stuff_id' => self::product_meta($product, '_hy_stuff_id'),
            'price' => self::rial($product->get_regular_price() !== '' ? $product->get_regular_price() : $product->get_price()),
            'vat_rate' => $vat !== '' ? (float) $vat : (float) HY_Settings::get('default_vat'),
            'wc_product_id' => $product->get_id(),
        );
        if (!$existing) {
            $data['unit_title'] = HY_Settings::get('default_unit_title');
        }
        return HY_Products::save($data, $existing ? (int) $existing['id'] : 0);
    }

    public static function sync_all_products() {
        $count = 0;
        $page = 1;
        do {
            $products = wc_get_products(array('limit' => 100, 'page' => $page, 'type' => array('simple', 'variable', 'variation'), 'status' => array('publish', 'private')));
            foreach ($products as $p) {
                if ($p->is_type('variable')) {
                    foreach ($p->get_children() as $child_id) {
                        self::sync_product(wc_get_product($child_id));
                        $count++;
                    }
                } else {
                    self::sync_product($p);
                    $count++;
                }
            }
            $page++;
        } while (count($products) === 100);
        return $count;
    }

    /* ---------------- order → invoice ---------------- */

    private static function find_or_create_person($order) {
        global $wpdb;
        $t = HY_DB::table('persons');
        $nid = preg_replace('/\D+/', '', HY_Jalali::latin_digits((string) $order->get_meta(HY_Settings::get('wc_national_id_meta'), true)));
        $ec = preg_replace('/\D+/', '', HY_Jalali::latin_digits((string) $order->get_meta(HY_Settings::get('wc_economic_code_meta'), true)));
        $phone = preg_replace('/\D+/', '', HY_Jalali::latin_digits((string) $order->get_billing_phone()));
        $customer_id = (int) $order->get_customer_id();

        $person = null;
        if ($nid !== '') {
            $person = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE national_id=%s LIMIT 1", $nid), ARRAY_A);
        }
        if (!$person && $customer_id) {
            $person = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE wc_customer_id=%d LIMIT 1", $customer_id), ARRAY_A);
        }
        if (!$person && $phone !== '') {
            $person = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE mobile=%s LIMIT 1", $phone), ARRAY_A);
        }

        $company = trim((string) $order->get_billing_company());
        $name = $company !== '' ? $company : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $type = $company !== '' || strlen($nid) === 11 ? 2 : 1;
        if ($nid === '' && $ec === '') {
            $type = $company !== '' ? 2 : 5;
        }
        $address = trim(implode(' - ', array_filter(array(
            WC()->countries->get_states('IR')[$order->get_billing_state()] ?? $order->get_billing_state(),
            $order->get_billing_city(),
            $order->get_billing_address_1(),
            $order->get_billing_address_2(),
        ))));
        $data = array(
            'name' => $name !== '' ? $name : 'مشتری فروشگاه',
            'person_type' => $type,
            'national_id' => $nid,
            'economic_code' => $ec,
            'postal_code' => preg_replace('/\D+/', '', HY_Jalali::latin_digits((string) $order->get_billing_postcode())),
            'mobile' => $phone,
            'email' => (string) $order->get_billing_email(),
            'address' => $address,
            'wc_customer_id' => $customer_id,
        );
        if ($person) {
            // Only fill blanks; never overwrite what was edited in Hesabyar.
            $update = array();
            foreach ($data as $k => $v) {
                if (($person[$k] === '' || $person[$k] === '0' || $person[$k] === null) && $v !== '' && $v !== 0) {
                    $update[$k] = $v;
                }
            }
            if ($update) {
                HY_Persons::save($update, (int) $person['id']);
            }
            return (int) $person['id'];
        }
        return HY_Persons::save($data);
    }

    /**
     * Converts each order line into an invoice line. VAT handling:
     *  - the order line has WooCommerce tax → that tax is used as-is
     *  - otherwise the paid price is treated as VAT-inclusive at the product
     *    rate, so the invoice total equals what the customer paid.
     */
    private static function line_from_amounts($product_row, $description, $qty, $subtotal_rial, $total_rial, $tax_rial, $rate) {
        $qty = max(0.0001, (float) $qty);
        if ($tax_rial > 0) {
            $gross = $subtotal_rial;
            $after = $total_rial;
            $rate = $after > 0 ? round($tax_rial * 100 / $after, 2) : $rate;
        } else {
            $factor = 1 + ($rate / 100);
            $gross = (int) round($subtotal_rial / $factor);
            $after = (int) round($total_rial / $factor);
        }
        $unit_price = (int) round($gross / $qty);
        $gross = (int) round($unit_price * $qty);
        $line = array(
            'product_id' => $product_row ? (int) $product_row['id'] : 0,
            'description' => $description,
            'unit_title' => $product_row ? $product_row['unit_title'] : HY_Settings::get('default_unit_title'),
            'unit_code' => $product_row ? $product_row['unit_code'] : HY_Settings::get('default_unit_code'),
            'stuff_id' => $product_row ? $product_row['stuff_id'] : '',
            'qty' => $qty,
            'unit_price' => $unit_price,
            'discount' => max(0, $gross - $after),
            'vat_rate' => $rate,
        );
        return HY_Invoices::calc_line($line);
    }

    /**
     * @return int|WP_Error invoice id
     */
    public static function create_invoice_from_order($order_id, $status = 'confirmed') {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('missing', 'سفارش پیدا نشد.');
        }
        $existing = HY_Invoices::by_wc_order($order->get_id());
        if ($existing) {
            return (int) $existing['id'];
        }

        $items = array();
        $sort = 0;
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            $row = null;
            $rate = (float) HY_Settings::get('default_vat');
            if ($product) {
                $pid = self::sync_product($product);
                $row = HY_Products::get($pid);
                $rate = (float) $row['vat_rate'];
            }
            $line = self::line_from_amounts(
                $row,
                wp_strip_all_tags($item->get_name()),
                $item->get_quantity(),
                self::rial($item->get_subtotal()),
                self::rial($item->get_total()),
                self::rial($item->get_total_tax()),
                $rate
            );
            $line['sort'] = $sort++;
            $items[] = $line;
        }
        $default_rate = (float) HY_Settings::get('default_vat');
        foreach ($order->get_items(array('shipping', 'fee')) as $item) {
            $total = self::rial($item->get_total());
            if ($total <= 0) {
                continue;
            }
            $tax = self::rial($item->get_total_tax());
            $line = self::line_from_amounts(null, wp_strip_all_tags($item->get_name()) ?: 'هزینه ارسال', 1, $total, $total, $tax, $default_rate);
            $line['stuff_id'] = (string) HY_Settings::get('wc_shipping_stuff_id');
            $line['unit_title'] = 'خدمت';
            $line['sort'] = $sort++;
            $items[] = $line;
        }
        if (!$items) {
            return new WP_Error('empty', 'سفارش ردیف قابل فاکتور ندارد.');
        }

        $person_id = self::find_or_create_person($order);
        $created = $order->get_date_paid() ?: $order->get_date_created();
        $ts = $created ? $created->getTimestamp() : time();
        $header = array(
            'kind' => 'sale',
            'person_id' => $person_id,
            'title' => 'سفارش آنلاین #' . $order->get_order_number(),
            'reference' => (string) $order->get_order_number(),
            'date_ts' => min($ts, time()),
            'due_ts' => min($ts, time()),
            'status' => $status,
            'paid' => $order->is_paid() ? 1 : 0,
            'wc_order_id' => $order->get_id(),
            'm_inty' => 0,
            'm_inp' => 1,
            'm_ins' => 1,
            'm_setm' => (int) HY_Settings::get('default_setm'),
        );
        $id = HY_Invoices::save($header, $items);
        if (!is_wp_error($id)) {
            $order->add_order_note('فاکتور حساب‌یار شماره ' . HY_Invoices::get($id, false)['number'] . ' ساخته شد.');
        }
        return $id;
    }

    public static function on_status_changed($order_id, $from, $to) {
        if (!HY_Settings::get('wc_auto_create') || $to !== HY_Settings::get('wc_auto_status')) {
            return;
        }
        $id = self::create_invoice_from_order($order_id, 'confirmed');
        if (!is_wp_error($id) && HY_Settings::get('wc_auto_send')) {
            wp_schedule_single_event(time() + 30, 'hy_wc_auto_send', array($id));
        }
    }

    public static function auto_send($invoice_id) {
        HY_Moadian_Service::send(array((int) $invoice_id));
    }

    /* ---------------- admin UI on orders ---------------- */

    public static function add_meta_box() {
        $screens = array('shop_order');
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }
        foreach ($screens as $screen) {
            add_meta_box('hy_invoice_box', 'حساب‌یار / مودیان', array(__CLASS__, 'render_meta_box'), $screen, 'side', 'high');
        }
    }

    public static function render_meta_box($post_or_order) {
        $order = ($post_or_order instanceof WP_Post) ? wc_get_order($post_or_order->ID) : $post_or_order;
        if (!$order) {
            return;
        }
        $inv = HY_Invoices::by_wc_order($order->get_id());
        if ($inv) {
            $url = admin_url('admin.php?page=hy-invoices&view=' . $inv['id']);
            echo '<p>فاکتور <a href="' . esc_url($url) . '"><strong>شماره ' . esc_html($inv['number']) . '</strong></a></p>';
            echo '<p>مودیان: ' . HY_Admin::m_badge($inv['m_status']) . '</p>';
            if ($inv['m_taxid']) {
                echo '<p dir="ltr" style="font-family:monospace;font-size:11px">' . esc_html($inv['m_taxid']) . '</p>';
            }
            return;
        }
        $url = wp_nonce_url(admin_url('admin-post.php?action=hy_wc_create_invoice&order_id=' . $order->get_id()), 'hy_wc_create_' . $order->get_id());
        echo '<p>برای این سفارش هنوز فاکتوری ساخته نشده است.</p>';
        echo '<a class="button button-primary" href="' . esc_url($url) . '">ساخت فاکتور</a>';
    }

    public static function handle_create_from_order() {
        $order_id = (int) ($_GET['order_id'] ?? 0);
        if (!current_user_can('hy_manage') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'hy_wc_create_' . $order_id)) {
            wp_die('دسترسی غیرمجاز');
        }
        $id = self::create_invoice_from_order($order_id);
        if (is_wp_error($id)) {
            wp_die(esc_html($id->get_error_message()));
        }
        wp_safe_redirect(admin_url('admin.php?page=hy-invoices&view=' . $id . '&hy_msg=created'));
        exit;
    }

    public static function register_bulk_action($actions) {
        $actions['hy_create_invoices'] = 'ساخت فاکتور حساب‌یار';
        return $actions;
    }

    public static function handle_bulk_action($redirect, $action, $ids) {
        if ($action !== 'hy_create_invoices' || !current_user_can('hy_manage')) {
            return $redirect;
        }
        $ok = 0;
        $err = 0;
        foreach ($ids as $id) {
            $r = self::create_invoice_from_order((int) $id);
            is_wp_error($r) ? $err++ : $ok++;
        }
        return add_query_arg(array('hy_bulk_ok' => $ok, 'hy_bulk_err' => $err), $redirect);
    }

    public static function bulk_notice() {
        if (!isset($_GET['hy_bulk_ok'])) {
            return;
        }
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s فاکتور ساخته شد%s. <a href="%s">مشاهده در سامانه مودیان</a></p></div>',
            (int) $_GET['hy_bulk_ok'],
            !empty($_GET['hy_bulk_err']) ? '، ' . (int) $_GET['hy_bulk_err'] . ' سفارش خطا داشت' : '',
            esc_url(admin_url('admin.php?page=hy-moadian'))
        );
    }
}
