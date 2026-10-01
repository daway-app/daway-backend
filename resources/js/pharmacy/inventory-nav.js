/* Daway — Phase 9: Instant Pagination Navigation (Pharmacy Inventory only).
 *
 * الهدف: عند الضغط على رابط ترقيم في /pharmacy/inventory، لا نعيد تحميل الصفحة
 * كاملة. بدلاً من ذلك:
 *   1) نمنع التنقّل الافتراضي فورًا.
 *   2) نُظهر loading state داخل منطقة المحتوى فقط (الـsidebar/topbar لا تُمَسّ).
 *   3) pushState فورًا (URL يتغيّر قبل وصول الاستجابة).
 *   4) fetch للـHTML الكامل (same-origin + credentials) في الخلفية.
 *   5) نستخرج #inventory-content من الـHTML الجديد ونستبدل القديم فقط.
 *
 * قيود أمنية صارمة (موروثة من تجربة SW السابقة):
 *   - لا Service Worker، لا Cache API، لا localStorage لتخزين HTML مصادَق.
 *   - لا كاش مشترك: كل طلب يحمل credentials:'same-origin' (جلسة المستخدم الحالي).
 *   - لا شيء يُخزَّن بين الطلبات — الاستبدال مباشر في الـDOM فقط.
 *
 * الصفحة الأولى (initial load) تبقى server-rendered كما هي — هذا الملف يتدخّل
 * فقط بعد تحميل الصفحة، وعلى روابط الترقيم داخل نطاق /pharmacy/inventory.
 */
(function () {
    'use strict';

    var CONTENT_ID = 'inventory-content';
    var NAV_SCOPE = /^\/pharmacy\/inventory\/?$/;
    var SKELETON_ROWS = 8;

    /** @type {AbortController|null} */
    var inflight = null;
    /** رقم الطلب الحالي — يمنع أن يكتب طلب قديم فوق طلب أحدث. */
    var requestSeq = 0;

    function contentEl() {
        return document.getElementById(CONTENT_ID);
    }

    /* ── loading state: skeleton داخل الجدول + بُعد الترقيم ── */
    function showLoading() {
        var host = contentEl();
        if (!host) return;

        // لا نلمس الـlayout — نستبدل داخل المحتوى فقط طبقة مؤقتة شفّافة.
        if (document.getElementById('inventory-nav-overlay')) return;

        var overlay = document.createElement('div');
        overlay.id = 'inventory-nav-overlay';
        overlay.setAttribute('aria-busy', 'true');
        overlay.style.cssText = 'position:relative;';

        var rows = '';
        for (var i = 0; i < SKELETON_ROWS; i++) {
            rows += '<div class="inv-skel-row" style="height:18px;margin:10px 0;border-radius:6px;'
                + 'background:linear-gradient(90deg,var(--ph-line-soft,#e5e7eb) 25%,var(--ph-surface,#f3f4f6) 37%,var(--ph-line-soft,#e5e7eb) 63%);'
                + 'background-size:400% 100%;animation:invSkel 1.2s ease-in-out infinite;"></div>';
        }

        overlay.innerHTML = '<div style="padding:18px 22px;">' + rows + '</div>';

        // خفض شفافية المحتوى القديم (لا نحذفه) — يمنع "وميض أبيض" ويمنح إحساسًا فوريًا.
        host.style.transition = 'opacity .12s ease';
        host.style.opacity = '0.35';
        host.setAttribute('aria-busy', 'true');

        host.parentNode.insertBefore(overlay, host);

        if (!document.getElementById('invSkelKf')) {
            var st = document.createElement('style');
            st.id = 'invSkelKf';
            st.textContent = '@keyframes invSkel{0%{background-position:100% 50%}100%{background-position:0 50%}}';
            document.head.appendChild(st);
        }
    }

    function hideLoading() {
        var overlay = document.getElementById('inventory-nav-overlay');
        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
        var host = contentEl();
        if (host) {
            host.style.opacity = '';
            host.style.transition = '';
            host.removeAttribute('aria-busy');
        }
    }

    /* ── استبدال المحتوى فقط ── */
    function replaceContent(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.getElementById(CONTENT_ID);
        var host = contentEl();
        if (!fresh || !host) return false;

        host.replaceWith(fresh);

        // تحديث العنوان إن وُجد.
        var newTitle = doc.querySelector('title');
        if (newTitle && newTitle.textContent) {
            document.title = newTitle.textContent;
        }

        // أعد تجهيز الترقيم للروابط الجديدة + أعد تشغيل الـpalette/الرسم إن لزم.
        bindPagination();
        return true;
    }

    /* ── التنقّل الفعلي ── */
    function navigate(url, push) {
        if (!NAV_SCOPE.test(new URL(url, window.location.origin).pathname)) return;

        if (inflight) inflight.abort();
        var controller = new AbortController();
        inflight = controller;
        var seq = ++requestSeq;

        if (push) window.history.pushState({ dawayInventory: true }, '', url);

        showLoading();

        fetch(url, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            },
            credentials: 'same-origin',
            signal: controller.signal
        })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .then(function (html) {
                if (seq !== requestSeq) return; // طلب أقدم — تجاهل
                hideLoading();
                if (!replaceContent(html)) {
                    window.location.href = url; // فشل الاستخراج ⇒ fallback حقيقي
                    return;
                }
                scrollToContent();
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return; // أُلغي بطلب أحدث
                if (seq !== requestSeq) return;
                hideLoading();
                window.location.href = url; // §11: fallback إلى التنقّل التقليدي
            });
    }

    function scrollToContent() {
        var host = contentEl();
        if (!host) return;
        var top = host.getBoundingClientRect().top + window.pageYOffset - 12;
        // لا نقفز إلى أعلى الموقع — فقط إلى بداية منطقة المحتوى، وبسلوك ناعم.
        window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    }

    /* ── ربط روابط الترقيم (يُعاد استدعاؤه بعد كل استبدال) ── */
    function bindPagination() {
        var host = contentEl();
        if (!host) return;
        var links = host.querySelectorAll('.pagination-wrapper a[href], nav[role="navigation"] a[href]');
        Array.prototype.forEach.call(links, function (a) {
            if (a.dataset.dawayNavBound === '1') return;
            var href = a.getAttribute('href');
            if (!href || href.charAt(0) !== '/' || href.indexOf('//') === 0) return;
            if (!NAV_SCOPE.test(new URL(href, window.location.origin).pathname)) return;
            a.dataset.dawayNavBound = '1';
            a.addEventListener('click', function (e) {
                if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
                e.preventDefault();
                navigate(href, true);
            });
        });
    }

    /* ── Back / Forward ── */
    window.addEventListener('popstate', function () {
        if (!NAV_SCOPE.test(window.location.pathname)) return;
        navigate(window.location.pathname + window.location.search, false);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindPagination);
    } else {
        bindPagination();
    }

    // تعريض للاختبارات فقط (بلا أي تخزين).
    window.DawayInventoryNav = { navigate: navigate, bindPagination: bindPagination };
})();
