<?php
/**
 * Persons, products and invoices.
 *
 * @package Hesabyar
 */

if (!defined('ABSPATH')) {
    exit;
}

class HY_Persons {

    public static $types = array(
        1 => 'حقیقی',
        2 => 'حقوقی',
        3 => 'مشارکت مدنی',
        4 => 'اتباع غیر ایرانی',
        5 => 'مصرف‌کننده نهایی',
    );

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . HY_DB::table('persons') . ' WHERE id=%d', $id), ARRAY_A);
    }

    public static function search($term = '', $limit = 30, $offset = 0) {
        global $wpdb;
        $t = HY_DB::table('persons');
        if ($term === '') {
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t ORDER BY code DESC LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A);
        }
        $like = '%' . $wpdb->esc_like($term) . '%';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE name LIKE %s OR national_id LIKE %s OR mobile LIKE %s OR phone LIKE %s OR CAST(code AS CHAR) LIKE %s ORDER BY code DESC LIMIT %d OFFSET %d",
            $like, $like, $like, $like, $like, $limit, $offset
        ), ARRAY_A);
    }

    public static function count($term = '') {
        global $wpdb;
        $t = HY_DB::table('persons');
        if ($term === '') {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
        }
        $like = '%' . $wpdb->esc_like($term) . '%';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE name LIKE %s OR national_id LIKE %s OR mobile LIKE %s OR phone LIKE %s", $like, $like, $like, $like));
    }

    public static function validate(array $d) {
        $errors = array();
        if (trim($d['name'] ?? '') === '') {
            $errors[] = 'نام شخص الزامی است.';
        }
        $nid = $d['national_id'] ?? '';
        $type = (int) ($d['person_type'] ?? 1);
        if ($nid !== '') {
            if ($type === 1 && !preg_match('/^\d{10}$/', $nid)) {
                $errors[] = 'کد ملی شخص حقیقی باید ۱۰ رقم باشد.';
            } elseif ($type === 1 && !self::valid_national_code($nid)) {
                $errors[] = 'کد ملی واردشده معتبر نیست.';
            } elseif ($type === 2 && !preg_match('/^\d{11}$/', $nid)) {
                $errors[] = 'شناسه ملی شخص حقوقی باید ۱۱ رقم باشد.';
            }
        }
        $ec = $d['economic_code'] ?? '';
        if ($ec !== '' && !preg_match('/^(\d{11}|\d{14})$/', $ec)) {
            $errors[] = 'کد اقتصادی باید ۱۱ یا ۱۴ رقم باشد.';
        }
        $pc = $d['postal_code'] ?? '';
        if ($pc !== '' && !preg_match('/^\d{10}$/', $pc)) {
            $errors[] = 'کدپستی باید ۱۰ رقم باشد.';
        }
        return $errors;
    }

    public static function valid_national_code($code) {
        if (!preg_match('/^\d{10}$/', $code) || preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $code[$i]) * (10 - $i);
        }
        $r = $sum % 11;
        $check = (int) $code[9];
        return ($r < 2 && $check === $r) || ($r >= 2 && $check === 11 - $r);
    }

    public static function sanitize(array $in) {
        $digits = function ($v) {
            return preg_replace('/\D+/', '', HY_Jalali::latin_digits((string) $v));
        };
        return array(
            'name' => sanitize_text_field($in['name'] ?? ''),
            'person_type' => max(1, min(5, (int) ($in['person_type'] ?? 1))),
            'national_id' => $digits($in['national_id'] ?? ''),
            'economic_code' => $digits($in['economic_code'] ?? ''),
            'postal_code' => $digits($in['postal_code'] ?? ''),
            'phone' => sanitize_text_field(HY_Jalali::latin_digits($in['phone'] ?? '')),
            'mobile' => sanitize_text_field(HY_Jalali::latin_digits($in['mobile'] ?? '')),
            'email' => sanitize_email($in['email'] ?? ''),
            'address' => sanitize_textarea_field($in['address'] ?? ''),
            'notes' => sanitize_textarea_field($in['notes'] ?? ''),
        );
    }

    public static function save(array $data, $id = 0) {
        global $wpdb;
        $t = HY_DB::table('persons');
        $data['updated_at'] = HY_DB::now();
        if ($id) {
            $wpdb->update($t, $data, array('id' => $id));
            return (int) $id;
        }
        $data['code'] = HY_DB::next_code('persons');
        $data['created_at'] = HY_DB::now();
        $wpdb->insert($t, $data);
        return (int) $wpdb->insert_id;
    }

    public static function delete($id) {
        global $wpdb;
        $used = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . HY_DB::table('invoices') . ' WHERE person_id=%d', $id));
        if ($used) {
            return new WP_Error('in_use', 'این شخص در فاکتور استفاده شده و قابل حذف نیست.');
        }
        $wpdb->delete(HY_DB::table('persons'), array('id' => $id));
        return true;
    }

    public static function balance($id) {
        global $wpdb;
        $t = HY_DB::table('invoices');
        $sales = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total),0) FROM $t WHERE person_id=%d AND kind='sale' AND status='confirmed' AND paid=0", $id));
        $returns = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total),0) FROM $t WHERE person_id=%d AND kind='return' AND status='confirmed' AND paid=0", $id));
        return $sales - $returns;
    }
}

class HY_Products {

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . HY_DB::table('products') . ' WHERE id=%d', $id), ARRAY_A);
    }

    public static function by_wc_id($wc_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . HY_DB::table('products') . ' WHERE wc_product_id=%d', $wc_id), ARRAY_A);
    }

    public static function search($term = '', $limit = 30, $offset = 0) {
        global $wpdb;
        $t = HY_DB::table('products');
        if ($term === '') {
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t ORDER BY code DESC LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A);
        }
        $like = '%' . $wpdb->esc_like($term) . '%';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE name LIKE %s OR sku LIKE %s OR stuff_id LIKE %s OR CAST(code AS CHAR) LIKE %s ORDER BY code DESC LIMIT %d OFFSET %d",
            $like, $like, $like, $like, $limit, $offset
        ), ARRAY_A);
    }

    public static function count($term = '') {
        global $wpdb;
        $t = HY_DB::table('products');
        if ($term === '') {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
        }
        $like = '%' . $wpdb->esc_like($term) . '%';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE name LIKE %s OR sku LIKE %s OR stuff_id LIKE %s", $like, $like, $like));
    }

    public static function sanitize(array $in) {
        $num = function ($v) {
            return preg_replace('/[^\d.]/', '', HY_Jalali::latin_digits((string) $v));
        };
        return array(
            'name' => sanitize_text_field($in['name'] ?? ''),
            'sku' => sanitize_text_field($in['sku'] ?? ''),
            'unit_title' => sanitize_text_field($in['unit_title'] ?? 'عدد') ?: 'عدد',
            'unit_code' => preg_replace('/\D+/', '', HY_Jalali::latin_digits($in['unit_code'] ?? '1627')) ?: '1627',
            'stuff_id' => preg_replace('/\D+/', '', HY_Jalali::latin_digits($in['stuff_id'] ?? '')),
            'price' => (int) $num($in['price'] ?? 0),
            'vat_rate' => (float) $num($in['vat_rate'] ?? HY_Settings::get('default_vat')),
            'is_service' => empty($in['is_service']) ? 0 : 1,
        );
    }

    public static function validate(array $d) {
        $errors = array();
        if ($d['name'] === '') {
            $errors[] = 'نام کالا الزامی است.';
        }
        if ($d['stuff_id'] !== '' && !preg_match('/^\d{13}$/', $d['stuff_id'])) {
            $errors[] = 'شناسه کالا/خدمت مودیان باید ۱۳ رقم باشد.';
        }
        if ($d['vat_rate'] < 0 || $d['vat_rate'] > 100) {
            $errors[] = 'نرخ مالیات نامعتبر است.';
        }
        return $errors;
    }

    public static function save(array $data, $id = 0) {
        global $wpdb;
        $t = HY_DB::table('products');
        $data['updated_at'] = HY_DB::now();
        if ($id) {
            $wpdb->update($t, $data, array('id' => $id));
            return (int) $id;
        }
        $data['code'] = HY_DB::next_code('products');
        $data['created_at'] = HY_DB::now();
        $wpdb->insert($t, $data);
        return (int) $wpdb->insert_id;
    }

    public static function delete($id) {
        global $wpdb;
        $used = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . HY_DB::table('invoice_items') . ' WHERE product_id=%d', $id));
        if ($used) {
            return new WP_Error('in_use', 'این کالا در فاکتور استفاده شده و قابل حذف نیست.');
        }
        $wpdb->delete(HY_DB::table('products'), array('id' => $id));
        return true;
    }
}

class HY_Invoices {

    public static $statuses = array(
        'draft' => 'پیش‌نویس',
        'confirmed' => 'تایید شده',
    );

    public static $m_statuses = array(
        'unsent' => 'ارسال نشده',
        'pending' => 'ارسال شده',
        'failed' => 'خطا',
        'success' => 'تایید شده',
    );

    public static $inty = array(
        1 => 'نوع اول (با مشخصات خریدار)',
        2 => 'نوع دوم (بدون مشخصات خریدار)',
        3 => 'نوع سوم (فروشگاهی)',
    );

    public static $inp = array(
        1 => 'فروش',
        2 => 'فروش ارزی',
        3 => 'طلا، جواهر و پلاتین',
        4 => 'پیمانکاری',
        5 => 'قبوض خدماتی',
        6 => 'بلیط هواپیما',
        7 => 'صادرات',
    );

    public static $ins = array(
        1 => 'اصلی',
        2 => 'اصلاحی',
        3 => 'ابطالی',
        4 => 'برگشت از فروش',
    );

    public static $setm = array(
        1 => 'نقدی',
        2 => 'نسیه',
        3 => 'نقدی/نسیه',
    );

    public static function get($id, $with_items = true) {
        global $wpdb;
        $inv = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . HY_DB::table('invoices') . ' WHERE id=%d', $id), ARRAY_A);
        if ($inv && $with_items) {
            $inv['items'] = self::items($id);
        }
        return $inv;
    }

    public static function items($invoice_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . HY_DB::table('invoice_items') . ' WHERE invoice_id=%d ORDER BY sort, id', $invoice_id), ARRAY_A);
    }

    public static function by_wc_order($order_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . HY_DB::table('invoices') . " WHERE wc_order_id=%d AND kind='sale' ORDER BY id DESC LIMIT 1", $order_id), ARRAY_A);
    }

    public static function next_number($kind) {
        global $wpdb;
        return 1 + (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(number),0) FROM ' . HY_DB::table('invoices') . ' WHERE kind=%s', $kind));
    }

    /** Line arithmetic, shared by PHP and mirrored in admin.js. */
    public static function calc_line(array $line) {
        $qty = (float) $line['qty'];
        $price = (int) $line['unit_price'];
        $gross = (int) round($qty * $price);
        $discount = max(0, min($gross, (int) $line['discount']));
        $after = $gross - $discount;
        $rate = (float) $line['vat_rate'];
        $tax = (int) round($after * $rate / 100);
        $line['discount'] = $discount;
        $line['tax'] = $tax;
        $line['total'] = $after + $tax;
        $line['gross'] = $gross;
        return $line;
    }

    public static function sanitize_items(array $rows) {
        $out = array();
        $sort = 0;
        foreach ($rows as $r) {
            $num = function ($v) {
                return preg_replace('/[^\d.]/', '', HY_Jalali::latin_digits((string) $v));
            };
            $product_id = (int) ($r['product_id'] ?? 0);
            $description = sanitize_text_field($r['description'] ?? '');
            $qty = (float) $num($r['qty'] ?? 0);
            if (!$product_id && $description === '') {
                continue;
            }
            if ($qty <= 0) {
                continue;
            }
            $line = array(
                'sort' => $sort++,
                'product_id' => $product_id,
                'description' => $description,
                'unit_title' => sanitize_text_field($r['unit_title'] ?? ''),
                'unit_code' => preg_replace('/\D+/', '', (string) ($r['unit_code'] ?? '')),
                'stuff_id' => preg_replace('/\D+/', '', (string) ($r['stuff_id'] ?? '')),
                'qty' => $qty,
                'unit_price' => (int) $num($r['unit_price'] ?? 0),
                'discount' => (int) $num($r['discount'] ?? 0),
                'vat_rate' => (float) $num($r['vat_rate'] ?? 0),
            );
            if ($product_id && ($p = HY_Products::get($product_id))) {
                $line['unit_title'] = $line['unit_title'] ?: $p['unit_title'];
                $line['unit_code'] = $line['unit_code'] ?: $p['unit_code'];
                $line['stuff_id'] = $line['stuff_id'] ?: $p['stuff_id'];
                $line['description'] = $line['description'] ?: $p['name'];
            }
            $out[] = self::calc_line($line);
        }
        return $out;
    }

    /**
     * Creates or updates an invoice with its lines.
     *
     * @return int|WP_Error
     */
    public static function save(array $header, array $items, $id = 0) {
        global $wpdb;
        if (!$items) {
            return new WP_Error('no_items', 'فاکتور باید حداقل یک ردیف کالا داشته باشد.');
        }
        if (empty($header['person_id']) || !HY_Persons::get($header['person_id'])) {
            return new WP_Error('no_person', 'مشتری را انتخاب کنید.');
        }
        if ($id) {
            $existing = self::get($id, false);
            if (!$existing) {
                return new WP_Error('missing', 'فاکتور پیدا نشد.');
            }
            if (in_array($existing['m_status'], array('pending', 'success'), true)) {
                return new WP_Error('locked', 'این فاکتور به سامانه مودیان ارسال شده و قابل ویرایش نیست. برای تغییر، از «اصلاحی» یا «برگشت از فروش» استفاده کنید.');
            }
        }

        $subtotal = $discount = $tax = $total = 0;
        foreach ($items as $l) {
            $subtotal += $l['gross'];
            $discount += $l['discount'];
            $tax += $l['tax'];
            $total += $l['total'];
        }
        $header['subtotal'] = $subtotal;
        $header['discount'] = $discount;
        $header['tax'] = $tax;
        $header['total'] = $total;
        $header['updated_at'] = HY_DB::now();

        $t = HY_DB::table('invoices');
        if ($id) {
            $wpdb->update($t, $header, array('id' => $id));
            $wpdb->delete(HY_DB::table('invoice_items'), array('invoice_id' => $id));
        } else {
            $kind = $header['kind'] ?? 'sale';
            if (empty($header['number'])) {
                $header['number'] = self::next_number($kind);
            }
            $header['created_at'] = HY_DB::now();
            $wpdb->insert($t, $header);
            $id = (int) $wpdb->insert_id;
            if (!$id) {
                return new WP_Error('db', 'ذخیره فاکتور ناموفق بود: ' . $wpdb->last_error);
            }
        }
        foreach ($items as $l) {
            unset($l['gross']);
            $l['invoice_id'] = $id;
            $wpdb->insert(HY_DB::table('invoice_items'), $l);
        }
        do_action('hy_invoice_saved', $id);
        return (int) $id;
    }

    public static function delete($id) {
        global $wpdb;
        $inv = self::get($id, false);
        if (!$inv) {
            return true;
        }
        if (in_array($inv['m_status'], array('pending', 'success'), true)) {
            return new WP_Error('locked', 'فاکتوری که به سامانه مودیان ارسال شده قابل حذف نیست.');
        }
        $wpdb->delete(HY_DB::table('invoice_items'), array('invoice_id' => $id));
        $wpdb->delete(HY_DB::table('invoices'), array('id' => $id));
        return true;
    }

    /** Builds a return-from-sale invoice (موضوع ۴) for the given sale. */
    public static function create_return($sale_id, array $qty_by_item = null) {
        $sale = self::get($sale_id);
        if (!$sale || $sale['kind'] !== 'sale') {
            return new WP_Error('missing', 'فاکتور فروش پیدا نشد.');
        }
        $items = array();
        foreach ($sale['items'] as $it) {
            $qty = $qty_by_item === null ? (float) $it['qty'] : (float) ($qty_by_item[$it['id']] ?? 0);
            $qty = min($qty, (float) $it['qty']);
            if ($qty <= 0) {
                continue;
            }
            $ratio = $qty / (float) $it['qty'];
            $line = $it;
            unset($line['id'], $line['invoice_id']);
            $line['qty'] = $qty;
            $line['discount'] = (int) round($it['discount'] * $ratio);
            $items[] = self::calc_line($line);
        }
        $header = array(
            'kind' => 'return',
            'ref_invoice_id' => $sale['id'],
            'person_id' => $sale['person_id'],
            'title' => 'برگشت از فروش فاکتور ' . $sale['number'],
            'date_ts' => time(),
            'due_ts' => time(),
            'status' => 'confirmed',
            'm_inty' => $sale['m_inty'],
            'm_inp' => $sale['m_inp'],
            'm_ins' => 4,
            'm_setm' => $sale['m_setm'],
            'm_irtaxid' => $sale['m_taxid'],
        );
        return self::save($header, $items);
    }

    public static function copy($id) {
        $inv = self::get($id);
        if (!$inv) {
            return new WP_Error('missing', 'فاکتور پیدا نشد.');
        }
        $items = array();
        foreach ($inv['items'] as $it) {
            unset($it['id'], $it['invoice_id']);
            $items[] = self::calc_line($it);
        }
        $header = array(
            'kind' => $inv['kind'],
            'person_id' => $inv['person_id'],
            'title' => $inv['title'],
            'date_ts' => time(),
            'due_ts' => time(),
            'status' => 'draft',
            'm_inty' => $inv['m_inty'],
            'm_inp' => $inv['m_inp'],
            'm_ins' => $inv['kind'] === 'return' ? 4 : 1,
            'm_setm' => $inv['m_setm'],
            'm_irtaxid' => $inv['kind'] === 'return' ? $inv['m_irtaxid'] : '',
        );
        return self::save($header, $items);
    }

    public static function list_query(array $args) {
        global $wpdb;
        $t = HY_DB::table('invoices');
        $p = HY_DB::table('persons');
        $where = array('1=1');
        $params = array();
        if (!empty($args['kind'])) {
            $where[] = 'i.kind=%s';
            $params[] = $args['kind'];
        }
        if (!empty($args['m_status'])) {
            $where[] = 'i.m_status=%s';
            $params[] = $args['m_status'];
        }
        if (!empty($args['status'])) {
            $where[] = 'i.status=%s';
            $params[] = $args['status'];
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(p.name LIKE %s OR CAST(i.number AS CHAR) LIKE %s OR i.title LIKE %s OR i.m_taxid LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($args['from'])) {
            $where[] = 'i.date_ts >= %d';
            $params[] = (int) $args['from'];
        }
        if (!empty($args['to'])) {
            $where[] = 'i.date_ts <= %d';
            $params[] = (int) $args['to'];
        }
        $w = implode(' AND ', $where);
        $limit = max(1, (int) ($args['per_page'] ?? 20));
        $offset = max(0, ((int) ($args['page'] ?? 1) - 1) * $limit);
        $sql_count = "SELECT COUNT(*) FROM $t i LEFT JOIN $p p ON p.id=i.person_id WHERE $w";
        $sql = "SELECT i.*, p.name AS person_name, p.code AS person_code FROM $t i LEFT JOIN $p p ON p.id=i.person_id WHERE $w ORDER BY i.date_ts DESC, i.id DESC LIMIT $limit OFFSET $offset";
        $count = (int) ($params ? $wpdb->get_var($wpdb->prepare($sql_count, $params)) : $wpdb->get_var($sql_count));
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        return array($rows, $count);
    }

    /** Confirmed sales minus returns between two timestamps. */
    public static function period_totals($from, $to) {
        global $wpdb;
        $t = HY_DB::table('invoices');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT kind, COUNT(*) c, COALESCE(SUM(total),0) total, COALESCE(SUM(tax),0) tax FROM $t WHERE status='confirmed' AND date_ts BETWEEN %d AND %d GROUP BY kind",
            $from, $to
        ), ARRAY_A);
        $out = array('count' => 0, 'total' => 0, 'tax' => 0, 'returns' => 0);
        foreach ($rows as $r) {
            $sign = $r['kind'] === 'return' ? -1 : 1;
            $out['total'] += $sign * (int) $r['total'];
            $out['tax'] += $sign * (int) $r['tax'];
            if ($r['kind'] === 'sale') {
                $out['count'] += (int) $r['c'];
            } else {
                $out['returns'] += (int) $r['c'];
            }
        }
        return $out;
    }

    public static function receivables() {
        global $wpdb;
        $t = HY_DB::table('invoices');
        return (int) $wpdb->get_var("SELECT COALESCE(SUM(CASE WHEN kind='return' THEN -total ELSE total END),0) FROM $t WHERE status='confirmed' AND paid=0");
    }

    public static function counts_by_m_status() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT m_status, COUNT(*) c FROM ' . HY_DB::table('invoices') . " WHERE status='confirmed' GROUP BY m_status", ARRAY_A);
        $out = array('all' => 0);
        foreach ($rows as $r) {
            $out[$r['m_status']] = (int) $r['c'];
            $out['all'] += (int) $r['c'];
        }
        return $out;
    }

    public static function update_fields($id, array $fields) {
        global $wpdb;
        $fields['updated_at'] = HY_DB::now();
        return $wpdb->update(HY_DB::table('invoices'), $fields, array('id' => (int) $id));
    }
}
