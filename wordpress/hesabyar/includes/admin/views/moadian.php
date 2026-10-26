<?php
if (!defined('ABSPATH')) {
    exit;
}
$tabs = array('all' => 'همه', 'unsent' => 'ارسال نشده', 'pending' => 'در انتظار پاسخ', 'failed' => 'خطا', 'success' => 'تایید شده');
$tab = sanitize_key($_GET['tab'] ?? 'all');
$tab = isset($tabs[$tab]) ? $tab : 'all';
$search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
$page_no = max(1, (int) ($_GET['paged'] ?? 1));
list($rows, $total) = HY_Invoices::list_query(array(
    'status' => 'confirmed',
    'm_status' => $tab === 'all' ? '' : $tab,
    'search' => HY_Jalali::latin_digits($search),
    'page' => $page_no,
    'per_page' => 50,
));
$counts = HY_Invoices::counts_by_m_status();
$pages = max(1, (int) ceil($total / 50));
$s = HY_Settings::all();
$configured = $s['fiscal_id'] && $s['economic_code'] && HY_Settings::has_secret('private_key');
$edit_menu = array(
    'm_inty' => array('نوع صورتحساب', array(0 => 'خودکار', 1 => 'نوع اول — با مشخصات خریدار', 2 => 'نوع دوم — بدون مشخصات خریدار')),
    'm_setm' => array('روش تسویه', HY_Invoices::$setm),
    'm_ins' => array('موضوع', HY_Invoices::$ins),
    'm_inp' => array('الگو', HY_Invoices::$inp),
);
$empty_text = array(
    'all' => array('هنوز فاکتور تاییدشده‌ای نیست', 'فاکتورهای تاییدشده اینجا برای ارسال به مودیان صف می‌شوند.'),
    'unsent' => array('همه فاکتورها ارسال شده‌اند', 'فاکتور تاییدشده‌ای منتظر ارسال نیست.'),
    'pending' => array('چیزی در انتظار پاسخ نیست', 'پاسخ سامانه برای همه ارسال‌ها رسیده است.'),
    'failed' => array('خطایی وجود ندارد', 'هیچ صورتحسابی رد نشده است.'),
    'success' => array('هنوز صورتحسابی تایید نشده', 'بعد از ارسال و استعلام، صورتحساب‌های تاییدشده اینجا دیده می‌شوند.'),
);
?>
<div class="hy-head">
    <h1>سامانه مودیان<span class="hy-sub">ارسال صورتحساب‌ها و پیگیری پاسخ سازمان امور مالیاتی</span></h1>
    <div class="hy-head-actions hy-env">
        <?php echo $s['environment'] === 'sandbox' ? '<span class="hy-badge hy-warn">محیط آزمایشی</span>' : '<span class="hy-badge hy-ok">محیط اصلی</span>'; ?>
        <span class="hy-badge hy-muted hy-plain"><?php echo $s['protocol'] === 'v2' ? 'با گواهی' : 'بدون گواهی'; ?> — <span class="hy-mono"><?php echo esc_html($s['fiscal_id'] ?: '؟'); ?></span></span>
        <button type="button" form="hy-moadian-form" class="hy-btn hy-btn-ghost" data-bulk="inquiry_all" data-noselect="1"><?php echo HY_Admin::icon('refresh'); ?> استعلام همه در انتظارها</button>
    </div>
</div>

<?php if (!$configured) : ?>
    <div class="hy-alert hy-alert-warning"><strong>اتصال به مودیان هنوز کامل نیست.</strong> شناسه حافظه، کد اقتصادی و کلید را در <a href="<?php echo esc_url(HY_Admin::url('hy-settings')); ?>">تنظیمات</a> وارد کنید.</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="hy-moadian-form">
    <?php wp_nonce_field('hy_moadian_bulk'); ?>
    <input type="hidden" name="action" value="hy_moadian_bulk">
    <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
    <input type="hidden" name="do" id="hy-bulk-do" value="">
    <input type="hidden" name="value" id="hy-bulk-value" value="">

    <div class="hy-card hy-p0" style="overflow:visible">
        <div class="hy-tabs">
            <?php foreach ($tabs as $k => $label) :
                $c = (int) ($counts[$k] ?? 0); ?>
                <a class="<?php echo $k === $tab ? 'is-active' : ''; ?> <?php echo ($k === 'failed' && $c) ? 'hy-tab-alert' : ''; ?>" href="<?php echo esc_url(HY_Admin::url('hy-moadian', array('tab' => $k))); ?>"><?php echo esc_html($label); ?> <span class="hy-count"><?php echo HY_Admin::fa($c); ?></span></a>
            <?php endforeach; ?>
        </div>

        <div class="hy-actionbar">
            <button type="button" class="hy-btn hy-btn-primary" data-bulk="send" data-needs-selection="1" data-confirm-count="{n} فاکتور به سامانه مودیان ارسال شود؟ بعد از تایید سامانه، تغییر فقط با صورتحساب اصلاحی یا ابطالی ممکن است."><?php echo HY_Admin::icon('send'); ?> ارسال</button>
            <button type="button" class="hy-btn hy-btn-ghost" data-bulk="inquiry" data-needs-selection="1"><?php echo HY_Admin::icon('refresh'); ?> بررسی وضعیت</button>
            <div class="hy-dropdown">
                <button type="button" class="hy-btn hy-btn-ghost hy-dropdown-toggle" data-needs-selection="1">تغییر اطلاعات مودیان <?php echo HY_Admin::icon('chevron'); ?></button>
                <div class="hy-dropdown-menu hy-menu-list" style="inset-inline-end:auto;inset-inline-start:0;max-height:420px;overflow:auto">
                    <?php foreach ($edit_menu as $field => $menu) : ?>
                        <div class="hy-menu-label"><?php echo esc_html($menu[0]); ?></div>
                        <?php foreach ($menu[1] as $k => $label) : ?>
                            <a href="#" data-bulk="<?php echo esc_attr($field); ?>" data-value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></a>
                        <?php endforeach; ?>
                        <hr>
                    <?php endforeach; ?>
                    <a href="#" data-bulk="m_irtaxid" data-prompt="شماره منحصر به فرد مالیاتی صورتحساب مرجع (۲۲ کاراکتر):">شماره صورتحساب مرجع…</a>
                    <a href="#" data-bulk="serial" data-confirm-count="به {n} فاکتور سریال جدید داده شود؟ برای خطای «شماره تکراری» کاربرد دارد.">سریال جدید</a>
                </div>
            </div>
            <div class="hy-dropdown">
                <button type="button" class="hy-btn hy-btn-ghost hy-dropdown-toggle" data-needs-selection="1" aria-label="کارهای بیشتر"><?php echo HY_Admin::icon('more'); ?></button>
                <div class="hy-dropdown-menu hy-menu-list" style="inset-inline-end:auto;inset-inline-start:0">
                    <div class="hy-menu-label">تغییر دستی وضعیت</div>
                    <a href="#" data-bulk="m_status" data-value="unsent">ارسال نشده</a>
                    <a href="#" data-bulk="m_status" data-value="success" data-confirm-count="وضعیت {n} فاکتور دستی «تایید شده» شود؟ فقط وقتی که در کارپوشه تایید آن را دیده‌اید.">تایید شده (ثبت‌شده در کارپوشه)</a>
                    <a href="#" data-bulk="m_status" data-value="failed">خطا</a>
                    <hr>
                    <a href="#" class="hy-danger-text" data-bulk="delete" data-confirm-count="{n} فاکتور حذف شود؟ فاکتورهای ارسال‌شده حذف نمی‌شوند.">حذف فاکتور</a>
                </div>
            </div>
            <span class="hy-selected-count" id="hy-selected-count"></span>
            <div class="hy-search" style="width:220px">
                <?php echo HY_Admin::icon('search'); ?>
                <input type="search" class="hy-input" id="hy-moadian-search" value="<?php echo esc_attr($search); ?>" placeholder="شماره، مشتری، شماره مالیاتی" data-url="<?php echo esc_url(HY_Admin::url('hy-moadian', array('tab' => $tab))); ?>">
            </div>
        </div>

        <?php if (!$rows) : ?>
            <div class="hy-empty-state"><strong><?php echo esc_html($empty_text[$tab][0]); ?></strong><?php echo esc_html($empty_text[$tab][1]); ?></div>
        <?php else : ?>
        <div class="hy-scroll">
            <table class="hy-table hy-moadian-table hy-mstack">
                <thead>
                <tr>
                    <th class="hy-col-check"><input type="checkbox" id="hy-check-all" aria-label="انتخاب همه"></th>
                    <th>فاکتور</th>
                    <th>تاریخ</th>
                    <th>مشتری</th>
                    <th>مبلغ کل (ریال)</th>
                    <th>نوع</th>
                    <th>موضوع</th>
                    <th>تسویه</th>
                    <th>وضعیت</th>
                    <th>پیام سامانه</th>
                    <th>شماره مالیاتی</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r) :
                    $msg = (string) $r['m_message']; ?>
                    <tr class="hy-select-row">
                        <td class="c-check"><input type="checkbox" name="ids[]" value="<?php echo (int) $r['id']; ?>" class="hy-check-row" aria-label="انتخاب فاکتور <?php echo esc_attr($r['number']); ?>"></td>
                        <td class="c-num"><a href="<?php echo esc_url(HY_Admin::url('hy-invoices', array('view' => $r['id']))); ?>"><?php echo ($r['kind'] === 'return' ? 'برگشت ' : 'فاکتور ') . HY_Admin::fa($r['number']); ?></a></td>
                        <td class="c-date hy-muted-text"><?php echo esc_html(HY_Admin::fa(HY_Jalali::format($r['date_ts']))); ?></td>
                        <td class="c-person"><?php echo esc_html($r['person_name']); ?></td>
                        <td class="c-amount hy-amount"><?php echo HY_Admin::money($r['total'], false); ?></td>
                        <td class="c-meta"><?php echo $r['m_inty'] ? 'نوع ' . HY_Admin::fa($r['m_inty']) : '<span class="hy-muted-text">خودکار</span>'; ?></td>
                        <td class="c-meta"><?php echo esc_html(HY_Invoices::$ins[$r['m_ins']] ?? ''); ?></td>
                        <td class="c-meta"><?php echo esc_html(HY_Invoices::$setm[$r['m_setm']] ?? ''); ?></td>
                        <td class="c-status"><?php echo HY_Admin::m_badge($r['m_status']); ?></td>
                        <td class="c-msg hy-msg-cell <?php echo $r['m_status'] === 'failed' ? 'is-failed' : ''; ?>"<?php echo mb_strlen($msg) > 140 ? ' title="' . esc_attr($msg) . '"' : ''; ?>><?php echo esc_html(mb_strimwidth($msg, 0, 140, '…')); ?></td>
                        <td class="c-taxid hy-mono hy-small hy-ltr"><?php echo esc_html($r['m_taxid']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php if ($pages > 1) : ?>
            <div class="hy-pager">
                <?php for ($i = 1; $i <= $pages; $i++) : ?>
                    <a class="<?php echo $i === $page_no ? 'is-current' : ''; ?>" href="<?php echo esc_url(HY_Admin::url('hy-moadian', array('tab' => $tab, 'paged' => $i, 's' => $search))); ?>"><?php echo HY_Admin::fa($i); ?></a>
                <?php endfor; ?>
                <span class="hy-muted-text"><?php echo HY_Admin::fa($total); ?> فاکتور</span>
            </div>
        <?php endif; ?>
    </div>
</form>

<div class="hy-modal" id="hy-confirm" hidden>
    <div class="hy-modal-box hy-modal-sm" role="alertdialog" aria-modal="true">
        <div class="hy-modal-head"><h2>تایید</h2></div>
        <p class="hy-confirm-text"></p>
        <div class="hy-modal-foot">
            <button type="button" class="hy-btn hy-btn-primary hy-confirm-yes">بله، انجام بده</button>
            <button type="button" class="hy-btn hy-btn-ghost hy-confirm-no">انصراف</button>
        </div>
    </div>
</div>
