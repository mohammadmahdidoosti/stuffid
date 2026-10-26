/* حساب‌یار — admin scripts */
(function ($) {
    'use strict';

    /* ================= helpers ================= */
    var FA = '۰۱۲۳۴۵۶۷۸۹';
    function fa(s) { return String(s).replace(/\d/g, function (d) { return FA[d]; }); }
    function latin(s) {
        return String(s == null ? '' : s)
            .replace(/[۰-۹]/g, function (d) { return FA.indexOf(d); })
            .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); })
            .replace(/٫/g, '.');
    }
    function num(s) { var v = parseFloat(latin(s).replace(/[^\d.\-]/g, '')); return isNaN(v) ? 0 : v; }
    function money(n) { return fa(Math.round(n).toLocaleString('en-US')); }
    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
    function pad(n) { return ('000000' + n).slice(-6); }
    var ICON = {
        trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>',
        calendar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>'
    };

    /* Persian amount in words (mirrors HY_Admin::words). */
    function words(n) {
        n = Math.round(n);
        if (!n) { return 'صفر ریال'; }
        var ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
        var teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
        var tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
        var hundreds = ['', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
        var scales = ['', 'هزار', 'میلیون', 'میلیارد', 'هزار میلیارد'];
        function three(x) {
            var p = [];
            if (x >= 100) { p.push(hundreds[Math.floor(x / 100)]); x %= 100; }
            if (x >= 20) { p.push(tens[Math.floor(x / 10)]); x %= 10; if (x) { p.push(ones[x]); } }
            else if (x >= 10) { p.push(teens[x - 10]); }
            else if (x > 0) { p.push(ones[x]); }
            return p.join(' و ');
        }
        var neg = n < 0, groups = [], i = 0;
        n = Math.abs(n);
        while (n > 0 && i < scales.length) {
            var g = n % 1000;
            if (g) { groups.unshift(g === 1 && i > 0 ? scales[i] : (three(g) + ' ' + scales[i]).trim()); }
            n = Math.floor(n / 1000);
            i++;
        }
        return (neg ? 'منفی ' : '') + groups.join(' و ') + ' ریال';
    }

    /* ================= toasts ================= */
    function toast(msg) {
        var $box = $('.hy-toasts');
        if (!$box.length) { $box = $('<div class="hy-toasts" role="status" aria-live="polite"></div>').appendTo('body'); }
        var $t = $('<div class="hy-toast"><span class="hy-toast-icon">✓</span><span></span></div>');
        $t.find('span').last().text(msg);
        $box.append($t);
        setTimeout(function () { $t.addClass('is-hiding'); setTimeout(function () { $t.remove(); }, 350); }, 3200);
    }
    $(function () {
        $('.hy-toast-source').each(function () { toast($(this).data('toast')); });
    });

    /* ================= generic UI ================= */
    $(document).on('click', '.hy-dropdown-toggle', function (e) {
        e.preventDefault();
        var $d = $(this).closest('.hy-dropdown');
        $('.hy-dropdown').not($d).removeClass('is-open');
        $d.toggleClass('is-open');
    });
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.hy-dropdown').length) { $('.hy-dropdown').removeClass('is-open'); }
        if (!$(e.target).closest('.hy-ac').length) { $('.hy-ac').removeClass('is-open'); }
        if (!$(e.target).closest('.hy-date-wrap').length) { $('.hy-cal').remove(); }
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $('.hy-modal').attr('hidden', true);
            $('.hy-dropdown').removeClass('is-open');
            $('.hy-cal').remove();
        }
    });
    $(document).on('click', 'a[data-confirm]', function (e) {
        if (!window.confirm($(this).data('confirm'))) { e.preventDefault(); }
    });
    $(document).on('click', '.hy-row-link td', function (e) {
        if ($(e.target).closest('a,button,input,label').length) { return; }
        window.location = $(this).closest('tr').data('href');
    });
    $(document).on('click', '.hy-modal-close', function () { $(this).closest('.hy-modal').attr('hidden', true); });

    /* ================= Jalali date picker ================= */
    function g2j(gy, gm, gd) {
        var g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        var gy2 = gm > 2 ? gy + 1 : gy;
        var days = 355666 + 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
        var jy = -1595 + 33 * Math.floor(days / 12053);
        days %= 12053;
        jy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        var jm = days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
        var jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
        return [jy, jm, jd];
    }
    function j2g(jy, jm, jd) {
        jy += 1595;
        var days = -355668 + 365 * jy + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
        var gy = 400 * Math.floor(days / 146097);
        days %= 146097;
        if (days > 36524) { gy += 100 * Math.floor(--days / 36524); days %= 36524; if (days >= 365) { days++; } }
        gy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        var gd = days + 1;
        var sal = [0, 31, (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0 ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        var gm = 0;
        for (; gm < 13 && gd > sal[gm]; gm++) { gd -= sal[gm]; }
        return [gy, gm, gd];
    }
    function jMonthDays(jy, jm) {
        if (jm <= 6) { return 31; }
        if (jm <= 11) { return 30; }
        var g = j2g(jy, 12, 30), back = g2j(g[0], g[1], g[2]);
        return back[1] === 12 ? 30 : 29;
    }
    function tehranToday() {
        var p = {};
        new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Tehran', year: 'numeric', month: 'numeric', day: 'numeric' })
            .formatToParts(new Date()).forEach(function (x) { p[x.type] = parseInt(x.value, 10); });
        return g2j(p.year, p.month, p.day);
    }
    function parseJ(v) {
        var m = latin(v).match(/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
        return m ? [+m[1], +m[2], +m[3]] : null;
    }
    function cmp(a, b) { return (a[0] - b[0]) || (a[1] - b[1]) || (a[2] - b[2]); }
    var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    function initDatePicker($input) {
        if ($input.parent().hasClass('hy-date-wrap')) { return; }
        $input.wrap('<div class="hy-date-wrap"></div>').after(ICON.calendar);
        var $wrap = $input.parent();
        var view;
        function setValue(d) {
            $input.val(d[0] + '/' + ('0' + d[1]).slice(-2) + '/' + ('0' + d[2]).slice(-2)).trigger('change');
        }
        function render() {
            var today = tehranToday();
            var sel = parseJ($input.val());
            var maxToday = $input.data('max-today');
            var g = j2g(view[0], view[1], 1);
            // JS getDay: 0=Sunday … 6=Saturday. Persian week starts on Saturday.
            var startDow = (new Date(g[0], g[1] - 1, g[2]).getDay() + 1) % 7;
            var html = '<div class="hy-cal" role="dialog" aria-label="انتخاب تاریخ"><div class="hy-cal-head">' +
                '<button type="button" class="hy-cal-nav" data-nav="-1" aria-label="ماه قبل">›</button>' +
                '<b>' + MONTHS[view[1] - 1] + ' ' + fa(view[0]) + '</b>' +
                '<button type="button" class="hy-cal-nav" data-nav="1" aria-label="ماه بعد">‹</button></div><div class="hy-cal-grid">';
            ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'].forEach(function (d) { html += '<span class="hy-cal-dow">' + d + '</span>'; });
            for (var i = 0; i < startDow; i++) { html += '<span></span>'; }
            var n = jMonthDays(view[0], view[1]);
            for (var d = 1; d <= n; d++) {
                var day = [view[0], view[1], d];
                var cls = [];
                if (!cmp(day, today)) { cls.push('is-today'); }
                if (sel && !cmp(day, sel)) { cls.push('is-selected'); }
                if ((startDow + d - 1) % 7 === 6) { cls.push('is-friday'); }
                var dis = maxToday && cmp(day, today) > 0 ? ' disabled' : '';
                html += '<button type="button" class="' + cls.join(' ') + '" data-day="' + d + '"' + dis + '>' + fa(d) + '</button>';
            }
            html += '</div><div class="hy-cal-foot"><button type="button" class="hy-link" data-today="1">امروز</button><button type="button" class="hy-link" data-close="1">بستن</button></div></div>';
            $wrap.find('.hy-cal').remove();
            $wrap.append(html);
        }
        function open() {
            if ($wrap.find('.hy-cal').length) { return; }
            $('.hy-cal').remove();
            var cur = parseJ($input.val()) || tehranToday();
            view = [cur[0], cur[1]];
            render();
        }
        $input.on('focus click', open);
        $input.on('keydown', function (e) { if (e.key === 'Tab' || e.key === 'Enter') { $wrap.find('.hy-cal').remove(); } });
        $input.on('input', function () { var v = parseJ(this.value); if (v && v[1] >= 1 && v[1] <= 12) { view = [v[0], v[1]]; render(); } });
        $wrap.on('mousedown', '.hy-cal', function (e) { e.preventDefault(); });
        $wrap.on('click', '[data-nav]', function () {
            view[1] += +$(this).data('nav');
            if (view[1] > 12) { view = [view[0] + 1, 1]; }
            if (view[1] < 1) { view = [view[0] - 1, 12]; }
            render();
        });
        $wrap.on('click', '[data-day]', function () { setValue([view[0], view[1], +$(this).data('day')]); $wrap.find('.hy-cal').remove(); });
        $wrap.on('click', '[data-today]', function () { setValue(tehranToday()); $wrap.find('.hy-cal').remove(); });
        $wrap.on('click', '[data-close]', function () { $wrap.find('.hy-cal').remove(); });
    }
    $('.hy-date').each(function () { initDatePicker($(this)); });

    /* ================= autocomplete ================= */
    function Autocomplete($root, opts) {
        var self = this, timer = null, items = [], active = -1, seq = 0, loading = false;
        this.$root = $root;
        this.$input = $root.find('.hy-ac-input');
        this.$value = $root.find('.hy-ac-value');
        this.$list = $root.find('.hy-ac-list');
        this.source = $root.data('source');
        this.onSelect = opts.onSelect || function () {};
        $root.data('ac', this);

        function render() {
            if (!items.length) {
                self.$list.html('<div class="hy-ac-empty">چیزی پیدا نشد. با دکمه + آن را بسازید.</div>');
                return;
            }
            self.$list.html(items.map(function (it, i) {
                var meta, right = '';
                if (self.source === 'persons') {
                    meta = esc(it.type) + (it.national_id ? ' — ' + fa(esc(it.national_id)) : '') + (it.phone ? ' — ' + fa(esc(it.phone)) : '');
                } else {
                    meta = it.stuff_id ? 'شناسه مودیان: ' + fa(it.stuff_id) : '<span class="hy-mark-warn">بدون شناسه مودیان</span>';
                    right = '<span class="hy-ac-price">' + money(it.price) + ' <span class="hy-cur">ریال</span><span class="hy-ac-meta">' + esc(it.unit_title) + '</span></span>';
                }
                return '<div class="hy-ac-item' + (i === active ? ' is-active' : '') + '" data-i="' + i + '" role="option">' +
                    '<span class="hy-ac-avatar">' + esc((it.name || '?').charAt(0)) + '</span>' +
                    '<span><b>' + esc(it.name) + '</b><span class="hy-ac-meta">' + fa(pad(it.code)) + ' — ' + meta + '</span></span>' + right + '</div>';
            }).join(''));
            var $a = self.$list.find('.is-active');
            if ($a.length) { $a[0].scrollIntoView({ block: 'nearest' }); }
        }
        function fetch(q) {
            var mine = ++seq;
            loading = true;
            $.get(HY.ajax, { action: 'hy_search_' + self.source, nonce: HY.nonce, q: q }, function (r) {
                if (mine !== seq) { return; } // a newer search has started
                loading = false;
                items = r && r.success ? r.data : [];
                active = items.length ? 0 : -1;
                render();
                $root.addClass('is-open');
            });
        }
        this.select = function (it) {
            self.$value.val(it.id).trigger('change');
            self.$input.val(fa(pad(it.code)) + ' - ' + it.name);
            $root.removeClass('is-open');
            self.onSelect(it, self);
        };
        this.$input.on('focus', function () { fetch(self.$value.val() ? '' : this.value); this.select(); });
        this.$input.on('input', function () {
            self.$value.val('');
            clearTimeout(timer);
            var q = this.value;
            items = []; active = -1; loading = true; seq++;
            self.$list.html('<div class="hy-ac-empty">در حال جستجو…</div>');
            $root.addClass('is-open');
            timer = setTimeout(function () { fetch(q); }, 200);
        });
        this.$input.on('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (!$root.hasClass('is-open')) { fetch(''); return; } active = Math.min(items.length - 1, active + 1); render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); render(); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                e.stopPropagation();
                if ($root.hasClass('is-open') && !loading && items[active]) { self.select(items[active]); }
                else if (self.$value.val() && opts.onEnter) { opts.onEnter(); }
            }
            else if (e.key === 'Escape' || e.key === 'Tab') { $root.removeClass('is-open'); }
        });
        this.$list.on('mousedown', '.hy-ac-item', function (e) {
            e.preventDefault();
            self.select(items[$(this).data('i')]);
        });
    }

    /* ================= quick-create modals ================= */
    var modalTarget = null;
    $(document).on('click', '.hy-ac-add', function () {
        modalTarget = $(this).closest('.hy-ac').data('ac');
        var $m = $('#' + $(this).data('modal'));
        $m.find('form')[0].reset();
        $m.find('.hy-form-error').text('');
        var typed = modalTarget ? modalTarget.$input.val() : '';
        if (typed && !/^\S+ - /.test(typed)) { $m.find('[name=name]').val(typed); }
        $m.removeAttr('hidden');
        setTimeout(function () { $m.find('[name=name]').trigger('focus'); }, 30);
    });
    $(document).on('submit', '.hy-quick-form', function (e) {
        e.preventDefault();
        var $f = $(this), data = $f.serializeArray();
        data.push({ name: 'action', value: $f.data('action') }, { name: 'nonce', value: HY.nonce });
        $f.find('button').prop('disabled', true);
        $.post(HY.ajax, $.param(data), function (r) {
            $f.find('button').prop('disabled', false);
            if (!r || !r.success) {
                $f.find('.hy-form-error').text(r && r.data ? r.data.message : 'ذخیره نشد. اتصال را بررسی کنید.');
                return;
            }
            $f.closest('.hy-modal').attr('hidden', true);
            toast($f.data('action') === 'hy_quick_person' ? 'شخص ساخته شد' : 'کالا ساخته شد');
            if (modalTarget) { modalTarget.select(r.data); }
        });
    });

    /* ================= invoice form ================= */
    var $form = $('#hy-invoice-form');
    if ($form.length) {
        var $tbody = $('#hy-items tbody');
        var rowSeq = 0;
        var dirty = false;

        $('#hy-auto-number').on('change', function () {
            $('#hy-number').prop('disabled', this.checked);
            if (!this.checked) { $('#hy-number').trigger('focus'); }
        });
        $('#hy-setm').on('change', function () { $('.hy-cap-field').toggle(this.value === '3'); });
        $('#hy-toggle-notes').on('click', function () { $('#hy-notes-box').toggle().find('textarea').trigger('focus'); });

        function renderCustomer(p) {
            var $c = $('#hy-customer-card');
            if (!p) { $c.removeClass('is-visible').empty(); return; }
            var hasId = !!(p.national_id || p.economic_code) && +p.type_id !== 5;
            var inty = $('#hy-inty').val();
            var note = '';
            if (inty === '1' && !hasId) {
                note = '<span class="hy-note hy-hint is-error">برای نوع اول، کد ملی یا شناسه ملی این مشتری لازم است.</span>';
            } else if (inty === '0') {
                note = '<span class="hy-note hy-hint">' + (hasId ? 'به‌صورت «نوع اول» (با مشخصات خریدار) به مودیان می‌رود.' : 'شناسه ندارد؛ به‌صورت «نوع دوم» به مودیان می‌رود.') + '</span>';
            }
            $c.html(
                '<span>' + esc(p.type || '') + '</span>' +
                (p.national_id ? '<span>شناسه: <b>' + fa(esc(p.national_id)) + '</b></span>' : '') +
                (p.phone ? '<span>تلفن: <b>' + fa(esc(p.phone)) + '</b></span>' : '') +
                '<span>تراز: <b>' + money(p.balance || 0) + '</b> ریال</span>' + note
            ).addClass('is-visible');
            $c.data('p', p);
        }
        renderCustomer($('#hy-customer-card').data('person'));
        $('#hy-inty').on('change', function () { renderCustomer($('#hy-customer-card').data('p')); });

        new Autocomplete($('#hy-person-ac'), {
            onSelect: function (p) {
                if (!$('#hy-title').val()) { $('#hy-title').val(p.name); }
                renderCustomer(p);
                dirty = true;
                setTimeout(function () { $tbody.find('tr:first .hy-ac-input').trigger('focus'); }, 10);
            }
        });

        function rowHtml(id) {
            var n = 'items[' + id + ']';
            return '<tr data-row="' + id + '">' +
                '<td class="hy-n"></td>' +
                '<td class="c-product"><div class="hy-ac" data-source="products">' +
                '<input type="hidden" class="hy-ac-value" name="' + n + '[product_id]">' +
                '<input type="text" class="hy-input hy-ac-input" placeholder="جستجوی کالا…" aria-label="کالا">' +
                '<button type="button" class="hy-ac-add" data-modal="hy-modal-product" title="کالای جدید" tabindex="-1">+</button>' +
                '<div class="hy-ac-list" role="listbox"></div></div>' +
                '<span class="hy-row-flag" hidden></span>' +
                '<input type="hidden" name="' + n + '[unit_code]" class="f-unit-code">' +
                '<input type="hidden" name="' + n + '[stuff_id]" class="f-stuff">' +
                '</td>' +
                '<td class="c-desc" data-label="شرح"><input class="hy-input f-desc hy-nav" name="' + n + '[description]" aria-label="شرح"></td>' +
                '<td class="c-unit" data-label="واحد"><input class="hy-input f-unit hy-nav" name="' + n + '[unit_title]" aria-label="واحد"></td>' +
                '<td class="c-qty" data-label="تعداد"><input class="hy-input f-qty hy-nav" name="' + n + '[qty]" dir="ltr" inputmode="decimal" aria-label="تعداد"></td>' +
                '<td class="c-price" data-label="مبلغ واحد"><input class="hy-input f-price hy-money hy-nav" name="' + n + '[unit_price]" dir="ltr" inputmode="numeric" aria-label="مبلغ واحد"></td>' +
                '<td class="c-discount" data-label="تخفیف"><input class="hy-input f-discount hy-money hy-nav" name="' + n + '[discount]" dir="ltr" inputmode="numeric" aria-label="تخفیف"></td>' +
                '<td class="c-vat" data-label="مالیات"><div class="hy-vat-cell"><input class="hy-input hy-vat-rate f-vat hy-nav" name="' + n + '[vat_rate]" dir="ltr" inputmode="decimal" aria-label="درصد مالیات">٪</div><span class="hy-vat-sub"></span></td>' +
                '<td class="hy-line-total" data-label="مبلغ کل"></td>' +
                '<td class="hy-del-cell"><button type="button" class="hy-del" title="حذف ردیف" tabindex="-1">' + ICON.trash + '</button></td>' +
                '</tr>';
        }

        function flagRow($tr) {
            var pid = $tr.find('.hy-ac-value').val();
            var $f = $tr.find('.hy-row-flag');
            if (pid && !$tr.find('.f-stuff').val()) {
                $f.html('شناسه مودیان ندارد — <a href="admin.php?page=hy-products&edit=' + encodeURIComponent(pid) + '" target="_blank">ثبت شناسه</a>').removeAttr('hidden');
            } else {
                $f.attr('hidden', true).empty();
            }
        }

        function addRow(data) {
            var id = rowSeq++;
            var $tr = $(rowHtml(id)).appendTo($tbody);
            new Autocomplete($tr.find('.hy-ac'), {
                onSelect: function (p) {
                    $tr.find('.f-desc').val(p.name);
                    $tr.find('.f-unit').val(p.unit_title);
                    $tr.find('.f-unit-code').val(p.unit_code);
                    $tr.find('.f-stuff').val(p.stuff_id);
                    if (num($tr.find('.f-qty').val()) <= 0) { $tr.find('.f-qty').val('۱'); }
                    $tr.find('.f-price').val(money(p.price));
                    $tr.find('.f-vat').val(fa(p.vat_rate));
                    flagRow($tr);
                    recalc();
                    dirty = true;
                    if ($tr.is(':last-child')) { addRow(); }
                    $tr.find('.f-qty').trigger('focus').trigger('select');
                },
                onEnter: function () { $tr.find('.f-qty').trigger('focus').trigger('select'); }
            });
            if (data) {
                $tr.find('.hy-ac-value').val(data.product_id || '');
                $tr.find('.hy-ac-input').val(data.product_name || '');
                $tr.find('.f-desc').val(data.description || '');
                $tr.find('.f-unit').val(data.unit_title || '');
                $tr.find('.f-unit-code').val(data.unit_code || '');
                $tr.find('.f-stuff').val(data.stuff_id || '');
                $tr.find('.f-qty').val(data.qty ? fa(data.qty) : '');
                $tr.find('.f-price').val(data.unit_price ? money(data.unit_price) : '');
                $tr.find('.f-discount').val(data.discount ? money(data.discount) : '');
                $tr.find('.f-vat').val(fa(data.vat_rate || 0));
                flagRow($tr);
            }
            renumber();
            return $tr;
        }

        function renumber() {
            $tbody.find('tr').each(function (i) { $(this).find('.hy-n').text(fa(i + 1)); });
        }

        function recalc() {
            var t = { rows: 0, gross: 0, discount: 0, tax: 0, total: 0 };
            $tbody.find('tr').each(function () {
                var $r = $(this);
                var qty = num($r.find('.f-qty').val());
                var gross = Math.round(qty * num($r.find('.f-price').val()));
                var disc = Math.min(gross, Math.max(0, num($r.find('.f-discount').val())));
                var after = gross - disc;
                var tax = Math.round(after * num($r.find('.f-vat').val()) / 100);
                var used = !!($r.find('.hy-ac-value').val() || $r.find('.f-desc').val() || qty > 0);
                // Empty rows stay blank instead of filling up with zeros.
                $r.toggleClass('is-empty', !used);
                $r.find('.hy-vat-sub').text(used && tax ? money(tax) : '');
                $r.find('.hy-line-total').text(used ? money(after + tax) : '');
                if (qty > 0 && ($r.find('.hy-ac-value').val() || $r.find('.f-desc').val())) { t.rows += 1; }
                t.gross += gross; t.discount += disc; t.tax += tax; t.total += after + tax;
            });
            $('#hy-t-qty').text(fa(t.rows));
            $('#hy-t-gross').text(money(t.gross));
            $('#hy-t-discount').text(money(t.discount));
            $('#hy-t-tax').text(money(t.tax));
            $('#hy-t-total').text(money(t.total));
            $('#hy-t-words').text(t.total ? words(t.total) : '');
            var hasTax = $tbody.find('.f-vat').filter(function () { return num(this.value) > 0; }).length > 0;
            $('#hy-tax-toggle').text(hasTax ? 'حذف مالیات' : 'اعمال مالیات ' + fa(HY.defaultVat) + '٪');
        }

        $tbody.on('input', 'input', function () { dirty = true; recalc(); });
        $form.on('input change', '.hy-input', function () { dirty = true; });
        $form.on('blur', '.hy-money', function () { if (this.value !== '') { this.value = money(num(this.value)); } });

        /* Enter moves to the next cell; at the end of the last row a new row appears. */
        $tbody.on('keydown', '.hy-nav', function (e) {
            if (e.key !== 'Enter') { return; }
            e.preventDefault();
            var $tr = $(this).closest('tr');
            var $cells = $tr.find('.hy-nav');
            var i = $cells.index(this);
            if (i < $cells.length - 1) {
                $cells.eq(i + 1).trigger('focus').trigger('select');
                return;
            }
            var $next = $tr.next();
            if (!$next.length) { $next = addRow(); }
            $next.find('.hy-ac-input').trigger('focus');
        });

        $tbody.on('click', '.hy-del', function () {
            $(this).closest('tr').remove();
            if (!$tbody.find('tr').length) { addRow(); }
            renumber();
            recalc();
            dirty = true;
        });
        $('#hy-add-row').on('click', function () { addRow().find('.hy-ac-input').trigger('focus'); });

        $('#hy-tax-toggle').on('click', function () {
            var anyTax = $tbody.find('.f-vat').filter(function () { return num(this.value) > 0; }).length > 0;
            $tbody.find('tr').each(function () {
                var $r = $(this);
                if (!$r.find('.hy-ac-value').val() && !$r.find('.f-desc').val()) { return; }
                $r.find('.f-vat').val(fa(anyTax ? 0 : HY.defaultVat));
            });
            recalc();
            dirty = true;
        });
        $('#hy-discount-all').on('click', function () {
            var p = window.prompt('چند درصد تخفیف روی همه ردیف‌ها اعمال شود؟', '');
            if (p === null || p === '') { return; }
            var pct = Math.max(0, Math.min(100, num(p)));
            $tbody.find('tr').each(function () {
                var $r = $(this);
                var gross = Math.round(num($r.find('.f-qty').val()) * num($r.find('.f-price').val()));
                if (gross) { $r.find('.f-discount').val(money(Math.round(gross * pct / 100))); }
            });
            recalc();
            dirty = true;
        });

        $form.on('click', '[data-submit]', function () { $('#hy-submit-as').val($(this).data('submit')); });
        $(document).on('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'س')) {
                e.preventDefault();
                $('#hy-submit-as').val('confirmed');
                $form.trigger('submit');
            }
        });
        $form.on('submit', function (e) {
            if (!$form.find('[name=person_id]').val()) {
                e.preventDefault();
                window.alert('مشتری را انتخاب کنید.');
                $('#hy-person-ac .hy-ac-input').trigger('focus');
                return;
            }
            var filled = $tbody.find('tr').filter(function () {
                return ($(this).find('.hy-ac-value').val() || $(this).find('.f-desc').val()) && num($(this).find('.f-qty').val()) > 0;
            }).length;
            if (!filled) {
                e.preventDefault();
                window.alert('حداقل یک ردیف کالا با تعداد بیشتر از صفر وارد کنید.');
                $tbody.find('tr:first .hy-ac-input').trigger('focus');
                return;
            }
            $form.find('.hy-money, .f-qty, .f-vat').each(function () { this.value = String(num(this.value)); });
            $form.find('[data-submit]').prop('disabled', true);
            dirty = false;
        });
        $(window).on('beforeunload', function () { if (dirty) { return 'تغییرات ذخیره نشده‌اند.'; } });

        var initial = JSON.parse($('#hy-form-data').text() || '{}');
        (initial.items || []).forEach(function (it) { addRow(it); });
        addRow();
        while ($tbody.find('tr').length < 3) { addRow(); }
        recalc();
        dirty = false;
        if (!$form.find('[name=person_id]').val() && window.innerWidth > 782) { $('#hy-person-input').trigger('focus'); }
    }

    /* ================= moadian page ================= */
    var $mf = $('#hy-moadian-form');
    if ($mf.length) {
        var syncSelection = function () {
            var n = $('.hy-check-row:checked').length;
            $('.hy-check-row').each(function () { $(this).closest('tr').toggleClass('is-selected', this.checked); });
            $('#hy-selected-count').html(n ? '<b>' + fa(n) + '</b> فاکتور انتخاب شده' : 'برای انجام کار، فاکتورها را انتخاب کنید');
            $mf.find('[data-needs-selection]').toggleClass('is-disabled', !n).prop('disabled', !n);
        };
        $('#hy-check-all').on('change', function () { $('.hy-check-row').prop('checked', this.checked); syncSelection(); });
        $mf.on('change', '.hy-check-row', syncSelection);
        $mf.on('click', '.hy-moadian-table tbody td', function (e) {
            if ($(e.target).closest('a,input,label').length) { return; }
            var $c = $(this).closest('tr').find('.hy-check-row');
            $c.prop('checked', !$c.prop('checked'));
            syncSelection();
        });
        syncSelection();
        $('#hy-moadian-search').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                window.location = $(this).data('url') + '&s=' + encodeURIComponent(this.value);
            }
        });
        var pending = null;
        var submitBulk = function (action, value) {
            $('#hy-bulk-do').val(action);
            $('#hy-bulk-value').val(value == null ? '' : value);
            $mf.trigger('submit');
        };
        $(document).on('click', '[data-bulk]', function (e) {
            e.preventDefault();
            $('.hy-dropdown').removeClass('is-open');
            var $b = $(this);
            var action = $b.data('bulk');
            var count = $('.hy-check-row:checked').length;
            if (!$b.data('noselect') && !count) {
                window.alert('ابتدا فاکتورها را انتخاب کنید.');
                return;
            }
            var value = $b.data('value');
            if ($b.data('prompt')) {
                value = window.prompt($b.data('prompt'), '');
                if (value === null) { return; }
            }
            if ($b.data('confirm-count')) {
                pending = [action, value];
                $('#hy-confirm .hy-confirm-text').text(String($b.data('confirm-count')).replace('{n}', fa(count)));
                $('#hy-confirm').removeAttr('hidden').find('.hy-confirm-yes').trigger('focus');
                return;
            }
            submitBulk(action, value);
        });
        $('#hy-confirm .hy-confirm-yes').on('click', function () {
            $('#hy-confirm').attr('hidden', true);
            if (pending) { submitBulk(pending[0], pending[1]); }
        });
        $('#hy-confirm .hy-confirm-no').on('click', function () { $('#hy-confirm').attr('hidden', true); pending = null; });
    }

    /* ================= settings ================= */
    $('#hy-protocol').on('change', function () { $('.hy-v2-only').toggle(this.value === 'v2'); });
    $(document).on('click', '.hy-copy', function () {
        var el = document.getElementById($(this).data('target'));
        el.select();
        if (navigator.clipboard) { navigator.clipboard.writeText(el.value); } else { try { document.execCommand('copy'); } catch (err) { /* ignore */ } }
        toast('کلید عمومی کپی شد');
    });
    $('#hy-test-connection').on('click', function () {
        var $b = $(this).prop('disabled', true);
        var label = $b.text();
        $b.text('در حال تست…');
        var $out = $('#hy-test-result').html('');
        $.post(HY.ajax, { action: 'hy_test_connection', nonce: HY.nonce }, function (r) {
            if (r && r.success) {
                $out.html('<div class="hy-alert hy-alert-success"><strong>اتصال برقرار است.</strong><ul class="hy-test-list">' + r.data.lines.map(function (l) { return '<li>' + esc(l) + '</li>'; }).join('') + '</ul></div>');
            } else {
                $out.html('<div class="hy-alert hy-alert-error"><strong>اتصال برقرار نشد.</strong><br>' + esc(r && r.data ? r.data.message : 'پاسخی از سرور سایت نیامد.') + '<br><small>اگر تنظیمات را تغییر داده‌اید، اول «ذخیره تنظیمات» را بزنید.</small></div>');
            }
        }).fail(function () {
            $out.html('<div class="hy-alert hy-alert-error">درخواست به سرور سایت نرسید. اینترنت یا ورود به وردپرس را بررسی کنید.</div>');
        }).always(function () { $b.prop('disabled', false).text(label); });
    });
})(jQuery);
