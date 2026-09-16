/**
 * Daway Accounting — الطبقة المشتركة (shared)
 * ==========================================================
 * هذا الملف يوفّر:
 *   1. أدوات تنسيق (مبلغ/تاريخ/باركود).
 *   2. `AccountingApi` — **الواجهة الوحيدة** التي تتحدث للـAPI.
 *
 * ── الحالة الآن: Backend حقيقي موجود ────────────────────────────────
 * الـendpoints الحقيقية (routes/api.php):
 *   GET  /api/pharmacy/accounting/overview
 *   GET  /api/pharmacy/accounting/sales
 *   POST /api/pharmacy/accounting/sales
 *   GET  /api/pharmacy/accounting/sales/{number}
 *   POST /api/pharmacy/accounting/sales/{number}/cancel
 *   GET  /api/pharmacy/accounting/sales-summary
 *   GET  /api/pharmacy/accounting/expenses
 *   POST /api/pharmacy/accounting/expenses
 *   POST /api/pharmacy/accounting/expenses/{id}/cancel
 *   GET  /api/pharmacy/accounting/expense-categories
 *   GET  /api/pharmacy/accounting/customers
 *   POST /api/pharmacy/accounting/customers
 *   POST /api/pharmacy/accounting/customers/{id}/payments
 *   GET  /api/pharmacy/accounting/suppliers
 *   POST /api/pharmacy/accounting/suppliers
 *   POST /api/pharmacy/accounting/suppliers/{id}/payments
 *   GET  /api/pharmacy/accounting/cash
 *   POST /api/pharmacy/accounting/cash/adjustments
 *   (+ البحث بالباركود في /api/medicines/barcode/{code} — موجود مسبقًا)
 *
 * كلها تحت `auth:sanctum` + `role:pharmacy`. تُمرَّر في Blade عبر
 * `window.acAccountingConfig.endpoints` — **لا نبني مسارات في JS**.
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
     * كل الدوال تُرجع Promise بالشكل الموحّد:
     *   { ok:true, data }            نجاح
     *   { ok:false, reason, status, message }   فشل
     *
     * ── قاعدة: الفشل **يُبلَّغ** ولا يُبتلع ──────────────────────────
     * أي استجابة غير ناجحة تُرجع `ok:false` مع `message` عربي من الـAPI
     * (لو وُجد). الواجهة تُظهر الخطأ بدل عرض صفر يوهم بأن البيانات فاضية.
     * هذا مهم محاسبيًا: "الصندوق 0.00" و"فشل الجلب" حالتان مختلفتان تمامًا.
     */
    var AccountingApi = (function () {
        function config() {
            return window.acAccountingConfig || {};
        }

        function endpoints() {
            return config().endpoints || {};
        }

        /**
         * طلب JSON موحّد. يضيف Accept + CSRF (للكتابة) ويرتّب الأخطاء.
         *
         * @param {string} method
         * @param {string} url
         * @param {object|null} body
         * @returns {Promise<{ok:boolean,status:number,json:object|null,message:string}>}
         */
        function request(method, url, body) {
            var headers = { 'Accept': 'application/json' };
            var opts = {
                method: method,
                headers: headers,
                credentials: 'same-origin'
            };

            if (body != null) {
                headers['Content-Type'] = 'application/json';
                var csrf = csrfToken();
                if (csrf) {
                    headers['X-CSRF-TOKEN'] = csrf;
                }
                opts.body = JSON.stringify(body);
            }

            return fetch(url, opts).then(function (res) {
                // 204 أو استجابة بلا جسم
                if (res.status === 204) {
                    return { ok: res.ok, status: res.status, json: null, message: '' };
                }
                return res.json().catch(function () {
                    return null;
                }).then(function (json) {
                    var message = (json && json.message) ? String(json.message) : '';
                    return { ok: res.ok, status: res.status, json: json, message: message };
                });
            }).catch(function () {
                // فشل شبكة (انقطاع، CORS، …) — يُبلَّغ كحالة مستقلة
                return { ok: false, status: 0, json: null, message: 'تعذّر الاتصال بالخادم' };
            });
        }

        function csrfToken() {
            var meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        }

        /**
         * يبني رابطًا مع معاملات الاستعلام.
         * `from`/`to` يغلبان `range` — كما في الـbackend.
         */
        function withQuery(base, params) {
            var parts = [];
            Object.keys(params || {}).forEach(function (k) {
                var v = params[k];
                if (v === null || v === undefined || v === '') {
                    return;
                }
                parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
            });
            return parts.length ? (base + '?' + parts.join('&')) : base;
        }

        /* ─────────────── البحث (كتالوج + باركود) ─────────────── */

        /** بحث الأدوية — /api/medicines/search (موجود مسبقًا، عام). */
        function searchMedicines(query) {
            var cfg = config();
            var q = String(query || '').trim();
            if (q.length < 2) {
                return Promise.resolve({ ok: true, source: 'local', data: [] });
            }

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

        /** بحث بالباركود — /api/medicines/barcode/{code} (موجود مسبقًا). */
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

        /* ─────────────── المبيعات ─────────────── */

        /**
         * حفظ فاتورة بيع — POST /api/pharmacy/accounting/sales.
         *
         * الباك-إند يخصم المخزون ويكتب حركة الصندوق ويزيد دين العميل
         * داخل معاملة واحدة (AccountingLedger). الواجهة لا تحسب شيئًا
         * من ذلك — تُرسل البنود كما هي وتنتظر الحقيقة.
         *
         * `optimistic:false` في الاستجابة لأن الأرقام الراجعة هي المرجع.
         *
         * @param {object} payload {customer_id, items[], discount, paid, payment_method, notes}
         * @returns {Promise<{ok:boolean, sale?:object, message:string, reason?:string, errors?:object}>}
         */
        function createSale(payload) {
            var url = endpoints().salesCreate;
            if (!url) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة حفظ الفاتورة' });
            }

            return request('POST', url, payload).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, sale: r.json.data, message: r.message || 'تم حفظ الفاتورة' };
                }

                // 422 = أخطاء تحقّق مفصّلة (لا خطأ عام)
                var errors = (r.json && r.json.errors) ? r.json.errors : null;
                var firstError = '';
                if (errors) {
                    var keys = Object.keys(errors);
                    if (keys.length) {
                        var v = errors[keys[0]];
                        firstError = Array.isArray(v) ? String(v[0]) : String(v);
                    }
                }

                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 422 ? 'validation' : (r.status === 0 ? 'network' : 'api_error'),
                    message: firstError || r.message || 'تعذّر حفظ الفاتورة',
                    errors: errors
                };
            });
        }

        /**
         * سجل المبيعات — GET /api/pharmacy/accounting/sales.
         *
         * @param {object} params {page, per_page, search, status, payment_method, range, from, to}
         * @returns {Promise<{ok:boolean, data:Array, pagination:object|null, stats:object|null, message:string}>}
         */
        function listSales(params) {
            var url = endpoints().salesIndex;
            if (!url) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة المبيعات', data: [] });
            }

            return request('GET', withQuery(url, params || {})).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return {
                        ok: true,
                        data: Array.isArray(r.json.data) ? r.json.data : [],
                        pagination: r.json.pagination || null,
                        stats: r.json.stats || null,
                        message: r.message
                    };
                }

                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 0 ? 'network' : 'api_error',
                    message: r.message || 'تعذّر جلب المبيعات',
                    data: []
                };
            });
        }

        /** تفاصيل فاتورة — GET /api/pharmacy/accounting/sales/{number}. */
        function getSale(number) {
            var base = endpoints().salesShow;
            if (!base) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة الفاتورة' });
            }

            var url = base.replace('__NUMBER__', encodeURIComponent(String(number)));

            return request('GET', url).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, sale: r.json.data, message: r.message };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 404 ? 'not_found' : (r.status === 0 ? 'network' : 'api_error'),
                    message: r.message || 'تعذّر جلب الفاتورة'
                };
            });
        }

        /** إلغاء فاتورة — POST .../sales/{number}/cancel. */
        function cancelSale(number, reason) {
            var base = endpoints().salesCancel;
            if (!base) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة الإلغاء' });
            }

            var url = base.replace('__NUMBER__', encodeURIComponent(String(number)));

            return request('POST', url, reason ? { reason: reason } : {}).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, sale: r.json.data, message: r.message };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 0 ? 'network' : 'api_error',
                    message: r.message || 'تعذّر إلغاء الفاتورة'
                };
            });
        }

        /** ملخّص المبيعات والتقارير — GET .../accounting/sales-summary. */
        function salesSummary(params) {
            var url = endpoints().salesSummary;
            if (!url) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة الملخّص' });
            }

            return request('GET', withQuery(url, params || {})).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, data: r.json.data, message: r.message };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 0 ? 'network' : 'api_error',
                    message: r.message || 'تعذّر جلب الملخّص'
                };
            });
        }

        /* ─────────────── النظرة العامة ─────────────── */

        /**
         * نظرة عامة المحاسبة — GET .../accounting/overview.
         * تعيد: kpis · series · expense_breakdown · recent_transactions ·
         * alerts · profit_indicator · comparison · receivables · barcode_coverage.
         */
        function overview(params) {
            var url = endpoints().overview;
            if (!url) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة النظرة العامة' });
            }

            return request('GET', withQuery(url, params || {})).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, data: r.json.data, message: r.message };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 0 ? 'network' : 'api_error',
                    message: r.message || 'تعذّر جلب نظرة عامة المحاسبة'
                };
            });
        }

        /**
         * بحث العملاء بالاسم/الهاتف — GET .../accounting/customers?search=.
         *
         * يُستخدم في نقطة البيع لربط الفاتورة بعميل **حقيقي** بدل اسم نصّي:
         * الـBackend يحتاج `customer_id` ليزيد دين العميل عند البيع الآجل.
         * اسم نصّي بلا id ينتج فاتورة آجلة بلا مدين — دين يضيع بلا أثر.
         */
        function searchCustomers(query) {
            var url = endpoints().customersIndex;
            var q = String(query || '').trim();
            if (!url || q.length < 2) {
                return Promise.resolve({ ok: true, data: [] });
            }

            return request('GET', withQuery(url, { search: q, per_page: 8 })).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, data: Array.isArray(r.json.data) ? r.json.data : [] };
                }
                return { ok: false, status: r.status, data: [], message: r.message || '' };
            });
        }

        /* ─────────────── المصروفات ─────────────── */

        function listExpenses(params) {
            var url = endpoints().expensesIndex;
            if (!url) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة المصروفات', data: [] });
            }

            return request('GET', withQuery(url, params || {})).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return {
                        ok: true,
                        data: Array.isArray(r.json.data) ? r.json.data : [],
                        pagination: r.json.pagination || null,
                        stats: r.json.stats || null,
                        message: r.message
                    };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 0 ? 'network' : 'api_error',
                    message: r.message || 'تعذّر جلب المصروفات',
                    data: []
                };
            });
        }

        function createExpense(payload) {
            var url = endpoints().expensesCreate;
            if (!url) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة حفظ المصروف' });
            }

            return request('POST', url, payload).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, expense: r.json.data, message: r.message };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 422 ? 'validation' : (r.status === 0 ? 'network' : 'api_error'),
                    message: r.message || 'تعذّر حفظ المصروف',
                    errors: (r.json && r.json.errors) || null
                };
            });
        }

        function cancelExpense(id) {
            var base = endpoints().expensesCancel;
            if (!base) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة الإلغاء' });
            }

            var url = base.replace('__ID__', encodeURIComponent(String(id)));

            return request('POST', url).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, expense: r.json.data, message: r.message };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 0 ? 'network' : 'api_error',
                    message: r.message || 'تعذّر إلغاء المصروف'
                };
            });
        }

        function expenseCategories() {
            var url = endpoints().expenseCategories;
            if (!url) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة التصنيفات', data: [] });
            }

            return request('GET', url).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, data: Array.isArray(r.json.data) ? r.json.data : [], message: r.message };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 0 ? 'network' : 'api_error',
                    message: r.message || 'تعذّر جلب التصنيفات',
                    data: []
                };
            });
        }

        /* ─────────────── العملاء والموردون ─────────────── */

        function listCustomers(params) {
            var url = endpoints().customersIndex;
            if (!url) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة العملاء', data: [] });
            }

            return request('GET', withQuery(url, params || {})).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return {
                        ok: true,
                        data: Array.isArray(r.json.data) ? r.json.data : [],
                        pagination: r.json.pagination || null,
                        stats: r.json.stats || null,
                        message: r.message
                    };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 0 ? 'network' : 'api_error',
                    message: r.message || 'تعذّر جلب العملاء',
                    data: []
                };
            });
        }

        function createCustomer(payload) {
            var url = endpoints().customersCreate;
            if (!url) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة إضافة العميل' });
            }

            return request('POST', url, payload).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, customer: r.json.data, message: r.message };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 422 ? 'validation' : (r.status === 0 ? 'network' : 'api_error'),
                    message: r.message || 'تعذّر إضافة العميل',
                    errors: (r.json && r.json.errors) || null
                };
            });
        }

        function customerPayment(id, payload) {
            var base = endpoints().customersPayment;
            if (!base) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة تسجيل الدفعة' });
            }

            var url = base.replace('__ID__', encodeURIComponent(String(id)));

            return request('POST', url, payload).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, data: r.json.data, message: r.message };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 422 ? 'validation' : (r.status === 0 ? 'network' : 'api_error'),
                    message: r.message || 'تعذّر تسجيل الدفعة',
                    errors: (r.json && r.json.errors) || null
                };
            });
        }

        function listSuppliers(params) {
            var url = endpoints().suppliersIndex;
            if (!url) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة الموردين', data: [] });
            }

            return request('GET', withQuery(url, params || {})).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return {
                        ok: true,
                        data: Array.isArray(r.json.data) ? r.json.data : [],
                        stats: r.json.stats || null,
                        message: r.message
                    };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 0 ? 'network' : 'api_error',
                    message: r.message || 'تعذّر جلب الموردين',
                    data: []
                };
            });
        }

        function createSupplier(payload) {
            var url = endpoints().suppliersCreate;
            if (!url) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة إضافة المورّد' });
            }

            return request('POST', url, payload).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, supplier: r.json.data, message: r.message };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 422 ? 'validation' : (r.status === 0 ? 'network' : 'api_error'),
                    message: r.message || 'تعذّر إضافة المورّد',
                    errors: (r.json && r.json.errors) || null
                };
            });
        }

        function supplierPayment(id, payload) {
            var base = endpoints().suppliersPayment;
            if (!base) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة الدفعة' });
            }

            var url = base.replace('__ID__', encodeURIComponent(String(id)));

            return request('POST', url, payload).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, data: r.json.data, message: r.message };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 422 ? 'validation' : (r.status === 0 ? 'network' : 'api_error'),
                    message: r.message || 'تعذّر تسجيل الدفعة',
                    errors: (r.json && r.json.errors) || null
                };
            });
        }

        /* ─────────────── الصندوق ─────────────── */

        function cash(params) {
            var url = endpoints().cashIndex;
            if (!url) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة الصندوق', data: [] });
            }

            return request('GET', withQuery(url, params || {})).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return {
                        ok: true,
                        data: Array.isArray(r.json.data) ? r.json.data : [],
                        pagination: r.json.pagination || null,
                        stats: r.json.stats || null,
                        period: r.json.period || null,
                        message: r.message
                    };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 0 ? 'network' : 'api_error',
                    message: r.message || 'تعذّر جلب حركات الصندوق',
                    data: []
                };
            });
        }

        function cashAdjustment(payload) {
            var url = endpoints().cashAdjust;
            if (!url) {
                return Promise.resolve({ ok: false, reason: 'not_configured', message: 'لم تُهيَّأ نقطة حركة الصندوق' });
            }

            return request('POST', url, payload).then(function (r) {
                if (r.ok && r.json && r.json.success) {
                    return { ok: true, data: r.json.data, message: r.message };
                }
                return {
                    ok: false,
                    status: r.status,
                    reason: r.status === 422 ? 'validation' : (r.status === 0 ? 'network' : 'api_error'),
                    message: r.message || 'تعذّر تسجيل حركة الصندوق',
                    errors: (r.json && r.json.errors) || null
                };
            });
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

        /**
         * يوحّد شكل نتيجة البحث إلى شكل السلة الموحّد.
         *
         * ⚠️ `/api/medicines/search` يبحث في **الكتالوج العام**، فمعرّفه
         * `medicines.id` وليس سطر مخزون. النتيجة تُوسم `in_inventory:false`
         * ما لم يوجد ربط محلي — لأن البيع من الكتالوج بلا مخزون لا يخصم شيئًا،
         * وعرضه كـ«متوفر» يوهم الكاشير بمخزون غير موجود.
         */
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
                var medId = r.medicine_id != null ? r.medicine_id
                    : (r.id != null ? r.id : null);

                // اربط بصف المخزون إن كان البحث يعيد معرّف مخزون صريحًا
                var pmId = r.pharmacy_medicine_id != null ? r.pharmacy_medicine_id : null;
                var local = null;

                if (pmId === null && medId !== null) {
                    var list = catalog();
                    for (var i = 0; i < list.length; i++) {
                        if (list[i].medicine_id != null
                            && String(list[i].medicine_id) === String(medId)) {
                            local = list[i];
                            break;
                        }
                    }
                }

                return {
                    medicine_id: medId,
                    pharmacy_medicine_id: pmId !== null ? pmId : (local ? local.id : null),
                    id: local ? local.id : medId,
                    barcode: r.barcode || r.bar_code || '',
                    trade_name: r.trade_name || r.trade_name_ar || r.name || '',
                    active_ingredient: r.active_ingredient || r.generic_name || '',
                    price: local ? Number(local.price)
                        : (r.official_price != null ? Number(r.official_price)
                            : (r.price != null ? Number(r.price) : 0)),
                    quantity: local ? local.quantity : null,
                    in_inventory: !!local,
                    from_catalog: true
                };
            });
        }

        /**
         * يوحّد شكل نتيجة /api/medicines/barcode/{code}.
         *
         * ── محوران مختلفان — لا تخلط ────────────────────────────────────
         * `data.medicine.local_medicine_id` هو **`medicines.id`**
         * (الكتالوج العام)، بينما `cfg.catalog` صفوف **مخزون الصيدلية**
         * (`pharmacy_medicines`) بمفتاحها الخاص + `medicine_id`.
         *
         * ⚠️ مقارنة `local_medicine_id` بـ`catalog[].id` كانت خطأ صامتًا:
         * المعرّفان من جدولين مستقلين، فالمطابقة تفشل دائمًا ⇒ `in_inventory`
         * يصير false ⇒ الواجهة تمنع بيع دواء موجود فعلًا في الرف.
         * المطابقة الصحيحة على `catalog[].medicine_id`.
         */
        function mapBarcodeResult(data) {
            var med = (data && data.medicine) || {};
            var medicineId = med.local_medicine_id != null ? med.local_medicine_id : null;

            // اربط بصف المخزون عبر `medicine_id` (المحور الصحيح)
            var local = null;
            if (medicineId != null) {
                var list = catalog();
                for (var i = 0; i < list.length; i++) {
                    if (list[i].medicine_id != null
                        && String(list[i].medicine_id) === String(medicineId)) {
                        local = list[i];
                        break;
                    }
                }
            }

            return {
                // معرّف الدواء في الكتالوج العام
                medicine_id: medicineId != null ? medicineId : med.id,
                // معرّف سطر المخزون — **هو ما يخصمه الـBackend**
                pharmacy_medicine_id: local ? local.id : null,
                // `id` يبقى سطر المخزون إن وُجد (توافقًا مع بقية الواجهة)
                id: local ? local.id : (medicineId != null ? medicineId : med.id),
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
            // البحث
            searchMedicines: searchMedicines,
            lookupBarcode: lookupBarcode,

            // المبيعات
            createSale: createSale,
            listSales: listSales,
            getSale: getSale,
            cancelSale: cancelSale,
            salesSummary: salesSummary,

            // النظرة العامة
            overview: overview,

            // المصروفات
            listExpenses: listExpenses,
            createExpense: createExpense,
            cancelExpense: cancelExpense,
            expenseCategories: expenseCategories,

            // الأطراف
            listCustomers: listCustomers,
            searchCustomers: searchCustomers,
            createCustomer: createCustomer,
            customerPayment: customerPayment,
            listSuppliers: listSuppliers,
            createSupplier: createSupplier,
            supplierPayment: supplierPayment,

            // الصندوق
            cash: cash,
            cashAdjustment: cashAdjustment
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
