/**
 * Daway Accounting — صفحة المبيعات (Sales list)
 * ==========================================================
 * ⚠️ الفلترة نفسها تجري **في السيرفر** (نموذج GET + ترقيم صفحات حقيقي)
 * — لا نجلب كل السجلات للعميل. هذا الملف مسؤول عن:
 *   1. إرسال الفلتر تلقائيًا عند تغيير قائمة اختيار (بلا زر «تطبيق»).
 *   2. الحدّ من تكرار الطلب أثناء الكتابة (debounce على حقل البحث).
 *   3. حوارات الإجراءات (دفعة/إرجاع) + رسائلها — بلا حفظ حقيقي.
 */
(function () {
    'use strict';

    var U = window.AccountingUtil;

    function init() {
        var form = document.querySelector('[data-ac-filters]');
        if (form) {
            // قوائم الاختيار: إرسال فوري
            form.querySelectorAll('select[data-ac-auto-submit]').forEach(function (sel) {
                sel.addEventListener('change', function () {
                    // إعادة للصفحة الأولى عند تغيير أي فلتر
                    var page = form.querySelector('input[name="page"]');
                    if (page) {
                        page.value = '1';
                    }
                    form.submit();
                });
            });

            // البحث: بعد توقّف الكتابة
            var searchInput = form.querySelector('input[type="search"], input[name="q"]');
            if (searchInput) {
                var submitSearch = U.debounce(function () {
                    form.submit();
                }, 500);
                searchInput.addEventListener('input', submitSearch);
            }
        }

        // إجراءات الصفوف: عرض/طباعة تعمل فعلًا؛ الباقي يعرض حالة صريحة
        document.querySelectorAll('[data-ac-coming-soon]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var target = document.querySelector(btn.getAttribute('data-ac-coming-soon'));
                if (target) {
                    U.showMessage(target, 'warning', window.acSalesI18n.not_available);
                    setTimeout(function () {
                        U.hideMessage(target);
                    }, 4000);
                }
            });
        });

        U.initModalA11y();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
