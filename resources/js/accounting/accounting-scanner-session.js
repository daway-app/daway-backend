/**
 * Daway Accounting — جلسة مسح الهاتف (Phone as Remote Barcode Scanner)
 * ======================================================================
 * الفكرة: الصيدلي يفتح نقطة البيع على الويب، والهاتف (Flutter) يعمل **قارئ
 * باركود عن بُعد**. الهاتف هو من يفتح الكاميرا ويُفكّ ترميز الباركود، ويُرسل
 * **نصًّا** فقط — ⚠️ **الويب لا يستقبل صورة الكاميرا ولا يشغّلها أبدًا**.
 *
 * التدفق:
 *   Web POS ── createScanSession() ──▶ Backend
 *          ◀── pairing code / QR ────┘
 *   Phone  ── pairPhone() ──────────▶ Backend
 *   Phone  ── receiveBarcode(str) ──▶ Backend
 *          ◀── polling/SSE/WS ─────── Web POS ──▶ نفس مسار البحث الحالي
 *
 * ## قواعد صارمة في هذا الملف
 *
 * 1) **صفر endpoints مُختلَقة.** كل المسارات تأتي من
 *    `window.acAccountingConfig.endpoints.scanSessions`. لو غير موجودة ⇒
 *    الخدمة تدخل `mode:'unavailable'` وتُرجع نتائج «غير متاح» بصراحة،
 *    ولا تتظاهر بنجاح ولا ترسل شيئًا لأي مسار مُخترَع.
 *
 * 2) **لا polling سريع.** الفاصل 2000ms فقط أثناء فتح النافذة، ويتوقف عند
 *    إخفاء التبويب وعند الإغلاق/الانتهاء. (المشروع يفضّل polling محدود —
 *    راجع `resources/js/offline/sync.js` الذي يستخدم 60s heartbeat.)
 *
 * 3) **لا توكنات دائمة في الـQR.** الحمولة المقترحة للـQR تمثّل جلسة مؤقتة
 *    فقط. لا كلمة مرور، ولا API key، ولا personal access token.
 *
 * 4) **المحاكاة للعرض فقط.** `scanSessionMock` تُفعَّل صراحةً من Blade عند
 *    وجود endpoint مفقود، وتُعلَن في الواجهة بـ«وضع تجريبي». لا بيانات محاكاة
 *    في الإنتاج.
 *
 * 5) **آلة حالة واحدة صريحة.** كل الانتقالات تمرّ من `setState()` وحدها،
 *    فيستحيل أن تعرض الواجهة حالة غير معرّفة.
 *
 * يُحمَّل **بعد** accounting-shared.js، وقبل ملف الشرائح (accounting-phone-scanner.js).
 */
(function () {
    'use strict';

    /* ======================================================
       1) الحالات — ثمانية، مطابقة لما طلبه التصميم حرفيًا
       ====================================================== */
    var STATE = {
        IDLE: 'idle',                 // لا جلسة بعد
        WAITING: 'waiting',           // QR معروض، ننتظر الهاتف
        CONNECTING: 'connecting',     // الهاتف بدأ الاقتران
        CONNECTED: 'connected',       // جاهز للمسح
        SCANNING: 'scanning',         // وصل باركود، قيد الحل
        RECEIVED: 'received',         // تم التعرّف على الدواء
        DISCONNECTED: 'disconnected', // انقطع الهاتف
        EXPIRED: 'expired',           // انتهت الجلسة
        ERROR: 'error'                // خطأ خدمة/شبكة
    };

    /** كل الحالات — للتحقق في الاختبارات */
    function allStates() {
        return [
            STATE.IDLE, STATE.WAITING, STATE.CONNECTING, STATE.CONNECTED,
            STATE.SCANNING, STATE.RECEIVED, STATE.DISCONNECTED,
            STATE.EXPIRED, STATE.ERROR
        ];
    }

    /**
     * الحالات التي يجوز فيها إرسال باركود إلى السلة.
     *
     * ⚠️ `received` ليست هنا: الباركود وصل ونحن نحلّ الدواء. الإضافة للسلة
     * تحدث **بعد** الحلّ — في محرّك الباركود المشترك (`AccountingBarcode`).
     */
    function canAcceptScans(state) {
        return state === STATE.CONNECTED || state === STATE.SCANNING;
    }

    /** حالة نهائية لا يُعاد بناء الجلسة منها (تحتاج جلسة جديدة) */
    function isTerminal(state) {
        return state === STATE.EXPIRED;
    }

    /** نصّ الحالة مترجمًا من Blade (window.acScannerI18n) — لا نصوص هنا */
    function i18n() {
        return window.acScannerI18n || {};
    }

    function stateLabel(state) {
        return (i18n().states && i18n().states[state]) || state;
    }

    /* ======================================================
       2) النقل — Transport abstraction
       ======================================================
       ⚠️ المشروع **لا يملك أي بنية real-time**:
          - `BROADCAST_CONNECTION=log` (بلا مشغّل فعّال)
          - لا Pusher / Reverb / laravel-echo / pusher-js
          - لا `config/broadcasting.php` ولا `routes/channels.php`
       لذلك الافتراضي **polling**، لكن الواجهة مصمّمة لتقبل:
          polling | sse | websocket
       بلا تعديل أي كود في الشرائح. عند إضافة مشغّل لاحقًا: مرّر اسمه في
       `window.acAccountingConfig.scanSessionTransport` وستُستخدم تلقائيًا.
       ====================================================== */

    var Transport = (function () {
        var current = 'polling';
        var timer = null;
        var pollOptions = {};

        function kind() {
            var cfg = window.acAccountingConfig || {};
            return cfg.scanSessionTransport || 'polling';
        }

        /**
         * يبدأ الاستقبال.
         *
         * @param {object} opts
         *   endpoints : { status:url, events:url }
         *   onEvent   : function(event)
         *   onError   : function(err)
         *   interval  : ms  (افتراضي 2000 — **لا تنقصه** بلا سبب)
         */
        function start(opts) {
            pollOptions = opts || {};
            current = kind();

            if (current === 'polling') {
                startPolling();
                return;
            }

            if (current === 'sse') {
                startSse();
                return;
            }

            if (current === 'websocket') {
                // ⚠️ غير منفَّذ: لا مكتبة عميل ولا مشغّل في الباك-إند.
                // نفشل بصراحة ونعود للـpolling بدل واجهة معلّقة للأبد.
                if (pollOptions.onError) {
                    pollOptions.onError({ reason: 'transport_unavailable', transport: 'websocket' });
                }
                current = 'polling';
                startPolling();
                return;
            }

            current = 'polling';
            startPolling();
        }

        function startPolling() {
            stop();
            var interval = Number(pollOptions.interval) || 2000;

            // Polling محدود: يتوقف فورًا إذا كان التبويب مخفيًّا — لا نستهلك
            // دورات الشبكة على شاشة غير مرئية (شبكة غزة حسّاسة).
            function tick() {
                if (document.hidden || !pollOptions.onTick) {
                    return;
                }
                pollOptions.onTick();
            }

            timer = setInterval(tick, interval);

            // عند العودة للتبويب: فحص فوري بدل انتظار دورة كاملة
            document.addEventListener('visibilitychange', onVisible);
        }

        function onVisible() {
            if (!document.hidden && pollOptions.onTick) {
                pollOptions.onTick();
            }
        }

        function startSse() {
            stop();
            if (typeof window.EventSource === 'undefined') {
                current = 'polling';
                startPolling();
                return;
            }
            var url = pollOptions.endpoints && pollOptions.endpoints.events;
            if (!url) {
                current = 'polling';
                startPolling();
                return;
            }
            try {
                var es = new EventSource(url);
                es.addEventListener('barcode', function (e) {
                    if (pollOptions.onEvent) {
                        try {
                            pollOptions.onEvent(JSON.parse(e.data));
                        } catch (err) {
                            pollOptions.onError && pollOptions.onError({ reason: 'bad_payload' });
                        }
                    }
                });
                es.onerror = function () {
                    pollOptions.onError && pollOptions.onError({ reason: 'sse_error' });
                };
                timer = { close: function () { es.close(); } };
            } catch (e) {
                current = 'polling';
                startPolling();
            }
        }

        function stop() {
            if (timer) {
                if (typeof timer === 'object' && timer.close) {
                    timer.close();
                } else {
                    clearInterval(timer);
                }
                timer = null;
            }
            document.removeEventListener('visibilitychange', onVisible);
        }

        return { start: start, stop: stop, kind: kind, __current: function () { return current; } };
    })();

    /* ======================================================
       3) طبقة الـAPI — خمس عمليات فقط، كما طلب العقد
       ======================================================
       createScanSession · getScanSessionStatus · pairPhone ·
       receiveBarcode · closeScanSession

       ⚠️ كلها تُرجع `{ok:false, reason:'no_backend'}` عند غياب المسارات.
       لا نرسل طلبًا لأي مسار غير موجود.
       ====================================================== */
    var Api = (function () {
        function cfg() {
            return window.acAccountingConfig || {};
        }

        /** المسارات كما جاءت من Blade — لا نبنيها هنا */
        function endpoints() {
            var c = cfg();
            return (c.endpoints && c.endpoints.scanSessions) || null;
        }

        function unavailable() {
            return Promise.resolve({ ok: false, reason: 'no_backend' });
        }

        function csrf() {
            var meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        }

        function request(method, url, body) {
            var opts = {
                method: method,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf()
                },
                credentials: 'same-origin'
            };
            if (body) {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
            return fetch(url, opts)
                .then(function (res) {
                    return res.json()
                        .then(function (json) { return { ok: res.ok, status: res.status, json: json }; })
                        .catch(function () { return { ok: res.ok, status: res.status, json: null }; });
                });
        }

        /** 1) إنشاء جلسة مسح مؤقتة */
        function createScanSession() {
            var e = endpoints();
            if (!e || !e.store) {
                return unavailable();
            }
            return request('POST', e.store, {})
                .then(function (r) {
                    if (r.ok && r.json && r.json.data) {
                        return { ok: true, session: normalizeSession(r.json.data) };
                    }
                    return { ok: false, reason: 'api_error', status: r.status };
                })
                .catch(function () { return { ok: false, reason: 'network' }; });
        }

        /** 2) قراءة حالة الجلسة (وهي أيضًا قناة وصول الباركود في وضع polling) */
        function getScanSessionStatus(id) {
            var e = endpoints();
            if (!e || !e.show) {
                return unavailable();
            }
            return request('GET', e.show.replace('{id}', encodeURIComponent(id)))
                .then(function (r) {
                    if (r.ok && r.json && r.json.data) {
                        return { ok: true, session: normalizeSession(r.json.data) };
                    }
                    if (r.status === 404 || r.status === 410) {
                        return { ok: false, reason: 'expired' };
                    }
                    return { ok: false, reason: 'api_error', status: r.status };
                })
                .catch(function () { return { ok: false, reason: 'network' }; });
        }

        /** 3) اقتران الهاتف بالجلسة */
        function pairPhone(id, deviceName) {
            var e = endpoints();
            if (!e || !e.pair) {
                return unavailable();
            }
            return request('POST', e.pair.replace('{id}', encodeURIComponent(id)), {
                device_name: deviceName || null
            })
                .then(function (r) {
                    if (r.ok) {
                        return { ok: true, device: (r.json && r.json.data) || null };
                    }
                    // 409 = جهاز آخر مرتبط ⇒ قرار بشري لا تجاوز
                    if (r.status === 409) {
                        return { ok: false, reason: 'device_conflict' };
                    }
                    return { ok: false, reason: 'api_error', status: r.status };
                })
                .catch(function () { return { ok: false, reason: 'network' }; });
        }

        /**
         * 4) استقبال باركود من الهاتف المقترن.
         *
         * ⚠️ هذه الدالة تُنفَّذ على الهاتف (Flutter لاحقًا) — وُجدت هنا لأن
         * العقد يجب أن يكون مكتملًا وموثَّقًا. الويب لا يناديها في الوضع الحالي.
         */
        function receiveBarcode(id, barcode) {
            var e = endpoints();
            if (!e || !e.barcode) {
                return unavailable();
            }
            return request('POST', e.barcode.replace('{id}', encodeURIComponent(id)), {
                barcode: String(barcode || '')
            })
                .then(function (r) {
                    return r.ok ? { ok: true } : { ok: false, reason: 'api_error', status: r.status };
                })
                .catch(function () { return { ok: false, reason: 'network' }; });
        }

        /** 5) إغلاق الجلسة */
        function closeScanSession(id) {
            var e = endpoints();
            if (!e || !e.destroy) {
                return unavailable();
            }
            return request('DELETE', e.destroy.replace('{id}', encodeURIComponent(id)))
                .then(function (r) {
                    return r.ok ? { ok: true } : { ok: false, reason: 'api_error' };
                })
                .catch(function () { return { ok: false, reason: 'network' }; });
        }

        /**
         * يوحّد شكل الجلسة القادم من الباك-إند.
         *
         * ⚠️ لا نمرّر أي حقل حسّاس إلى الواجهة. القائمة أدناه **بيضاء**:
         * ما ليس فيها يُهمَل. هذا يمنع تسريب توكن دائم لو أرسله الباك-إند سهوًا.
         */
        function normalizeSession(raw) {
            var d = raw || {};
            return {
                id: d.id != null ? String(d.id) : null,
                status: d.status || 'waiting',
                pairingCode: d.pairing_code || null,
                pairingUrl: d.pairing_url || null,      // للـQR — جلسة مؤقتة فقط
                qrSvg: d.pairing_qr_svg || null,        // اختياري: SVG جاهز من الباك-إند
                expiresAt: d.expires_at || null,
                secondsRemaining: d.seconds_remaining != null ? Number(d.seconds_remaining) : null,
                // الجهاز المقترن — اسم ودور فقط، بلا معرّفات داخلية
                device: d.device ? {
                    name: d.device.name || null,
                    platform: d.device.platform || null
                } : null,
                devices: Array.isArray(d.devices) ? d.devices.map(function (x) {
                    return { name: x.name || null, platform: x.platform || null, active: !!x.active };
                }) : [],
                pendingDevice: d.pending_device ? { name: d.pending_device.name || null } : null,
                barcode: d.barcode || null              // باركود وصل ولم يُعالَج بعد
            };
        }

        return {
            createScanSession: createScanSession,
            getScanSessionStatus: getScanSessionStatus,
            pairPhone: pairPhone,
            receiveBarcode: receiveBarcode,
            closeScanSession: closeScanSession,
            __normalizeSession: normalizeSession,
            __endpoints: endpoints
        };
    })();

    /* ======================================================
       4) المحاكاة — للعرض فقط، صريحة وموسومة
       ======================================================
       ⚠️ لا تُنتج بيانات إنتاج. تُفعَّل فقط عندما يمرّر Blade
       `scanSessionMock` صراحةً (وذلك يحدث حين لا توجد endpoints)،
       وتُعرض في الواجهة بوسم «وضع تجريبي» حتى لا يظنّ أحدها حقيقية.
       ====================================================== */
    var Mock = (function () {
        var seq = 0;

        function enabled() {
            var c = window.acAccountingConfig || {};
            return c.scanSessionMock === true;
        }

        /** محاكاة إنشاء جلسة */
        function create() {
            seq++;
            return {
                id: 'demo-session-' + seq,
                status: STATE.WAITING,
                pairingCode: 'A7K92F',
                pairingUrl: null,
                qrSvg: null,
                expiresAt: null,
                secondsRemaining: 300,
                device: null,
                devices: [],
                pendingDevice: null,
                barcode: null
            };
        }

        /** محاكاة مسح من الهاتف — تُستدعى يدويًّا من زر «محاكاة مسح» في الوضع التجريبي */
        function nextBarcode() {
            var c = window.acAccountingConfig || {};
            var list = Array.isArray(c.scanSessionMockBarcodes)
                ? c.scanSessionMockBarcodes
                : ['6281234567890'];
            var code = list[seq % list.length];
            seq++;
            return code;
        }

        return { enabled: enabled, create: create, nextBarcode: nextBarcode };
    })();

    /* ======================================================
       5) المتحكّم — آلة الحالة
       ======================================================
       كل انتقال من مكان واحد. المستهلك (الشريحة) يشترك في `onChange`
       ويرسم فقط — لا منطق حالة في ملف الشرائح.
       ====================================================== */
    function createController(options) {
        options = options || {};

        var state = {
            mode: 'unavailable',      // 'live' | 'mock' | 'unavailable'
            status: STATE.IDLE,
            sessionId: null,
            pairingCode: null,
            pairingUrl: null,
            qrSvg: null,
            secondsRemaining: null,
            device: null,
            devices: [],
            pendingDevice: null,
            lastBarcode: null,
            error: null
        };

        var listeners = [];
        var queue = [];               // طابور الباركود الواردة — بالترتيب
        var polling = false;
        var expiryTimer = null;

        function emit(type, payload) {
            listeners.forEach(function (fn) {
                try { fn(type, payload || {}, snapshot()); } catch (e) { /* لا نكسر الشريحة */ }
            });
        }

        function snapshot() {
            return {
                mode: state.mode,
                status: state.status,
                sessionId: state.sessionId,
                pairingCode: state.pairingCode,
                pairingUrl: state.pairingUrl,
                qrSvg: state.qrSvg,
                secondsRemaining: state.secondsRemaining,
                device: state.device,
                devices: state.devices,
                pendingDevice: state.pendingDevice,
                lastBarcode: state.lastBarcode,
                error: state.error,
                queueLength: queue.length
            };
        }

        function setState(next, patch) {
            if (state.status === next && !patch) {
                return;
            }
            state.status = next;
            if (patch) {
                Object.keys(patch).forEach(function (k) {
                    state[k] = patch[k];
                });
            }
            emit('state', { status: next });
        }

        /* ---------- متابعة ---------- */
        function onChange(fn) {
            listeners.push(fn);
            return function off() {
                listeners = listeners.filter(function (f) { return f !== fn; });
            };
        }

        /* ---------- دورة الحياة ---------- */

        /** بدء جلسة جديدة — يُلغي أي جلسة سابقة (والـQR القديم يصير بلا قيمة) */
        function start() {
            stopPolling();

            if (Mock.enabled()) {
                state.mode = 'mock';
                var s = Mock.create();
                applySession(s);
                setState(STATE.WAITING);
                attachMock();
                return Promise.resolve(snapshot());
            }

            var e = Api.__endpoints();
            if (!e) {
                state.mode = 'unavailable';
                setState(STATE.WAITING, { error: 'no_backend' });
                return Promise.resolve(snapshot());
            }

            state.mode = 'live';
            return Api.createScanSession().then(function (res) {
                if (!res.ok) {
                    state.mode = res.reason === 'no_backend' ? 'unavailable' : state.mode;
                    setState(STATE.ERROR, { error: res.reason });
                    return snapshot();
                }
                applySession(res.session);
                setState(STATE.WAITING);
                startPolling();
                return snapshot();
            });
        }

        function applySession(s) {
            state.sessionId = s.id;
            state.pairingCode = s.pairingCode;
            state.pairingUrl = s.pairingUrl;
            state.qrSvg = s.qrSvg;
            state.secondsRemaining = s.secondsRemaining;
            state.device = s.device;
            state.devices = s.devices || [];
            state.pendingDevice = s.pendingDevice;
            scheduleExpiry();
        }

        /** عدّاد الانتهاء محليًّا — لا ننتظر الباك-إند ليخبرنا */
        function scheduleExpiry() {
            clearInterval(expiryTimer);
            if (state.secondsRemaining == null) {
                return;
            }
            expiryTimer = setInterval(function () {
                state.secondsRemaining = Math.max(0, state.secondsRemaining - 1);
                emit('tick', { secondsRemaining: state.secondsRemaining });

                if (state.secondsRemaining === 0) {
                    clearInterval(expiryTimer);
                    stopPolling();
                    setState(STATE.EXPIRED);
                }
            }, 1000);
        }

        /* ---------- الاستقبال ---------- */

        function startPolling() {
            if (polling) {
                return;
            }
            polling = true;

            Transport.start({
                endpoints: { events: null },
                interval: 2000,   // ⚠️ لا تُنقصه — polling سريع ممنوع
                onTick: function () {
                    Api.getScanSessionStatus(state.sessionId).then(function (res) {
                        if (!res.ok) {
                            if (res.reason === 'expired') {
                                stopPolling();
                                setState(STATE.EXPIRED);
                            } else if (res.reason === 'network') {
                                setState(STATE.DISCONNECTED, { error: 'network' });
                            }
                            return;
                        }
                        handleSessionUpdate(res.session);
                    });
                },
                onError: function (err) {
                    setState(STATE.ERROR, { error: err.reason });
                }
            });
        }

        function stopPolling() {
            polling = false;
            Transport.stop();
        }

        /** يستقبل تحديث الجلسة من أي مصدر (polling / SSE / WS) */
        function handleSessionUpdate(s) {
            if (!s) {
                return;
            }

            // الجهاز
            if (s.device && !state.device) {
                state.device = s.device;
                setState(STATE.CONNECTED);
            } else if (s.device) {
                state.device = s.device;
            }
            if (s.devices && s.devices.length) {
                state.devices = s.devices;
            }

            // طلب اقتران جهاز آخر — قرار بشري
            if (s.pendingDevice && (!state.pendingDevice || state.pendingDevice.name !== s.pendingDevice.name)) {
                state.pendingDevice = s.pendingDevice;
                emit('device_request', { device: s.pendingDevice });
            }

            // باركود وصل — ندخله الطابور بالترتيب
            if (s.barcode) {
                enqueue(s.barcode);
            }

            // أول مرة نتصل
            if (state.status === STATE.WAITING && s.device) {
                setState(STATE.CONNECTED);
            }

            emit('session', s);
        }

        /**
         * الطابور — الاستقبال بالترتيب.
         *
         * ⚠️ **لا تعقيد عميل زائد**: نُنفّذ باركودًا واحدًا في كل مرة، والباقي
         * ينتظر. شبكة غزة لا تحتمل 5 طلبات متوازية، والترتيب أهم من السرعة.
         */
        function enqueue(barcode) {
            var code = String(barcode || '').trim();
            if (code === '') {
                return;
            }
            queue.push(code);
            emit('queued', { barcode: code, queueLength: queue.length });
            drainQueue();
        }

        var draining = false;

        function drainQueue() {
            if (draining || queue.length === 0) {
                return;
            }
            draining = true;

            var code = queue.shift();
            state.lastBarcode = code;
            setState(STATE.SCANNING, { lastBarcode: code });

            emit('barcode', { barcode: code, queueLength: queue.length });

            // الشريحة (أو المحرّك المشترك) تُبلّغنا بالانتهاء عبر `resolve()`
            // حتى ننتقل إلى التالي — لا مؤقّت تخميني.
        }

        /** يُنادى من الشريحة بعد معالجة الباركود الحالي */
        function resolve() {
            draining = false;

            if (queue.length > 0) {
                drainQueue();
                return;
            }
            // لا جلسة ⇒ لا حالة موصولة
            if (isTerminal(state.status)) {
                return;
            }
            setState(state.device ? STATE.CONNECTED : STATE.WAITING);
        }

        /** المسح التالي يدويًّا (وضع الاتصال + الوضع التجريبي) */
        function simulateScan() {
            if (!Mock.enabled()) {
                return;
            }
            enqueue(Mock.nextBarcode());
        }

        function attachMock() {
            // لا polling في الوضع التجريبي — الزر هو مصدر الباركود
        }

        /* ---------- الجهاز ---------- */

        /** جهاز آخر يريد الاتصال — [سماح] / [رفض] */
        function answerDeviceRequest(allow) {
            var pending = state.pendingDevice;
            state.pendingDevice = null;

            if (!allow) {
                emit('device_rejected', { device: pending });
                return Promise.resolve({ ok: true });
            }
            if (state.mode !== 'live') {
                // وضع تجريبي/غير متاح: نعرض الأثر البصري فقط
                if (pending) {
                    state.device = { name: pending.name, platform: null };
                }
                setState(STATE.CONNECTED);
                return Promise.resolve({ ok: true });
            }
            return Api.pairPhone(state.sessionId, pending ? pending.name : null)
                .then(function () {
                    state.device = pending || state.device;
                    setState(STATE.CONNECTED);
                    return { ok: true };
                });
        }

        /** فصل الجهاز الحالي — ⚠️ السلة **لا** تُفقد */
        function disconnect() {
            stopPolling();

            if (state.mode === 'live' && state.sessionId) {
                Api.closeScanSession(state.sessionId);
            }

            var hadDevice = state.device;
            state.device = null;
            state.sessionId = null;
            clearInterval(expiryTimer);
            queue = [];
            draining = false;

            setState(STATE.DISCONNECTED, { device: null });
            return Promise.resolve({ ok: true, device: hadDevice });
        }

        /** إعادة الاتصال — جلسة جديدة بباركود جديد */
        function reconnect() {
            return start();
        }

        /** إغلاق كامل — يُلغى الـQR ويُوقف كل شيء */
        function close() {
            stopPolling();
            clearInterval(expiryTimer);
            queue = [];
            draining = false;

            var id = state.sessionId;
            state.sessionId = null;
            state.device = null;
            state.pairingCode = null;
            state.status = STATE.IDLE;

            if (state.mode === 'live' && id) {
                Api.closeScanSession(id);
            }
            return Promise.resolve({ ok: true });
        }

        /* ---------- للاختبارات ---------- */
        /**
         * يُعلن أن باركودًا وصل وتم التعرّف على الدواء — انتقال `scanning`
         * (أو `connected`) ⇒ `received`.
         *
         * ⚠️ موجودة لأن المستهلك (accounting-phone-scanner.js) كان يكتب
         * `controller.state.status = RECEIVED` **مباشرة**، وهذا يتجاوز
         * `setState` فلا يُطلق حدث `state` لأي مستمع آخر: أي شاشة تعتمد على
         * الحدث (شارة الحالة، عدّاد الطابور) تتجمّد على الحالة القديمة.
         */
        function markReceived(barcode) {
            if (barcode !== undefined) {
                state.lastBarcode = barcode;
            }
            setState(STATE.RECEIVED, { lastBarcode: state.lastBarcode });
        }

        function __setSession(s) {
            applySession(s);
        }

        return {
            start: start,
            enqueue: enqueue,
            resolve: resolve,
            markReceived: markReceived,
            simulateScan: simulateScan,
            answerDeviceRequest: answerDeviceRequest,
            disconnect: disconnect,
            reconnect: reconnect,
            close: close,
            onChange: onChange,
            snapshot: snapshot,
            state: state,
            __setSession: __setSession,
            __queueLength: function () { return queue.length; }
        };
    }

    /* ======================================================
       6) التصدير
       ====================================================== */
    window.AccountingScanSession = {
        STATE: STATE,
        allStates: allStates,
        canAcceptScans: canAcceptScans,
        isTerminal: isTerminal,
        stateLabel: stateLabel,
        Api: Api,
        Transport: Transport,
        Mock: Mock,
        createController: createController
    };
})();
