<?php
/** @var array $inv */
if (!defined('ABSPATH')) {
    exit;
}
$person = HY_Persons::get($inv['person_id']);
$is_return = $inv['kind'] === 'return';
$locked = in_array($inv['m_status'], array('pending', 'success'), true);
$act = function ($do, $confirm = '') use ($inv) {
    $url = HY_Admin::post_url('hy_invoice_action', array('id' => $inv['id'], 'do' => $do));
    return 'href="' . esc_url($url) . '"' . ($confirm ? ' data-confirm="' . esc_attr($confirm) . '"' : '');
};
$ref = $inv['ref_invoice_id'] ? HY_Invoices::get($inv['ref_invoice_id'], false) : null;
$returns = array();
if (!$is_return) {
    global $wpdb;
    $returns = $wpdb->get_results($wpdb->prepare('SELECT id, number, total, m_status FROM ' . HY_DB::table('invoices') . " WHERE kind='return' AND ref_invoice_id=%d", $inv['id']), ARRAY_A);
}

// Stamp + timeline describe where the invoice is in the Moadian lifecycle.
$m = $inv['m_status'];
$stamps = array(
    'success' => array('تایید مودیان', 'is-success'),
    'failed' => array('رد شده', 'is-failed'),
    'pending' => array('در انتظار مودیان', 'is-pending'),
    'unsent' => array($inv['status'] === 'draft' ? 'پیش‌نویس' : 'ارسال نشده', 'is-unsent'),
);
$stamp = $stamps[$m] ?? array('', '');
$sent_at = $inv['m_sent_at'] ? HY_Jalali::format(strtotime($inv['m_sent_at'] . ' UTC'), true) : '';
$checked_at = $inv['m_checked_at'] ? HY_Jalali::format(strtotime($inv['m_checked_at'] . ' UTC'), true) : '';
$was_sent = in_array($m, array('pending', 'success', 'failed'), true) && $inv['m_reference'];
$steps = array(
    array('ثبت فاکتور', 'is-done', HY_Jalali::format($inv['date_ts'])),
    array('ارسال به مودیان', $was_sent ? 'is-done' : ($m === 'failed' ? 'is-error' : 'is-current'), $was_sent ? $sent_at : ''),
    array($m === 'failed' && $was_sent ? 'رد شد' : 'تایید سامانه', $m === 'success' ? 'is-done' : ($m === 'failed' && $was_sent ? 'is-error' : ($m === 'pending' ? 'is-current' : '')), $was_sent ? $checked_at : ''),
);

// Plain-language next steps for the most common rejection reasons.
$fixes = array();
if ($m === 'failed') {
    $msg = (string) $inv['m_message'];
    if (strpos($msg, 'شناسه') !== false && strpos($msg, 'کالا') !== false) {
        $fixes[] = 'برای کالاهای این فاکتور شناسه ۱۳ رقمی مودیان ثبت کنید (<a href="' . esc_url(HY_Admin::url('hy-products')) . '">کالاها و خدمات</a>)، سپس دوباره ارسال کنید.';
    }
    if (strpos($msg, 'نوع اول') !== false || strpos($msg, 'خریدار') !== false) {
        $fixes[] = 'کد ملی یا شناسه ملی مشتری را <a href="' . esc_url(HY_Admin::url('hy-persons', array('edit' => $inv['person_id']))) . '">در پرونده شخص</a> وارد کنید، یا نوع صورتحساب را «نوع دوم» بگذارید.';
    }
    if (strpos($msg, 'تکراری') !== false) {
        $fixes[] = 'در صفحه سامانه مودیان این فاکتور را انتخاب و «سریال جدید» را بزنید، بعد دوباره ارسال کنید.';
    }
    if (strpos($msg, 'کلید') !== false || strpos($msg, 'توکن') !== false || strpos($msg, 'حافظه') !== false || strpos($msg, 'ارتباط') !== false) {
        $fixes[] = 'اتصال را در <a href="' . esc_url(HY_Admin::url('hy-settings')) . '">تنظیمات</a> با «تست اتصال» بررسی کنید.';
    }
    if (!$fixes) {
        $fixes[] = 'فاکتور را مطابق متن خطا ویرایش و دوباره ارسال کنید.';
    }
}
?>
<div class="hy-head">
    <h1><a class="hy-back" href="<?php echo esc_url(HY_Admin::url($is_return ? 'hy-returns' : 'hy-invoices')); ?>" title="بازگشت"><?php echo HY_Admin::icon('back'); ?></a><span><?php echo $is_return ? 'برگشت از فروش' : 'فاکتور فروش'; ?> <?php echo HY_Admin::fa($inv['number']); ?><span class="hy-sub"><?php echo esc_html($inv['title']); ?></span></span></h1>
    <div class="hy-head-actions">
        <?php if ($inv['status'] === 'draft') : ?>
            <a class="hy-btn hy-btn-save" <?php echo $act('confirm'); ?>><?php echo HY_Admin::icon('check'); ?> تایید فاکتور</a>
        <?php elseif (!$locked) : ?>
            <a class="hy-btn hy-btn-primary" <?php echo $act('send', 'این فاکتور به سامانه مودیان ارسال شود؟'); ?>><?php echo HY_Admin::icon('send'); ?> ارسال به مودیان</a>
        <?php elseif ($m === 'pending') : ?>
            <a class="hy-btn hy-btn-primary" <?php echo $act('inquiry'); ?>><?php echo HY_Admin::icon('refresh'); ?> بررسی وضعیت</a>
        <?php endif; ?>
        <?php if (!$locked) : ?>
            <a class="hy-btn hy-btn-ghost" href="<?php echo esc_url(HY_Admin::url('hy-invoices', array('edit' => $inv['id']))); ?>"><?php echo HY_Admin::icon('edit'); ?> ویرایش</a>
        <?php endif; ?>
        <a class="hy-btn hy-btn-ghost" target="_blank" href="<?php echo esc_url(HY_Admin::post_url('hy_print', array('id' => $inv['id']))); ?>"><?php echo HY_Admin::icon('print'); ?> چاپ</a>
        <div class="hy-dropdown">
            <button type="button" class="hy-btn hy-btn-ghost hy-dropdown-toggle" aria-label="کارهای بیشتر"><?php echo HY_Admin::icon('more'); ?></button>
            <div class="hy-dropdown-menu hy-menu-list">
                <?php if ($inv['paid']) : ?>
                    <a <?php echo $act('unpaid'); ?>>علامت «پرداخت نشده»</a>
                <?php else : ?>
                    <a <?php echo $act('paid'); ?>>علامت «پرداخت شده»</a>
                <?php endif; ?>
                <?php if (!$is_return && $inv['status'] === 'confirmed') : ?>
                    <a href="<?php echo esc_url(HY_Admin::url('hy-invoices', array('return_of' => $inv['id']))); ?>">ثبت برگشت از فروش</a>
                <?php endif; ?>
                <a <?php echo $act('copy'); ?>>کپی به فاکتور جدید</a>
                <?php if (!$locked) : ?>
                    <hr>
                    <a <?php echo $act('delete', 'این فاکتور برای همیشه حذف شود؟'); ?> class="hy-danger-text">حذف فاکتور</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="hy-card hy-doc">
    <?php if ($stamp[0]) : ?>
        <div class="hy-stamp <?php echo esc_attr($stamp[1]); ?>" aria-label="وضعیت در سامانه مودیان"><?php echo esc_html($stamp[0]); ?><?php if ($m === 'success' && $inv['m_checked_at']) : ?><small><?php echo esc_html(HY_Admin::fa(HY_Jalali::format(strtotime($inv['m_checked_at'] . ' UTC')))); ?></small><?php endif; ?></div>
    <?php endif; ?>
    <div class="hy-doc-head">
        <div><span>تاریخ</span><b><?php echo esc_html(HY_Admin::fa(HY_Jalali::format($inv['date_ts'], true))); ?></b></div>
        <div><span>سررسید</span><b><?php echo esc_html(HY_Admin::fa(HY_Jalali::format($inv['due_ts']))); ?></b></div>
        <div><span>تسویه</span><b><?php echo esc_html(HY_Invoices::$setm[$inv['m_setm']] ?? ''); ?></b></div>
        <?php if ($inv['reference']) : ?><div><span>ارجاع</span><b><?php echo esc_html(HY_Admin::fa($inv['reference'])); ?></b></div><?php endif; ?>
        <div class="hy-doc-total"><span>مبلغ کل</span><b><?php echo HY_Admin::money($inv['total']); ?></b></div>
    </div>
    <?php if ($person) : ?>
        <div class="hy-doc-party">
            <h2><a href="<?php echo esc_url(HY_Admin::url('hy-persons', array('edit' => $inv['person_id']))); ?>" style="color:inherit;text-decoration:none"><?php echo esc_html($person['name']); ?></a></h2>
            <div><span><?php echo (int) $person['person_type'] === 2 ? 'شناسه ملی' : 'کد ملی'; ?></span><?php echo esc_html(HY_Admin::fa($person['national_id'] ?: '—')); ?></div>
            <?php if ($person['economic_code']) : ?><div><span>کد اقتصادی</span><?php echo esc_html(HY_Admin::fa($person['economic_code'])); ?></div><?php endif; ?>
            <div><span>تلفن</span><?php echo esc_html(HY_Admin::fa($person['mobile'] ?: $person['phone'] ?: '—')); ?></div>
            <div><span>کدپستی</span><?php echo esc_html(HY_Admin::fa($person['postal_code'] ?: '—')); ?></div>
            <div style="flex:1 1 300px"><span>آدرس</span><?php echo esc_html($person['address'] ?: '—'); ?></div>
        </div>
    <?php endif; ?>
    <div class="hy-tags">
        <?php echo $inv['paid'] ? '<span class="hy-badge hy-ok">پرداخت شده</span>' : '<span class="hy-badge hy-muted">پرداخت نشده</span>'; ?>
        <?php if ($inv['wc_order_id']) :
            $wc_order = function_exists('wc_get_order') ? wc_get_order($inv['wc_order_id']) : null; ?>
            <a class="hy-badge hy-muted hy-plain" href="<?php echo esc_url($wc_order ? $wc_order->get_edit_order_url() : admin_url('post.php?post=' . (int) $inv['wc_order_id'] . '&action=edit')); ?>">سفارش ووکامرس #<?php echo HY_Admin::fa($inv['wc_order_id']); ?></a>
        <?php endif; ?>
        <?php if ($ref) : ?>
            <a class="hy-badge hy-muted hy-plain" href="<?php echo esc_url(HY_Admin::url('hy-invoices', array('view' => $ref['id']))); ?>">برگشتِ فاکتور فروش <?php echo HY_Admin::fa($ref['number']); ?></a>
        <?php endif; ?>
        <?php foreach ($returns as $r) : ?>
            <a class="hy-badge hy-warn hy-plain" href="<?php echo esc_url(HY_Admin::url('hy-invoices', array('view' => $r['id']))); ?>">برگشت از فروش <?php echo HY_Admin::fa($r['number']); ?> — <?php echo HY_Admin::money($r['total'], false); ?> ریال</a>
        <?php endforeach; ?>
    </div>
</div>

<div class="hy-card hy-p0">
    <div class="hy-scroll">
        <table class="hy-table">
            <thead><tr><th>#</th><th>کالا / شرح</th><th>واحد</th><th>تعداد</th><th>مبلغ واحد</th><th>تخفیف</th><th>مالیات</th><th>مبلغ کل</th></tr></thead>
            <tbody>
            <?php foreach ($inv['items'] as $i => $it) : ?>
                <tr>
                    <td class="hy-muted-text"><?php echo HY_Admin::fa($i + 1); ?></td>
                    <td><?php echo esc_html($it['description']); ?><?php if ($it['stuff_id']) : ?><div class="hy-hint">شناسه مودیان: <?php echo esc_html(HY_Admin::fa($it['stuff_id'])); ?></div><?php endif; ?></td>
                    <td><?php echo esc_html($it['unit_title']); ?></td>
                    <td><?php echo HY_Admin::fa(rtrim(rtrim(number_format((float) $it['qty'], 4, '.', ''), '0'), '.')); ?></td>
                    <td class="hy-num"><?php echo HY_Admin::money($it['unit_price']); ?></td>
                    <td class="hy-num"><?php echo $it['discount'] ? HY_Admin::money($it['discount']) : '<span class="hy-muted-text">—</span>'; ?></td>
                    <td class="hy-num"><?php echo HY_Admin::money($it['tax'], false); ?> <span class="hy-cur">(<?php echo HY_Admin::fa((float) $it['vat_rate']); ?>٪)</span></td>
                    <td class="hy-amount"><?php echo HY_Admin::money($it['total']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="hy-footer-grid">
    <div class="hy-card">
        <h2 class="hy-section-title">سامانه مودیان <?php echo HY_Admin::m_badge($m); ?></h2>
        <?php if ($inv['status'] === 'confirmed') : ?>
            <ol class="hy-timeline">
                <?php foreach ($steps as $st) : ?>
                    <li class="<?php echo esc_attr($st[1]); ?>"><?php echo esc_html($st[0]); ?><?php if ($st[2]) : ?><small><?php echo esc_html(HY_Admin::fa($st[2])); ?></small><?php endif; ?></li>
                <?php endforeach; ?>
            </ol>
        <?php else : ?>
            <p class="hy-hint" style="margin-top:0">پیش‌نویس به مودیان ارسال نمی‌شود. بعد از «تایید فاکتور» می‌توانید آن را بفرستید.</p>
        <?php endif; ?>

        <?php if ($m === 'failed') : ?>
            <div class="hy-alert hy-alert-error">
                <strong><?php echo $was_sent ? 'سامانه این صورتحساب را نپذیرفت:' : 'صورتحساب ارسال نشد:'; ?></strong>
                <div><?php echo nl2br(esc_html($inv['m_message'])); ?></div>
                <ul class="hy-fix-list"><?php foreach ($fixes as $f) : ?><li><?php echo wp_kses($f, array('a' => array('href' => array()))); ?></li><?php endforeach; ?></ul>
            </div>
        <?php elseif ($inv['m_message'] && $m !== 'unsent') : ?>
            <p class="hy-hint" style="margin-top:0"><?php echo nl2br(esc_html($inv['m_message'])); ?></p>
        <?php endif; ?>

        <dl class="hy-kv">
            <dt>نوع / الگو / موضوع</dt>
            <dd><?php echo esc_html(($inv['m_inty'] ? HY_Invoices::$inty[$inv['m_inty']] : 'خودکار') . ' / ' . (HY_Invoices::$inp[$inv['m_inp']] ?? '') . ' / ' . (HY_Invoices::$ins[$inv['m_ins']] ?? '')); ?></dd>
            <?php if ($inv['m_taxid']) : ?><dt>شماره منحصر به فرد مالیاتی</dt><dd class="hy-mono hy-ltr"><?php echo esc_html($inv['m_taxid']); ?></dd><?php endif; ?>
            <?php if ($inv['m_irtaxid']) : ?><dt>صورتحساب مرجع</dt><dd class="hy-mono hy-ltr"><?php echo esc_html($inv['m_irtaxid']); ?></dd><?php endif; ?>
            <?php if ($inv['m_reference']) : ?><dt>شماره پیگیری</dt><dd class="hy-mono hy-ltr"><?php echo esc_html($inv['m_reference']); ?></dd><?php endif; ?>
            <?php if ($inv['m_serial']) : ?><dt>سریال</dt><dd><?php echo HY_Admin::fa($inv['m_serial']); ?></dd><?php endif; ?>
        </dl>
    </div>
    <div class="hy-totals">
        <div><span>مجموع</span><b><?php echo HY_Admin::money($inv['subtotal']); ?></b></div>
        <div><span>تخفیف</span><b><?php echo HY_Admin::money($inv['discount']); ?></b></div>
        <div><span>مالیات بر ارزش افزوده</span><b><?php echo HY_Admin::money($inv['tax']); ?></b></div>
        <div class="hy-grand"><span>مبلغ کل</span><b><?php echo HY_Admin::money($inv['total']); ?></b></div>
        <p class="hy-words-row"><?php echo esc_html(HY_Admin::words($inv['total'])); ?></p>
    </div>
</div>
<?php if ($inv['notes']) : ?>
    <div class="hy-card"><h2 class="hy-section-title">توضیحات</h2><?php echo nl2br(esc_html($inv['notes'])); ?></div>
<?php endif; ?>
