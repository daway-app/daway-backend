/**
 * Daway Accounting — الطبقة المشتركة (shared)
 * ==========================================================
 * ⚠️ لا يوجد Backend محاسبة. هذا الملف يوفّر:
 *   1. أدوات تنسيق (مبلغ/تاريخ/باركود).
 *   2. `AccountingApi` — **الواجهة الوحيدة** التي ستتحدث للـAPI لاحقًا.
 *      حاليًا تفشل بهدوء وتُرجع بيانات من `window.acAccountingConfig.catalog`
 *      (mock) — فلا يوجد أي endpoint مُختلَق.
 *
 * عند بناء الـBackend: عدّل `AccountingApi` فقط. لا تلمس باقي الملفات.
 *
 * يُحمَّل هذا الملف **قبل** ملفات الصفحات في @vite([...]).
 */
(function () {
    'use strict';

    /* ------------------------------------------------------
       1) قراءة التوكنات (لا قيم لونية في JS)
       ------------------------------------------------------ */
    function token(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    function isDark() {
        return document.documentElement.classList.contains('dark-mode')
            || document.body.classList.contains('dark-mode');
    }

    /** يحوّل عنوان توكن ('--success') إلى لون فعلي؛ وإلا يمرّر القيمة كما هي. */
    function color(c) {
        if (typeof c === 'string' && c.slice(0, 2) === '--') {
            return token(c) || '#94A3B8';
        }
        return c;
    }

    /* ------------------------------------------------------
       2) التنسيق
       ------------------------------------------------------ */
    var CURRENCY = (window.acAccountingConfig && window.acAccountingConfig.currency) || '₪';

    /** 1234.5 → "1,234.50 ₪" */
    function fmtMoney(value) {
        var n = Number(value);
        if (!isFinite(n)) {
            n = 0;
        }
        return n.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' ' + CURRENCY;
    }

    /** 1234.5 → "1,234.50" (بلا رمز العملة — للخلايا الرقمية) */
    function fmtNumber(value, decimals) {
        var n = Number(value);
        if (!isFinite(n)) {
            n = 0;
        }
        var d = typeof decimals === 'number' ? decimals : 2;
        return n.toLocaleString('en-US', {
            minimumFractionDigits: d,
            maximumFractionDigits: d
        });
    }

    /** تقريب آمن إلى منزلتين (يتفادى أخطاء الفاصلة العائمة: 0.1+0.2) */
    function round2(n) {
        return Math.round((Number(n) + Number.EPSILON) * 100) / 100;
    }

    /**
     * تطبيع الباركود محليًا (تحقّق شكلي فقط — لا اتصال).
     * الأنواع المدعومة: EAN-13 · EAN-8 · UPC-A (12) · GTIN-14 · UPC-E (8).
     * يُرجع { ok:true, value:'6281001001234' } أو { ok:false, reason:'...' }
     *
     * ملاحظة: التحقّق الحقيقي في الـbackend (BarcodeNormalizer) — هذه
     * بوّابة مبكرة تُوفّر رحلة شبكة على مدخلات غير صالحة.
     */
    function normalizeBarcode(raw) {
        var s = String(raw == null ? '' : raw).trim();
        // السماح بمسافات/شرطات داخل الرقم (يقرأها بعض الماسحات)
        s = s.replace(/[\s\-_]/g, '');

        if (s === '') {
            return { ok: false, reason: 'empty' };
        }
        if (!/^\d+$/.test(s)) {
            return { ok: false, reason: 'non_numeric' };
        }
        var len = s.length;
        if (len !== 8 && len !== 12 && len !== 13 && len !== 14) {
            return { ok: false, reason: 'length' };
        }
        // EAN-8 يبدأ بـ0 أو لا — النوع يُحدَّد من الطول
        var type = len === 14 ? 'GTIN14'
            : len === 13 ? 'EAN13'
                : len === 12 ? 'UPCA' : 'EAN8';

        return { ok: true, value: s, type: type };
    }

    /* ------------------------------------------------------
       3) الـAPI — نقطة الفصل الوحيدة
       ------------------------------------------------------ */
    /**
     * كل الدوال تُرجع Promise. عند عدم وجود endpoint (الحالة الراهنة)
     * تُرجع بيانات من الـcatalog المحلي، أو `{ ok:false, reason:'no_backend' }.
     *
     * `endpoints` تأتي من Blade عبر window.acAccountingConfig.endpoints —
     * ولا تُبنى هنا، حتى لا تختلق مسارات.
     */
    var AccountingApi = (function () {
        function config() {
            return window.acAccountingConfig || {};
        }

        /** بحث الأدوية — يستخدم /api/medicines/search الموجود فعلاً. */
        function searchMedicines(query) {
            var cfg = config();
            var q = String(query || '').trim();
            if (q.length < 2) {
                return Promise.resolve({ ok: true, source: 'local', data: [] });
            }

            // المسار يستخدم مسارًا موجودًا فعلاً في routes/api.php (عام، بلا auth)
            if (cfg.endpoints && cfg.endpoints.medicineSearch) {
                var url = cfg.endpoints.medicineSearch + '?q=' + encodeURIComponent(q);
                return fetch(url, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                })
                    .then(function (res) {
                        if (!res.ok) {
                            throw new Error('HTTP ' + res.status);
                        }
                        return res.json();
                    })
                    .then(function (json) {
                        return { ok: true, source: 'api', data: normalizeSearchPayload(json) };
                    })
                    .catch(function () {
                        // فشل الشبكة لا يكسر الشاشة — نسقط للمخزون المحلي
                        return { ok: true, source: 'local-fallback', data: localSearch(q) };
                    });
            }

            return Promise.resolve({ ok: true, source: 'local', data: localSearch(q) });
        }

        /** بحث بالباركود — يستخدم /api/medicines/barcode/{code} الموجود فعلاً. */
        function lookupBarcode(code) {
            var cfg = config();
            var norm = normalizeBarcode(code);
            if (!norm.ok) {
                return Promise.resolve({ ok: false, reason: 'invalid', detail: norm.reason });
            }

            if (cfg.endpoints && cfg.endpoints.barcodeLookup) {
                var url = cfg.endpoints.barcodeLookup + '/' + encodeURIComponent(norm.value);
                return fetch(url, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                })
                    .then(function (res) {
                        return res.json().then(function (json) {
                            return { ok: res.ok, status: res.status, json: json };
                        });
                    })
                    .then(function (r) {
                        if (r.ok && r.json && r.json.success && r.json.data) {
                            return {
                                ok: true,
                                source: 'api',
                                item: mapBarcodeResult(r.json.data),
                                barcode: norm
                            };
                        }
                        // 404 من الـAPI = غير موجود => نسقط للمخزون المحلي قبل الحكم
                        var local = localByBarcode(norm.value);
                        if (local) {
                            return { ok: true, source: 'local-fallback', item: local, barcode: norm };
                        }
                        return {
                            ok: false,
                            reason: r.status === 404 ? 'not_found' : 'api_error',
                            barcode: norm
                        };
                    })
                    .catch(function () {
                        var local = localByBarcode(norm.value);
                        if (local) {
                            return { ok: true, source: 'local-fallback', item: local, barcode: norm };
                        }
                        return { ok: false, reason: 'network', barcode: norm };
                    });
            }

            var fallback = localByBarcode(norm.value);
            if (fallback) {
                return Promise.resolve({ ok: true, source: 'local', item: fallback, barcode: norm });
            }
            return Promise.resolve({ ok: false, reason: 'not_found', barcode: norm });
        }

        /**
         * حفظ فاتورة بيع.
         * ⚠️ لا يوجد endpoint ⇒ تُرجع `{ok:false, reason:'no_backend'}` دائمًا.
         * لا نرسل أي شيء لأي مسار — لا نختلق عقد API.
         */
        function createSale() {
            return Promise.resolve({ ok: false, reason: 'no_backend' });
        }

        /** سجل المبيعات — لا endpoint بعد. */
        function listSales() {
            return Promise.resolve({ ok: false, reason: 'no_backend' });
        }

        /* ---- أدوات داخلية ---- */

        function catalog() {
            var cfg = config();
            return Array.isArray(cfg.catalog) ? cfg.catalog : [];
        }

        function localSearch(q) {
            var needle = q.toLowerCase();
            return catalog().filter(function (m) {
                return String(m.trade_name || '').toLowerCase().indexOf(needle) !== -1
                    || String(m.active_ingredient || '').toLowerCase().indexOf(needle) !== -1;
            }).slice(0, 20);
        }

        function localByBarcode(code) {
            var list = catalog();
            for (var i = 0; i < list.length; i++) {
                if (String(list[i].barcode) === String(code)) {
                    return list[i];
                }
            }
            return null;
        }

        /** يوحّد شكل نتيجة /api/medicines/search إلى شكل السلة الموحّد. */
        function normalizeSearchPayload(json) {
            var rows = [];
            if (json && Array.isArray(json.data)) {
                rows = json.data;
            } else if (Array.isArray(json)) {
                rows = json;
            } else if (json && json.data && Array.isArray(json.data.data)) {
                rows = json.data.data;
            }

            return rows.map(function (r) {
                return {
                    id: r.id != null ? r.id : null,
                    barcode: r.barcode || r.bar_code || '',
                    trade_name: r.trade_name || r.trade_name_ar || r.name || '',
                    active_ingredient: r.active_ingredient || r.generic_name || '',
                    price: r.official_price != null ? Number(r.official_price)
                        : (r.price != null ? Number(r.price) : 0),
                    quantity: null, // غير معروف من الكتالوج — يُحلّ من المخزون المحلي
                    from_catalog: true
                };
            });
        }

        /** يوحّد شكل نتيجة /api/medicines/barcode/{code}. */
        function mapBarcodeResult(data) {
            var med = (data && data.medicine) || {};
            var localId = med.local_medicine_id != null ? med.local_medicine_id : null;

            // لو الـbackend أعاد local_medicine_id، نحاول ربطه بمخزوننا المحلي
            var local = null;
            if (localId != null) {
                var list = catalog();
                for (var i = 0; i < list.length; i++) {
                    if (String(list[i].id) === String(localId)) {
                        local = list[i];
                        break;
                    }
                }
            }

            return {
                id: localId != null ? localId : med.id,
                barcode: (data.barcode && data.barcode.value) || '',
                trade_name: med.name_en || med.name_ar || '',
                active_ingredient: med.active_ingredient || '',
                price: local ? Number(local.price) : (med.official_price != null ? Number(med.official_price) : 0),
                quantity: local ? local.quantity : null,
                in_inventory: !!local,
                from_catalog: true
            };
        }

        return {
            searchMedicines: searchMedicines,
            lookupBarcode: lookupBarcode,
            createSale: createSale,
            listSales: listSales
        };
    })();

    /* ------------------------------------------------------
       4) أدوات DOM صغيرة
       ------------------------------------------------------ */
    /** debounce — للبحث (لا طلب لكل ضغطة مفتاح) */
    function debounce(fn, wait) {
        var t = null;
        return function () {
            var args = arguments;
            var self = this;
            clearTimeout(t);
            t = setTimeout(function () {
                fn.apply(self, args);
            }, typeof wait === 'number' ? wait : 250);
        };
    }

    /** يعرض/يخفي رسالة مضمنة داخل حاوية */
    function showMessage(el, type, text) {
        if (!el) {
            return;
        }
        el.className = 'ac-inline-msg show ' + type;
        var icon = type === 'success' ? 'fas fa-circle-check'
            : type === 'error' ? 'fas fa-circle-exclamation' : 'fas fa-triangle-exclamation';
        el.innerHTML = '<i class="' + icon + '" aria-hidden="true"></i><span></span>';
        el.querySelector('span').textContent = text;
    }

    function hideMessage(el) {
        if (el) {
            el.className = 'ac-inline-msg';
            el.textContent = '';
        }
    }

    /** إغلاق الحوارات: Esc + النقر على الخلفية (يكمّل initModals في pharmacy_hub.js) */
    function initModalA11y() {
        document.querySelectorAll('[data-ph-modal-open]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var sel = btn.getAttribute('data-ph-modal-open');
                var modal = document.querySelector(sel);
                if (!modal) {
                    return;
                }
                // إعادة التركيز لأول عنصر تفاعلي — مطلوب لـWCAG 2.4.3
                var focusable = modal.querySelector('input, select, textarea, button:not(.ph-close)');
                if (focusable) {
                    setTimeout(function () { focusable.focus(); }, 60);
                }
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') {
                return;
            }
            var open = document.querySelector('.ph-modal-overlay.active');
            if (open) {
                open.classList.remove('active');
                document.body.classList.remove('ph-modal-open');
            }
        });
    }

    // تصدير عام
    window.AccountingUtil = {
        token: token,
        isDark: isDark,
        color: color,
        fmtMoney: fmtMoney,
        fmtNumber: fmtNumber,
        round2: round2,
        normalizeBarcode: normalizeBarcode,
        debounce: debounce,
        showMessage: showMessage,
        hideMessage: hideMessage,
        initModalA11y: initModalA11y
    };
    window.AccountingApi = AccountingApi;
})();
