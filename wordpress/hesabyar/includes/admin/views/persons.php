<?php
if (!defined('ABSPATH')) {
    exit;
}
$edit = $_GET['edit'] ?? '';
if ($edit !== '') :
    $p = $edit === 'new' ? null : HY_Persons::get((int) $edit);
    $v = function ($k, $d = '') use ($p) {
        return $p ? $p[$k] : $d;
    };
    ?>
    <div class="hy-head"><h1><a class="hy-back" href="<?php echo esc_url(HY_Admin::url('hy-persons')); ?>" title="بازگشت"><?php echo HY_Admin::icon('back'); ?></a><span><?php echo $p ? esc_html($p['name']) : 'شخص جدید'; ?><?php if ($p) : ?><span class="hy-sub">کد <?php echo HY_Admin::fa(str_pad($p['code'], 6, '0', STR_PAD_LEFT)); ?> — تراز <?php echo HY_Admin::money(HY_Persons::balance($p['id']), false); ?> ریال</span><?php endif; ?></span></h1></div>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hy-card" style="max-width:980px">
        <?php wp_nonce_field('hy_save_person'); ?>
        <input type="hidden" name="action" value="hy_save_person">
        <input type="hidden" name="id" value="<?php echo $p ? (int) $p['id'] : 0; ?>">
        <div class="hy-grid hy-grid-3">
            <div class="hy-field hy-req"><label>نام یا نام شرکت</label><input class="hy-input" name="name" value="<?php echo esc_attr($v('name')); ?>" required></div>
            <div class="hy-field"><label>نوع شخص</label>
                <select class="hy-input" name="person_type">
                    <?php foreach (HY_Persons::$types as $k => $t) : ?>
                        <option value="<?php echo $k; ?>" <?php selected((int) $v('person_type', 1), $k); ?>><?php echo esc_html($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="hy-field"><label>کد ملی یا شناسه ملی</label><input class="hy-input hy-ltr" name="national_id" value="<?php echo esc_attr($v('national_id')); ?>" inputmode="numeric"><span class="hy-hint">۱۰ رقم برای حقیقی، ۱۱ رقم برای حقوقی</span></div>
            <div class="hy-field"><label>کد اقتصادی</label><input class="hy-input hy-ltr" name="economic_code" value="<?php echo esc_attr($v('economic_code')); ?>" inputmode="numeric"></div>
            <div class="hy-field"><label>کدپستی</label><input class="hy-input hy-ltr" name="postal_code" value="<?php echo esc_attr($v('postal_code')); ?>" inputmode="numeric"></div>
            <div class="hy-field"><label>موبایل</label><input class="hy-input hy-ltr" name="mobile" value="<?php echo esc_attr($v('mobile')); ?>" inputmode="tel"></div>
            <div class="hy-field"><label>تلفن</label><input class="hy-input hy-ltr" name="phone" value="<?php echo esc_attr($v('phone')); ?>" inputmode="tel"></div>
            <div class="hy-field"><label>ایمیل</label><input class="hy-input hy-ltr" name="email" value="<?php echo esc_attr($v('email')); ?>" inputmode="email"></div>
        </div>
        <div class="hy-field"><label>آدرس</label><textarea class="hy-input" name="address" rows="2"><?php echo esc_textarea($v('address')); ?></textarea></div>
        <div class="hy-field" style="margin-top:10px"><label>یادداشت</label><textarea class="hy-input" name="notes" rows="2"><?php echo esc_textarea($v('notes')); ?></textarea></div>
        <div class="hy-alert hy-alert-info" style="margin:14px 0">با کد ملی، شناسه ملی یا کد اقتصادی، فاکتورهای این شخص «نوع اول» (با مشخصات خریدار) به مودیان می‌روند. بدون آن‌ها «نوع دوم» می‌شوند.</div>
        <button class="hy-btn hy-btn-save"><?php echo HY_Admin::icon('check'); ?> ذخیره شخص</button>
    </form>
<?php
else :
    $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
    $page_no = max(1, (int) ($_GET['paged'] ?? 1));
    $rows = HY_Persons::search(HY_Jalali::latin_digits($search), 30, ($page_no - 1) * 30);
    $total = HY_Persons::count(HY_Jalali::latin_digits($search));
    $pages = max(1, (int) ceil($total / 30));
    ?>
    <div class="hy-head">
        <h1>اشخاص<span class="hy-sub"><?php echo HY_Admin::fa($total); ?> شخص</span></h1>
        <div class="hy-head-actions"><a class="hy-btn hy-btn-save" href="<?php echo esc_url(HY_Admin::url('hy-persons', array('edit' => 'new'))); ?>"><?php echo HY_Admin::icon('plus'); ?> شخص جدید</a></div>
    </div>
    <div class="hy-card hy-p0">
        <form method="get" class="hy-toolbar">
            <input type="hidden" name="page" value="hy-persons">
            <div class="hy-search hy-grow"><?php echo HY_Admin::icon('search'); ?><input type="search" class="hy-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="نام، کد ملی یا موبایل"></div>
            <button class="hy-btn hy-btn-ghost">جستجو</button>
        </form>
        <?php if (!$rows) : ?>
            <div class="hy-empty-state">
                <strong><?php echo $search ? 'کسی با این مشخصات پیدا نشد' : 'هنوز شخصی ثبت نشده'; ?></strong>
                مشتری‌ها را اینجا یا هنگام صدور فاکتور با دکمه + اضافه کنید.
            </div>
        <?php else : ?>
        <table class="hy-table hy-stack">
            <thead><tr><th>کد</th><th>نام</th><th>نوع</th><th>کد/شناسه ملی</th><th>موبایل</th><th>تراز (ریال)</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r) : ?>
                <tr>
                    <td class="hy-muted-text"><?php echo HY_Admin::fa(str_pad($r['code'], 6, '0', STR_PAD_LEFT)); ?></td>
                    <td><a href="<?php echo esc_url(HY_Admin::url('hy-persons', array('edit' => $r['id']))); ?>"><?php echo esc_html($r['name']); ?></a></td>
                    <td class="hy-hide-sm"><?php echo esc_html(HY_Persons::$types[$r['person_type']] ?? ''); ?></td>
                    <td><?php echo $r['national_id'] ? esc_html(HY_Admin::fa($r['national_id'])) : '<span class="hy-muted-text">—</span>'; ?></td>
                    <td class="hy-hide-sm"><?php echo esc_html(HY_Admin::fa($r['mobile'] ?: $r['phone'])); ?></td>
                    <td class="hy-amount"><?php echo HY_Admin::money(HY_Persons::balance($r['id']), false); ?></td>
                    <td class="hy-row-actions">
                        <a href="<?php echo esc_url(HY_Admin::url('hy-persons', array('edit' => $r['id']))); ?>">ویرایش</a>
                        <a class="hy-danger-text" data-confirm="این شخص حذف شود؟" href="<?php echo esc_url(HY_Admin::post_url('hy_delete_person', array('id' => $r['id']))); ?>">حذف</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if ($pages > 1) : ?>
            <div class="hy-pager">
                <?php for ($i = 1; $i <= $pages; $i++) : ?>
                    <a class="<?php echo $i === $page_no ? 'is-current' : ''; ?>" href="<?php echo esc_url(HY_Admin::url('hy-persons', array('paged' => $i, 's' => $search))); ?>"><?php echo HY_Admin::fa($i); ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
