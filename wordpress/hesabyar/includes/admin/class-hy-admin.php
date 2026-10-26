<?php
/**
 * Admin menu, assets, form handlers and AJAX endpoints.
 *
 * @package Hesabyar
 */

if (!defined('ABSPATH')) {
    exit;
}

class HY_Admin {

    const CAP = 'hy_manage';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));

        $posts = array(
            'hy_save_invoice', 'hy_invoice_action', 'hy_create_return', 'hy_save_person', 'hy_delete_person',
            'hy_save_product', 'hy_delete_product', 'hy_sync_products', 'hy_moadian_bulk', 'hy_save_settings',
            'hy_generate_keys', 'hy_download', 'hy_print',
        );
        foreach ($posts as $action) {
            add_action('admin_post_' . $action, array(__CLASS__, 'handle_' . substr($action, 3)));
        }
        foreach (array('hy_search_persons', 'hy_search_products', 'hy_quick_person', 'hy_quick_product', 'hy_test_connection') as $ajax) {
            add_action('wp_ajax_' . $ajax, array(__CLASS__, 'ajax_' . substr($ajax, 3)));
        }
    }

    public static function can() {
        return current_user_can(self::CAP) || current_user_can('manage_options');
    }

    private static function guard($nonce_action) {
        if (!self::can()) {
            wp_die('دسترسی غیرمجاز', 403);
        }
        check_admin_referer($nonce_action);
    }

    public static function menu() {
        $cap = current_user_can(self::CAP) ? self::CAP : 'manage_options';
        add_menu_page('حساب‌یار', 'حساب‌یار', $cap, 'hy-dashboard', array(__CLASS__, 'page_dashboard'), 'dashicons-calculator', 56);
        add_submenu_page('hy-dashboard', 'داشبورد', 'داشبورد', $cap, 'hy-dashboard', array(__CLASS__, 'page_dashboard'));
        add_submenu_page('hy-dashboard', 'فاکتورهای فروش', 'فاکتورهای فروش', $cap, 'hy-invoices', array(__CLASS__, 'page_invoices'));
        add_submenu_page('hy-dashboard', 'فروش جدید', 'فروش جدید', $cap, 'hy-invoice-new', array(__CLASS__, 'page_invoice_new'));
        add_submenu_page('hy-dashboard', 'برگشت از فروش', 'برگشت از فروش', $cap, 'hy-returns', array(__CLASS__, 'page_returns'));
        add_submenu_page('hy-dashboard', 'اشخاص', 'اشخاص', $cap, 'hy-persons', array(__CLASS__, 'page_persons'));
        add_submenu_page('hy-dashboard', 'کالاها و خدمات', 'کالاها و خدمات', $cap, 'hy-products', array(__CLASS__, 'page_products'));
        add_submenu_page('hy-dashboard', 'سامانه مودیان', 'سامانه مودیان', $cap, 'hy-moadian', array(__CLASS__, 'page_moadian'));
        add_submenu_page('hy-dashboard', 'تنظیمات', 'تنظیمات', $cap, 'hy-settings', array(__CLASS__, 'page_settings'));
    }

    public static function assets($hook) {
        if (strpos((string) $hook, 'hy-') === false && strpos((string) $hook, 'page_hy') === false) {
            return;
        }
        wp_enqueue_style('hy-admin', HY_URL . 'assets/admin.css', array(), HY_VERSION);
        wp_enqueue_script('hy-admin', HY_URL . 'assets/admin.js', array('jquery'), HY_VERSION, true);
        wp_localize_script('hy-admin', 'HY', array(
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hy_ajax'),
            'defaultVat' => (float) HY_Settings::get('default_vat'),
            'defaultUnit' => HY_Settings::get('default_unit_title'),
            'defaultUnitCode' => HY_Settings::get('default_unit_code'),
            'personTypes' => HY_Persons::$types,
        ));
    }

    /* ================================================================== */
    /*  Helpers used by the views                                          */
    /* ================================================================== */

    public static function fa($s) {
        return HY_Jalali::persian_digits($s);
    }

    public static function money($amount, $unit = true) {
        $s = self::fa(number_format((float) $amount));
        return $unit ? $s . ' <span class="hy-cur">ریال</span>' : $s;
    }

    public static function m_badge($status) {
        $label = HY_Invoices::$m_statuses[$status] ?? $status;
        return '<span class="hy-badge hy-m-' . esc_attr($status) . '">' . esc_html($label) . '</span>';
    }

    public static function url($page, array $args = array()) {
        return add_query_arg(array_merge(array('page' => $page), $args), admin_url('admin.php'));
    }

    public static function post_url($action, array $args = array()) {
        return wp_nonce_url(add_query_arg(array_merge(array('action' => $action), $args), admin_url('admin-post.php')), $action);
    }

    public static function redirect($page, array $args = array()) {
        wp_safe_redirect(self::url($page, $args));
        exit;
    }

    public static function flash($message, $type = 'success') {
        set_transient('hy_flash_' . get_current_user_id(), array('m' => $message, 't' => $type), 60);
    }

    /**
     * Success messages that fit on one line become a toast; anything longer
     * (reports, error lists) stays on the page so it can be read.
     */
    public static function render_flash() {
        $key = 'hy_flash_' . get_current_user_id();
        $f = get_transient($key);
        if (!$f) {
            return;
        }
        delete_transient($key);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $f['m']))));
        if ($f['t'] === 'success' && count($lines) === 1) {
            echo '<div class="hy-toast-source" data-toast="' . esc_attr($lines[0]) . '" hidden></div>';
            return;
        }
        echo '<div class="hy-alert hy-alert-' . esc_attr($f['t']) . '" role="status"><strong>' . esc_html(array_shift($lines)) . '</strong>';
        if ($lines) {
            echo '<ul>';
            foreach ($lines as $l) {
                echo '<li>' . esc_html($l) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    /** Small inline SVG icon set (stroke icons, inherit colour). */
    public static function icon($name) {
        static $paths = array(
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
            'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
            'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
            'print' => '<path d="M7 9V3h10v6M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><rect x="7" y="14" width="10" height="7"/>',
            'send' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
            'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/>',
            'check' => '<path d="m5 12 5 5 9-10"/>',
            'more' => '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
            'refresh' => '<path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/>',
            'chevron' => '<path d="m6 9 6 6 6-6"/>',
            'back' => '<path d="m9 6 6 6-6 6"/>',
            'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3"/>',
            'download' => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
        );
        $p = $paths[$name] ?? '';
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
    }

    /** Rial amount in Persian words, e.g. «چهل و چهار میلیون و دویست و بیست هزار ریال». */
    public static function words($amount) {
        $n = (int) round((float) $amount);
        if ($n === 0) {
            return 'صفر ریال';
        }
        $ones = array('', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه');
        $teens = array('ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده');
        $tens = array('', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود');
        $hundreds = array('', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد');
        $scales = array('', 'هزار', 'میلیون', 'میلیارد', 'هزار میلیارد');
        $three = function ($x) use ($ones, $teens, $tens, $hundreds) {
            $parts = array();
            if ($x >= 100) {
                $parts[] = $hundreds[intdiv($x, 100)];
                $x %= 100;
            }
            if ($x >= 20) {
                $parts[] = $tens[intdiv($x, 10)];
                $x %= 10;
                if ($x) {
                    $parts[] = $ones[$x];
                }
            } elseif ($x >= 10) {
                $parts[] = $teens[$x - 10];
            } elseif ($x > 0) {
                $parts[] = $ones[$x];
            }
            return implode(' و ', $parts);
        };
        $neg = $n < 0;
        $n = abs($n);
        $groups = array();
        $i = 0;
        while ($n > 0 && $i < count($scales)) {
            $g = $n % 1000;
            if ($g) {
                $groups[] = ($g === 1 && $i > 0) ? $scales[$i] : trim($three($g) . ' ' . $scales[$i]);
            }
            $n = intdiv($n, 1000);
            $i++;
        }
        return ($neg ? 'منفی ' : '') . implode(' و ', array_reverse($groups)) . ' ریال';
    }

    public static function report_text(array $r, $kind = 'send') {
        if ($kind === 'send') {
            $text = sprintf('%s فاکتور ارسال شد و در صف بررسی سامانه قرار گرفت.', self::fa($r['sent']));
            if ($r['failed']) {
                $text .= ' ' . self::fa($r['failed']) . ' فاکتور خطا داشت.';
            }
            if ($r['skipped']) {
                $text .= ' ' . self::fa($r['skipped']) . ' فاکتور رد شد.';
            }
        } else {
            $text = sprintf('تایید شده: %s، خطا: %s، هنوز در صف: %s', self::fa($r['success']), self::fa($r['failed']), self::fa($r['pending']));
        }
        if (!empty($r['messages'])) {
            $text .= "\n" . implode("\n", array_slice($r['messages'], 0, 15));
        }
        return $text;
    }

    private static function view($name, array $vars = array()) {
        extract($vars, EXTR_SKIP);
        echo '<div class="wrap hy-wrap" dir="rtl">';
        self::render_flash();
        include HY_DIR . 'includes/admin/views/' . $name . '.php';
        echo '</div>';
    }

    /* ================================================================== */
    /*  Pages                                                              */
    /* ================================================================== */

    public static function page_invoices() {
        if (!empty($_GET['view'])) {
            $inv = HY_Invoices::get((int) $_GET['view']);
            if (!$inv) {
                wp_die('فاکتور پیدا نشد.');
            }
            return self::view('invoice-view', array('inv' => $inv));
        }
        if (!empty($_GET['edit'])) {
            $inv = HY_Invoices::get((int) $_GET['edit']);
            return self::view('invoice-form', array('inv' => $inv, 'kind' => $inv['kind']));
        }
        if (!empty($_GET['return_of'])) {
            $sale = HY_Invoices::get((int) $_GET['return_of']);
            return self::view('return-form', array('sale' => $sale));
        }
        self::view('invoices', array('kind' => 'sale'));
    }

    public static function page_dashboard() {
        self::view('dashboard');
    }

    public static function page_invoice_new() {
        self::view('invoice-form', array('inv' => null, 'kind' => 'sale'));
    }

    public static function page_returns() {
        self::view('invoices', array('kind' => 'return'));
    }

    public static function page_persons() {
        self::view('persons');
    }

    public static function page_products() {
        self::view('products');
    }

    public static function page_moadian() {
        self::view('moadian');
    }

    public static function page_settings() {
        self::view('settings');
    }

    /* ================================================================== */
    /*  Invoice handlers                                                   */
    /* ================================================================== */

    public static function handle_save_invoice() {
        self::guard('hy_save_invoice');
        $in = wp_unslash($_POST);
        $id = (int) ($in['id'] ?? 0);
        $kind = ($in['kind'] ?? 'sale') === 'return' ? 'return' : 'sale';
        $submit = sanitize_key($in['submit_as'] ?? 'confirmed');

        $date = HY_Jalali::parse($in['date'] ?? '', $in['time'] ?? '00:00');
        $due = HY_Jalali::parse($in['due_date'] ?? '') ?: $date;
        if (!$date) {
            self::flash('تاریخ فاکتور نامعتبر است.', 'error');
            self::redirect($id ? 'hy-invoices' : 'hy-invoice-new', $id ? array('edit' => $id) : array());
        }
        if ($date > time() + 300) {
            self::flash('تاریخ فاکتور نمی‌تواند در آینده باشد (سامانه مودیان صورتحساب با تاریخ آینده را نمی‌پذیرد).', 'error');
            self::redirect($id ? 'hy-invoices' : 'hy-invoice-new', $id ? array('edit' => $id) : array());
        }
        $header = array(
            'kind' => $kind,
            'person_id' => (int) ($in['person_id'] ?? 0),
            'title' => sanitize_text_field($in['title'] ?? ''),
            'reference' => sanitize_text_field($in['reference'] ?? ''),
            'date_ts' => $date,
            'due_ts' => $due,
            'notes' => sanitize_textarea_field($in['notes'] ?? ''),
            'status' => $submit === 'draft' ? 'draft' : 'confirmed',
            'm_inty' => (int) ($in['m_inty'] ?? 0),
            'm_inp' => (int) ($in['m_inp'] ?? 1),
            'm_setm' => (int) ($in['m_setm'] ?? 1),
            'm_cap' => (int) preg_replace('/\D+/', '', HY_Jalali::latin_digits($in['m_cap'] ?? '0')),
        );
        if ($header['title'] === '' && $header['person_id'] && ($p = HY_Persons::get($header['person_id']))) {
            $header['title'] = $p['name'];
        }
        if (empty($in['auto_number']) && !empty($in['number'])) {
            $header['number'] = (int) HY_Jalali::latin_digits($in['number']);
        }
        if (!$id) {
            $header['m_ins'] = $kind === 'return' ? 4 : 1;
        }
        if ($kind === 'return' && !empty($in['ref_invoice_id'])) {
            $header['ref_invoice_id'] = (int) $in['ref_invoice_id'];
        }
        $items = HY_Invoices::sanitize_items((array) ($in['items'] ?? array()));
        $result = HY_Invoices::save($header, $items, $id);
        if (is_wp_error($result)) {
            self::flash($result->get_error_message(), 'error');
            set_transient('hy_form_' . get_current_user_id(), $in, 120);
            self::redirect($id ? 'hy-invoices' : 'hy-invoice-new', $id ? array('edit' => $id) : array('restore' => 1));
        }
        if ($submit === 'send') {
            $report = HY_Moadian_Service::send(array($result));
            self::flash("فاکتور ذخیره شد.\n" . self::report_text($report), $report['failed'] ? 'error' : 'success');
            self::redirect('hy-invoices', array('view' => $result));
        }
        self::flash($header['status'] === 'draft' ? 'فاکتور به‌صورت پیش‌نویس ذخیره شد.' : 'فاکتور ذخیره و تایید شد.');
        if ($submit === 'next') {
            self::redirect('hy-invoice-new');
        }
        self::redirect('hy-invoices', array('view' => $result));
    }

    public static function handle_invoice_action() {
        self::guard('hy_invoice_action');
        $id = (int) ($_REQUEST['id'] ?? 0);
        $do = sanitize_key($_REQUEST['do'] ?? '');
        $inv = HY_Invoices::get($id, false);
        $back = $inv && $inv['kind'] === 'return' ? 'hy-returns' : 'hy-invoices';
        switch ($do) {
            case 'delete':
                $r = HY_Invoices::delete($id);
                if (is_wp_error($r)) {
                    self::flash($r->get_error_message(), 'error');
                    self::redirect('hy-invoices', array('view' => $id));
                }
                self::flash('فاکتور حذف شد.');
                self::redirect($back);
                break;
            case 'copy':
                $new = HY_Invoices::copy($id);
                if (is_wp_error($new)) {
                    self::flash($new->get_error_message(), 'error');
                    self::redirect('hy-invoices', array('view' => $id));
                }
                self::flash('یک کپی پیش‌نویس از فاکتور ساخته شد.');
                self::redirect('hy-invoices', array('edit' => $new));
                break;
            case 'paid':
            case 'unpaid':
                HY_Invoices::update_fields($id, array('paid' => $do === 'paid' ? 1 : 0));
                self::flash($do === 'paid' ? 'وضعیت به «پرداخت شده» تغییر کرد.' : 'وضعیت به «پرداخت نشده» تغییر کرد.');
                break;
            case 'confirm':
                HY_Invoices::update_fields($id, array('status' => 'confirmed'));
                self::flash('فاکتور تایید شد.');
                break;
            case 'send':
                $report = HY_Moadian_Service::send(array($id));
                self::flash(self::report_text($report), $report['failed'] ? 'error' : 'success');
                break;
            case 'inquiry':
                try {
                    $report = HY_Moadian_Service::inquire(array($id));
                    self::flash(self::report_text($report, 'inquiry'));
                } catch (Exception $e) {
                    self::flash($e->getMessage(), 'error');
                }
                break;
        }
        self::redirect('hy-invoices', array('view' => $id));
    }

    public static function handle_create_return() {
        self::guard('hy_create_return');
        $sale_id = (int) ($_POST['sale_id'] ?? 0);
        $qty = array();
        foreach ((array) ($_POST['qty'] ?? array()) as $item_id => $q) {
            $qty[(int) $item_id] = (float) HY_Jalali::latin_digits(wp_unslash($q));
        }
        $id = HY_Invoices::create_return($sale_id, $qty);
        if (is_wp_error($id)) {
            self::flash($id->get_error_message(), 'error');
            self::redirect('hy-invoices', array('return_of' => $sale_id));
        }
        self::flash('فاکتور برگشت از فروش ساخته شد. برای ثبت در مودیان، آن را ارسال کنید.');
        self::redirect('hy-invoices', array('view' => $id));
    }

    public static function handle_print() {
        if (!self::can()) {
            wp_die('دسترسی غیرمجاز', 403);
        }
        check_admin_referer('hy_print');
        $inv = HY_Invoices::get((int) ($_GET['id'] ?? 0));
        if (!$inv) {
            wp_die('فاکتور پیدا نشد.');
        }
        include HY_DIR . 'includes/admin/views/invoice-print.php';
        exit;
    }

    /* ================================================================== */
    /*  Persons / products                                                 */
    /* ================================================================== */

    public static function handle_save_person() {
        self::guard('hy_save_person');
        $id = (int) ($_POST['id'] ?? 0);
        $data = HY_Persons::sanitize(wp_unslash($_POST));
        $errors = HY_Persons::validate($data);
        if ($errors) {
            self::flash(implode("\n", $errors), 'error');
            self::redirect('hy-persons', array('edit' => $id ?: 'new'));
        }
        HY_Persons::save($data, $id);
        self::flash('شخص ذخیره شد.');
        self::redirect('hy-persons');
    }

    public static function handle_delete_person() {
        self::guard('hy_delete_person');
        $r = HY_Persons::delete((int) ($_GET['id'] ?? 0));
        self::flash(is_wp_error($r) ? $r->get_error_message() : 'شخص حذف شد.', is_wp_error($r) ? 'error' : 'success');
        self::redirect('hy-persons');
    }

    public static function handle_save_product() {
        self::guard('hy_save_product');
        $id = (int) ($_POST['id'] ?? 0);
        $data = HY_Products::sanitize(wp_unslash($_POST));
        $errors = HY_Products::validate($data);
        if ($errors) {
            self::flash(implode("\n", $errors), 'error');
            self::redirect('hy-products', array('edit' => $id ?: 'new'));
        }
        HY_Products::save($data, $id);
        $p = HY_Products::get($id);
        if ($p && $p['wc_product_id'] && function_exists('wc_get_product') && ($wc = wc_get_product($p['wc_product_id']))) {
            $wc->update_meta_data('_hy_stuff_id', $data['stuff_id']);
            $wc->update_meta_data('_hy_unit_code', $data['unit_code']);
            $wc->update_meta_data('_hy_vat_rate', $data['vat_rate']);
            $wc->save_meta_data();
        }
        self::flash('کالا ذخیره شد.');
        self::redirect('hy-products');
    }

    public static function handle_delete_product() {
        self::guard('hy_delete_product');
        $r = HY_Products::delete((int) ($_GET['id'] ?? 0));
        self::flash(is_wp_error($r) ? $r->get_error_message() : 'کالا حذف شد.', is_wp_error($r) ? 'error' : 'success');
        self::redirect('hy-products');
    }

    public static function handle_sync_products() {
        self::guard('hy_sync_products');
        if (!HY_WooCommerce::active()) {
            self::flash('ووکامرس فعال نیست.', 'error');
            self::redirect('hy-products');
        }
        $n = HY_WooCommerce::sync_all_products();
        self::flash(self::fa($n) . ' کالا از ووکامرس همگام شد.');
        self::redirect('hy-products');
    }

    /* ================================================================== */
    /*  Moadian page                                                       */
    /* ================================================================== */

    public static function handle_moadian_bulk() {
        self::guard('hy_moadian_bulk');
        $ids = array_map('intval', (array) ($_POST['ids'] ?? array()));
        $do = sanitize_key($_POST['do'] ?? '');
        $value = sanitize_text_field(wp_unslash($_POST['value'] ?? ''));
        $back = array('tab' => sanitize_key($_POST['tab'] ?? 'all'));
        if ($do !== 'inquiry_all' && !$ids) {
            self::flash('هیچ فاکتوری انتخاب نشده است.', 'error');
            self::redirect('hy-moadian', $back);
        }
        try {
            switch ($do) {
                case 'send':
                    $r = HY_Moadian_Service::send($ids);
                    self::flash(self::report_text($r), $r['failed'] ? 'error' : 'success');
                    break;
                case 'inquiry':
                    $r = HY_Moadian_Service::inquire($ids);
                    self::flash(self::report_text($r, 'inquiry'));
                    break;
                case 'inquiry_all':
                    $r = HY_Moadian_Service::inquire(null);
                    self::flash(self::report_text($r, 'inquiry'));
                    break;
                case 'delete':
                    $n = 0;
                    foreach ($ids as $id) {
                        $res = HY_Invoices::delete($id);
                        if (!is_wp_error($res)) {
                            $n++;
                        }
                    }
                    self::flash(self::fa($n) . ' فاکتور حذف شد (فاکتورهای ارسال‌شده حذف نمی‌شوند).');
                    break;
                case 'm_status':
                case 'm_inty':
                case 'm_inp':
                case 'm_ins':
                case 'm_setm':
                case 'm_irtaxid':
                case 'serial':
                    $r = HY_Moadian_Service::bulk_update($ids, $do, $value);
                    self::flash(self::fa($r['changed']) . ' فاکتور به‌روز شد.' . ($r['messages'] ? "\n" . implode("\n", $r['messages']) : ''), $r['messages'] ? 'warning' : 'success');
                    break;
            }
        } catch (Exception $e) {
            self::flash($e->getMessage(), 'error');
        }
        self::redirect('hy-moadian', $back);
    }

    /* ================================================================== */
    /*  Settings                                                           */
    /* ================================================================== */

    public static function handle_save_settings() {
        self::guard('hy_save_settings');
        $in = wp_unslash($_POST);
        $tab = sanitize_key($in['tab'] ?? 'moadian');
        $digits = function ($v) {
            return preg_replace('/\D+/', '', HY_Jalali::latin_digits((string) $v));
        };
        $values = array();
        $errors = array();
        if ($tab === 'moadian') {
            $fiscal = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['fiscal_id'] ?? '')));
            if ($fiscal !== '' && strlen($fiscal) !== 6) {
                $errors[] = 'شناسه یکتای حافظه مالیاتی باید ۶ کاراکتر باشد.';
            }
            $ec = $digits($in['economic_code'] ?? '');
            if ($ec !== '' && !preg_match('/^(\d{11}|\d{14})$/', $ec)) {
                $errors[] = 'کد اقتصادی باید ۱۱ یا ۱۴ رقم باشد.';
            }
            $values = array(
                'fiscal_id' => $fiscal,
                'economic_code' => $ec,
                'protocol' => ($in['protocol'] ?? '') === 'v2' ? 'v2' : 'selftsp',
                'environment' => ($in['environment'] ?? '') === 'sandbox' ? 'sandbox' : 'production',
                'next_serial' => max(1, (int) $digits($in['next_serial'] ?? 1000)),
                'auto_inquiry' => empty($in['auto_inquiry']) ? 0 : 1,
            );
            foreach (array('selftsp_url_production', 'selftsp_url_sandbox', 'v2_url_production', 'v2_url_sandbox') as $k) {
                if (!empty($in[$k])) {
                    $values[$k] = esc_url_raw(trim($in[$k]));
                }
            }
            if (trim((string) ($in['private_key'] ?? '')) !== '') {
                try {
                    HY_Settings::import_private_key($in['private_key']);
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
            if (trim((string) ($in['certificate'] ?? '')) !== '') {
                $cert = trim($in['certificate']);
                $pem = strpos($cert, '-----BEGIN') === false
                    ? "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+/', '', $cert), 64, "\n") . "-----END CERTIFICATE-----"
                    : $cert;
                if (openssl_x509_read($pem)) {
                    HY_Settings::set_secret('certificate', $pem);
                } else {
                    $errors[] = 'گواهی واردشده معتبر نیست.';
                }
            }
            if (!empty($in['remove_certificate'])) {
                HY_Settings::set_secret('certificate', '');
            }
            HY_Moadian_Service::clear_cache();
        } elseif ($tab === 'business') {
            $values = array(
                'seller_name' => sanitize_text_field($in['seller_name'] ?? ''),
                'seller_national_id' => $digits($in['seller_national_id'] ?? ''),
                'seller_postal_code' => $digits($in['seller_postal_code'] ?? ''),
                'seller_phone' => sanitize_text_field($in['seller_phone'] ?? ''),
                'seller_address' => sanitize_textarea_field($in['seller_address'] ?? ''),
            );
        } elseif ($tab === 'invoice') {
            $stuff = $digits($in['default_stuff_id'] ?? '');
            if ($stuff !== '' && strlen($stuff) !== 13) {
                $errors[] = 'شناسه کالای پیش‌فرض باید ۱۳ رقم باشد.';
            }
            $values = array(
                'default_vat' => (float) HY_Jalali::latin_digits($in['default_vat'] ?? 10),
                'default_unit_title' => sanitize_text_field($in['default_unit_title'] ?? 'عدد'),
                'default_unit_code' => $digits($in['default_unit_code'] ?? '1627') ?: '1627',
                'default_stuff_id' => $stuff,
                'default_inty' => (int) ($in['default_inty'] ?? 0),
                'default_setm' => max(1, min(3, (int) ($in['default_setm'] ?? 1))),
            );
        } elseif ($tab === 'woocommerce') {
            $stuff = $digits($in['wc_shipping_stuff_id'] ?? '');
            $values = array(
                'wc_auto_create' => empty($in['wc_auto_create']) ? 0 : 1,
                'wc_auto_status' => sanitize_key($in['wc_auto_status'] ?? 'completed'),
                'wc_auto_send' => empty($in['wc_auto_send']) ? 0 : 1,
                'wc_amount_multiplier' => (float) HY_Jalali::latin_digits($in['wc_amount_multiplier'] ?? 0),
                'wc_national_id_meta' => sanitize_text_field($in['wc_national_id_meta'] ?? '_billing_national_id'),
                'wc_economic_code_meta' => sanitize_text_field($in['wc_economic_code_meta'] ?? '_billing_economic_code'),
                'wc_shipping_stuff_id' => $stuff,
            );
        }
        HY_Settings::update($values);
        self::flash($errors ? "برخی موارد ذخیره نشد:\n" . implode("\n", $errors) : 'تنظیمات ذخیره شد.', $errors ? 'error' : 'success');
        self::redirect('hy-settings', array('tab' => $tab));
    }

    public static function handle_generate_keys() {
        self::guard('hy_generate_keys');
        $in = wp_unslash($_POST);
        if (empty($in['confirm_replace']) && HY_Settings::has_secret('private_key')) {
            self::flash('برای جایگزینی کلید فعلی، گزینه تایید را علامت بزنید.', 'error');
            self::redirect('hy-settings', array('tab' => 'moadian'));
        }
        try {
            HY_Settings::generate_keys(array(
                'common_name' => sanitize_text_field($in['csr_name'] ?? ''),
                'serial_number' => preg_replace('/\D+/', '', HY_Jalali::latin_digits($in['csr_national_id'] ?? '')),
                'organization' => sanitize_text_field($in['csr_org'] ?? ''),
                'state' => sanitize_text_field($in['csr_state'] ?? ''),
                'city' => sanitize_text_field($in['csr_city'] ?? ''),
            ));
            HY_Moadian_Service::clear_cache();
            self::flash("کلید جدید ساخته شد.\nکلید عمومی را در کارپوشه مودیان ثبت کنید و شناسه حافظه جدید را در همین صفحه وارد کنید.");
        } catch (Exception $e) {
            self::flash($e->getMessage(), 'error');
        }
        self::redirect('hy-settings', array('tab' => 'moadian'));
    }

    public static function handle_download() {
        self::guard('hy_download');
        $what = sanitize_key($_GET['what'] ?? '');
        if ($what === 'public') {
            $content = HY_Settings::public_key();
            $name = 'public_key.pem';
        } elseif ($what === 'public_txt') {
            $content = HY_Settings::public_key_base64();
            $name = 'public_key.txt';
        } elseif ($what === 'csr') {
            $content = (string) get_option('hy_csr', '');
            $name = 'request.csr';
        } elseif ($what === 'private') {
            $content = HY_Settings::get_secret('private_key');
            $name = 'private_key_BACKUP_KEEP_SECRET.pem';
        } else {
            wp_die('نامعتبر');
        }
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        echo $content; // phpcs:ignore
        exit;
    }

    /* ================================================================== */
    /*  AJAX                                                               */
    /* ================================================================== */

    private static function ajax_guard() {
        if (!self::can() || !check_ajax_referer('hy_ajax', 'nonce', false)) {
            wp_send_json_error(array('message' => 'دسترسی غیرمجاز'), 403);
        }
    }

    public static function ajax_search_persons() {
        self::ajax_guard();
        $q = sanitize_text_field(HY_Jalali::latin_digits(wp_unslash($_GET['q'] ?? '')));
        $out = array();
        foreach (HY_Persons::search($q, 20) as $p) {
            $out[] = array(
                'id' => (int) $p['id'],
                'code' => (int) $p['code'],
                'name' => $p['name'],
                'phone' => $p['mobile'] ?: $p['phone'],
                'type' => HY_Persons::$types[$p['person_type']] ?? '',
                'type_id' => (int) $p['person_type'],
                'national_id' => $p['national_id'],
                'economic_code' => $p['economic_code'],
                'balance' => HY_Persons::balance($p['id']),
            );
        }
        wp_send_json_success($out);
    }

    public static function ajax_search_products() {
        self::ajax_guard();
        $q = sanitize_text_field(HY_Jalali::latin_digits(wp_unslash($_GET['q'] ?? '')));
        $out = array();
        foreach (HY_Products::search($q, 20) as $p) {
            $out[] = array(
                'id' => (int) $p['id'],
                'code' => (int) $p['code'],
                'name' => $p['name'],
                'price' => (int) $p['price'],
                'unit_title' => $p['unit_title'],
                'unit_code' => $p['unit_code'],
                'stuff_id' => $p['stuff_id'],
                'vat_rate' => (float) $p['vat_rate'],
            );
        }
        wp_send_json_success($out);
    }

    public static function ajax_quick_person() {
        self::ajax_guard();
        $data = HY_Persons::sanitize(wp_unslash($_POST));
        $errors = HY_Persons::validate($data);
        if ($errors) {
            wp_send_json_error(array('message' => implode("\n", $errors)));
        }
        $id = HY_Persons::save($data);
        $p = HY_Persons::get($id);
        wp_send_json_success(array(
            'id' => $id, 'code' => (int) $p['code'], 'name' => $p['name'], 'balance' => 0,
            'type' => HY_Persons::$types[$p['person_type']] ?? '', 'type_id' => (int) $p['person_type'],
            'national_id' => $p['national_id'], 'economic_code' => $p['economic_code'], 'phone' => $p['mobile'] ?: $p['phone'],
        ));
    }

    public static function ajax_quick_product() {
        self::ajax_guard();
        $data = HY_Products::sanitize(wp_unslash($_POST));
        $errors = HY_Products::validate($data);
        if ($errors) {
            wp_send_json_error(array('message' => implode("\n", $errors)));
        }
        $id = HY_Products::save($data);
        $p = HY_Products::get($id);
        wp_send_json_success(array(
            'id' => $id, 'code' => (int) $p['code'], 'name' => $p['name'], 'price' => (int) $p['price'],
            'unit_title' => $p['unit_title'], 'unit_code' => $p['unit_code'], 'stuff_id' => $p['stuff_id'], 'vat_rate' => (float) $p['vat_rate'],
        ));
    }

    public static function ajax_test_connection() {
        self::ajax_guard();
        try {
            $lines = HY_Moadian_Service::test_connection();
            wp_send_json_success(array('lines' => $lines));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
}
