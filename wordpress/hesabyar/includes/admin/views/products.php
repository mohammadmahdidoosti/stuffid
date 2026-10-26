<?php
if (!defined('ABSPATH')) {
    exit;
}
$s = HY_Settings::all();
$edit = $_GET['edit'] ?? '';
if ($edit !== '') :
    $p = $edit === 'new' ? null : HY_Products::get((int) $edit);
    $v = function ($k, $d = '') use ($p) {
        return $p ? $p[$k] : $d;
    };
    ?>
    <div class="hy-head"><h1><a class="hy-back" href="<?php echo esc_url(HY_Admin::url('hy-products')); ?>" title="بازگشت"><?php echo HY_Admin::icon('back'); ?></a><span><?php echo $p ? esc_html($p['name']) : 'کالا یا خدمت جدید'; ?><?php if ($p) : ?><span class="hy-sub">کد <?php echo HY_Admin::fa(str_pad($p['code'], 6, '0', STR_PAD_LEFT)); ?></span><?php endif; ?></span></h1></div>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hy-card" style="max-width:980px">
        <?php wp_nonce_field('hy_save_product'); ?>
        <input type="hidden" name="action" value="hy_save_product">
        <input type="hidden" name="id" value="<?php echo $p ? (int) $p['id'] : 0; ?>">
        <h2 class="hy-section-title">مشخصات</h2>
        <div class="hy-grid hy-grid-3">
            <div class="hy-field hy-req"><label>نام کالا یا خدمت</label><input class="hy-input" name="name" value="<?php echo esc_attr($v('name')); ?>" required></div>
            <div class="hy-field"><label>کد کالا (SKU)</label><input class="hy-input hy-ltr" name="sku" value="<?php echo esc_attr($v('sku')); ?>"></div>
            <div class="hy-field"><label>قیمت فروش (ریال)</label><input class="hy-input hy-money hy-ltr" name="price" value="<?php echo esc_attr(HY_Admin::fa(number_format((int) $v('price', 0)))); ?>" inputmode="numeric"></div>
            <div class="hy-field"><label>عنوان واحد</label><input class="hy-input" name="unit_title" value="<?php echo esc_attr($v('unit_title', $s['default_unit_title'])); ?>"></div>
            <div class="hy-field"><label>نرخ مالیات بر ارزش افزوده (%)</label><input class="hy-input hy-ltr" name="vat_rate" value="<?php echo esc_attr((float) $v('vat_rate', $s['default_vat'])); ?>"></div>
            <div class="hy-field" style="justify-content:flex-end"><label class="hy-check"><input type="checkbox" name="is_service" value="1" <?php checked((int) $v('is_service', 0), 1); ?>> این یک خدمت است</label></div>
        </div>
        <h2 class="hy-section-title" style="margin-top:18px">اطلاعات مودیان</h2>
        <div class="hy-grid hy-grid-3">
            <div class="hy-field"><label>شناسه ۱۳ رقمی کالا/خدمت</label><input class="hy-input hy-ltr hy-mono" name="stuff_id" maxlength="13" value="<?php echo esc_attr($v('stuff_id', $s['default_stuff_id'])); ?>" inputmode="numeric"><span class="hy-hint">از <a href="https://stuffid.tax.gov.ir" target="_blank" rel="noopener">stuffid.tax.gov.ir</a> یا کارپوشه. بدون آن، فاکتور ارسال نمی‌شود.</span></div>
            <div class="hy-field"><label>کد واحد</label><input class="hy-input hy-ltr" name="unit_code" value="<?php echo esc_attr($v('unit_code', $s['default_unit_code'])); ?>"><span class="hy-hint">۱۶۲۷ = عدد. فهرست کامل در کارپوشه.</span></div>
        </div>
        <?php if ($p && $p['wc_product_id']) : ?>
            <div class="hy-alert hy-alert-info" style="margin:14px 0 0">این کالا به محصول ووکامرس #<?php echo HY_Admin::fa($p['wc_product_id']); ?> وصل است. شناسه، کد واحد و نرخ مالیات روی محصول ووکامرس هم ذخیره می‌شود.</div>
        <?php endif; ?>
        <p style="margin:16px 0 0"><button class="hy-btn hy-btn-save"><?php echo HY_Admin::icon('check'); ?> ذخیره کالا</button></p>
    </form>
<?php
else :
    $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
    $page_no = max(1, (int) ($_GET['paged'] ?? 1));
    $rows = HY_Products::search(HY_Jalali::latin_digits($search), 30, ($page_no - 1) * 30);
    $total = HY_Products::count(HY_Jalali::latin_digits($search));
    $pages = max(1, (int) ceil($total / 30));
    global $wpdb;
    $missing = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . HY_DB::table('products') . " WHERE stuff_id=''");
    ?>
    <div class="hy-head">
        <h1>کالاها و خدمات<span class="hy-sub"><?php echo HY_Admin::fa($total); ?> مورد</span></h1>
        <div class="hy-head-actions">
            <?php if (HY_WooCommerce::active()) : ?>
                <a class="hy-btn hy-btn-ghost" href="<?php echo esc_url(HY_Admin::post_url('hy_sync_products')); ?>"><?php echo HY_Admin::icon('refresh'); ?> همگام‌سازی از ووکامرس</a>
            <?php endif; ?>
            <a class="hy-btn hy-btn-save" href="<?php echo esc_url(HY_Admin::url('hy-products', array('edit' => 'new'))); ?>"><?php echo HY_Admin::icon('plus'); ?> کالای جدید</a>
        </div>
    </div>
    <?php if ($missing) : ?>
        <div class="hy-alert hy-alert-warning"><strong><?php echo HY_Admin::fa($missing); ?> کالا شناسه مودیان ندارد.</strong> فاکتورهایی که این کالاها را دارند تا ثبت شناسه ارسال نمی‌شوند.</div>
    <?php endif; ?>
    <div class="hy-card hy-p0">
        <form method="get" class="hy-toolbar">
            <input type="hidden" name="page" value="hy-products">
            <div class="hy-search hy-grow"><?php echo HY_Admin::icon('search'); ?><input type="search" class="hy-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="نام، کد یا شناسه کالا"></div>
            <button class="hy-btn hy-btn-ghost">جستجو</button>
        </form>
        <?php if (!$rows) : ?>
            <div class="hy-empty-state">
                <strong><?php echo $search ? 'کالایی با این مشخصات پیدا نشد' : 'هنوز کالایی ثبت نشده'; ?></strong>
                <?php echo HY_WooCommerce::active() ? 'با «همگام‌سازی از ووکامرس» محصولات فروشگاه را یکجا بیاورید.' : 'کالاها را اینجا یا هنگام صدور فاکتور اضافه کنید.'; ?>
            </div>
        <?php else : ?>
        <table class="hy-table hy-stack">
            <thead><tr><th>کد</th><th>نام</th><th>واحد</th><th>قیمت (ریال)</th><th>مالیات</th><th>شناسه مودیان</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r) : ?>
                <tr>
                    <td class="hy-muted-text"><?php echo HY_Admin::fa(str_pad($r['code'], 6, '0', STR_PAD_LEFT)); ?></td>
                    <td><a href="<?php echo esc_url(HY_Admin::url('hy-products', array('edit' => $r['id']))); ?>"><?php echo esc_html($r['name']); ?></a><?php echo $r['wc_product_id'] ? ' <span class="hy-badge hy-muted hy-plain">ووکامرس</span>' : ''; ?></td>
                    <td class="hy-hide-sm"><?php echo esc_html($r['unit_title']); ?></td>
                    <td class="hy-amount"><?php echo HY_Admin::money($r['price'], false); ?></td>
                    <td class="hy-hide-sm"><?php echo HY_Admin::fa((float) $r['vat_rate']); ?>٪</td>
                    <td class="hy-mono hy-ltr"><?php echo $r['stuff_id'] ? esc_html($r['stuff_id']) : '<span class="hy-badge hy-warn">ثبت نشده</span>'; ?></td>
                    <td class="hy-row-actions">
                        <a href="<?php echo esc_url(HY_Admin::url('hy-products', array('edit' => $r['id']))); ?>">ویرایش</a>
                        <a class="hy-danger-text" data-confirm="این کالا حذف شود؟" href="<?php echo esc_url(HY_Admin::post_url('hy_delete_product', array('id' => $r['id']))); ?>">حذف</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if ($pages > 1) : ?>
            <div class="hy-pager">
                <?php for ($i = 1; $i <= $pages; $i++) : ?>
                    <a class="<?php echo $i === $page_no ? 'is-current' : ''; ?>" href="<?php echo esc_url(HY_Admin::url('hy-products', array('paged' => $i, 's' => $search))); ?>"><?php echo HY_Admin::fa($i); ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
