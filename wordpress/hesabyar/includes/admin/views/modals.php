<?php
if (!defined('ABSPATH')) {
    exit;
}
$s = HY_Settings::all();
?>
<div class="hy-modal" id="hy-modal-person" hidden>
    <div class="hy-modal-box">
        <div class="hy-modal-head"><h2>شخص جدید</h2><button type="button" class="hy-modal-close">×</button></div>
        <form class="hy-quick-form" data-action="hy_quick_person">
            <div class="hy-grid hy-grid-2">
                <div class="hy-field hy-req"><label>نام / نام شرکت</label><input class="hy-input" name="name" required></div>
                <div class="hy-field"><label>نوع</label>
                    <select class="hy-input" name="person_type">
                        <?php foreach (HY_Persons::$types as $k => $v) : ?>
                            <option value="<?php echo $k; ?>"><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hy-field"><label>کد ملی / شناسه ملی</label><input class="hy-input" name="national_id" dir="ltr"></div>
                <div class="hy-field"><label>کد اقتصادی</label><input class="hy-input" name="economic_code" dir="ltr"></div>
                <div class="hy-field"><label>کدپستی</label><input class="hy-input" name="postal_code" dir="ltr"></div>
                <div class="hy-field"><label>موبایل</label><input class="hy-input" name="mobile" dir="ltr"></div>
            </div>
            <div class="hy-field"><label>آدرس</label><textarea class="hy-input" name="address" rows="2"></textarea></div>
            <div class="hy-modal-foot"><button class="hy-btn hy-btn-green">ذخیره و انتخاب</button><span class="hy-form-error"></span></div>
        </form>
    </div>
</div>

<div class="hy-modal" id="hy-modal-product" hidden>
    <div class="hy-modal-box">
        <div class="hy-modal-head"><h2>کالا / خدمت جدید</h2><button type="button" class="hy-modal-close">×</button></div>
        <form class="hy-quick-form" data-action="hy_quick_product">
            <div class="hy-grid hy-grid-2">
                <div class="hy-field hy-req"><label>نام کالا</label><input class="hy-input" name="name" required></div>
                <div class="hy-field"><label>قیمت فروش (ریال)</label><input class="hy-input hy-money" name="price" dir="ltr"></div>
                <div class="hy-field"><label>شناسه کالا مودیان (۱۳ رقم)</label><input class="hy-input" name="stuff_id" maxlength="13" dir="ltr" value="<?php echo esc_attr($s['default_stuff_id']); ?>"></div>
                <div class="hy-field"><label>نرخ مالیات (%)</label><input class="hy-input" name="vat_rate" dir="ltr" value="<?php echo esc_attr($s['default_vat']); ?>"></div>
                <div class="hy-field"><label>عنوان واحد</label><input class="hy-input" name="unit_title" value="<?php echo esc_attr($s['default_unit_title']); ?>"></div>
                <div class="hy-field"><label>کد واحد مودیان</label><input class="hy-input" name="unit_code" dir="ltr" value="<?php echo esc_attr($s['default_unit_code']); ?>"></div>
            </div>
            <div class="hy-modal-foot"><button class="hy-btn hy-btn-green">ذخیره و انتخاب</button><span class="hy-form-error"></span></div>
        </form>
    </div>
</div>
