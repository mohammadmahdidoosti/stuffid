/* شناسه‌یاب — product box and admin page behaviour */
(function ($) {
    'use strict';

    function originLabel(o) { return o === 'I' ? 'وارداتی' : 'تولید داخل'; }

    function post(action, data) {
        return $.post(HYS.ajax, $.extend({ action: action, nonce: HYS.nonce }, data));
    }

    // Debounced search under any .hys-q input
    var timer = null;
    $(document).on('input', '.hys-q', function () {
        var $in = $(this), $out = $in.siblings('.hys-results');
        clearTimeout(timer);
        var q = $.trim($in.val());
        if (q.length < 2) { $out.empty(); return; }
        timer = setTimeout(function () {
            post('hys_search', { q: q }).done(function (r) {
                $out.empty();
                if (!r.success || !r.data.length) { $out.text('چیزی پیدا نشد.'); return; }
                r.data.forEach(function (it) {
                    $('<button type="button" class="button button-small hys-pick"></button>')
                        .text(it.id + ' — ' + it.desc + ' (' + originLabel(it.origin) + '، ' + it.vat + '٪)')
                        .data(it).appendTo($out);
                });
            });
        }, 350);
    });

    // Product edit screen: fill Hesabyar's fields
    function fillProduct(id, vat) {
        $('#_hy_stuff_id').val(id).trigger('change');
        if (vat !== undefined && $('#_hy_vat_rate').val() === '') { $('#_hy_vat_rate').val(vat); }
    }
    $(document).on('click', '.hys-use', function () {
        fillProduct($(this).data('id'), $(this).data('vat'));
    });

    // Pick a search result
    $(document).on('click', '.hys-pick', function () {
        var it = $(this).data(), $row = $(this).closest('tr[data-product]');
        if (!$row.length) { fillProduct(it.id, it.vat); $(this).parent().empty(); return; }
        post('hys_set', { product: $row.data('product'), stuff_id: it.id }).done(function (r) {
            if (!r.success) { alert(r.data && r.data.message ? r.data.message : 'خطا'); return; }
            $row.find('.hys-cur').html('<b dir="ltr"></b><br><small></small>');
            $row.find('.hys-cur b').text(r.data.id);
            $row.find('.hys-cur small').text(r.data.desc + ' · دستی');
            $row.find('.hys-results').empty();
            $row.find('.hys-q').val('');
        });
    });

    // Admin page: batch filler
    $('#hys-run').on('click', function () {
        var $btn = $(this).prop('disabled', true), $p = $('#hys-progress'), $bar = $('.hys-bar span');
        var tot = { filled: 0, none: 0, skipped: 0 }, n = 0, replace = $('#hys-replace').is(':checked') ? 1 : 0;
        (function step(after) {
            post('hys_batch', { after: after, replace_auto: replace }).done(function (r) {
                if (!r.success) { $p.text('خطا در پردازش.'); $btn.prop('disabled', false); return; }
                var d = r.data; n += d.filled + d.none + d.skipped;
                tot.filled += d.filled; tot.none += d.none; tot.skipped += d.skipped;
                $p.text(n + ' محصول بررسی شد — پر شد: ' + tot.filled + '، بدون پیشنهاد: ' + tot.none + '، بدون تغییر: ' + tot.skipped);
                $bar.css('width', Math.min(99, 1 + n * 100 / Math.max(1, HYS.total || 30000)) + '%');
                if (d.done) { $bar.css('width', '100%'); $p.append(' — تمام شد.'); $btn.prop('disabled', false); return; }
                step(d.last);
            }).fail(function () {
                $p.append(' — قطع شد؛ دوباره «شروع» را بزنید (از همان‌جا ادامه نمی‌دهد ولی موارد پرشده دوباره پر نمی‌شوند).');
                $btn.prop('disabled', false);
            });
        })(0);
    });
})(jQuery);
