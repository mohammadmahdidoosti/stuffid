<?php
/** @var string $kind sale|return */
if (!defined('ABSPATH')) {
    exit;
}
$is_return = $kind === 'return';
$search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
$filter = sanitize_key($_GET['f'] ?? '');
$page_no = max(1, (int) ($_GET['paged'] ?? 1));
$per_page = (int) ($_GET['per_page'] ?? 20);
$per_page = in_array($per_page, array(10, 20, 50, 100), true) ? $per_page : 20;
$args = array('kind' => $kind, 'search' => HY_Jalali::latin_digits($search), 'page' => $page_no, 'per_page' => $per_page);
if ($filter === 'draft') {
    $args['status'] = 'draft';
} elseif (in_array($filter, array('unsent', 'failed', 'success', 'pending'), true)) {
    $args['status'] = 'confirmed';
    $args['m_status'] = $filter;
}
list($rows, $total) = HY_Invoices::list_query($args);
$pages = max(1, (int) ceil($total / $per_page));
$page_slug = $is_return ? 'hy-returns' : 'hy-invoices';
$filters = array('' => 'همه', 'draft' => 'پیش‌نویس', 'unsent' => 'ارسال نشده', 'failed' => 'خطای مودیان', 'success' => 'تایید مودیان');
?>
<div class="hy-head">
    <h1><?php echo $is_return ? 'برگشت از فروش' : 'فاکتورهای فروش'; ?><span class="hy-sub"><?php echo HY_Admin::fa($total); ?> فاکتور<?php echo $is_return ? ' — برگشت را از صفحه فاکتور فروش ثبت کنید' : ''; ?></span></h1>
    <div class="hy-head-actions">
        <?php if (!$is_return) : ?>
            <a class="hy-btn hy-btn-save" href="<?php echo esc_url(HY_Admin::url('hy-invoice-new')); ?>"><?php echo HY_Admin::icon('plus'); ?> فروش جدید</a>
        <?php endif; ?>
    </div>
</div>

<div class="hy-card hy-p0">
    <div class="hy-tabs">
        <?php foreach ($filters as $k => $label) : ?>
            <a class="<?php echo $k === $filter ? 'is-active' : ''; ?>" href="<?php echo esc_url(HY_Admin::url($page_slug, array_filter(array('f' => $k, 's' => $search)))); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="hy-toolbar">
        <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
        <?php if ($filter) : ?><input type="hidden" name="f" value="<?php echo esc_attr($filter); ?>"><?php endif; ?>
        <div class="hy-search hy-grow">
            <?php echo HY_Admin::icon('search'); ?>
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="شماره، نام مشتری، عنوان یا شماره مالیاتی" class="hy-input">
        </div>
        <select name="per_page" class="hy-input" style="width:auto" onchange="this.form.submit()" aria-label="تعداد ردیف">
            <?php foreach (array(10, 20, 50, 100) as $n) : ?>
                <option value="<?php echo $n; ?>" <?php selected($per_page, $n); ?>><?php echo HY_Admin::fa($n); ?> ردیف</option>
            <?php endforeach; ?>
        </select>
        <button class="hy-btn hy-btn-ghost">جستجو</button>
    </form>

    <?php if (!$rows) : ?>
        <div class="hy-empty-state">
            <?php if ($search || $filter) : ?>
                <strong>فاکتوری با این مشخصات نیست</strong>
                جستجو یا فیلتر را تغییر دهید.
            <?php elseif ($is_return) : ?>
                <strong>هنوز برگشتی ثبت نشده</strong>
                برای ثبت برگشت، فاکتور فروش را باز کنید و از منوی «⋯» گزینه «ثبت برگشت از فروش» را بزنید.
            <?php else : ?>
                <strong>هنوز فاکتوری نساخته‌اید</strong>
                اولین فاکتور فروش را بسازید.
                <div><a class="hy-btn hy-btn-save" href="<?php echo esc_url(HY_Admin::url('hy-invoice-new')); ?>"><?php echo HY_Admin::icon('plus'); ?> فروش جدید</a></div>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <table class="hy-table hy-stack">
            <thead>
            <tr><th>شماره</th><th>تاریخ</th><th>مشتری</th><th>مبلغ کل</th><th>پرداخت</th><th>مودیان</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r) : ?>
                <tr class="hy-row-link" data-href="<?php echo esc_url(HY_Admin::url('hy-invoices', array('view' => $r['id']))); ?>">
                    <td><a href="<?php echo esc_url(HY_Admin::url('hy-invoices', array('view' => $r['id']))); ?>"><strong><?php echo HY_Admin::fa($r['number']); ?></strong></a></td>
                    <td class="hy-muted-text"><?php echo esc_html(HY_Admin::fa(HY_Jalali::format($r['date_ts']))); ?></td>
                    <td><?php echo esc_html($r['person_name']); ?><?php if ($r['title'] && $r['title'] !== $r['person_name']) : ?><div class="hy-hint"><?php echo esc_html($r['title']); ?></div><?php endif; ?></td>
                    <td class="hy-amount"><?php echo HY_Admin::money($r['total']); ?></td>
                    <td class="hy-hide-sm"><?php echo $r['paid'] ? '<span class="hy-badge hy-ok">پرداخت شده</span>' : '<span class="hy-badge hy-muted">پرداخت نشده</span>'; ?></td>
                    <td><?php echo $r['status'] === 'draft' ? '<span class="hy-badge hy-draft">پیش‌نویس</span>' : HY_Admin::m_badge($r['m_status']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($pages > 1) : ?>
        <div class="hy-pager">
            <?php for ($i = 1; $i <= $pages; $i++) : ?>
                <a class="<?php echo $i === $page_no ? 'is-current' : ''; ?>" href="<?php echo esc_url(HY_Admin::url($page_slug, array_filter(array('paged' => $i, 's' => $search, 'f' => $filter, 'per_page' => $per_page)))); ?>"><?php echo HY_Admin::fa($i); ?></a>
            <?php endfor; ?>
            <span class="hy-muted-text">صفحه <?php echo HY_Admin::fa($page_no); ?> از <?php echo HY_Admin::fa($pages); ?></span>
        </div>
    <?php endif; ?>
</div>
