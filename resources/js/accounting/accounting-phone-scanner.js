/**
 * Daway Accounting — شريحة المسح بالهاتف (Phone Scanner slide)
 * ======================================================================
 * تربط `window.AccountingScanSession` (آلة الحالة) بالـDOM، وتُسلّم الباركود
 * الوارد إلى **نفس** محرّك الباركود المستخدم للمسح المحلي وقارئ USB:
 *
 *   phone  →  AccountingScanSession  →  AccountingBarcode.resolveBarcode()
 *          →  known / unknown / conflict  →  السلة
 *
 * ⚠️ قواعد ثابتة هنا:
 *   1) **لا ننشئ نظام نقطة بيع ثانيًا.** الإضافة للسلة تمرّ إلى POS
 *      عبر `window.__acPosHook.addScannedResult` الذي تمرّره صفحة نقطة البيع.
 *      في أي صفحة أخرى، نكتفي بالبحث والعرض.
 *   2) **لا كاميرا في الويب.** لا `getUserMedia`، ولا صورة، ولا فيديو.
 *      الباركود يصل كنصّ جاهز.
 *   3) **لا polling سريع.** الفاصل داخل طبقة الجلسة (2000ms) ويتوقف عند
 *      إخفاء التبويب وعند غياب الحاجة.
 *   4) **لا نظهر توكنات.** رمز الاقتران + اسم الجهاز + الحالة فقط.
 *   5) **لا حالة معلّقة كاذبة.** عند غياب مسارات الباك-إند نعرض «وضع تجريبي»
 *      ونشتغل بالمحاكاة، ولا نُوهم المستخدم باقتران حقيقي.
 *
 * يُحمَّل آخر ملف: بعد accounting-shared.js و accounting-barcode.js
 * و accounting-scanner-session.js.
 */
(function () {
    'use strict';

    if (!window.AccountingScanSession) {
        return; // طبقة الجلسة غير محمّلة — لا ننكسر، فقط لا نُهيّئ
    }

    var S = window.AccountingScanSession;
    var STATE = S.STATE;

    function i18n() {
        return window.acScannerI18n || {};
    }

    function t(key, fallback) {
        return i18n()[key] || fallback || '';
    }

    var dom = {};
    var controller = null;
    var offChange = null;

    /* ======================================================
       رسم الحالة
       ====================================================== */
    function renderStatus(snap) {
        if (!dom.status) {
            return;
        }

        // الحالة → صنف CSS
        var families = [
            'is-waiting', 'is-connecting', 'is-connected', 'is-scanning',
            'is-received', 'is-disconnected', 'is-expired', 'is-error'
        ];
        families.forEach(function (c) {
            dom.status.classList.remove(c);
        });
        dom.status.classList.add('is-' + snap.status);
        dom.status.setAttribute('data-status', snap.status);

        if (dom.statusLabel) {
            dom.statusLabel.textContent = S.stateLabel(snap.status);
        }

        // مؤقّت الانتهاء — يظهر فقط عندما نعرف الوقت المتبقي
        if (dom.statusTimer) {
            if (snap.secondsRemaining != null && snap.status !== STATE.EXPIRED) {
                dom.statusTimer.hidden = false;
                dom.statusTimer.textContent = formatSeconds(snap.secondsRemaining);
            } else {
                dom.statusTimer.hidden = true;
            }
        }

        // أزرار الحالة — تظهر بحسب الحالة الفعلية فقط
        if (dom.reconnectBtn) {
            dom.reconnectBtn.hidden = snap.status !== STATE.DISCONNECTED
                && snap.status !== STATE.ERROR;
        }
        if (dom.newSessionBtn) {
            dom.newSessionBtn.hidden = snap.status !== STATE.EXPIRED;
        }

        // بطاقة الاقتران تختفي بعد الاتصال — لا QR بلا معنى
        if (dom.pairSection) {
            var showPair = snap.status === STATE.WAITING
                || snap.status === STATE.CONNECTING
                || snap.status === STATE.IDLE;
            dom.pairSection.hidden = !showPair;
        }
    }

    function formatSeconds(s) {
        var n = Math.max(0, Number(s) || 0);
        var m = Math.floor(n / 60);
        var r = n % 60;
        return m + ':' + (r < 10 ? '0' : '') + r;
    }

    function renderPairing(snap) {
        if (dom.pairCode) {
            dom.pairCode.textContent = snap.pairingCode || '—';
        }

        // الـQR: نعرضه فقط إذا جاء من الباك-إند. لا نصنع QR بأنفسنا
        // ولا نرمّز أي توكن. لو غاب، المؤقّت البصري يبقى.
        if (dom.qrPlaceholder && (snap.qrSvg || snap.pairingUrl)) {
            dom.qrPlaceholder.innerHTML = snap.qrSvg
                ? snap.qrSvg
                : '<img src="' + escapeAttr(snap.pairingUrl) + '" alt="'
                  + escapeAttr(t('qr_alt', 'QR')) + '" width="168" height="168">';
            dom.qrPlaceholder.classList.add('is-ready');
        }
    }

    function renderDevices(snap) {
        if (dom.deviceName && snap.device) {
            dom.deviceName.textContent = snap.device.name || '';
        }
        if (dom.deviceRow) {
            dom.deviceRow.hidden = !snap.device;
        }
        if (dom.deviceEmpty) {
            dom.deviceEmpty.hidden = !!snap.device;
        }

        // نقطة «متصل» على زر المسح
        if (dom.scannerDot) {
            dom.scannerDot.hidden = !snap.device
                && snap.status !== STATE.CONNECTED;
        }
    }

    function renderDeviceRequest(snap) {
        if (!dom.deviceRequest) {
            return;
        }
        if (snap.pendingDevice) {
            dom.deviceRequest.hidden = false;
            if (dom.deviceRequestName) {
                dom.deviceRequestName.textContent = snap.pendingDevice.name || '';
            }
        } else {
            dom.deviceRequest.hidden = true;
        }
    }

    function renderReceived(snap) {
        if (!dom.received) {
            return;
        }
        if (snap.lastBarcode && (snap.status === STATE.SCANNING || snap.status === STATE.RECEIVED)) {
            dom.received.hidden = false;
            if (dom.receivedCode) {
                dom.receivedCode.textContent = snap.lastBarcode;
            }
            if (dom.receivedHint) {
                dom.receivedHint.textContent = S.stateLabel(snap.status);
            }
        } else if (snap.status !== STATE.SCANNING) {
            dom.received.hidden = true;
        }
    }

    /** طابور المسح — صفوف مرقّمة بالترتيب، الحالي مميّز */
    function renderQueue(snap) {
        if (!dom.queue || !dom.queueList) {
            return;
        }
        var len = snap.queueLength || 0;
        if (len === 0) {
            dom.queue.hidden = true;
            dom.queueList.innerHTML = '';
            return;
        }
        dom.queue.hidden = false;

        var html = '';
        // الباركود الحالي في المقدمة، ثم المنتظرون
        html += '<li class="ac-queue-item is-current">→ '
            + escapeHtml(snap.lastBarcode || '') + '</li>';
        for (var i = 0; i < len; i++) {
            html += '<li class="ac-queue-item">' + escapeHtml(String(i + 1)) + ' · …</li>';
        }
        dom.queueList.innerHTML = html;
    }

    function renderAll(snap) {
        renderStatus(snap);
        renderPairing(snap);
        renderDevices(snap);
        renderDeviceRequest(snap);
        renderReceived(snap);
        renderQueue(snap);
    }

    /* ======================================================
       الباركود الوارد ⇒ محرّك الباركود المشترك
       ======================================================
       ⚠️ مهم: نستخدم `AccountingBarcode.resolveBarcode()` — نفس الدالة التي
       يستخدمها المسح المحلي. فلا يوجد «مسار هاتف» منفصل بمنطق مختلف، ولا
       احتمال أن تختلف نتيجة مسح الهاتف عن مسح USB لنفس الرقم.
       ====================================================== */
    function handleIncomingBarcode(barcode) {
        // ⚠️ نُسمّي المرجع مرة واحدة. قبل ذلك كان الفحص على
        // `window.AccountingBarcode` ثم الاستعمال بـ`AccountingBarcode` المجرّد
        // (سطر 216 وما بعده) — وهو **ReferenceError** لا undefined، فلو فشل
        // تحميل الملف لا يُنقَذ السطر بل يسقط المسار كله.
        var B = window.AccountingBarcode;

        if (!B || typeof B.resolveBarcode !== 'function') {
            // بلا محرّك باركود: نمرّر كما هو لأي مستهلك مسجَّل
            deliver(barcode, { status: 'pending', barcode: barcode, item: null });
            return;
        }

        B.resolveBarcode(barcode).then(function (result) {
            // نُبلّغ الحالة على الواجهة وفق نتيجة الحلّ الفعلية
            if (result.status === 'unknown') {
                B.openLinkModal(result.barcode, function () {
                    B.notify(t('link_saved', ''), 'success');
                });
                finish('unknown');
                return;
            }

            if (result.status === 'conflict') {
                B.openConflictModal(result.barcode, '—', '');
                finish('conflict');
                return;
            }

            if (result.status === 'invalid') {
                B.notify(
                    (window.acBarcodeI18n || {}).empty_code || '',
                    'error'
                );
                finish('invalid');
                return;
            }

            // معروف ⇒ نصعد الحالة إلى «تم الاستلام» ثم نسلّمه للسلة.
            // ⚠️ عبر `markReceived()` لا بكتابة مباشرة على `controller.state`،
            // وإلا لم يُطلَق حدث `state` وتجمّدت شارة الحالة والطابور.
            if (controller && typeof controller.markReceived === 'function') {
                controller.markReceived(result.barcode);
            }
            renderAll(controller ? controller.snapshot() : {});

            deliver(result.barcode, result);
            finish('resolved');
        });
    }

    /** يسلّم النتيجة إلى السلة إن كانت الصفحة توفّر خطّافًا */
    function deliver(barcode, result) {
        // نفس السبب: مرجع واحد بـ`window.` لا اسم مجرّد.
        var B = window.AccountingBarcode;

        if (typeof window.__acPosHook === 'object'
            && window.__acPosHook
            && typeof window.__acPosHook.addScannedResult === 'function') {
            window.__acPosHook.addScannedResult(result);
            if (B && typeof B.notify === 'function') {
                B.notify((window.acBarcodeI18n || {}).found_added || '', 'success');
            }
            return;
        }

        // لا خطّاف (صفحة غير نقطة البيع) ⇒ نكتفي بالإشعار
        if (B && typeof B.notify === 'function') {
            B.notify((window.acBarcodeI18n || {}).found_title || '', 'success');
        }
    }

    /** يُعلِم آلة الحالة أننا انتهينا من هذا الباركود (⇒ التالي في الطابور) */
    function finish(reason) {
        if (controller && typeof controller.resolve === 'function') {
            controller.resolve();
        }
    }

    /* ======================================================
       فتح/إغلاق
       ====================================================== */
    function openScanner() {
        var modal = dom.modal;
        if (!modal) {
            return;
        }

        modal.classList.add('active');
        document.body.classList.add('ph-modal-open');

        // جلسة جديدة في كل فتح — الـQR القديم لا يُعاد استخدامه
        if (!controller) {
            controller = S.createController();
            offChange = controller.onChange(function (type, payload, snap) {
                renderAll(snap);

                if (type === 'barcode') {
                    handleIncomingBarcode(payload.barcode);
                }
                if (type === 'device_request') {
                    var B = window.AccountingBarcode;
                    if (B && typeof B.notify === 'function') {
                        B.notify(t('device_request_title', ''), 'warn');
                    }
                }
            });
        }

        controller.start().then(function (snap) {
            renderAll(snap);
        });

        // تركيز أول عنصر تفاعلي — WCAG 2.4.3
        var focusable = modal.querySelector('button, input, [href]');
        if (focusable) {
            setTimeout(function () { focusable.focus(); }, 60);
        }

        lastFocused = document.activeElement;
    }

    var lastFocused = null;

    function closeScanner() {
        if (dom.modal) {
            dom.modal.classList.remove('active');
            document.body.classList.remove('ph-modal-open');
        }

        // ⚠️ السلة لا تُمسّ — الجلسة فقط تُلغى
        if (controller) {
            controller.close();
        }

        if (lastFocused && typeof lastFocused.focus === 'function') {
            try { lastFocused.focus(); } catch (e) { /* أُزيل من DOM */ }
        }
    }

    /* ======================================================
       الربط
       ====================================================== */
    function cacheDom() {
        dom.modal = document.querySelector('[data-phone-scanner-modal]');
        if (!dom.modal) {
            return false; // الصفحة لا تستخدم الماسح
        }
        dom.status = dom.modal.querySelector('[data-session-status]');
        dom.statusLabel = dom.modal.querySelector('[data-session-label]');
        dom.statusTimer = dom.modal.querySelector('[data-session-timer]');
        dom.pairSection = dom.modal.querySelector('[data-pair-section]');
        dom.pairCode = dom.modal.querySelector('[data-pair-code]');
        dom.qrPlaceholder = dom.modal.querySelector('[data-pair-qr-placeholder]');
        dom.received = dom.modal.querySelector('[data-session-received]');
        dom.receivedCode = dom.modal.querySelector('[data-received-code]');
        dom.receivedHint = dom.modal.querySelector('[data-received-hint]');
        dom.queue = dom.modal.querySelector('[data-session-queue]');
        dom.queueList = dom.modal.querySelector('[data-queue-list]');
        dom.deviceRow = dom.modal.querySelector('[data-device-row]');
        dom.deviceName = dom.modal.querySelector('[data-device-name]');
        dom.deviceEmpty = dom.modal.querySelector('[data-device-empty]');
        dom.deviceRequest = dom.modal.querySelector('[data-device-request]');
        dom.deviceRequestName = dom.modal.querySelector('[data-device-request-name]');
        dom.reconnectBtn = dom.modal.querySelector('[data-scanner-reconnect]');
        dom.newSessionBtn = dom.modal.querySelector('[data-scanner-new-session]');
        dom.simulateBtn = dom.modal.querySelector('[data-scanner-simulate]');
        dom.scannerDot = document.querySelector('[data-phone-scanner-dot]');
        return true;
    }

    function bind() {
        // أزرار فتح النافذة (قد تكون أكثر من واحد على الصفحة)
        document.querySelectorAll('[data-phone-scanner-open]').forEach(function (btn) {
            btn.addEventListener('click', openScanner);
        });

        // إغلاق
        dom.modal.querySelectorAll('[data-phone-scanner-close]').forEach(function (btn) {
            btn.addEventListener('click', closeScanner);
        });

        // Esc + النقر على الخلفية
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && dom.modal.classList.contains('active')) {
                closeScanner();
            }
        });
        dom.modal.addEventListener('click', function (e) {
            if (e.target === dom.modal) {
                closeScanner();
            }
        });

        // إعادة الاتصال — جلسة جديدة
        if (dom.reconnectBtn) {
            dom.reconnectBtn.addEventListener('click', function () {
                if (controller) {
                    controller.reconnect().then(renderAll);
                }
            });
        }

        // جلسة جديدة بعد الانتهاء
        if (dom.newSessionBtn) {
            dom.newSessionBtn.addEventListener('click', function () {
                if (controller) {
                    controller.reconnect().then(renderAll);
                }
            });
        }

        // محاكاة مسح — زر عرض تجريبي فقط
        if (dom.simulateBtn) {
            dom.simulateBtn.addEventListener('click', function () {
                if (controller) {
                    controller.simulateScan();
                }
            });
        }

        /* ---------- أزرار منطقة الأجهزة: تفويض حدث واحد ----------
         *
         * ⚠️ لماذا التفويض لا `querySelectorAll` مباشر:
         * كان `bind()` (يُنادى **مرة واحدة** في `init()`) يبحث عن
         * `[data-device-disconnect]` / `[data-device-allow]` /
         * `[data-device-reject]` في لحظة التحميل. لكن `connected-device-card`
         * كان يُرسم بـ`@if($active)`، والنافذة تمرّر `:active="null"` ⇒ هذه
         * الأزرار **غير موجودة** عند التحميل ⇒ لا يُربط أي مستمع، فيستحيل فصل
         * الهاتف أو قبول جهاز ثانٍ من الواجهة إلى الأبد.
         *
         * التفويض على `dom.modal` يحلّ ذلك نهائيًّا: نستمع على الأب الثابت
         * ونتعرّف على الهدف عبر `closest()` — فيعمل أي زر يُضاف أو يُظهر لاحقًا.
         */
        dom.modal.addEventListener('click', function (e) {
            var target = e.target;
            if (!target || typeof target.closest !== 'function') {
                return;
            }

            // فصل الجهاز — ⚠️ السلة تبقى
            var off = target.closest('[data-device-disconnect]');
            if (off && controller) {
                controller.disconnect().then(function () {
                    renderAll(controller.snapshot());
                    var B = window.AccountingBarcode;
                    if (B && typeof B.notify === 'function') {
                        B.notify(t('disconnected_note', ''), 'warn');
                    }
                });
                return;
            }

            // جهاز آخر يطلب الاتصال — [سماح] / [رفض]
            var allow = target.closest('[data-device-allow]');
            if (allow && controller) {
                controller.answerDeviceRequest(true).then(renderAll);
                return;
            }

            var reject = target.closest('[data-device-reject]');
            if (reject && controller) {
                controller.answerDeviceRequest(false).then(renderAll);
            }
        });
    }

    function init() {
        if (!cacheDom()) {
            return;
        }
        bind();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* ----- أدوات نصية ----- */
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    function escapeAttr(s) { return escapeHtml(s).replace(/`/g, '&#96;'); }

    /* ----- التصدير ----- */
    window.AccountingPhoneScanner = {
        open: openScanner,
        close: closeScanner,
        __controller: function () { return controller; }
    };
})();
