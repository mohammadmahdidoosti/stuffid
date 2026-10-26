<?php
if (!defined('ABSPATH')) {
    exit;
}
$s = HY_Settings::all();
$tabs = array('moadian' => 'اتصال به مودیان', 'business' => 'اطلاعات کسب‌وکار', 'invoice' => 'پیش‌فرض‌های فاکتور', 'woocommerce' => 'ووکامرس');
$tab = sanitize_key($_GET['tab'] ?? 'moadian');
$tab = isset($tabs[$tab]) ? $tab : 'moadian';
$has_key = HY_Settings::has_secret('private_key');
$has_cert = HY_Settings::has_secret('certificate');
$public = HY_Settings::public_key_base64();
$csr = (string) get_option('hy_csr', '');
$setup = array(
    array($has_key, 'کلید ساخته شده'),
    array((bool) preg_match('/^[A-Z0-9]{6}$/', $s['fiscal_id']), 'شناسه حافظه وارد شده'),
    array((bool) preg_match('/^(\d{11}|\d{14})$/', $s['economic_code']), 'کد اقتصادی وارد شده'),
    array($s['environment'] === 'production', 'روی محیط اصلی'),
);
?>
<div class="hy-head"><h1>تنظیمات</h1></div>

<?php if ($tab === 'moadian') : ?>
    <ol class="hy-setup" aria-label="مراحل راه‌اندازی">
        <?php foreach ($setup as $i => $st) : ?>
            <li class="<?php echo $st[0] ? 'is-done' : ''; ?>"><span class="hy-dot"><?php echo $st[0] ? '✓' : HY_Admin::fa($i + 1); ?></span><?php echo esc_html($st[1]); ?></li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<div class="hy-card hy-p0">
    <div class="hy-tabs">
        <?php foreach ($tabs as $k => $label) : ?>
            <a class="<?php echo $k === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url(HY_Admin::url('hy-settings', array('tab' => $k))); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </div>

    <div class="hy-pad">
    <?php if ($tab === 'moadian') : ?>
        <div class="hy-settings-cols">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hy_save_settings'); ?>
                <input type="hidden" name="action" value="hy_save_settings">
                <input type="hidden" name="tab" value="moadian">
                <div class="hy-grid hy-grid-2">
                    <div class="hy-field"><label for="f-fiscal">شناسه یکتای حافظه مالیاتی</label><input id="f-fiscal" class="hy-input hy-mono hy-ltr" name="fiscal_id" maxlength="6" value="<?php echo esc_attr($s['fiscal_id']); ?>" placeholder="A1B2C3"><span class="hy-hint">۶ کاراکتر، از کارپوشه مودیان</span></div>
                    <div class="hy-field"><label for="f-ec">کد اقتصادی فروشنده</label><input id="f-ec" class="hy-input hy-ltr" name="economic_code" value="<?php echo esc_attr($s['economic_code']); ?>"><span class="hy-hint">۱۴ رقم برای اشخاص حقیقی، ۱۱ رقم برای حقوقی</span></div>
                    <div class="hy-field"><label for="hy-protocol">روش اتصال</label>
                        <select class="hy-input" name="protocol" id="hy-protocol">
                            <option value="selftsp" <?php selected($s['protocol'], 'selftsp'); ?>>بدون گواهی — فقط کلید عمومی در کارپوشه ثبت شده</option>
                            <option value="v2" <?php selected($s['protocol'], 'v2'); ?>>با گواهی امضای الکترونیکی</option>
                        </select>
                    </div>
                    <div class="hy-field"><label for="f-env">محیط</label>
                        <select id="f-env" class="hy-input" name="environment">
                            <option value="production" <?php selected($s['environment'], 'production'); ?>>اصلی — صورتحساب‌ها رسماً ثبت می‌شوند</option>
                            <option value="sandbox" <?php selected($s['environment'], 'sandbox'); ?>>آزمایشی — برای تست</option>
                        </select>
                    </div>
                    <div class="hy-field"><label for="f-serial">سریال بعدی صورتحساب</label><input id="f-serial" class="hy-input hy-ltr" name="next_serial" value="<?php echo esc_attr($s['next_serial']); ?>"><span class="hy-hint">بزرگ‌تر از آخرین سریالی که حسابفا یا نرم‌افزار قبلی با همین حافظه فرستاده.</span></div>
                    <div class="hy-field" style="justify-content:center"><label class="hy-check"><input type="checkbox" name="auto_inquiry" value="1" <?php checked($s['auto_inquiry'], 1); ?>> پاسخ سامانه خودکار پیگیری شود (هر ساعت و ۲ دقیقه بعد از هر ارسال)</label></div>
                </div>

                <div class="hy-field" style="margin-top:8px">
                    <label for="f-pk">کلید خصوصی <?php echo $has_key ? '<span class="hy-badge hy-ok">ذخیره شده و رمزنگاری‌شده</span>' : '<span class="hy-badge hy-warn">هنوز ساخته نشده</span>'; ?></label>
                    <textarea id="f-pk" class="hy-input hy-mono hy-ltr" name="private_key" rows="3" placeholder="<?php echo $has_key ? 'فقط برای جایگزینی، کلید دیگری را اینجا بچسبانید' : '-----BEGIN PRIVATE KEY-----'; ?>"></textarea>
                    <span class="hy-hint">کلید ذخیره‌شده دوباره نمایش داده نمی‌شود. اگر کلید ندارید، از ستون کناری «ساخت کلید» را بزنید.</span>
                </div>

                <div class="hy-field hy-v2-only" style="<?php echo $s['protocol'] === 'v2' ? '' : 'display:none'; ?>;margin-top:8px">
                    <label for="f-cert">گواهی امضا <?php echo $has_cert ? '<span class="hy-badge hy-ok">ذخیره شده</span>' : ''; ?></label>
                    <textarea id="f-cert" class="hy-input hy-mono hy-ltr" name="certificate" rows="3" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                    <?php if ($has_cert) : ?><label class="hy-check"><input type="checkbox" name="remove_certificate" value="1"> حذف گواهی فعلی</label><?php endif; ?>
                </div>

                <details class="hy-details">
                    <summary>آدرس سرورها (فقط اگر سازمان آدرس را عوض کرد)</summary>
                    <div class="hy-grid hy-grid-2">
                        <?php foreach (array('selftsp_url_production' => 'بدون گواهی — اصلی', 'selftsp_url_sandbox' => 'بدون گواهی — آزمایشی', 'v2_url_production' => 'با گواهی — اصلی', 'v2_url_sandbox' => 'با گواهی — آزمایشی') as $k => $label) : ?>
                            <div class="hy-field"><label><?php echo esc_html($label); ?></label><input class="hy-input hy-mono hy-ltr" name="<?php echo $k; ?>" value="<?php echo esc_attr($s[$k]); ?>"></div>
                        <?php endforeach; ?>
                    </div>
                </details>

                <div class="hy-row-gap">
                    <button class="hy-btn hy-btn-save"><?php echo HY_Admin::icon('check'); ?> ذخیره تنظیمات</button>
                    <button type="button" class="hy-btn hy-btn-ghost" id="hy-test-connection">تست اتصال</button>
                </div>
                <div id="hy-test-result" style="margin-top:12px"></div>
            </form>

            <aside class="hy-side">
                <h3>کلید و ثبت در کارپوشه</h3>
                <?php if ($public) : ?>
                    <label for="hy-public-key" class="hy-hint">کلید عمومی — این را در کارپوشه ثبت کنید</label>
                    <textarea class="hy-input hy-mono hy-ltr hy-small" rows="5" readonly id="hy-public-key"><?php echo esc_textarea($public); ?></textarea>
                    <div class="hy-row-gap" style="margin-top:8px">
                        <button type="button" class="hy-btn hy-btn-primary hy-btn-sm hy-copy" data-target="hy-public-key">کپی کلید عمومی</button>
                        <a class="hy-btn hy-btn-ghost hy-btn-sm" href="<?php echo esc_url(HY_Admin::post_url('hy_download', array('what' => 'public_txt'))); ?>"><?php echo HY_Admin::icon('download'); ?> فایل txt</a>
                        <?php if ($csr) : ?><a class="hy-btn hy-btn-ghost hy-btn-sm" href="<?php echo esc_url(HY_Admin::post_url('hy_download', array('what' => 'csr'))); ?>"><?php echo HY_Admin::icon('download'); ?> CSR</a><?php endif; ?>
                    </div>
                    <p class="hy-hint" style="margin:10px 0 0"><a class="hy-danger-text" data-confirm="فایل کلید خصوصی را فقط جای امن نگه دارید و برای کسی نفرستید. دانلود شود؟" href="<?php echo esc_url(HY_Admin::post_url('hy_download', array('what' => 'private'))); ?>">دانلود نسخه پشتیبان کلید خصوصی</a></p>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hy-keygen">
                    <?php wp_nonce_field('hy_generate_keys'); ?>
                    <input type="hidden" name="action" value="hy_generate_keys">
                    <h4><?php echo $has_key ? 'ساخت کلید تازه' : 'ساخت کلید'; ?></h4>
                    <div class="hy-field"><label>نام (برای CSR)</label><input class="hy-input" name="csr_name" value="<?php echo esc_attr($s['seller_name']); ?>"></div>
                    <div class="hy-field" style="margin-top:8px"><label>کد ملی / شناسه ملی</label><input class="hy-input hy-ltr" name="csr_national_id" value="<?php echo esc_attr($s['seller_national_id']); ?>"></div>
                    <?php if ($has_key) : ?>
                        <label class="hy-check hy-danger-text" style="margin-top:8px"><input type="checkbox" name="confirm_replace" value="1"> کلید فعلی کنار گذاشته شود. کلید عمومی جدید باید دوباره در کارپوشه ثبت شود.</label>
                    <?php endif; ?>
                    <p style="margin:10px 0 0"><button class="hy-btn hy-btn-primary"><?php echo HY_Admin::icon('key'); ?> ساخت کلید و CSR</button></p>
                </form>
                <ol class="hy-steps">
                    <li>«ساخت کلید و CSR» را بزنید.</li>
                    <li>در کارپوشه، بخش «شناسه یکتای حافظه مالیاتی»، روش «توسط مودی» را انتخاب و کلید عمومی را ثبت کنید.</li>
                    <li>شناسه ۶ کاراکتری را در همین صفحه وارد، ذخیره و «تست اتصال» را بزنید.</li>
                </ol>
            </aside>
        </div>

    <?php elseif ($tab === 'business') : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:860px">
            <?php wp_nonce_field('hy_save_settings'); ?>
            <input type="hidden" name="action" value="hy_save_settings">
            <input type="hidden" name="tab" value="business">
            <div class="hy-grid hy-grid-2">
                <div class="hy-field"><label>نام کسب‌وکار</label><input class="hy-input" name="seller_name" value="<?php echo esc_attr($s['seller_name']); ?>"></div>
                <div class="hy-field"><label>کد ملی / شناسه ملی</label><input class="hy-input hy-ltr" name="seller_national_id" value="<?php echo esc_attr($s['seller_national_id']); ?>"></div>
                <div class="hy-field"><label>کدپستی</label><input class="hy-input hy-ltr" name="seller_postal_code" value="<?php echo esc_attr($s['seller_postal_code']); ?>"></div>
                <div class="hy-field"><label>تلفن</label><input class="hy-input hy-ltr" name="seller_phone" value="<?php echo esc_attr($s['seller_phone']); ?>"></div>
            </div>
            <div class="hy-field"><label>نشانی</label><textarea class="hy-input" name="seller_address" rows="2"><?php echo esc_textarea($s['seller_address']); ?></textarea></div>
            <p class="hy-hint">این اطلاعات بالای فاکتور چاپی، در بخش «مشخصات فروشنده»، می‌آید.</p>
            <button class="hy-btn hy-btn-save"><?php echo HY_Admin::icon('check'); ?> ذخیره</button>
        </form>

    <?php elseif ($tab === 'invoice') : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:860px">
            <?php wp_nonce_field('hy_save_settings'); ?>
            <input type="hidden" name="action" value="hy_save_settings">
            <input type="hidden" name="tab" value="invoice">
            <div class="hy-grid hy-grid-2">
                <div class="hy-field"><label>نرخ مالیات بر ارزش افزوده (%)</label><input class="hy-input hy-ltr" name="default_vat" value="<?php echo esc_attr($s['default_vat']); ?>"><span class="hy-hint">برای کالای جدید و دکمه «اعمال مالیات»</span></div>
                <div class="hy-field"><label>روش تسویه</label>
                    <select class="hy-input" name="default_setm"><?php foreach (HY_Invoices::$setm as $k => $v) : ?><option value="<?php echo $k; ?>" <?php selected((int) $s['default_setm'], $k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select>
                </div>
                <div class="hy-field"><label>عنوان واحد</label><input class="hy-input" name="default_unit_title" value="<?php echo esc_attr($s['default_unit_title']); ?>"></div>
                <div class="hy-field"><label>کد واحد مودیان</label><input class="hy-input hy-ltr" name="default_unit_code" value="<?php echo esc_attr($s['default_unit_code']); ?>"><span class="hy-hint">۱۶۲۷ = عدد</span></div>
                <div class="hy-field"><label>شناسه کالای پیش‌فرض (اختیاری)</label><input class="hy-input hy-ltr" name="default_stuff_id" maxlength="13" value="<?php echo esc_attr($s['default_stuff_id']); ?>"><span class="hy-hint">برای کالایی که شناسه اختصاصی ندارد. بهتر است هر کالا شناسه درست خودش را داشته باشد.</span></div>
                <div class="hy-field"><label>نوع صورتحساب</label>
                    <select class="hy-input" name="default_inty">
                        <option value="0" <?php selected((int) $s['default_inty'], 0); ?>>خودکار — نوع اول اگر مشتری شناسه دارد</option>
                        <option value="1" <?php selected((int) $s['default_inty'], 1); ?>>همیشه نوع اول</option>
                        <option value="2" <?php selected((int) $s['default_inty'], 2); ?>>همیشه نوع دوم</option>
                    </select>
                </div>
            </div>
            <button class="hy-btn hy-btn-save"><?php echo HY_Admin::icon('check'); ?> ذخیره</button>
        </form>

    <?php else : ?>
        <?php if (!HY_WooCommerce::active()) : ?>
            <div class="hy-alert hy-alert-warning">ووکامرس روی این سایت فعال نیست.</div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:860px">
            <?php wp_nonce_field('hy_save_settings'); ?>
            <input type="hidden" name="action" value="hy_save_settings">
            <input type="hidden" name="tab" value="woocommerce">
            <h3 class="hy-section-title">ساخت خودکار فاکتور</h3>
            <div class="hy-grid hy-grid-2">
                <div class="hy-field"><label class="hy-check"><input type="checkbox" name="wc_auto_create" value="1" <?php checked($s['wc_auto_create'], 1); ?>> برای هر سفارش فاکتور ساخته شود، وقتی وضعیتش می‌شود:</label>
                    <select class="hy-input" name="wc_auto_status">
                        <?php foreach (array('completed' => 'تکمیل شده', 'processing' => 'در حال انجام') as $k => $v) : ?>
                            <option value="<?php echo $k; ?>" <?php selected($s['wc_auto_status'], $k); ?>><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hy-field" style="justify-content:flex-end"><label class="hy-check"><input type="checkbox" name="wc_auto_send" value="1" <?php checked($s['wc_auto_send'], 1); ?>> فاکتور ساخته‌شده خودکار به مودیان هم ارسال شود</label></div>
            </div>
            <h3 class="hy-section-title" style="margin-top:18px">تبدیل اطلاعات سفارش</h3>
            <div class="hy-grid hy-grid-2">
                <div class="hy-field"><label>ضریب تبدیل قیمت به ریال</label><input class="hy-input hy-ltr" name="wc_amount_multiplier" value="<?php echo esc_attr($s['wc_amount_multiplier']); ?>"><span class="hy-hint">۰ یعنی خودکار از واحد پول فروشگاه<?php echo HY_WooCommerce::active() ? ' (الان: ' . esc_html(get_woocommerce_currency()) . ' × ' . esc_html(HY_Admin::fa(HY_WooCommerce::multiplier())) . ')' : ''; ?></span></div>
                <div class="hy-field"><label>شناسه خدمت مودیان برای هزینه ارسال</label><input class="hy-input hy-ltr" name="wc_shipping_stuff_id" maxlength="13" value="<?php echo esc_attr($s['wc_shipping_stuff_id']); ?>"></div>
                <div class="hy-field"><label>فیلد کد ملی خریدار (متای سفارش)</label><input class="hy-input hy-ltr hy-mono" name="wc_national_id_meta" value="<?php echo esc_attr($s['wc_national_id_meta']); ?>"></div>
                <div class="hy-field"><label>فیلد کد اقتصادی خریدار (متای سفارش)</label><input class="hy-input hy-ltr hy-mono" name="wc_economic_code_meta" value="<?php echo esc_attr($s['wc_economic_code_meta']); ?>"></div>
            </div>
            <div class="hy-alert hy-alert-info" style="margin-top:12px">
                <strong>مالیات سفارش‌ها چطور حساب می‌شود؟</strong>
                اگر ووکامرس روی سفارش مالیات ثبت کرده باشد، همان استفاده می‌شود. اگر نه، مبلغ پرداختی «شامل مالیات» با نرخ هر کالا در نظر گرفته می‌شود تا جمع فاکتور با پول دریافتی یکی باشد.
            </div>
            <button class="hy-btn hy-btn-save"><?php echo HY_Admin::icon('check'); ?> ذخیره</button>
        </form>
    <?php endif; ?>
    </div>
</div>
