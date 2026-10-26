<?php
if (!defined('ABSPATH')) {
    exit;
}
$s = HY_Settings::all();
list($jy, $jm) = HY_Jalali::parts(time());
$month_start = HY_Jalali::parse(sprintf('%04d/%02d/01', $jy, $jm));
$season_first = intdiv($jm - 1, 3) * 3 + 1;
$season_start = HY_Jalali::parse(sprintf('%04d/%02d/01', $jy, $season_first));
$seasons = array(1 => 'بهار', 4 => 'تابستان', 7 => 'پاییز', 10 => 'زمستان');
$month = HY_Invoices::period_totals($month_start, time());
$season = HY_Invoices::period_totals($season_start, time());
$receivable = HY_Invoices::receivables();
$counts = HY_Invoices::counts_by_m_status();
list($recent) = HY_Invoices::list_query(array('per_page' => 8));

$pipe = array(
    'unsent' => array('ارسال نشده', '#2563a6'),
    'pending' => array('در انتظار پاسخ سامانه', '#c98a12'),
    'failed' => array('خطا — نیاز به اصلاح', '#b4322a'),
    'success' => array('تایید شده', '#1e7a4c'),
);
$pipe_total = max(1, array_sum(array_intersect_key($counts, $pipe)));

$health = array(
    array(preg_match('/^[A-Z0-9]{6}$/', $s['fiscal_id']), 'شناسه حافظه مالیاتی', $s['fiscal_id'] ?: 'وارد نشده'),
    array(preg_match('/^(\d{11}|\d{14})$/', $s['economic_code']), 'کد اقتصادی فروشنده', $s['economic_code'] ? HY_Admin::fa($s['economic_code']) : 'وارد نشده'),
    array(HY_Settings::has_secret('private_key'), 'کلید خصوصی', HY_Settings::has_secret('private_key') ? 'ذخیره شده' : 'ساخته نشده'),
    array($s['environment'] === 'production', 'محیط ارسال', $s['environment'] === 'production' ? 'اصلی' : 'آزمایشی — صورتحساب‌ها ثبت رسمی نمی‌شوند'),
);
?>
<div class="hy-head">
    <h1>داشبورد<span class="hy-sub"><?php echo esc_html(HY_Admin::fa(HY_Jalali::format(time())) . ' — ' . HY_Jalali::month_name($jm) . ' ' . HY_Admin::fa($jy)); ?></span></h1>
    <div class="hy-head-actions">
        <a class="hy-btn hy-btn-ghost" href="<?php echo esc_url(HY_Admin::url('hy-moadian')); ?>">سامانه مودیان</a>
        <a class="hy-btn hy-btn-save" href="<?php echo esc_url(HY_Admin::url('hy-invoice-new')); ?>"><?php echo HY_Admin::icon('plus'); ?> فروش جدید</a>
    </div>
</div>

<div class="hy-ledger">
    <div>
        <span>فروش <?php echo esc_html(HY_Jalali::month_name($jm)); ?></span>
        <b><?php echo HY_Admin::money($month['total'], false); ?></b>
        <small>ریال — <?php echo HY_Admin::fa($month['count']); ?> فاکتور<?php echo $month['returns'] ? '، ' . HY_Admin::fa($month['returns']) . ' برگشتی' : ''; ?></small>
    </div>
    <div>
        <span>مالیات بر ارزش افزوده فصل <?php echo esc_html($seasons[$season_first]); ?></span>
        <b><?php echo HY_Admin::money($season['tax'], false); ?></b>
        <small>ریال — برای اظهارنامه فصلی</small>
    </div>
    <div>
        <span>فروش فصل <?php echo esc_html($seasons[$season_first]); ?></span>
        <b><?php echo HY_Admin::money($season['total'], false); ?></b>
        <small>ریال، با مالیات</small>
    </div>
    <div>
        <span>طلب از مشتریان</span>
        <b><?php echo HY_Admin::money($receivable, false); ?></b>
        <small>ریال — فاکتورهای پرداخت‌نشده</small>
    </div>
</div>

<div class="hy-dash-cols">
    <div class="hy-card hy-p0">
        <div class="hy-toolbar" style="justify-content:space-between">
            <h2 class="hy-section-title" style="margin:0">آخرین فاکتورها</h2>
            <a href="<?php echo esc_url(HY_Admin::url('hy-invoices')); ?>">همه فاکتورها</a>
        </div>
        <?php if (!$recent) : ?>
            <div class="hy-empty-state">
                <strong>هنوز فاکتوری ثبت نشده</strong>
                اولین فاکتور فروش را بسازید یا سفارش‌های ووکامرس را به فاکتور تبدیل کنید.
                <div><a class="hy-btn hy-btn-save" href="<?php echo esc_url(HY_Admin::url('hy-invoice-new')); ?>"><?php echo HY_Admin::icon('plus'); ?> فروش جدید</a></div>
            </div>
        <?php else : ?>
            <table class="hy-table hy-stack">
                <thead><tr><th>شماره</th><th>تاریخ</th><th>مشتری</th><th>مبلغ کل</th><th>مودیان</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r) : ?>
                    <tr class="hy-row-link" data-href="<?php echo esc_url(HY_Admin::url('hy-invoices', array('view' => $r['id']))); ?>">
                        <td><a href="<?php echo esc_url(HY_Admin::url('hy-invoices', array('view' => $r['id']))); ?>"><?php echo ($r['kind'] === 'return' ? 'برگشت ' : '') . HY_Admin::fa($r['number']); ?></a></td>
                        <td class="hy-muted-text"><?php echo esc_html(HY_Admin::fa(HY_Jalali::format($r['date_ts']))); ?></td>
                        <td><?php echo esc_html($r['person_name']); ?></td>
                        <td class="hy-amount"><?php echo HY_Admin::money($r['total']); ?></td>
                        <td><?php echo $r['status'] === 'draft' ? '<span class="hy-badge hy-draft">پیش‌نویس</span>' : HY_Admin::m_badge($r['m_status']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div>
        <div class="hy-card">
            <h2 class="hy-section-title">وضعیت صورتحساب‌ها در مودیان</h2>
            <div class="hy-pipeline" role="img" aria-label="سهم هر وضعیت">
                <?php foreach ($pipe as $k => $p) : if (empty($counts[$k])) { continue; } ?>
                    <span style="width:<?php echo esc_attr(round($counts[$k] * 100 / $pipe_total, 2)); ?>%;background:<?php echo esc_attr($p[1]); ?>"></span>
                <?php endforeach; ?>
            </div>
            <div class="hy-pipe-list">
                <?php foreach ($pipe as $k => $p) : ?>
                    <a href="<?php echo esc_url(HY_Admin::url('hy-moadian', array('tab' => $k))); ?>">
                        <i style="background:<?php echo esc_attr($p[1]); ?>"></i><?php echo esc_html($p[0]); ?>
                        <b><?php echo HY_Admin::fa($counts[$k] ?? 0); ?></b>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($counts['failed'])) : ?>
                <p class="hy-hint is-error" style="margin:10px 0 0">صورتحساب‌های خطادار را اصلاح و دوباره ارسال کنید؛ تا تایید نشوند، در سامانه ثبت نیستند.</p>
            <?php endif; ?>
        </div>

        <div class="hy-card">
            <h2 class="hy-section-title">اتصال به مودیان</h2>
            <ul class="hy-health">
                <?php foreach ($health as $h) : ?>
                    <li><span class="hy-dot <?php echo $h[0] ? 'is-ok' : ''; ?>"><?php echo $h[0] ? '✓' : ''; ?></span><span><?php echo esc_html($h[1]); ?><br><small class="hy-muted-text"><?php echo esc_html($h[2]); ?></small></span></li>
                <?php endforeach; ?>
            </ul>
            <p style="margin:12px 0 0"><a href="<?php echo esc_url(HY_Admin::url('hy-settings')); ?>">تنظیمات اتصال</a></p>
        </div>
    </div>
</div>
