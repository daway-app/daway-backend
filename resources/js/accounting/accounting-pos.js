/**
 * Daway Accounting — شاشة البيع (POS)
 * ==========================================================
 * الحفظ حقيقي: `AccountingApi.createSale()` يضرب
 * POST /api/pharmacy/accounting/sales، والـBackend يخصم المخزون ويكتب
 * حركة الصندوق ويزيد دين العميل داخل معاملة واحدة (AccountingLedger).
 *
 * ── عقد الطلب (مطابق لـAccountingSalesController@store حرفيًا) ────────
 *   { items:[{pharmacy_medicine_id, medicine_id, medicine_name, barcode,
 *             unit_price, quantity, line_discount}],
 *     payment_method, paid?, discount?, customer_id?, notes? }
 *
 * ⚠️ **`payment_method` لا `payment`، و`items` لا `lines`.** أي انحراف في
 * المفاتيح يُرفض بـ422 برسالة مضلّلة («طريقة الدفع مطلوبة») لأن الـBackend
 * يرى الحقل مفقودًا. هذا العطل وقع فعلًا — لذلك المفاتيح مكتوبة هنا صراحة.
 *
 * آلة الحالة: cart[] (مصدر الحقيقة الوحيد) → render → totals.
 * كل العمليات المالية تُقرّب بـ round2() لتفادي أخطاء الفاصلة العائمة.
 */
(function () {
    'use strict';

    var U = window.AccountingUtil;
    var Api = window.AccountingApi;

    /**
     * شبكة أمان للنصوص: `window.acPosI18n` يُحقن من
     * `partials/accounting-i18n`. لو لم يُحمَّل الـpartial (أو تغيّر ترتيب
     * السكربتات) فإن **كل** `window.acPosI18n.x` يرمي TypeError داخل مسار
     * البيع — فيفشل الحفظ أو الرسم بلا رسالة مفهومة.
     *
     * ⚠️ نحرس مرة واحدة هنا (فنّ التوكيد على نمط `|| {}`) بدل تعديل 25 موضعًا:
     * القيم المفقودة تصير `''` لا `undefined`، و`.replace()` يبقى صالحًا.
     */
    window.acPosI18n = window.acPosI18n || new Proxy({}, {
        get: function (target, key) {
            return key in target ? target[key] : '';
        }
    });

    /* ------------------------------------------------------
       الحالة
       ------------------------------------------------------ */
    var state = {
        lines: [],          // {key, id, barcode, name, ingredient, qty, price, discount, stock, fromCatalog}
        customer: null,     // الاسم المعروض
        customerId: null,   // id عميل حقيقي — إلزامي للبيع الآجل (وإلا ضاع الدين)
        payment: 'cash',
        paid: 0,
        saving: false
    };

    var dom = {};

    /* ------------------------------------------------------
       حسابات السلة — دوال نقية (تُختبر منفصلة)
       ------------------------------------------------------ */
    function lineGross(line) {
        return U.round2(Number(line.qty) * Number(line.price));
    }

    /** صافي السطر = (الكمية × السعر) − الخصم، ولا يقل عن صفر */
    function lineNet(line) {
        var gross = lineGross(line);
        var discount = Number(line.discount) || 0;
        return U.round2(Math.max(0, gross - discount));
    }

    function computeTotals(lines) {
        var subtotal = 0;
        var discount = 0;
        lines.forEach(function (l) {
            subtotal = U.round2(subtotal + lineGross(l));
            discount = U.round2(discount + (Number(l.discount) || 0));
        });
        var total = U.round2(Math.max(0, subtotal - discount));
        return { subtotal: subtotal, discount: discount, total: total };
    }

    /** يتحقق من صفوف السلة ويُرجع قائمة أخطاء (فارغة = صالح) */
    function validateLines(lines) {
        var errors = [];
        if (!lines.length) {
            errors.push({ field: 'lines', message: window.acPosI18n.lines_required });
            return errors;
        }
        lines.forEach(function (l, i) {
            if (!l.qty || Number(l.qty) <= 0) {
                errors.push({ field: 'qty', index: i, message: window.acPosI18n.invalid_qty });
            }
            if (Number(l.price) < 0 || isNaN(Number(l.price))) {
                errors.push({ field: 'price', index: i, message: window.acPosI18n.invalid_amount });
            }
            if (Number(l.discount) < 0 || isNaN(Number(l.discount))) {
                errors.push({ field: 'discount', index: i, message: window.acPosI18n.invalid_amount });
            }
        });
        return errors;
    }

    /* ------------------------------------------------------
       عمليات السلة
       ------------------------------------------------------ */
    function findIndexByKey(key) {
        for (var i = 0; i < state.lines.length; i++) {
            if (state.lines[i].key === key) {
                return i;
            }
        }
        return -1;
    }

    /**
     * إضافة صنف. لو موجود بنفس المعرّف/الباركود ⇒ زيادة الكمية (لا صف مكرر).
     * يُرجع {added:true} أو {added:false, reason:'out_of_stock'}
     *
     * ── محورا المعرّف: لا تخلط بينهما ────────────────────────────────────
     *   `medicine_id`          → `medicines.id`        (الكتالوج العام)
     *   `pharmacy_medicine_id` → `pharmacy_medicines.id` (سطر مخزون هذه الصيدلية)
     *
     * الـBackend يخصم بـ`pharmacy_medicine_id` تحديدًا. لو أرسلنا
     * `medicines.id` في مكانه، يخصم الـLedger سطر مخزون **لا علاقة له**
     * بالدواء المبيع — أو يفشل بلا سبب ظاهر. لذلك نحفظ الاثنين منفصلين.
     */
    function addItem(item) {
        var stock = item.quantity;

        if (stock !== null && stock !== undefined && Number(stock) <= 0) {
            return { added: false, reason: 'out_of_stock', item: item };
        }

        // معرّف سطر المخزون هو ما يُخصم فعلًا؛ وإلا فالكتالوج العام.
        var pmId = item.pharmacy_medicine_id != null ? item.pharmacy_medicine_id : null;
        var medId = item.medicine_id != null ? item.medicine_id : (pmId === null ? item.id : null);

        // مفتاح الدمج: سطر المخزون أولًا، ثم الدواء، ثم الباركود.
        var matchKey = String(pmId != null ? 'pm:' + pmId
            : (medId != null ? 'm:' + medId : 'b:' + (item.barcode || '')));

        var existing = state.lines.find(function (l) {
            return l.matchKey === matchKey;
        });

        if (existing) {
            existing.qty = Number(existing.qty) + 1;
            return { added: true, merged: true, line: existing };
        }

        state.lines.push({
            key: 'l' + Date.now() + '_' + Math.random().toString(36).slice(2, 7),
            matchKey: matchKey,
            medicine_id: medId != null ? medId : null,
            pharmacy_medicine_id: pmId,
            id: medId != null ? medId : null,
            barcode: item.barcode || '',
            name: item.trade_name || '',
            ingredient: item.active_ingredient || '',
            qty: 1,
            price: Number(item.price) || 0,
            discount: 0,
            stock: stock === undefined ? null : stock,
            inInventory: pmId !== null
        });

        return { added: true, line: state.lines[state.lines.length - 1] };
    }

    function removeLine(key) {
        var i = findIndexByKey(key);
        if (i !== -1) {
            state.lines.splice(i, 1);
        }
        render();
    }

    function updateLine(key, field, value) {
        var i = findIndexByKey(key);
        if (i === -1) {
            return;
        }
        if (field === 'qty') {
            var q = parseInt(value, 10);
            state.lines[i].qty = isNaN(q) ? 0 : Math.max(0, q);
        } else if (field === 'price' || field === 'discount') {
            var n = parseFloat(value);
            state.lines[i][field] = isNaN(n) ? 0 : Math.max(0, n);
        }
        render();
    }

    function clearCart() {
        if (!state.lines.length) {
            return;
        }
        if (!window.confirm(window.acPosI18n.clear_cart_confirm)) {
            return;
        }
        state.lines = [];
        render();
    }

    /* ------------------------------------------------------
       العرض
       ------------------------------------------------------ */
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function render() {
        renderCart();
        renderTotals();
        toggleEmptyState();
    }

    function toggleEmptyState() {
        if (dom.empty) {
            dom.empty.classList.toggle('ac-hidden', state.lines.length > 0);
        }
        if (dom.tableWrap) {
            dom.tableWrap.classList.toggle('ac-hidden', state.lines.length === 0);
        }
        if (dom.toolbar) {
            dom.toolbar.classList.toggle('ac-hidden', state.lines.length === 0);
        }
    }

    function stockCell(line) {
        if (line.stock === null || line.stock === undefined) {
            return '<span class="ac-muted">' + esc(window.acPosI18n.not_in_inventory) + '</span>';
        }
        if (Number(line.stock) <= 0) {
            return '<span class="ph-badge out">' + esc(window.acPosI18n.stock_none) + '</span>';
        }
        var over = Number(line.qty) > Number(line.stock);
        var label = window.acPosI18n.stock_available.replace(':qty', line.stock);
        return '<span class="' + (over ? 'ac-neg ac-strong' : 'ac-muted') + '">' + esc(label) + '</span>';
    }

    /**
     * خليّة عمود الباركود.
     * ⚠️ «بلا باركود» **ليست خطأ** — تُعرض بلغة هادئة رمادية، لا حمراء.
     * الصنف المضاف بالاسم فقط لا يزال قابلًا للبيع تمامًا.
     */
    function barcodeCell(line) {
        if (!line.barcode) {
            return '<span class="ac-bcode-none">' +
                esc(barcodeI18n().inventory_no_barcode || '') + '</span>';
        }

        var status = line.barcodeStatus || 'pending';
        var label = window.AccountingBarcode.statusLabel(status);

        return '<span class="ac-bcode-cell">'
            + '<span class="ac-bcode-code">' + esc(line.barcode) + '</span>'
            + '<span class="ac-bcode-badge is-' + esc(status) + ' is-sm" role="status">'
            + '<span class="ac-bcode-dot" aria-hidden="true"></span>'
            + '<span class="ac-bcode-label">' + esc(label) + '</span>'
            + '</span>'
            + '</span>';
    }

    function renderCart() {
        if (!dom.tbody) {
            return;
        }

        var html = state.lines.map(function (line, i) {
            var over = line.stock !== null && line.stock !== undefined && Number(line.qty) > Number(line.stock);

            return ''
                + '<tr data-key="' + esc(line.key) + '"' + (over ? ' data-over="1"' : '') + '>'
                + '<td><div class="ac-cell-stack">'
                + '<strong>' + esc(line.name) + '</strong>'
                + (line.ingredient ? '<small class="ac-muted">' + esc(line.ingredient) + '</small>' : '')
                + '</div></td>'
                + '<td>' + barcodeCell(line) + '</td>'
                + '<td>' + stockCell(line) + '</td>'
                + '<td style="width:90px;">'
                + '<label class="ac-hidden" for="ac-qty-' + i + '">' + esc(window.acPosI18n.qty_label) + '</label>'
                + '<input id="ac-qty-' + i + '" class="ac-line-input' + (over ? ' is-error' : '') + '"'
                + ' type="number" min="1" step="1" inputmode="numeric"'
                + ' value="' + esc(line.qty) + '" data-ac-field="qty"'
                + (over ? ' aria-invalid="true"' : '') + '>'
                + '</td>'
                + '<td style="width:110px;">'
                + '<label class="ac-hidden" for="ac-price-' + i + '">' + esc(window.acPosI18n.price_label) + '</label>'
                + '<input id="ac-price-' + i + '" class="ac-line-input" type="number" min="0" step="0.01"'
                + ' inputmode="decimal" value="' + esc(line.price) + '" data-ac-field="price">'
                + '</td>'
                + '<td style="width:110px;">'
                + '<label class="ac-hidden" for="ac-disc-' + i + '">' + esc(window.acPosI18n.discount_label) + '</label>'
                + '<input id="ac-disc-' + i + '" class="ac-line-input" type="number" min="0" step="0.01"'
                + ' inputmode="decimal" value="' + esc(line.discount) + '" data-ac-field="discount">'
                + '</td>'
                + '<td class="ac-line-total">' + esc(U.fmtNumber(lineNet(line))) + '</td>'
                + '<td>'
                + '<button type="button" class="ac-icon-btn" data-ac-remove="' + esc(line.key) + '"'
                + ' aria-label="' + esc(window.acPosI18n.remove_label + ' ' + line.name) + '">'
                + '<i class="fas fa-trash-can" aria-hidden="true"></i></button>'
                + '</td>'
                + '</tr>';
        }).join('');

        dom.tbody.innerHTML = html;
        if (dom.cartCount) {
            dom.cartCount.textContent = String(state.lines.length);
        }
    }

    function renderTotals() {
        var t = computeTotals(state.lines);

        if (dom.subtotal) {
            dom.subtotal.textContent = U.fmtNumber(t.subtotal);
        }
        if (dom.discount) {
            dom.discount.textContent = U.fmtNumber(t.discount);
        }
        if (dom.total) {
            dom.total.textContent = U.fmtMoney(t.total);
        }

        // المدفوع: يُقترح تلقائيًا مساويًا للإجمالي حتى يعدّله الكاشير
        if (dom.paidInput && !dom.paidInput.dataset.touched) {
            dom.paidInput.value = t.total > 0 ? t.total.toFixed(2) : '';
            state.paid = t.total;
        }

        var paid = Number(state.paid) || 0;
        var remaining = U.round2(t.total - paid);
        if (dom.remaining) {
            dom.remaining.textContent = U.fmtNumber(Math.abs(remaining) < 0.005 ? 0 : Math.abs(remaining));
        }
        if (dom.remainingLine) {
            dom.remainingLine.classList.toggle('warn', remaining > 0.005);
        }

        // تنبيه تجاوز المخزون
        var over = state.lines.some(function (l) {
            return l.stock !== null && l.stock !== undefined && Number(l.qty) > Number(l.stock);
        });
        if (over) {
            U.showMessage(dom.msg, 'warning', window.acPosI18n.out_of_stock_warn.replace(':qty', '±'));
        }
    }

    /* ------------------------------------------------------
       الباركود — المسار الأول للإدخال (لا احتياطي)
       ------------------------------------------------------
       ⚠️ المبدأ: قاعدة `moh_medicines` لا تحتوي باركودًا كاملًا. لذلك:
         - باركود معروف  ⇒ بطاقة تأكيد ثم إضافة للسلة (خطوة واحدة إضافية فقط)
         - باركود مجهول  ⇒ **مسار ربط** (بناء تغطية) — لا رسالة خطأ
         - تعارض          ⇒ حوار مراجعة — لا تجاوز
       كل المنطق الثقيل في `AccountingBarcode` — هذا الملف يعرض فقط.
       ------------------------------------------------------ */

    /** آخر نتيجة مسح — تُخزَّن لأن الإضافة تتم بضغطة تأكيد منفصلة */
    var lastScanResult = null;

    function barcodeI18n() {
        return window.acBarcodeI18n || {};
    }

    /** يعرض بطاقة «تم التعرّف على الدواء» */
    function showFoundCard(result) {
        lastScanResult = result;

        if (!dom.foundCard) {
            return;
        }
        dom.foundCard.hidden = false;

        var item = result.item || {};
        if (dom.foundName) {
            dom.foundName.textContent = item.trade_name || '';
        }
        if (dom.foundMeta) {
            var parts = [];
            if (item.active_ingredient) {
                parts.push(item.active_ingredient);
            }
            parts.push(U.fmtNumber(item.price) + ' ' +
                ((window.acAccountingConfig && window.acAccountingConfig.currency) || ''));

            // تحذير المخزون — معلومة لا خطأ
            if (item.quantity !== null && item.quantity !== undefined && Number(item.quantity) <= 0) {
                parts.push(barcodeI18n().no_stock_warning || '');
            }
            dom.foundMeta.textContent = parts.join(' · ');
        }

        // شارة الحالة تُبنى من الحالة الحقيقية — لا تلوين عشوائي
        if (dom.foundBadge) {
            dom.foundBadge.setAttribute('data-barcode-status', result.status);
            var families = ['is-unknown', 'is-pending', 'is-verified', 'is-conflict'];
            families.forEach(function (c) { dom.foundBadge.classList.remove(c); });
            dom.foundBadge.classList.add('is-' + result.status);

            var labelEl = dom.foundBadge.querySelector('.ac-bcode-label');
            if (labelEl) {
                labelEl.textContent = window.AccountingBarcode.statusLabel(result.status);
            }
        }

        if (dom.scanUnknown) {
            dom.scanUnknown.classList.add('ac-hidden');
        }
    }

    function hideFoundCard() {
        lastScanResult = null;
        if (dom.foundCard) {
            dom.foundCard.hidden = true;
        }
    }

    /**
     * يعالج نتيجة مسح واحدة.
     * ⚠️ لا نضيف للسلة تلقائيًا: بطاقة التأكيد خطوة واحدة تفصل بين
     * «مسحتُه» و«بِعته» — تمنع إضافة صنف خاطئ بضغطة ماسح غير مقصودة.
     */
    function handleScanResult(result) {
        // مسح جديد يلغي أي نتيجة معلّقة سابقة
        hideFoundCard();

        if (result.status === 'unknown' || result.status === 'conflict') {
            if (result.status === 'unknown' && dom.scanUnknown) {
                dom.scanUnknown.classList.remove('ac-hidden');
            }
            return;
        }

        if (result.status === 'invalid') {
            AccountingBarcode.notify(barcodeI18n().empty_code || '', 'error');
            return;
        }

        if (!result.item) {
            return;
        }

        showFoundCard(result);
        AccountingBarcode.notify(
            barcodeI18n().found_title || '',
            'success'
        );
    }

    /** يضيف نتيجة المسح المعلّقة إلى السلة */
    function addScannedItem() {
        if (!lastScanResult || !lastScanResult.item) {
            return;
        }

        var item = lastScanResult.item;
        var res = addItem(item);

        if (!res.added) {
            AccountingBarcode.notify(
                window.acPosI18n.barcode_out_of_stock.replace(':name', item.trade_name || ''),
                'error'
            );
            return;
        }

        // نحفظ الحالة على السطر ليظهر في عمود الباركود
        if (res.line) {
            res.line.barcodeStatus = lastScanResult.status;
        }

        render();
        hideFoundCard();

        if (dom.barcodeInput) {
            dom.barcodeInput.value = '';
            dom.barcodeInput.focus();
        }
    }

    /* ------------------------------------------------------
       البحث عن عميل (لربط الفاتورة الآجلة بمدين حقيقي)
       ------------------------------------------------------ */
    /**
     * يعرض نتائج بحث العملاء تحت الحقل.
     * كل زر يحمل `data-ac-customer-id` — وهو ما يُرسل للـBackend فعلًا.
     */
    function renderCustomerMatches(rows) {
        if (!dom.customerMatches) {
            return;
        }

        if (!rows || !rows.length) {
            dom.customerMatches.innerHTML = '';
            dom.customerMatches.hidden = true;
            return;
        }

        dom.customerMatches.hidden = false;
        dom.customerMatches.innerHTML = rows.map(function (c) {
            var debt = Number(c.current_balance) || 0;
            var debtTxt = debt > 0 ? ' · ' + U.fmtMoney(debt) : '';
            return '<button type="button" class="ac-search-item"'
                + ' data-ac-customer-id="' + escapeAttr(c.id) + '"'
                + ' data-ac-customer-name="' + escapeAttr(c.name || '') + '">'
                + '<span class="ac-search-name">' + escapeHtml(c.name || '') + '</span>'
                + '<span class="ac-search-meta">'
                + escapeHtml(c.phone || '') + debtTxt
                + '</span></button>';
        }).join('');
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function escapeAttr(s) {
        return escapeHtml(s);
    }

    /* ------------------------------------------------------
       البحث عن دواء
       ------------------------------------------------------ */
    function renderSearchResults(rows) {
        if (!dom.results) {
            return;
        }
        if (!rows.length) {
            dom.results.innerHTML = '';
            U.showMessage(dom.searchMsg, 'warning', window.acPosI18n.search_no_results);
            return;
        }
        U.hideMessage(dom.searchMsg);

        dom.results.innerHTML = rows.map(function (m) {
            var out = m.quantity !== null && m.quantity !== undefined && Number(m.quantity) <= 0;
            return ''
                + '<li>'
                + '<button type="button" class="ac-search-item' + (out ? ' is-out' : '') + '"'
                + ' data-ac-pick="' + esc(JSON.stringify({
                    id: m.id, barcode: m.barcode, trade_name: m.trade_name,
                    active_ingredient: m.active_ingredient, price: m.price, quantity: m.quantity
                })) + '">'
                + '<span class="ac-si-body">'
                + '<span class="ac-si-name">' + esc(m.trade_name) + '</span>'
                + '<span class="ac-si-sub">' + esc(m.active_ingredient) + '</span>'
                + '</span>'
                + '<span class="ac-si-price">' + esc(U.fmtNumber(m.price)) + '</span>'
                + '</button>'
                + '</li>';
        }).join('');
    }

    var search = U.debounce(function (q) {
        if (q.length < 2) {
            dom.results.innerHTML = '';
            U.hideMessage(dom.searchMsg);
            return;
        }
        Api.searchMedicines(q).then(function (res) {
            renderSearchResults(res.data || []);
        });
    }, 280);

    /* ------------------------------------------------------
       إتمام البيع
       ------------------------------------------------------ */

    /**
     * يحوّل سلة الواجهة إلى عقد الـBackend.
     *
     * ⚠️ أسماء المفاتيح ليست تجميلية — هي العقد. `qty→quantity`،
     * `price→unit_price`، `discount→line_discount`، `name→medicine_name`.
     * و`state.customer` نصّي (اسم) أو null، بينما الـBackend يريد
     * `customer_id` رقميًا — لذا نرسله فقط لو كان رقمًا حقيقيًا.
     *
     * @returns {object} payload جاهز لـPOST
     */
    function buildSalePayload(paid) {
        var t = computeTotals(state.lines);

        var items = state.lines.map(function (l) {
            var row = {
                medicine_name: String(l.name || ''),
                barcode: l.barcode || null,
                unit_price: U.round2(Number(l.price) || 0),
                quantity: Number(l.qty) || 1,
                line_discount: U.round2(Number(l.discount) || 0)
            };

            // المعرّفان يُرسلان فقط لو موجودان — لا نرسل null صريحًا
            // لأن `nullable|integer` تقبل الغياب ولا تحتاج المفتاح.
            if (l.pharmacy_medicine_id != null) {
                row.pharmacy_medicine_id = l.pharmacy_medicine_id;
            }
            if (l.medicine_id != null) {
                row.medicine_id = l.medicine_id;
            }

            return row;
        });

        var payload = {
            items: items,
            payment_method: state.payment || 'cash',
            // الخصم الكلي على الفاتورة = مجموع خصومات الأسطر (الواجهة لا
            // تفرّق بينهما)، والـBackend يحسب total = subtotal − discount.
            discount: U.round2(t.discount),
            paid: U.round2(paid)
        };

        // العميل: نقبل id رقميًا فقط. الاسم النصّي لا يكفي لأن الـBackend
        // يحتاجه لربط الدين — والزائر النقدي لا دين عليه أصلًا.
        var cid = Number(state.customerId);
        if (state.customerId != null && !isNaN(cid) && cid > 0) {
            payload.customer_id = cid;
        }

        return payload;
    }

    function completeSale() {
        if (state.saving) {
            return; // حماية من الضغط المكرر
        }

        var errors = validateLines(state.lines);
        if (errors.length) {
            U.showMessage(dom.msg, 'error', errors[0].message);
            return;
        }

        var t = computeTotals(state.lines);
        var paid = Number(state.paid) || 0;
        if (paid < 0 || isNaN(paid)) {
            U.showMessage(dom.msg, 'error', window.acPosI18n.invalid_amount);
            return;
        }
        if (paid > t.total + 0.005) {
            U.showMessage(dom.msg, 'error', window.acPosI18n.paid_exceeds_total);
            return;
        }

        state.saving = true;
        if (dom.submitBtn) {
            dom.submitBtn.disabled = true;
            dom.submitBtn.setAttribute('aria-busy', 'true');
            dom.submitLabel.textContent = window.acPosI18n.processing;
        }

        Api.createSale(buildSalePayload(paid)).then(function (res) {
            state.saving = false;
            if (dom.submitBtn) {
                dom.submitBtn.disabled = false;
                dom.submitBtn.removeAttribute('aria-busy');
                dom.submitLabel.textContent = window.acPosI18n.complete_sale;
            }

            if (res.ok) {
                lastSavedSale = res.sale || null;
                U.showMessage(dom.msg, 'success', window.acPosI18n.sale_saved);
                state.lines = [];
                state.paid = 0;
                if (dom.paidInput) {
                    dom.paidInput.value = '';
                    dom.paidInput.dataset.touched = '';
                }
                render();
                notifySaleSaved(lastSavedSale);
                return;
            }

            // خطأ حقيقي من الـBackend. السبب يُقال كما هو — لا رسالة عامة
            // تُخفي السبب الحقيقي (كان يحدث حين كان الرد يُبتلع).
            U.showMessage(dom.msg, 'error', res.message || window.acPosI18n.sale_failed);
        });
    }

    /** آخر فاتورة محفوظة — تُستخدم لزر «عرض/طباعة». */
    var lastSavedSale = null;

    /** إظهار رابط الفاتورة المحفوظة بدل تركه للمستخدم يبحث عنها. */
    function notifySaleSaved(sale) {
        if (!sale || !sale.number || !dom.msg) {
            return;
        }
        var href = (window.acPosConfig && window.acPosConfig.invoiceUrlTemplate)
            ? window.acPosConfig.invoiceUrlTemplate.replace('__NUMBER__', encodeURIComponent(sale.number))
            : null;

        if (!href) {
            return;
        }

        var link = document.createElement('a');
        link.href = href;
        link.className = 'ac-inline-link';
        link.textContent = sale.number;
        if (dom.msg.querySelector('span')) {
            dom.msg.querySelector('span').appendChild(document.createTextNode(' — '));
            dom.msg.querySelector('span').appendChild(link);
        }
    }

    /* ------------------------------------------------------
       ربط الأحداث
       ------------------------------------------------------ */
    function bind() {
        // -----------------------------------------------------------
        // مدخل 1: لوحة المفاتيح / قارئ USB
        // -----------------------------------------------------------
        // ⚠️ قارئ USB يعرّف نفسه كـ**لوحة مفاتيح**: يكتب الرقم ثم يرسل Enter.
        // لذلك نستمع لـEnter على الحقل، ونمنع submit النموذج، ونمرّر النتيجة
        // إلى `AccountingBarcode.initScanner` — وهو المالك الوحيد لمنطق المسح.
        // ⚠️ لا نضيف مستمع Enter ثانيًا هنا: `initScanner` (المُنادى في init)
        // هو من يربطه، وربطه مرتين = مسح مزدوج لكل ضغطة.
        if (dom.barcodeInput) {
            var clearBtn = document.querySelector('[data-barcode-clear]');
            if (clearBtn) {
                clearBtn.addEventListener('click', hideFoundCard);
            }
        }

        // زر تأكيد إضافة الدواء الممسوح
        if (dom.foundAddBtn) {
            dom.foundAddBtn.addEventListener('click', addScannedItem);
        }

        // «ابحث بالاسم» ينقل التركيز لمسار البحث — بلا تعطيل أي مسار
        if (dom.focusSearchBtn && dom.searchInput) {
            dom.focusSearchBtn.addEventListener('click', function () {
                dom.searchInput.focus();
                dom.searchInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        }

        // البحث المباشر
        if (dom.searchInput) {
            dom.searchInput.addEventListener('input', function () {
                search(dom.searchInput.value.trim());
            });
        }

        // اختيار نتيجة بحث
        if (dom.results) {
            dom.results.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-ac-pick]');
                if (!btn) {
                    return;
                }
                var item = JSON.parse(btn.getAttribute('data-ac-pick'));
                var result = addItem(item);
                if (!result.added) {
                    U.showMessage(dom.searchMsg, 'error',
                        window.acPosI18n.barcode_out_of_stock.replace(':name', item.trade_name || ''));
                    return;
                }
                render();
                U.hideMessage(dom.searchMsg);
                dom.searchInput.value = '';
                dom.results.innerHTML = '';
                dom.searchInput.focus();
            });
        }

        // تعديل/حذف صفوف السلة (تفويض الأحداث)
        if (dom.tbody) {
            dom.tbody.addEventListener('input', function (e) {
                var input = e.target.closest('[data-ac-field]');
                if (!input) {
                    return;
                }
                var row = input.closest('tr');
                if (!row) {
                    return;
                }
                var field = input.getAttribute('data-ac-field');

                // الحفاظ على موضع المؤشر: لا نعيد رسم كامل أثناء الكتابة في نفس الحقل
                var key = row.getAttribute('data-key');
                var idx = findIndexByKey(key);
                if (idx === -1) {
                    return;
                }
                if (field === 'qty') {
                    var q = parseInt(input.value, 10);
                    state.lines[idx].qty = isNaN(q) ? 0 : Math.max(0, q);
                } else {
                    var n = parseFloat(input.value);
                    state.lines[idx][field] = isNaN(n) ? 0 : Math.max(0, n);
                }

                // تحديث الخلايا الرقمية فقط (بلا innerHTML)
                var totalCell = row.querySelector('.ac-line-total');
                if (totalCell) {
                    totalCell.textContent = U.fmtNumber(lineNet(state.lines[idx]));
                }
                renderTotals();
            });

            dom.tbody.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-ac-remove]');
                if (btn) {
                    removeLine(btn.getAttribute('data-ac-remove'));
                }
            });
        }

        if (dom.clearBtn) {
            dom.clearBtn.addEventListener('click', clearCart);
        }

        // المدفوع
        if (dom.paidInput) {
            dom.paidInput.addEventListener('input', function () {
                dom.paidInput.dataset.touched = '1';
                var n = parseFloat(dom.paidInput.value);
                state.paid = isNaN(n) ? 0 : Math.max(0, n);
                renderTotals();
            });
        }

        // طريقة الدفع
        if (dom.paymentSelect) {
            dom.paymentSelect.addEventListener('change', function () {
                state.payment = dom.paymentSelect.value;
            });
        }

        // العميل
        // ⚠️ حقل نصّي حرّ لا يكفي: البيع الآجل يحتاج `customer_id` رقميًا
        // ليزيد دين عميل حقيقي. لذلك نبحث في الـAPI ونخزّن id المختار،
        // ولو كتب المستخدم اسمًا لا يطابق شيئًا نبقيه null ولا نرسله.
        if (dom.customerInput) {
            var lookupCustomer = U.debounce(function () {
                var v = dom.customerInput.value.trim();
                state.customer = v === '' ? null : v;
                state.customerId = null;

                if (v.length < 2) {
                    renderCustomerMatches([]);
                    return;
                }

                Api.searchCustomers(v).then(function (res) {
                    renderCustomerMatches(res.ok ? res.data : []);
                });
            }, 300);

            dom.customerInput.addEventListener('input', lookupCustomer);
            dom.customerInput.addEventListener('change', lookupCustomer);

            // اختيار صريح من القائمة ⇒ نثبّت الـid
            if (dom.customerMatches) {
                dom.customerMatches.addEventListener('click', function (e) {
                    var btn = e.target.closest ? e.target.closest('[data-ac-customer-id]') : null;
                    if (!btn) {
                        return;
                    }
                    state.customerId = Number(btn.getAttribute('data-ac-customer-id'));
                    state.customer = btn.getAttribute('data-ac-customer-name') || dom.customerInput.value;
                    dom.customerInput.value = state.customer;
                    renderCustomerMatches([]);
                });
            }
        }

        // إتمام البيع
        if (dom.submitBtn) {
            dom.submitBtn.addEventListener('click', completeSale);
        }
    }

    /* ------------------------------------------------------
       الإقلاع
       ------------------------------------------------------ */
    function init() {
        dom = {
            barcodeInput: document.querySelector('[data-barcode-input]'),
            searchInput: document.querySelector('[data-ac-search]'),
            searchMsg: document.querySelector('[data-ac-search-msg]'),
            results: document.querySelector('[data-ac-results]'),
            tbody: document.querySelector('[data-ac-cart-body]'),
            cartCount: document.querySelector('[data-ac-cart-count]'),
            tableWrap: document.querySelector('[data-ac-cart-table]'),
            toolbar: document.querySelector('[data-ac-cart-toolbar]'),
            empty: document.querySelector('[data-ac-cart-empty]'),
            clearBtn: document.querySelector('[data-ac-clear]'),
            subtotal: document.querySelector('[data-ac-subtotal]'),
            discount: document.querySelector('[data-ac-discount]'),
            total: document.querySelector('[data-ac-total]'),
            paidInput: document.querySelector('[data-ac-paid]'),
            remaining: document.querySelector('[data-ac-remaining]'),
            remainingLine: document.querySelector('[data-ac-remaining-line]'),
            paymentSelect: document.querySelector('[data-ac-payment]'),
            customerInput: document.querySelector('[data-ac-customer]'),
            customerMatches: document.querySelector('[data-ac-customer-matches]'),
            submitBtn: document.querySelector('[data-ac-submit]'),
            submitLabel: document.querySelector('[data-ac-submit-label]'),
            msg: document.querySelector('[data-ac-msg]'),

            // عناصر مسار المسح
            foundCard: document.querySelector('[data-ac-scan-found]'),
            foundName: document.querySelector('[data-ac-found-name]'),
            foundMeta: document.querySelector('[data-ac-found-meta]'),
            foundBadge: document.querySelector('[data-ac-found-badge]'),
            foundAddBtn: document.querySelector('[data-ac-found-add]'),
            scanUnknown: document.querySelector('[data-ac-scan-unknown]'),
            focusSearchBtn: document.querySelector('[data-ac-focus-search]')
        };

        render();
        bind();
        U.initModalA11y();

        // -----------------------------------------------------------
        // ربط المسح — المصدر الوحيد لسلوك الباركود في هذه الصفحة
        // -----------------------------------------------------------
        // `onResolved` = باركود معروف ⇒ بطاقة تأكيد (لا إضافة تلقائية)
        // `onUnknown`  = غير مسجَّل    ⇒ مسار الربط (بناء تغطية، لا خطأ)
        // `onConflict` = مرتبط بدواء آخر ⇒ حوار مراجعة، بلا تجاوز
        if (window.AccountingBarcode) {
            AccountingBarcode.initScanner(document, {
                onResolved: handleScanResult,
                onUnknown: function (result) {
                    hideFoundCard();
                    if (dom.scanUnknown) {
                        dom.scanUnknown.classList.remove('ac-hidden');
                    }
                    AccountingBarcode.openLinkModal(result.barcode, function () {
                        AccountingBarcode.notify(
                            (window.acBarcodeI18n || {}).link_saved || '',
                            'success'
                        );
                        if (dom.barcodeInput) {
                            dom.barcodeInput.value = '';
                            dom.barcodeInput.focus();
                        }
                    });
                },
                onConflict: function (result) {
                    hideFoundCard();
                    AccountingBarcode.openConflictModal(result.barcode, '—', '');
                }
            });
        }

        // تركيز تلقائي على الباركود — جاهز للماسح فورًا
        if (dom.barcodeInput) {
            dom.barcodeInput.focus();
        }
    }

    document.addEventListener('DOMContentLoaded', init);

    // تُصدَّر للاختبارات (tests/js) — الدوال النقية فقط
    window.__acPos = {
        computeTotals: computeTotals,
        lineNet: lineNet,
        lineGross: lineGross,
        validateLines: validateLines,
        addItem: addItem,
        state: state
    };

    /* ------------------------------------------------------
       خطّاف المسح بالهاتف
       ------------------------------------------------------
       ⚠️ نقطة الوصل الوحيدة بين `accounting-phone-scanner.js` والسلة.

       لماذا خطّاف وليس استيرادًا مباشرًا؟ لأن ترتيب التحميل لا يضمن وجود
       `AccountingPhoneScanner` عند تعريف هذه الدالة — الخطّاف يُقرأ وقت
       التنفيذ لا وقت التعريف.

       ⚠️ **لا ننشئ آلة سلة ثانية**: نسخة الهاتف تُمرَّر إلى `addItem`
       و`render` نفسيهما المستخدمتين للبحث وقارئ USB. فالنتيجة واحدة
       دائمًا لنفس الدواء بغضّ النظر عن طريقة الإدخال.
       ------------------------------------------------------ */
    window.__acPosHook = {
        /**
         * يستقبل نتيجة حلّ الباركود من أي مصدر (هاتف / محاكاة).
         * @param {{status:string, barcode:string, item:?object}} result
         */
        addScannedResult: function (result) {
            if (!result || !result.item) {
                return { added: false, reason: 'no_item' };
            }

            var res = addItem(result.item);

            if (!res.added) {
                AccountingBarcode.notify(
                    window.acPosI18n.barcode_out_of_stock.replace(':name', result.item.trade_name || ''),
                    'error'
                );
                return res;
            }

            // نحفظ الحالة على السطر ليظهر في عمود الباركود
            if (res.line) {
                res.line.barcodeStatus = result.status || 'pending';
            }

            render();

            if (dom.barcodeInput) {
                dom.barcodeInput.value = '';
                dom.barcodeInput.focus();
            }

            return res;
        }
    };
})();
