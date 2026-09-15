/**
 * Daway Accounting — تفاصيل الفاتورة (Invoice details)
 * ==========================================================
 * ⚠️ الطباعة حقيقية (window.print عبر مستمع واحد). التنزيل/الإرجاع/تسجيل
 * دفعة تحتاج Backend غير موجود ⇒ تُعرض رسالة صريحة، ولا يُختلق endpoint.
 */
(function () {
    'use strict';

    var U = window.AccountingUtil;

    function init() {
        var printBtn = document.querySelector('[data-ac-print]');
        if (printBtn) {
            printBtn.addEventListener('click', function () {
                window.print();
            });
        }

        document.querySelectorAll('[data-ac-coming-soon]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var target = document.querySelector(btn.getAttribute('data-ac-coming-soon'));
                if (target) {
                    U.showMessage(target, 'warning', window.acInvoiceI18n.not_available);
                    setTimeout(function () {
                        U.hideMessage(target);
                    }, 4500);
                }
            });
        });

        U.initModalA11y();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
