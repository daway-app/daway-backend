/**
 * Daway Accounting — منطق الباركود (barcode-aware UX)
 * ==========================================================
 * ⚠️ المبدأ الحاكم لهذا الملف:
 *
 *   **قاعدة `moh_medicines` لا تحتوي باركودًا كاملًا.**
 *   التغطية تُبنى تدريجيًا من الصيدليات نفسها ⇒ «باركود غير معروف» حالة
 *   طبيعية يمرّ بها الصيدلي كل يوم، **وليست خطأ**.
 *
 * ومن هنا تنبع كل القرارات التصميمية أدناه:
 *
 *   1) البحث والمسح **مساران متساويان** لإضافة دواء — لا «بحث أساسي ومسح احتياطي».
 *   2) باركود مجهول ⇒ نفتح **مسار ربط بدواء موجود** (بناء تغطية)، لا نافذة فشل.
 *   3) التعارض ⇒ **لا نتجاوزه أبدًا**؛ قواعد الباك-إند (`barcode` فريد عالميًا)
 *      هي المرجع، ونعرض قرارًا بشريًا فقط.
 *   4) بعد الربط نقول **«تم حفظ ربط الباركود»** ولا نوعد بمزامنة لم تحدث —
 *      `medicine_barcodes` لا يحتوي `pharmacy_id`، فلا يمكن نسب الإضافة لصيدلية
 *      ولا الجزم بأنها ستراها كل الصيدليات.
 *   5) **صفر endpoints مُختلَقة**: لا `POST /api/barcodes/link`. الربط معطّل
 *      بوضوح حتى يوجد الـendpoint فعلًا.
 *
 * ## قارئات الباركود = لوحة مفاتيح
 * قارئ USB يكتب الأرقام ثم يرسل `Enter`. لذلك:
 *   - نستمع لـ`keydown` Enter على حقل الباركود (لا نعتمد على `input` فقط).
 *   - نمنع `submit` الافتراضي للنموذج حتى لا تُرسَل الصفحة.
 *   - نجمّع الضغطات السريعة المتتالية (الماسح يكتب ~10ms/حرف) لنمنع مسحًا مزدوجًا.
 *
 * يُحمَّل **بعد** accounting-shared.js (يستخدم AccountingApi و AccountingUtil).
 * وهو **المالك الوحيد** لسلوك الباركود: لا تكتب منطق مسح في ملف صفحة أخرى.
 */
(function () {
    'use strict';

    /* ======================================================
       1) مفردات الحالة — مرآة `App\Support\Accounting\BarcodeStatus`
       ======================================================
       ⚠️ الأسماء يجب أن تبقى مطابقة للـPHP حرفيًا. لو أضفت حالة هناك،
       أضفها هنا وإلا سقطت الواجهة إلى `unknown` (وهذا مقبول لكن غير دقيق). */
    var STATUS = {
        UNKNOWN: 'unknown',
        PENDING: 'pending',
        VERIFIED: 'verified',
        CONFLICT: 'conflict'
    };

    /** نصّ الحالة — يأتي من Blade (window.acBarcodeI18n) لا مكتوبًا هنا */
    function i18n() {
        return window.acBarcodeI18n || {};
    }

    function statusLabel(status) {
        var i = i18n();
        return (i.status && i.status[status]) || status;
    }

    function statusHint(status) {
        var i = i18n();
        return (i.status_hint && i.status_hint[status]) || '';
    }

    /** حالة قابلة للبيع مباشرة؟ (التوثيق يخصّ جودة البيانات لا صلاحية البيع) */
    function isSellable(status) {
        return status === STATUS.PENDING || status === STATUS.VERIFIED;
    }

    /* ======================================================
       2) تجميع ضغطات الماسح (scanner keystroke coalescing)
       ======================================================
       الماسح يكتب الحروف بسرعة (~10ms) ثم Enter. المستخدم البشري يكتب أبطأ.
       لكن لو ضغط المستخدم Enter مرتين بسرعة على رقم واحد، يجب ألا نرسل
       طلبين ونضيف سطرين. نستخدم نافذة زمنية قصيرة لكل عملية مسح. */
    var lastScan = { code: '', at: 0 };
    var DUPLICATE_WINDOW_MS = 900;

    function isDuplicateScan(code) {
        var now = Date.now();
        if (lastScan.code === code && (now - lastScan.at) < DUPLICATE_WINDOW_MS) {
            return true;
        }
        lastScan.code = code;
        lastScan.at = now;
        return false;
    }

    /* ======================================================
       3) حوارات الباركود — فتح/إغلاق بوصولية صحيحة
       ====================================================== */
    var lastFocused = null;

    function openModal(selector) {
        var modal = document.querySelector(selector);
        if (!modal) {
            return null;
        }
        lastFocused = document.activeElement;
        modal.classList.add('active');
        document.body.classList.add('ph-modal-open');

        // التركيز على أول عنصر مفيد — WCAG 2.4.3 (ترتيب التركيز)
        var focusable = modal.querySelector('[data-link-search], input, select, textarea, button');
        if (focusable) {
            setTimeout(function () { focusable.focus(); }, 60);
        }
        return modal;
    }

    function closeModal(modal) {
        var el = typeof modal === 'string' ? document.querySelector(modal) : modal;
        if (!el) {
            return;
        }
        el.classList.remove('active');
        document.body.classList.remove('ph-modal-open');

        // إعادة التركيز لمصدر الفتح — ضروري لمستخدمي لوحة المفاتيح
        if (lastFocused && typeof lastFocused.focus === 'function') {
            try { lastFocused.focus(); } catch (e) { /* عنصر أُزيل من DOM */ }
        }
        lastFocused = null;
    }

    // Esc يُغلق أي حوار باركود مفتوح + النقر على الخلفية
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }
        ['[data-barcode-link-modal]', '[data-barcode-conflict-modal]'].forEach(function (sel) {
            var m = document.querySelector(sel + '.active');
            if (m) {
                closeModal(m);
            }
        });
    });

    document.addEventListener('click', function (e) {
        var overlay = e.target.closest && e.target.closest('.ac-modal-overlay');
        if (overlay && e.target === overlay) {
            closeModal(overlay);
        }
    });

    /* ======================================================
       4) نافذة الربط — المسار الطبيعي للباركود المجهول
       ======================================================
       ⚠️ لا يوجد endpoint. لأن الربط معطّل من الأساس، لا نُرسل شيئًا.
       عند توفّر `POST /api/.../barcodes/link`:
         - مرّر `data-endpoint` على مكوّن النافذة،
         - وابنِ الطلب هنا في `saveLink()` فقط. */
    var linkState = { barcode: '', medicine: null, onSaved: null };

    function openLinkModal(barcode, onSaved) {
        var modal = document.querySelector('[data-barcode-link-modal]');
        if (!modal) {
            return;
        }

        linkState.barcode = barcode;
        linkState.medicine = null;
        linkState.onSaved = typeof onSaved === 'function' ? onSaved : null;

        var codeEl = modal.querySelector('[data-link-barcode]');
        if (codeEl) {
            codeEl.textContent = barcode || '—';
        }

        var search = modal.querySelector('[data-link-search]');
        if (search) {
            search.value = '';
        }

        var results = modal.querySelector('[data-link-results]');
        if (results) {
            results.innerHTML = '<div class="ac-link-placeholder">'
                + escapeHtml(i18n().link_search_empty || '') + '</div>';
        }

        var chosen = modal.querySelector('[data-link-chosen]');
        if (chosen) {
            chosen.hidden = true;
        }

        openModal('[data-barcode-link-modal]');
    }

    /** شكل صفّ نتيجة البحث داخل نافذة الربط */
    function linkResultRow(item) {
        var name = item.trade_name || item.name || '—';
        var ing = item.active_ingredient || '';

        return '<button type="button" class="ac-link-result" data-link-pick'
            + ' data-id="' + escapeAttr(item.id) + '"'
            + ' data-name="' + escapeAttr(name) + '">'
            + '<span class="ac-link-result-name" dir="auto">' + escapeHtml(name) + '</span>'
            + (ing ? '<span class="ac-link-result-ing" dir="auto">' + escapeHtml(ing) + '</span>' : '')
            + '</button>';
    }

    function renderLinkResults(items) {
        var modal = document.querySelector('[data-barcode-link-modal]');
        if (!modal) {
            return;
        }
        var box = modal.querySelector('[data-link-results]');
        if (!box) {
            return;
        }

        if (!items || !items.length) {
            box.innerHTML = '<div class="ac-link-placeholder">'
                + escapeHtml(i18n().link_no_results || '') + '</div>';
            return;
        }

        box.innerHTML = items.map(linkResultRow).join('');
    }

    function bindLinkModal() {
        var modal = document.querySelector('[data-barcode-link-modal]');
        if (!modal || modal.getAttribute('data-bound') === '1') {
            return;
        }
        modal.setAttribute('data-bound', '1');

        var input = modal.querySelector('[data-link-search]');

        // البحث داخل النافذة — يستخدم نفس /api/medicines/search
        if (input) {
            input.addEventListener('input', AccountingUtil.debounce(function () {
                var q = input.value.trim();
                if (q.length < 2) {
                    renderLinkResults([]);
                    return;
                }
                AccountingApi.searchMedicines(q).then(function (res) {
                    renderLinkResults(res && res.data ? res.data : []);
                });
            }, 280));
        }

        // اختيار دواء
        modal.addEventListener('click', function (e) {
            var pick = e.target.closest && e.target.closest('[data-link-pick]');
            if (pick) {
                linkState.medicine = {
                    id: pick.getAttribute('data-id'),
                    name: pick.getAttribute('data-name')
                };
                var chosen = modal.querySelector('[data-link-chosen]');
                var nameEl = modal.querySelector('[data-link-chosen-name]');
                if (nameEl) {
                    nameEl.textContent = linkState.medicine.name;
                }
                if (chosen) {
                    chosen.hidden = false;
                }
                return;
            }

            if (e.target.closest && e.target.closest('[data-link-clear]')) {
                linkState.medicine = null;
                var chosenBox = modal.querySelector('[data-link-chosen]');
                if (chosenBox) {
                    chosenBox.hidden = true;
                }
                return;
            }

            if (e.target.closest && e.target.closest('[data-barcode-link-cancel]')) {
                closeModal(modal);
                return;
            }

            if (e.target.closest && e.target.closest('[data-barcode-link-save]')) {
                saveLink(modal);
            }
        });
    }

    /**
     * حفظ الربط.
     *
     * ⚠️ **لا endpoint ⇒ لا إرسال.** الرسالة تشرح ذلك صراحةً بدل إيهام
     * المستخدم بحفظ لم يحدث.
     *
     * ⚠️ ونصّ النجاح (`link_saved`) **محايد عن قصد**: لا نقول «تمت إضافته
     * لمخزونك» ولا «سيراه كل الصيدليات» — المخطط لا يدعم أيًّا من الادّعاءين.
     */
    function saveLink(modal) {
        if (!modal) {
            return;
        }

        var endpoint = modal.getAttribute('data-endpoint');

        if (!linkState.medicine) {
            flashInModal(modal, i18n().link_need_choice || '', 'warn');
            return;
        }

        if (!endpoint) {
            // النافذة تعرض تنبيهًا دائمًا بهذا المعنى — لا نكرّره كنافذة خطأ.
            flashInModal(modal, i18n().link_unavailable_body || '', 'warn');
            return;
        }

        // مسار مستقبلي — يُبنى فقط عند وجود endpoint حقيقي
        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken()
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                barcode: linkState.barcode,
                medicine_id: linkState.medicine.id
            })
        })
            .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, json: j }; }); })
            .then(function (r) {
                if (r.ok && r.json && r.json.success !== false) {
                    closeModal(modal);
                    if (linkState.onSaved) {
                        linkState.onSaved(linkState.barcode, linkState.medicine);
                    }
                    return;
                }
                // تعارض من الباك-إند ⇒ نعرض حوار التعارض ولا نتجاوز
                openConflictModal(
                    linkState.barcode,
                    (r.json && r.json.data && r.json.data.existing_name) || '—',
                    linkState.medicine.name
                );
            })
            .catch(function () {
                flashInModal(modal, i18n().lookup_failed || '', 'error');
            });
    }

    function flashInModal(modal, text, type) {
        var box = modal.querySelector('.ac-modal-flash');
        if (!box) {
            box = document.createElement('p');
            box.className = 'ac-modal-flash';
            box.setAttribute('role', 'status');
            var body = modal.querySelector('.ph-modal-body');
            if (body) {
                body.appendChild(box);
            }
        }
        box.className = 'ac-modal-flash is-' + (type || 'info');
        box.textContent = text;
    }

    /* ======================================================
       5) حوار التعارض — لا تجاوز لقواعد الباك-إند
       ====================================================== */
    function openConflictModal(barcode, existingName, attemptedName) {
        var modal = document.querySelector('[data-barcode-conflict-modal]');
        if (!modal) {
            return;
        }

        var codeEl = modal.querySelector('[data-conflict-barcode]');
        if (codeEl) {
            codeEl.textContent = barcode || '—';
        }

        var existingEl = modal.querySelector('[data-conflict-existing]');
        if (existingEl) {
            existingEl.textContent = existingName || '—';
        }

        openModal('[data-barcode-conflict-modal]');
    }

    function bindConflictModal() {
        var modal = document.querySelector('[data-barcode-conflict-modal]');
        if (!modal || modal.getAttribute('data-bound') === '1') {
            return;
        }
        modal.setAttribute('data-bound', '1');

        modal.addEventListener('click', function (e) {
            if (!e.target.closest) {
                return;
            }
            if (e.target.closest('[data-conflict-cancel]')) {
                closeModal(modal);
                return;
            }
            if (e.target.closest('[data-conflict-review]')) {
                // «مراجعة» لا تحلّ التعارض — تقود إلى سياق القرار فقط.
                closeModal(modal);
                notify(i18n().conflict_review_note || '', 'info');
            }
        });
    }

    /* ======================================================
       6) قلب النظام: حلّ باركود واحد ⇒ نتائج مفهومة
       ======================================================
       هذه هي الدالة التي تستهلكها كل الصفحات (نقطة البيع · المخزون · المشتريات).

       تُرجع Promise بـ:
         { status, barcode, item|null, raw }
       حيث status ∈ unknown | pending | verified | conflict

       ⚠️ مهم: `unknown` تُرجع **نجاحًا** (`ok` ضمني) لا فشلًا — لأنها حالة
       طبيعية. الصفحة هي من تقرّر بعدها: تضيف للسلة (pending/verified) أو
       تفتح مسار الربط (unknown) أو حوار التعارض (conflict). */
    function resolveBarcode(rawCode, options) {
        options = options || {};

        var res = AccountingApi.lookupBarcode(rawCode).then(function (r) {
            if (r.ok && r.item) {
                // الباك-إند أعاد دواءً ⇒ نستنتج التوثيق من الحقول الحقيقية
                var verified = r.item.is_verified === true
                    || r.item.verification_status === 'verified';
                var pendingReview = r.item.verification_status === 'pending';
                var status = verified ? STATUS.VERIFIED
                    : (pendingReview ? STATUS.PENDING : STATUS.PENDING);

                // توثيق غير مؤكَّد من الباك-إند ⇒ نُبقي `pending` (لا ندّعي توثيقًا)
                if (r.item.is_verified == null && r.item.verification_status == null) {
                    status = STATUS.PENDING;
                }

                return {
                    status: status,
                    barcode: r.barcode ? r.barcode.value : AccountingUtil.normalizeBarcode(rawCode).value,
                    item: r.item,
                    source: r.source
                };
            }

            // لم نجد ⇒ حالة طبيعية، ليست خطأ
            var norm = AccountingUtil.normalizeBarcode(rawCode);

            if (r.reason === 'invalid') {
                // مدخل غير صالح شكلًا = خطأ إدخال حقيقي (يختلف عن «غير مسجَّل»)
                return {
                    status: 'invalid',
                    barcode: '',
                    item: null,
                    reason: r.detail || 'invalid'
                };
            }

            return {
                status: STATUS.UNKNOWN,
                barcode: norm.ok ? norm.value : String(rawCode || ''),
                item: null,
                source: r.reason || 'not_found'
            };
        });

        return res;
    }

    /* ======================================================
       7) ربط الأزرار و الحقول تلقائيًا داخل أي صفحة
       ====================================================== */
    /**
     * تهيئة سلوك المسح داخل حاوية.
     *
     * @param {Element} root
     * @param {{ onResolved: function, onUnknown: function, onConflict: function }} handlers
     */
    function initScanner(root, handlers) {
        root = root || document;
        handlers = handlers || {};

        // زر المسح ⇒ يركّز الحقل (يعمل مع قارئ USB) — والمسار نفسه للبحث اليدوي
        root.querySelectorAll('[data-barcode-scan]').forEach(function (btn) {
            if (btn.getAttribute('data-scan-bound') === '1') {
                return;
            }
            btn.setAttribute('data-scan-bound', '1');

            btn.addEventListener('click', function () {
                var sel = btn.getAttribute('data-scan-target');
                var input = sel ? document.getElementById(sel) : root.querySelector('[data-barcode-input]');
                if (input) {
                    input.focus();
                    input.select();
                }
            });
        });

        root.querySelectorAll('[data-barcode-input]').forEach(function (input) {
            if (input.getAttribute('data-scan-bound') === '1') {
                return;
            }
            input.setAttribute('data-scan-bound', '1');

            // Enter من الماسح (أو من المستخدم) ⇒ يحلّ الباركود مباشرة
            input.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') {
                    return;
                }
                e.preventDefault();   // لا نُرسل النموذج — المسح ليس submit
                e.stopPropagation();
                runScan(input, root, handlers);
            });

            // زر تفريغ اختياري داخل نفس الحاوية
            var wrap = input.closest('.ac-barcode-wrap');
            if (wrap) {
                var clearBtn = wrap.querySelector('[data-barcode-clear]');
                if (clearBtn && clearBtn.getAttribute('data-scan-bound') !== '1') {
                    clearBtn.setAttribute('data-scan-bound', '1');
                    clearBtn.addEventListener('click', function () {
                        input.value = '';
                        setFieldState(wrap, STATUS.UNKNOWN, '');
                        input.focus();
                    });
                }
            }
        });
    }

    /** ينفّذ مسحًا واحدًا ويمرّر النتيجة للمعالجات */
    function runScan(input, root, handlers) {
        var raw = input.value;

        if (!String(raw || '').trim()) {
            notify(i18n().empty_code || '', 'warn');
            return;
        }

        var norm = AccountingUtil.normalizeBarcode(raw);
        if (!norm.ok) {
            setFieldState(input.closest('.ac-barcode-wrap'), 'invalid', '');
            notify(i18n().empty_code || '', 'error');
            return;
        }

        if (isDuplicateScan(norm.value)) {
            return; // ضغطتان متتاليتان على نفس الرقم — نتجاهل الثانية
        }

        setFieldState(input.closest('.ac-barcode-wrap'), 'loading', '');

        resolveBarcode(norm.value).then(function (result) {
            setFieldState(input.closest('.ac-barcode-wrap'), result.status, result.barcode);

            if (result.status === STATUS.UNKNOWN) {
                // ⚠️ المسار الطبيعي — نفتح الربط، ولا نُظهر خطأ
                if (handlers.onUnknown) {
                    handlers.onUnknown(result, input);
                } else {
                    openLinkModal(result.barcode, function () {
                        notify(i18n().link_saved || '', 'success');
                    });
                }
                return;
            }

            if (result.status === STATUS.CONFLICT) {
                if (handlers.onConflict) {
                    handlers.onConflict(result, input);
                } else {
                    openConflictModal(result.barcode, '—', '');
                }
                return;
            }

            if (handlers.onResolved) {
                handlers.onResolved(result, input);
            }
        });
    }

    /** يحدّث مؤشّر الحالة بجوار الحقل (نصّ + صنف) */
    function setFieldState(wrap, status, code) {
        if (!wrap) {
            return;
        }

        // الحقل نفسه يحمل الصنف لتلوين الحدّ
        var input = wrap.querySelector('[data-barcode-input]');
        if (input) {
            var families = ['is-unknown', 'is-pending', 'is-verified', 'is-conflict', 'is-loading', 'is-invalid'];
            families.forEach(function (c) { input.classList.remove(c); });
            input.classList.add('is-' + status);
        }

        var state = wrap.querySelector('[data-barcode-state]');
        if (!state) {
            return;
        }

        state.setAttribute('data-barcode-status', status);
        state.className = 'ac-barcode-state is-' + status;

        if (status === 'loading') {
            state.textContent = i18n().looking || '';
        } else if (status === 'invalid') {
            state.textContent = i18n().empty_code || '';
        } else if (statusLabel(status)) {
            state.textContent = statusLabel(status);
        } else {
            state.textContent = '';
        }
    }

    /* ======================================================
       8) إشعار عائم — لأحداث المسح/الربط
       ====================================================== */
    var notifyTimer = null;

    function notify(text, type) {
        if (!text) {
            return;
        }
        var host = document.getElementById('ac-barcode-toast');
        if (!host) {
            host = document.createElement('div');
            host.id = 'ac-barcode-toast';
            host.className = 'ac-toast-host';
            host.setAttribute('role', 'status');
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }

        host.className = 'ac-toast-host show is-' + (type || 'info');
        host.textContent = text;

        clearTimeout(notifyTimer);
        notifyTimer = setTimeout(function () {
            host.className = 'ac-toast-host';
        }, 3200);
    }

    /* ======================================================
       9) أدوات نصية — لا innerHTML بمدخلات المستخدم
       ====================================================== */
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escapeAttr(s) {
        return escapeHtml(s).replace(/`/g, '&#96;');
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /* ======================================================
       10) التهيئة العامة
       ====================================================== */
    function init() {
        bindLinkModal();
        bindConflictModal();
        // الفحص العام: أي حقل باركود في الصفحة يعمل بلا كود إضافي
        initScanner(document, {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* ------------------------------------------------------
       التصدير — للاستخدام من ملفات الصفحات والاختبارات
       ------------------------------------------------------ */
    window.AccountingBarcode = {
        STATUS: STATUS,
        statusLabel: statusLabel,
        statusHint: statusHint,
        isSellable: isSellable,
        isDuplicateScan: isDuplicateScan,
        resolveBarcode: resolveBarcode,
        initScanner: initScanner,
        runScan: runScan,
        setFieldState: setFieldState,
        openLinkModal: openLinkModal,
        openConflictModal: openConflictModal,
        closeModal: closeModal,
        notify: notify,
        escapeHtml: escapeHtml,

        // للاختبارات: تصفير نافذة منع المسح المزدوج
        __resetScanWindow: function () {
            lastScan = { code: '', at: 0 };
        }
    };
})();
