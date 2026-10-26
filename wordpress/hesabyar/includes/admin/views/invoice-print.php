<?php
/**
 * Printable "صورتحساب فروش کالا و خدمات" (A4 landscape).
 *
 * @var array $inv
 */
if (!defined('ABSPATH')) {
    exit;
}
$s = HY_Settings::all();
$person = HY_Persons::get($inv['person_id']);
$fa = array('HY_Admin', 'fa');
$m = function ($n) {
    return HY_Admin::fa(number_format((float) $n));
};
$is_return = $inv['kind'] === 'return';
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title><?php echo $is_return ? 'برگشت از فروش' : 'فاکتور'; ?> <?php echo esc_html($inv['number']); ?></title>
<style>
    @font-face { font-family: 'Vazirmatn'; src: url('<?php echo esc_url(HY_URL . 'assets/fonts/Vazirmatn.woff2'); ?>') format('woff2'); font-weight: 100 900; font-display: swap; }
    @page { size: A4 landscape; margin: 10mm; }
    * { box-sizing: border-box; }
    body { font-family: Vazirmatn, Tahoma, sans-serif; font-size: 12px; color: #111; margin: 0; background: #fff; }
    .sheet { max-width: 277mm; margin: 0 auto; padding: 8px; }
    .top { display: grid; grid-template-columns: 1fr 2fr 1fr; align-items: center; margin-bottom: 8px; }
    .top h1 { text-align: center; font-size: 17px; margin: 0; }
    .top .meta { font-size: 12px; line-height: 1.9; }
    .top .meta.left { text-align: left; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #333; padding: 5px 6px; vertical-align: middle; }
    th { background: #eceff3; font-weight: 700; }
    .party th { width: 140px; text-align: center; writing-mode: horizontal-tb; }
    .party td { line-height: 1.9; }
    .lbl { color: #555; margin-left: 4px; }
    .num { text-align: left; direction: ltr; white-space: nowrap; }
    .items td { text-align: center; }
    .items td.desc { text-align: right; }
    .sum td { font-weight: 700; }
    .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 18px; }
    .sign div { border-top: 1px dashed #666; padding-top: 6px; text-align: center; color: #444; height: 60px; }
    .taxid { font-family: monospace; direction: ltr; }
    .noprint { text-align: center; margin: 12px; }
    @media print { .noprint { display: none; } }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()" style="font-family:inherit;padding:8px 18px">چاپ</button></div>
<div class="sheet">
    <div class="top">
        <div class="meta">
            <?php if ($inv['m_taxid']) : ?><div><span class="lbl">شماره مالیاتی:</span> <span class="taxid"><?php echo esc_html($inv['m_taxid']); ?></span></div><?php endif; ?>
            <?php if ($inv['m_irtaxid']) : ?><div><span class="lbl">صورتحساب مرجع:</span> <span class="taxid"><?php echo esc_html($inv['m_irtaxid']); ?></span></div><?php endif; ?>
        </div>
        <h1><?php echo $is_return ? 'صورتحساب برگشت از فروش' : 'صورتحساب فروش کالا و خدمات'; ?></h1>
        <div class="meta left">
            <div><span class="lbl">شماره:</span> <?php echo $fa($inv['number']); ?></div>
            <div><span class="lbl">تاریخ:</span> <?php echo esc_html($fa(HY_Jalali::format($inv['date_ts']))); ?></div>
        </div>
    </div>

    <table class="party">
        <tr>
            <th>مشخصات فروشنده</th>
            <td>
                <span class="lbl">نام:</span> <?php echo esc_html($s['seller_name']); ?>
                &nbsp;&nbsp; <span class="lbl">کد اقتصادی:</span> <?php echo esc_html($fa($s['economic_code'])); ?>
                <?php if ($s['seller_national_id']) : ?>&nbsp;&nbsp; <span class="lbl">شناسه/کد ملی:</span> <?php echo esc_html($fa($s['seller_national_id'])); ?><?php endif; ?>
                <br><span class="lbl">نشانی:</span> <?php echo esc_html($s['seller_address']); ?>
                &nbsp;&nbsp; <span class="lbl">کدپستی:</span> <?php echo esc_html($fa($s['seller_postal_code'])); ?>
                &nbsp;&nbsp; <span class="lbl">تلفن:</span> <?php echo esc_html($fa($s['seller_phone'])); ?>
            </td>
        </tr>
        <tr>
            <th>مشخصات خریدار</th>
            <td>
                <span class="lbl">نام:</span> <?php echo esc_html($person['name'] ?? ''); ?>
                <?php if (!empty($person['economic_code'])) : ?>&nbsp;&nbsp; <span class="lbl">کد اقتصادی:</span> <?php echo esc_html($fa($person['economic_code'])); ?><?php endif; ?>
                <?php if (!empty($person['national_id'])) : ?>&nbsp;&nbsp; <span class="lbl"><?php echo (int) $person['person_type'] === 2 ? 'شناسه ملی:' : 'کد ملی:'; ?></span> <?php echo esc_html($fa($person['national_id'])); ?><?php endif; ?>
                <br><span class="lbl">نشانی:</span> <?php echo esc_html($person['address'] ?? ''); ?>
                &nbsp;&nbsp; <span class="lbl">کدپستی:</span> <?php echo esc_html($fa($person['postal_code'] ?? '')); ?>
                &nbsp;&nbsp; <span class="lbl">تلفن:</span> <?php echo esc_html($fa($person['mobile'] ?: ($person['phone'] ?? ''))); ?>
            </td>
        </tr>
    </table>

    <table class="items" style="margin-top:8px">
        <thead>
        <tr>
            <th>ردیف</th><th>شناسه کالا</th><th>شرح کالا یا خدمت</th><th>تعداد</th><th>واحد</th>
            <th>مبلغ واحد (ریال)</th><th>مبلغ کل (ریال)</th><th>تخفیف</th><th>مبلغ پس از تخفیف</th><th>مالیات و عوارض</th><th>جمع کل (ریال)</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($inv['items'] as $i => $it) :
            $gross = (int) round($it['qty'] * $it['unit_price']); ?>
            <tr>
                <td><?php echo $fa($i + 1); ?></td>
                <td class="taxid"><?php echo esc_html($it['stuff_id']); ?></td>
                <td class="desc"><?php echo esc_html($it['description']); ?></td>
                <td><?php echo $fa((float) $it['qty']); ?></td>
                <td><?php echo esc_html($it['unit_title']); ?></td>
                <td class="num"><?php echo $m($it['unit_price']); ?></td>
                <td class="num"><?php echo $m($gross); ?></td>
                <td class="num"><?php echo $m($it['discount']); ?></td>
                <td class="num"><?php echo $m($gross - $it['discount']); ?></td>
                <td class="num"><?php echo $m($it['tax']); ?></td>
                <td class="num"><?php echo $m($it['total']); ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="sum">
            <td colspan="6">جمع کل</td>
            <td class="num"><?php echo $m($inv['subtotal']); ?></td>
            <td class="num"><?php echo $m($inv['discount']); ?></td>
            <td class="num"><?php echo $m($inv['subtotal'] - $inv['discount']); ?></td>
            <td class="num"><?php echo $m($inv['tax']); ?></td>
            <td class="num"><?php echo $m($inv['total']); ?></td>
        </tr>
        <tr>
            <td colspan="11" style="text-align:right">
                <span class="lbl">شرایط و نحوه فروش:</span> <?php echo esc_html(HY_Invoices::$setm[$inv['m_setm']] ?? ''); ?>
                <?php if ($inv['notes']) : ?>&nbsp;&nbsp; <span class="lbl">توضیحات:</span> <?php echo esc_html($inv['notes']); ?><?php endif; ?>
            </td>
        </tr>
        </tbody>
    </table>
    <div class="sign"><div>مهر و امضای فروشنده</div><div>مهر و امضای خریدار</div></div>
</div>
</body>
</html>
