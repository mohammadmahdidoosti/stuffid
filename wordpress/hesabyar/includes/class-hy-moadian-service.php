<?php
/**
 * Turns stored invoices into Moadian INVOICE.V01 documents and manages the
 * send → inquiry lifecycle (unsent → pending → success | failed).
 *
 * @package Hesabyar
 */

if (!defined('ABSPATH')) {
    exit;
}

class HY_Moadian_Service {

    const BATCH = 10;

    /** @var callable|null test hook for HTTP */
    public static $http = null;

    /* ------------------------------------------------------------------ */
    /*  Building                                                           */
    /* ------------------------------------------------------------------ */

    /** inty 0 means "automatic": type 1 when the buyer can be identified. */
    public static function resolve_inty(array $invoice, $person) {
        $inty = (int) $invoice['m_inty'];
        if ($inty) {
            return $inty;
        }
        $default = (int) HY_Settings::get('default_inty');
        if ($default) {
            return $default;
        }
        if ($person && (int) $person['person_type'] !== 5 && ($person['national_id'] !== '' || $person['economic_code'] !== '')) {
            return 1;
        }
        return 2;
    }

    /**
     * Validates and builds the INVOICE.V01 array. Throws HY_Moadian_Exception
     * with a Persian message listing every problem found.
     */
    public static function build(array $invoice) {
        $s = HY_Settings::all();
        $errors = array();
        $person = HY_Persons::get($invoice['person_id']);
        $items = isset($invoice['items']) ? $invoice['items'] : HY_Invoices::items($invoice['id']);

        $fiscal_id = strtoupper(trim($s['fiscal_id']));
        $tins = preg_replace('/\D+/', '', $s['economic_code']);
        if (!preg_match('/^[A-Z0-9]{6}$/', $fiscal_id)) {
            $errors[] = 'شناسه یکتای حافظه مالیاتی در تنظیمات ثبت نشده است.';
        }
        if (!preg_match('/^(\d{11}|\d{14})$/', $tins)) {
            $errors[] = 'کد اقتصادی فروشنده در تنظیمات باید ۱۱ یا ۱۴ رقم باشد.';
        }
        if ($invoice['status'] !== 'confirmed') {
            $errors[] = 'فاکتور پیش‌نویس است؛ ابتدا آن را تایید کنید.';
        }
        if (empty($invoice['m_serial'])) {
            $errors[] = 'سریال صورتحساب تعیین نشده است.';
        }

        $inty = self::resolve_inty($invoice, $person);
        $ins = (int) $invoice['m_ins'] ?: 1;
        $inp = (int) $invoice['m_inp'] ?: 1;
        $setm = (int) $invoice['m_setm'] ?: 1;

        $irtaxid = null;
        if ($ins !== 1) {
            $irtaxid = trim((string) $invoice['m_irtaxid']);
            if (!preg_match('/^[A-Z0-9]{22}$/i', $irtaxid)) {
                $errors[] = 'برای صورتحساب «' . HY_Invoices::$ins[$ins] . '» شماره منحصر به فرد صورتحساب مرجع (۲۲ کاراکتر) لازم است.';
            }
        }

        $buyer = array('tob' => null, 'bid' => null, 'tinb' => null, 'bpc' => null);
        if ($inty === 1) {
            if (!$person) {
                $errors[] = 'مشتری فاکتور پیدا نشد.';
            } else {
                $nid = $person['national_id'];
                $ec = $person['economic_code'];
                if ($nid === '' && $ec === '') {
                    $errors[] = 'برای صورتحساب نوع اول، کد ملی/شناسه ملی یا کد اقتصادی مشتری «' . $person['name'] . '» لازم است.';
                }
                $buyer = array(
                    'tob' => (int) $person['person_type'],
                    'bid' => $nid !== '' ? $nid : null,
                    'tinb' => $ec !== '' ? $ec : ($nid !== '' ? $nid : null),
                    'bpc' => $person['postal_code'] !== '' ? $person['postal_code'] : null,
                );
            }
        }

        $body = array();
        $t = array('tprdis' => 0, 'tdis' => 0, 'tadis' => 0, 'tvam' => 0, 'tbill' => 0);
        foreach ($items as $i => $it) {
            $n = $i + 1;
            $product = (!empty($it['product_id']) && ($it['stuff_id'] === '' || $it['unit_code'] === '')) ? HY_Products::get($it['product_id']) : null;
            if ($product) {
                // Line saved before the product had a Moadian ID: use the product's current values.
                $it['stuff_id'] = $it['stuff_id'] !== '' ? $it['stuff_id'] : $product['stuff_id'];
                $it['unit_code'] = $it['unit_code'] !== '' ? $it['unit_code'] : $product['unit_code'];
            }
            $stuff = $it['stuff_id'] !== '' ? $it['stuff_id'] : $s['default_stuff_id'];
            if (!preg_match('/^\d{13}$/', (string) $stuff)) {
                $errors[] = "ردیف " . HY_Jalali::persian_digits($n) . " («{$it['description']}»): شناسه ۱۳ رقمی کالا/خدمت مودیان ثبت نشده است.";
            }
            $mu = $it['unit_code'] !== '' ? $it['unit_code'] : $s['default_unit_code'];
            $line = HY_Invoices::calc_line($it);
            $qty = (float) $it['qty'];
            $body[] = array(
                'sstid' => (string) $stuff,
                'sstt' => mb_substr((string) $it['description'], 0, 400),
                'am' => floor($qty) == $qty ? (int) $qty : $qty,
                'mu' => (string) $mu,
                'fee' => (int) $it['unit_price'],
                'cfee' => null,
                'cut' => null,
                'exr' => null,
                'prdis' => $line['gross'],
                'dis' => $line['discount'],
                'adis' => $line['gross'] - $line['discount'],
                'vra' => (float) $it['vat_rate'],
                'vam' => $line['tax'],
                'odt' => null,
                'odr' => null,
                'odam' => null,
                'olt' => null,
                'olr' => null,
                'olam' => null,
                'consfee' => null,
                'spro' => null,
                'bros' => null,
                'tcpbs' => null,
                'cop' => null,
                'vop' => null,
                'bsrn' => null,
                'tsstam' => $line['total'],
            );
            $t['tprdis'] += $line['gross'];
            $t['tdis'] += $line['discount'];
            $t['tadis'] += $line['gross'] - $line['discount'];
            $t['tvam'] += $line['tax'];
            $t['tbill'] += $line['total'];
        }
        if (!$body) {
            $errors[] = 'فاکتور ردیفی ندارد.';
        }

        if ($errors) {
            throw new HY_Moadian_Exception(implode("\n", $errors));
        }

        $indatim = (int) $invoice['date_ts'] * 1000;
        if ($setm === 1) {
            $cap = $t['tbill'];
            $insp = 0;
        } elseif ($setm === 2) {
            $cap = 0;
            $insp = $t['tbill'];
        } else {
            $cap = max(0, min($t['tbill'], (int) $invoice['m_cap']));
            $insp = $t['tbill'] - $cap;
        }

        $header = array_merge(
            array(
                'taxid' => HY_Moadian_Util::generate_tax_id($fiscal_id, (int) $invoice['m_serial'], (int) $invoice['date_ts']),
                'indatim' => $indatim,
                'indati2m' => $indatim,
                'inty' => $inty,
                'inno' => HY_Moadian_Util::invoice_number($invoice['m_serial']),
                'irtaxid' => $irtaxid,
                'inp' => $inp,
                'ins' => $ins,
                'tins' => $tins,
            ),
            $buyer,
            array(
                'sbc' => null,
                'bbc' => null,
                'ft' => null,
                'bpn' => null,
                'scln' => null,
                'scc' => null,
                'crn' => null,
                'billid' => null,
                'tprdis' => $t['tprdis'],
                'tdis' => $t['tdis'],
                'tadis' => $t['tadis'],
                'tvam' => $t['tvam'],
                'todam' => 0,
                'tbill' => $t['tbill'],
                'setm' => $setm,
                'cap' => $cap,
                'insp' => $insp,
                'tvop' => null,
                'tax17' => 0,
            )
        );

        $doc = array('header' => $header, 'body' => $body, 'payments' => array());
        $doc = apply_filters('hy_moadian_invoice', $doc, $invoice);
        return HY_Moadian_Util::clean_numbers($doc);
    }

    /* ------------------------------------------------------------------ */
    /*  Connection                                                         */
    /* ------------------------------------------------------------------ */

    private static function cache_key($what) {
        $s = HY_Settings::all();
        return 'hy_' . $what . '_' . md5($s['protocol'] . $s['environment'] . $s['fiscal_id']);
    }

    public static function token(HY_Moadian_Transport $transport, $force = false) {
        $key = self::cache_key('token');
        if (!$force) {
            $cached = get_transient($key);
            if (is_array($cached) && $cached['expires_at'] > time() + 30) {
                return $cached['token'];
            }
        }
        $token = $transport->get_token();
        $ttl = max(1, $token['expires_at'] - time() - 30);
        if ($ttl > 5) {
            set_transient($key, $token, $ttl);
        }
        return $token['token'];
    }

    public static function server_key(HY_Moadian_Transport $transport, $force = false) {
        $key = self::cache_key('server_key');
        if (!$force) {
            $cached = get_transient($key);
            if (is_array($cached) && !empty($cached['key'])) {
                return $cached;
            }
        }
        $server_key = $transport->get_server_key();
        set_transient($key, $server_key, 12 * HOUR_IN_SECONDS);
        return $server_key;
    }

    public static function clear_cache() {
        delete_transient(self::cache_key('token'));
        delete_transient(self::cache_key('server_key'));
    }

    /** Runs $fn($token); on HTTP 401 refreshes the token once and retries. */
    private static function with_token(HY_Moadian_Transport $transport, callable $fn) {
        try {
            return $fn(self::token($transport));
        } catch (HY_Moadian_Exception $e) {
            if ($e->get_http_status() === 401 || $e->get_http_status() === 403) {
                return $fn(self::token($transport, true));
            }
            throw $e;
        }
    }

    public static function test_connection() {
        $transport = HY_Settings::transport(self::$http);
        self::clear_cache();
        $lines = array();
        $server = self::server_key($transport, true);
        $lines[] = 'کلید عمومی سازمان دریافت شد (شناسه کلید: ' . $server['id'] . ').';
        $token = self::token($transport, true);
        $lines[] = 'احراز هویت با کلید خصوصی موفق بود و توکن دریافت شد.';
        try {
            $info = $transport->get_fiscal_information($token);
            $name = is_array($info) ? ($info['nameTrade'] ?? $info['name'] ?? $info['fiscalStatus'] ?? '') : '';
            $lines[] = 'اطلاعات حافظه مالیاتی دریافت شد' . ($name ? ': ' . $name : '.');
        } catch (HY_Moadian_Exception $e) {
            $lines[] = 'دریافت اطلاعات حافظه مالیاتی: ' . $e->getMessage();
        }
        return $lines;
    }

    /* ------------------------------------------------------------------ */
    /*  Sending                                                            */
    /* ------------------------------------------------------------------ */

    public static function assign_serial($invoice_id, $force_new = false) {
        global $wpdb;
        $inv = HY_Invoices::get($invoice_id, false);
        if (!$inv) {
            return 0;
        }
        if (!$force_new && !empty($inv['m_serial'])) {
            return (int) $inv['m_serial'];
        }
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $serial = HY_Settings::take_counter('next_serial');
            $exists = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . HY_DB::table('invoices') . ' WHERE m_serial=%d', $serial));
            if (!$exists) {
                HY_Invoices::update_fields($invoice_id, array('m_serial' => $serial));
                return $serial;
            }
        }
        return 0;
    }

    /**
     * Sends the given invoices. Returns ['sent' => n, 'failed' => n, 'skipped' => n, 'messages' => []].
     */
    public static function send(array $ids) {
        $report = array('sent' => 0, 'failed' => 0, 'skipped' => 0, 'messages' => array());
        $queue = array();

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $inv = HY_Invoices::get($id);
            if (!$inv) {
                continue;
            }
            if (in_array($inv['m_status'], array('pending', 'success'), true)) {
                $report['skipped']++;
                $report['messages'][] = "فاکتور " . HY_Jalali::persian_digits($inv['number']) . ": قبلاً ارسال شده است.";
                continue;
            }
            if ($inv['status'] !== 'confirmed') {
                $report['skipped']++;
                $report['messages'][] = "فاکتور " . HY_Jalali::persian_digits($inv['number']) . ": پیش‌نویس است و ارسال نشد.";
                continue;
            }
            if ($inv['kind'] === 'return' && empty($inv['m_irtaxid']) && $inv['ref_invoice_id']) {
                $ref = HY_Invoices::get($inv['ref_invoice_id'], false);
                if ($ref && $ref['m_taxid'] && $ref['m_status'] === 'success') {
                    HY_Invoices::update_fields($id, array('m_irtaxid' => $ref['m_taxid']));
                    $inv['m_irtaxid'] = $ref['m_taxid'];
                }
            }
            $inv['m_serial'] = self::assign_serial($id);
            try {
                $doc = self::build($inv);
            } catch (HY_Moadian_Exception $e) {
                HY_Invoices::update_fields($id, array('m_status' => 'failed', 'm_message' => $e->getMessage()));
                $report['failed']++;
                $report['messages'][] = "فاکتور " . HY_Jalali::persian_digits($inv['number']) . ": " . $e->getMessage();
                continue;
            }
            $uid = HY_Moadian_Util::uuid4();
            HY_Invoices::update_fields($id, array(
                'm_taxid' => $doc['header']['taxid'],
                'm_uid' => $uid,
                'm_indatim' => $doc['header']['indatim'],
                'm_payload' => wp_json_encode($doc, JSON_UNESCAPED_UNICODE),
            ));
            $queue[] = array('id' => $id, 'number' => $inv['number'], 'uid' => $uid, 'invoice' => $doc);
        }

        if (!$queue) {
            return $report;
        }

        try {
            $transport = HY_Settings::transport(self::$http);
            $server_key = self::server_key($transport);
        } catch (HY_Moadian_Exception $e) {
            foreach ($queue as $q) {
                HY_Invoices::update_fields($q['id'], array('m_status' => 'failed', 'm_message' => $e->getMessage()));
            }
            $report['failed'] += count($queue);
            $report['messages'][] = $e->getMessage();
            return $report;
        }

        foreach (array_chunk($queue, self::BATCH) as $chunk) {
            try {
                $results = self::with_token($transport, function ($token) use ($transport, $chunk, $server_key) {
                    return $transport->send_invoices($chunk, $token, $server_key);
                });
                HY_DB::log(0, 'send', $transport->last_exchange['status'] ?? 0, array('uids' => wp_list_pluck($chunk, 'uid')), $transport->last_exchange['response'] ?? '');
            } catch (HY_Moadian_Exception $e) {
                HY_DB::log(0, 'send_error', $e->get_http_status(), array('uids' => wp_list_pluck($chunk, 'uid')), $transport->last_exchange['response'] ?? $e->getMessage());
                foreach ($chunk as $q) {
                    HY_Invoices::update_fields($q['id'], array('m_status' => 'failed', 'm_message' => $e->getMessage()));
                    $report['failed']++;
                }
                $report['messages'][] = 'ارسال ناموفق: ' . $e->getMessage();
                continue;
            }
            foreach ($chunk as $i => $q) {
                $r = $results[$q['uid']] ?? null;
                if ($r === null && count($results) === count($chunk)) {
                    $r = array_values($results)[$i];
                }
                if ($r && !empty($r['referenceNumber']) && empty($r['error'])) {
                    HY_Invoices::update_fields($q['id'], array(
                        'm_status' => 'pending',
                        'm_reference' => $r['referenceNumber'],
                        'm_message' => 'در صف بررسی سامانه مودیان',
                        'm_sent_at' => HY_DB::now(),
                    ));
                    $report['sent']++;
                } else {
                    $msg = $r['error'] ?? 'سامانه شماره پیگیری برنگرداند.';
                    HY_Invoices::update_fields($q['id'], array('m_status' => 'failed', 'm_message' => $msg));
                    $report['failed']++;
                    $report['messages'][] = "فاکتور " . HY_Jalali::persian_digits($q['number']) . ": $msg";
                }
            }
        }

        if ($report['sent'] && HY_Settings::get('auto_inquiry')) {
            wp_schedule_single_event(time() + 120, 'hy_moadian_inquiry');
        }
        return $report;
    }

    /* ------------------------------------------------------------------ */
    /*  Inquiry                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Checks the status of pending invoices (all of them when $ids is null).
     */
    public static function inquire(array $ids = null) {
        global $wpdb;
        $t = HY_DB::table('invoices');
        if ($ids === null) {
            $rows = $wpdb->get_results("SELECT id, number, m_reference FROM $t WHERE m_status='pending' AND m_reference<>'' ORDER BY id LIMIT 200", ARRAY_A);
        } else {
            $ids = array_filter(array_map('intval', $ids));
            if (!$ids) {
                return array('success' => 0, 'failed' => 0, 'pending' => 0, 'messages' => array());
            }
            $rows = $wpdb->get_results("SELECT id, number, m_reference FROM $t WHERE m_reference<>'' AND id IN (" . implode(',', $ids) . ')', ARRAY_A);
        }
        $report = array('success' => 0, 'failed' => 0, 'pending' => 0, 'messages' => array());
        if (!$rows) {
            return $report;
        }
        $by_ref = array();
        foreach ($rows as $r) {
            $by_ref[$r['m_reference']] = $r;
        }
        $transport = HY_Settings::transport(self::$http);
        foreach (array_chunk(array_keys($by_ref), 20) as $refs) {
            try {
                $results = self::with_token($transport, function ($token) use ($transport, $refs) {
                    return $transport->inquire_by_reference($refs, $token);
                });
            } catch (HY_Moadian_Exception $e) {
                $report['messages'][] = 'استعلام ناموفق: ' . $e->getMessage();
                continue;
            }
            foreach ($results as $res) {
                if (!isset($by_ref[$res['referenceNumber']])) {
                    continue;
                }
                $row = $by_ref[$res['referenceNumber']];
                $fields = array('m_checked_at' => HY_DB::now());
                if ($res['status'] === 'SUCCESS') {
                    $fields['m_status'] = 'success';
                    $fields['m_message'] = $res['warnings'] ? 'هشدار: ' . implode(' | ', $res['warnings']) : 'تایید شد';
                    $report['success']++;
                } elseif ($res['status'] === 'FAILED') {
                    $fields['m_status'] = 'failed';
                    $fields['m_message'] = $res['errors'] ? implode("\n", $res['errors']) : 'سامانه صورتحساب را رد کرد.';
                    $report['failed']++;
                    $report['messages'][] = "فاکتور " . HY_Jalali::persian_digits($row['number']) . ": " . $fields['m_message'];
                } else {
                    $fields['m_message'] = 'در صف بررسی سامانه مودیان (' . $res['status'] . ')';
                    $report['pending']++;
                }
                HY_Invoices::update_fields($row['id'], $fields);
                HY_DB::log($row['id'], 'inquiry', 200, $res['referenceNumber'], $res);
            }
        }
        return $report;
    }

    public static function cron_inquiry() {
        if (!HY_Settings::get('auto_inquiry')) {
            return;
        }
        try {
            self::inquire(null);
        } catch (Exception $e) {
            HY_DB::log(0, 'cron_error', 0, '', $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Bulk edits from the Moadian page                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Applies a Moadian-field change to invoices. Sent/approved invoices are
     * not touched, except when switching an approved invoice to اصلاحی/ابطالی,
     * which links it to its own tax ID as the reference and re-queues it.
     */
    public static function bulk_update(array $ids, $field, $value) {
        $changed = 0;
        $messages = array();
        foreach (array_map('intval', $ids) as $id) {
            $inv = HY_Invoices::get($id, false);
            if (!$inv) {
                continue;
            }
            $locked = in_array($inv['m_status'], array('pending', 'success'), true);
            $fields = array();
            switch ($field) {
                case 'm_ins':
                    $value = (int) $value;
                    if ($locked) {
                        if ($inv['m_status'] === 'success' && in_array($value, array(2, 3), true)) {
                            $fields = array(
                                'm_ins' => $value,
                                'm_irtaxid' => $inv['m_taxid'],
                                'm_serial' => null,
                                'm_taxid' => '',
                                'm_reference' => '',
                                'm_uid' => '',
                                'm_status' => 'unsent',
                                'm_message' => ($value === 2 ? 'اصلاحیِ ' : 'ابطالیِ ') . $inv['m_taxid'],
                            );
                            HY_DB::log($id, 'subject_change', 0, $inv['m_taxid'], 'ins=' . $value);
                        } else {
                            $messages[] = "فاکتور " . HY_Jalali::persian_digits($inv['number']) . ": فقط فاکتور تاییدشده را می‌توان اصلاحی یا ابطالی کرد.";
                        }
                    } else {
                        $fields = array('m_ins' => max(1, min(4, $value)));
                    }
                    break;
                case 'm_inty':
                case 'm_inp':
                case 'm_setm':
                    if (!$locked) {
                        $fields = array($field => (int) $value);
                    }
                    break;
                case 'm_irtaxid':
                    if (!$locked) {
                        $fields = array('m_irtaxid' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value)));
                    }
                    break;
                case 'm_status':
                    if (in_array($value, array('unsent', 'success', 'failed'), true)) {
                        $fields = array('m_status' => $value, 'm_message' => 'تغییر دستی وضعیت');
                    }
                    break;
                case 'serial':
                    if (!$locked) {
                        HY_Invoices::update_fields($id, array('m_serial' => null));
                        self::assign_serial($id, true);
                        $fields = array('m_taxid' => '', 'm_status' => 'unsent', 'm_message' => 'سریال جدید اختصاص یافت');
                    }
                    break;
            }
            if ($fields) {
                HY_Invoices::update_fields($id, $fields);
                $changed++;
            } elseif ($locked && $field !== 'm_ins') {
                $messages[] = "فاکتور " . HY_Jalali::persian_digits($inv['number']) . ": ارسال‌شده است و تغییر نکرد.";
            }
        }
        return array('changed' => $changed, 'messages' => $messages);
    }
}
