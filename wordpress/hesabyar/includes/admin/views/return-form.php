<?php
/** @var array $sale */
if (!defined('ABSPATH')) {
    exit;
}
if (!$sale) {
    echo '<div class="hy-alert hy-alert-error">فاکتور پیدا نشد.</div>';
    return;
}
$person = HY_Persons::get($sale['person_id']);
?>
<div class="hy-head">
    <h1><a class="hy-back" href="<?php echo esc_url(HY_Admin::url('hy-invoices', array('view' => $sale['id']))); ?>">&rarr;</a> برگشت از فروش — فاکتور <?php echo HY_Admin::fa($sale['number']); ?></h1>
</div>
<?php if ($sale['m_status'] !== 'success') : ?>
    <div class="hy-alert hy-alert-warning">این فاکتور هنوز در سامانه مودیان «تایید شده» نیست. برگشت از فروش در مودیان فقط برای صورتحساب تاییدشده پذیرفته می‌شود؛ می‌توانید الان فاکتور برگشت را بسازید و بعد از تایید فاکتور اصلی ارسالش کنید.</div>
<?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('hy_create_return'); ?>
    <input type="hidden" name="action" value="hy_create_return">
    <input type="hidden" name="sale_id" value="<?php echo (int) $sale['id']; ?>">
    <div class="hy-card">
        <p>مشتری: <strong><?php echo esc_html($person['name'] ?? ''); ?></strong> — تعداد برگشتی هر ردیف را وارد کنید (صفر = برگشت ندارد).</p>
        <table class="hy-table">
            <thead><tr><th>#</th><th>شرح</th><th>تعداد فروش</th><th>مبلغ واحد</th><th style="width:140px">تعداد برگشتی</th></tr></thead>
            <tbody>
            <?php foreach ($sale['items'] as $i => $it) : ?>
                <tr>
                    <td><?php echo HY_Admin::fa($i + 1); ?></td>
                    <td><?php echo esc_html($it['description']); ?></td>
                    <td><?php echo HY_Admin::fa((float) $it['qty']); ?></td>
                    <td class="hy-num"><?php echo HY_Admin::money($it['unit_price']); ?></td>
                    <td><input type="text" class="hy-input" dir="ltr" name="qty[<?php echo (int) $it['id']; ?>]" value="<?php echo esc_attr((float) $it['qty']); ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><button class="hy-btn hy-btn-green">ساخت فاکتور برگشت از فروش</button></p>
    </div>
</form>
