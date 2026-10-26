<?php
/**
 * Sales / return invoice form.
 *
 * @var array|null $inv
 * @var string     $kind
 */
if (!defined('ABSPATH')) {
    exit;
}
$is_edit = !empty($inv);
$restore = !$is_edit && !empty($_GET['restore']) ? get_transient('hy_form_' . get_current_user_id()) : null;
$s = HY_Settings::all();

$person = $is_edit ? HY_Persons::get($inv['person_id']) : (!empty($restore['person_id']) ? HY_Persons::get((int) $restore['person_id']) : null);
$person_json = $person ? array(
    'id' => (int) $person['id'],
    'type' => HY_Persons::$types[$person['person_type']] ?? '',
    'type_id' => (int) $person['person_type'],
    'national_id' => $person['national_id'],
    'economic_code' => $person['economic_code'],
    'phone' => $person['mobile'] ?: $person['phone'],
    'balance' => HY_Persons::balance($person['id']),
) : null;

$items = array();
$source_items = $is_edit ? $inv['items'] : (array) ($restore['items'] ?? array());
foreach ($source_items as $it) {
    $p = !empty($it['product_id']) ? HY_Products::get((int) $it['product_id']) : null;
    $items[] = array(
        'product_id' => $p ? (int) $p['id'] : 0,
        'product_name' => $p ? HY_Admin::fa(str_pad($p['code'], 6, '0', STR_PAD_LEFT)) . ' - ' . $p['name'] : '',
        'description' => sanitize_text_field($it['description'] ?? ''),
        'unit_title' => sanitize_text_field($it['unit_title'] ?? ''),
        'unit_code' => preg_replace('/\D+/', '', (string) ($it['unit_code'] ?? '')),
        'stuff_id' => preg_replace('/\D+/', '', (string) ($it['stuff_id'] ?? '')) ?: ($p ? $p['stuff_id'] : ''),
        'qty' => (float) HY_Jalali::latin_digits($it['qty'] ?? 0),
        'unit_price' => (int) preg_replace('/\D+/', '', HY_Jalali::latin_digits($it['unit_price'] ?? 0)),
        'discount' => (int) preg_replace('/\D+/', '', HY_Jalali::latin_digits($it['discount'] ?? 0)),
        'vat_rate' => (float) ($it['vat_rate'] ?? 0),
    );
}

$date_ts = $is_edit ? (int) $inv['date_ts'] : time();
$due_ts = $is_edit ? (int) $inv['due_ts'] : time();
$is_return = $kind === 'return';
$heading = $is_return ? 'فاکتور برگشت از فروش' : 'فاکتور فروش';
$sub = $is_edit ? 'ویرایش فاکتور شماره ' . HY_Admin::fa($inv['number']) : 'فاکتور جدید، شماره ' . HY_Admin::fa(HY_Invoices::next_number($kind));
$m_inty = $is_edit ? (int) $inv['m_inty'] : (int) ($restore['m_inty'] ?? 0);
$m_setm = $is_edit ? (int) $inv['m_setm'] : (int) ($restore['m_setm'] ?? $s['default_setm']);
$m_inp = $is_edit ? (int) $inv['m_inp'] : 1;
$back = $is_edit ? HY_Admin::url('hy-invoices', array('view' => $inv['id'])) : HY_Admin::url('hy-invoices');
?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="hy-invoice-form" autocomplete="off">
    <?php wp_nonce_field('hy_save_invoice'); ?>
    <input type="hidden" name="action" value="hy_save_invoice">
    <input type="hidden" name="id" value="<?php echo $is_edit ? (int) $inv['id'] : 0; ?>">
    <input type="hidden" name="kind" value="<?php echo esc_attr($kind); ?>">
    <?php if ($is_edit && $inv['ref_invoice_id']) : ?>
        <input type="hidden" name="ref_invoice_id" value="<?php echo (int) $inv['ref_invoice_id']; ?>">
    <?php endif; ?>
    <input type="hidden" name="submit_as" id="hy-submit-as" value="confirmed">
    <input type="hidden" name="m_inp" value="<?php echo (int) $m_inp; ?>">

    <div class="hy-head">
        <h1><a class="hy-back" href="<?php echo esc_url($back); ?>" title="بازگشت"><?php echo HY_Admin::icon('back'); ?></a><span><?php echo esc_html($heading); ?><span class="hy-sub"><?php echo esc_html($sub); ?></span></span></h1>
        <?php if ($is_edit && (int) $inv['m_ins'] !== 1) : ?>
            <span class="hy-badge hy-warn">موضوع در مودیان: <?php echo esc_html(HY_Invoices::$ins[$inv['m_ins']]); ?></span>
        <?php endif; ?>
    </div>

    <div class="hy-form-top">
        <div class="hy-card">
            <div class="hy-field hy-req">
                <label for="hy-person-input">مشتری</label>
                <div class="hy-ac" data-source="persons" id="hy-person-ac">
                    <input type="hidden" name="person_id" value="<?php echo $person ? (int) $person['id'] : ''; ?>" class="hy-ac-value">
                    <input type="text" id="hy-person-input" class="hy-input hy-ac-input" placeholder="نام، کد ملی یا موبایل را بنویسید…" value="<?php echo $person ? esc_attr(HY_Admin::fa(str_pad($person['code'], 6, '0', STR_PAD_LEFT)) . ' - ' . $person['name']) : ''; ?>">
                    <button type="button" class="hy-ac-add" title="شخص جدید" data-modal="hy-modal-person">+</button>
                    <div class="hy-ac-list" role="listbox"></div>
                </div>
                <div class="hy-customer-card" id="hy-customer-card" data-person="<?php echo esc_attr(wp_json_encode($person_json)); ?>"></div>
            </div>
            <div class="hy-field hy-req" style="margin-top:12px">
                <label for="hy-title">عنوان</label>
                <input type="text" name="title" id="hy-title" class="hy-input" value="<?php echo esc_attr($is_edit ? $inv['title'] : ($restore['title'] ?? '')); ?>" placeholder="به‌طور پیش‌فرض نام مشتری">
            </div>
        </div>

        <div class="hy-card">
            <div class="hy-grid hy-grid-2">
                <div class="hy-field">
                    <label for="hy-number">شماره</label>
                    <div class="hy-inline">
                        <input type="text" name="number" class="hy-input" id="hy-number" value="<?php echo $is_edit ? esc_attr($inv['number']) : ''; ?>" <?php echo $is_edit ? '' : 'disabled'; ?> placeholder="<?php echo $is_edit ? '' : esc_attr(HY_Admin::fa(HY_Invoices::next_number($kind))); ?>">
                        <?php if (!$is_edit) : ?>
                            <label class="hy-check" style="white-space:nowrap"><input type="checkbox" name="auto_number" value="1" checked id="hy-auto-number"> خودکار</label>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="hy-field">
                    <label for="hy-reference">ارجاع</label>
                    <input type="text" name="reference" id="hy-reference" class="hy-input" value="<?php echo esc_attr($is_edit ? $inv['reference'] : ($restore['reference'] ?? '')); ?>" placeholder="مثلاً شماره سفارش">
                </div>
                <div class="hy-field">
                    <label>تاریخ و ساعت</label>
                    <div class="hy-inline">
                        <input type="text" name="date" class="hy-input hy-date" data-max-today="1" value="<?php echo esc_attr(HY_Jalali::format($date_ts)); ?>" dir="ltr" aria-label="تاریخ">
                        <input type="text" name="time" class="hy-input" style="width:76px;flex:0 0 76px;text-align:center" value="<?php echo esc_attr(HY_Jalali::time($date_ts)); ?>" dir="ltr" aria-label="ساعت">
                    </div>
                </div>
                <div class="hy-field">
                    <label>تاریخ سررسید</label>
                    <input type="text" name="due_date" class="hy-input hy-date" value="<?php echo esc_attr(HY_Jalali::format($due_ts)); ?>" dir="ltr" aria-label="تاریخ سررسید">
                </div>
                <div class="hy-field">
                    <label for="hy-setm">روش تسویه</label>
                    <select name="m_setm" class="hy-input" id="hy-setm">
                        <?php foreach (HY_Invoices::$setm as $k => $v) : ?>
                            <option value="<?php echo $k; ?>" <?php selected($m_setm, $k); ?>><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hy-field">
                    <label for="hy-inty">نوع صورتحساب در مودیان</label>
                    <select name="m_inty" class="hy-input" id="hy-inty">
                        <option value="0" <?php selected($m_inty, 0); ?>>خودکار</option>
                        <option value="1" <?php selected($m_inty, 1); ?>>نوع اول — با مشخصات خریدار</option>
                        <option value="2" <?php selected($m_inty, 2); ?>>نوع دوم — بدون مشخصات خریدار</option>
                    </select>
                </div>
            </div>
            <div class="hy-field hy-cap-field" style="<?php echo $m_setm === 3 ? '' : 'display:none'; ?>">
                <label>مبلغی که نقد پرداخت شده (ریال)</label>
                <input type="text" name="m_cap" class="hy-input hy-money" value="<?php echo HY_Admin::fa(number_format($is_edit ? (int) $inv['m_cap'] : 0)); ?>" dir="ltr">
            </div>
        </div>
    </div>

    <div class="hy-card hy-p0 hy-items-card">
        <div class="hy-scroll" style="overflow:visible">
            <table class="hy-table hy-items" id="hy-items">
                <thead>
                <tr>
                    <th class="hy-col-n">#</th>
                    <th class="hy-col-product">کالا</th>
                    <th>شرح</th>
                    <th class="hy-col-unit">واحد</th>
                    <th class="hy-col-qty">تعداد</th>
                    <th class="hy-col-money">مبلغ واحد</th>
                    <th class="hy-col-money">تخفیف</th>
                    <th class="hy-col-vat">مالیات</th>
                    <th class="hy-col-money">مبلغ کل</th>
                    <th class="hy-col-del"><span class="screen-reader-text">حذف</span></th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="hy-items-actions">
            <button type="button" class="hy-btn hy-btn-ghost hy-btn-sm" id="hy-add-row"><?php echo HY_Admin::icon('plus'); ?> افزودن ردیف</button>
            <button type="button" class="hy-btn hy-btn-ghost hy-btn-sm" id="hy-discount-all">تخفیف درصدی</button>
            <button type="button" class="hy-btn hy-btn-ghost hy-btn-sm" id="hy-tax-toggle">مالیات</button>
            <button type="button" class="hy-btn hy-btn-ghost hy-btn-sm" id="hy-toggle-notes">توضیحات</button>
            <span class="hy-hint">با Enter به خانه بعدی بروید؛ ردیف جدید خودکار اضافه می‌شود.</span>
            <div id="hy-notes-box" style="<?php echo ($is_edit && $inv['notes']) ? '' : 'display:none;'; ?>width:100%">
                <textarea name="notes" class="hy-input" rows="2" placeholder="توضیحاتی که روی فاکتور چاپ می‌شود"><?php echo esc_textarea($is_edit ? $inv['notes'] : ''); ?></textarea>
            </div>
        </div>
    </div>

    <div class="hy-sticky-bar">
        <div class="hy-sum"><span>اقلام</span><b id="hy-t-qty">۰</b></div>
        <div class="hy-sum"><span>مجموع</span><b id="hy-t-gross">۰</b></div>
        <div class="hy-sum"><span>تخفیف</span><b id="hy-t-discount">۰</b></div>
        <div class="hy-sum"><span>مالیات</span><b id="hy-t-tax">۰</b></div>
        <div class="hy-sum hy-sum-grand"><span>مبلغ کل (ریال)</span><b id="hy-t-total">۰</b></div>
        <div class="hy-words" id="hy-t-words"></div>
        <div class="hy-spacer"></div>
        <button type="submit" class="hy-btn hy-btn-ghost" data-submit="draft">پیش‌نویس</button>
        <div class="hy-split">
            <button type="submit" class="hy-btn hy-btn-save" data-submit="confirmed"><?php echo HY_Admin::icon('check'); ?> ذخیره و تایید <span class="hy-kbd">Ctrl+S</span></button>
            <div class="hy-dropdown is-up">
                <button type="button" class="hy-btn hy-btn-save hy-dropdown-toggle" aria-label="گزینه‌های بیشتر ذخیره"><?php echo HY_Admin::icon('chevron'); ?></button>
                <div class="hy-dropdown-menu hy-menu-list">
                    <?php if (!$is_edit) : ?>
                        <button type="submit" data-submit="next">تایید و فاکتور بعدی</button>
                    <?php endif; ?>
                    <button type="submit" data-submit="send"><?php echo HY_Admin::icon('send'); ?> تایید و ارسال به مودیان</button>
                </div>
            </div>
        </div>
    </div>
</form>

<script type="application/json" id="hy-form-data"><?php echo wp_json_encode(array('items' => $items)); ?></script>

<?php include __DIR__ . '/modals.php'; ?>
