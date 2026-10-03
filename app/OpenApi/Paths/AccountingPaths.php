<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * توثيق قسم محاسبة الصيدلية (/api/pharmacy/accounting/*).
 *
 * المصدر: app/Http/Controllers/Api/Accounting*Controller.php + routes/api.php
 * كل المسارات: `auth:sanctum` + `role:pharmacy`.
 *
 * ⚠️ `sales/{number}` يستقبل **رقم الفاتورة** (INV-1042) لا الـid، والبحث
 * مقيّد بـpharmacy_id داخل الاستعلام (لا IDOR).
 */
class AccountingPaths
{
    #[OA\Get(
        path: '/api/pharmacy/accounting/overview',
        operationId: 'accountingOverview',
        summary: 'نظرة عامة على المحاسبة',
        description: 'يجمع ملخّص البيع/المصروفات/الصندوق/المديونيات خلال فترة.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'ملخّص المحاسبة'),
            new OA\Response(response: 403, description: 'يتطلب دور pharmacy'),
        ],
    )]
    public function accountingOverview() {}

    #[OA\Get(
        path: '/api/pharmacy/accounting/sales-summary',
        operationId: 'accountingSalesSummary',
        summary: 'ملخّص المبيعات',
        description: 'يستثني الفواتير الملغاة. مُعلَن قبل sales/{number} لتفادي التقاط الكلمة كرقم فاتورة.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'ملخّص المبيعات')],
    )]
    public function accountingSalesSummary() {}

    #[OA\Get(
        path: '/api/pharmacy/accounting/sales',
        operationId: 'accountingSalesIndex',
        summary: 'قائمة الفواتير',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'payment_method', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'فواتير الصيدلية فقط')],
    )]
    public function accountingSalesIndex() {}

    #[OA\Get(
        path: '/api/pharmacy/accounting/sales/{number}',
        operationId: 'accountingSalesShow',
        summary: 'تفاصيل فاتورة',
        description: '`{number}` = رقم الفاتورة (مثال INV-1042) وليس الـid.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'number', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'INV-1042')],
        responses: [
            new OA\Response(response: 200, description: 'تفاصيل الفاتورة + بنودها'),
            new OA\Response(response: 404, description: 'غير موجودة'),
        ],
    )]
    public function accountingSalesShow() {}

    #[OA\Post(
        path: '/api/pharmacy/accounting/sales',
        operationId: 'accountingSalesStore',
        summary: 'إنشاء فاتورة بيع',
        description: 'يخصم المخزون ويكتب حركة صندوق داخل DB::transaction. حد الكتابة throttle:writes.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['items', 'payment_method'],
            properties: [
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', properties: [
                    new OA\Property(property: 'medicine_id', type: 'integer'),
                    new OA\Property(property: 'quantity', type: 'integer'),
                    new OA\Property(property: 'price', type: 'number'),
                    new OA\Property(property: 'discount', type: 'number'),
                ])),
                new OA\Property(property: 'payment_method', type: 'string', enum: ['cash', 'credit', 'bank']),
                new OA\Property(property: 'customer_id', type: 'integer', nullable: true),
                new OA\Property(property: 'paid', type: 'number', nullable: true),
                new OA\Property(property: 'discount', type: 'number', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'تم إنشاء الفاتورة'),
            new OA\Response(response: 422, description: 'بنود ناقصة/مخزون غير كافٍ'),
            new OA\Response(response: 429, description: 'تجاوز حد الكتابة'),
        ],
    )]
    public function accountingSalesStore() {}

    #[OA\Post(
        path: '/api/pharmacy/accounting/sales/{number}/cancel',
        operationId: 'accountingSalesCancel',
        summary: 'إلغاء فاتورة',
        description: 'يُعيد المخزون ويعكس حركة الصندوق. الإلغاء المزدوج مرفوض.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'number', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'تم الإلغاء'),
            new OA\Response(response: 422, description: 'ملغاة مسبقًا / تعذّر الإلغاء'),
        ],
    )]
    public function accountingSalesCancel() {}

    /* ─────────────── الإرجاعات ─────────────── */

    #[OA\Get(
        path: '/api/pharmacy/accounting/refunds',
        operationId: 'accountingRefundsIndex',
        summary: 'قائمة الإرجاعات',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة الإرجاعات')],
    )]
    public function accountingRefundsIndex() {}

    #[OA\Get(
        path: '/api/pharmacy/accounting/refunds/{refund}',
        operationId: 'accountingRefundsShow',
        summary: 'تفاصيل إرجاع',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'refund', in: 'path', required: true, schema: new OA\Schema(type: 'integer', pattern: '[0-9]+'))],
        responses: [
            new OA\Response(response: 200, description: 'تفاصيل الإرجاع'),
            new OA\Response(response: 404, description: 'غير موجود'),
        ],
    )]
    public function accountingRefundsShow() {}

    #[OA\Get(
        path: '/api/pharmacy/accounting/sales/{number}/refunds',
        operationId: 'accountingSaleRefunds',
        summary: 'إرجاعات فاتورة',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'number', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'قائمة الإرجاعات')],
    )]
    public function accountingSaleRefunds() {}

    #[OA\Get(
        path: '/api/pharmacy/accounting/sales/{number}/refund-items',
        operationId: 'accountingSaleRefundItems',
        summary: 'بنود قابلة للإرجاع',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'number', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'البنود المتاحة للإرجاع')],
    )]
    public function accountingSaleRefundItems() {}

    #[OA\Post(
        path: '/api/pharmacy/accounting/refunds',
        operationId: 'accountingRefundsStore',
        summary: 'إنشاء إرجاع',
        description: 'يُنشئ قيدًا في الدفتر ويعيد المخزون. حد الكتابة throttle:writes.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'تم إنشاء الإرجاع'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function accountingRefundsStore() {}

    /* ─────────────── المصروفات ─────────────── */

    #[OA\Get(
        path: '/api/pharmacy/accounting/expense-categories',
        operationId: 'accountingExpenseCategories',
        summary: 'تصنيفات المصروفات (تُنشأ عند الطلب)',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة التصنيفات')],
    )]
    public function accountingExpenseCategories() {}

    #[OA\Get(
        path: '/api/pharmacy/accounting/expenses',
        operationId: 'accountingExpensesIndex',
        summary: 'قائمة المصروفات',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة المصروفات')],
    )]
    public function accountingExpensesIndex() {}

    #[OA\Post(
        path: '/api/pharmacy/accounting/expenses',
        operationId: 'accountingExpensesStore',
        summary: 'إنشاء مصروف',
        description: 'يرفض المبالغ غير الموجبة. التحويل البنكي لا يمسّ الصندوق.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'تم الإنشاء'),
            new OA\Response(response: 422, description: 'مبلغ غير صالح'),
        ],
    )]
    public function accountingExpensesStore() {}

    #[OA\Post(
        path: '/api/pharmacy/accounting/expenses/{expense}/cancel',
        operationId: 'accountingExpensesCancel',
        summary: 'إلغاء مصروف',
        description: 'يُعيد المال إلى الصندوق.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'expense', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'تم الإلغاء'),
            new OA\Response(response: 422, description: 'تعذّر الإلغاء'),
        ],
    )]
    public function accountingExpensesCancel() {}

    /* ─────────────── العملاء والموردون ─────────────── */

    #[OA\Get(
        path: '/api/pharmacy/accounting/customers',
        operationId: 'accountingCustomersIndex',
        summary: 'قائمة العملاء',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'عملاء الصيدلية')],
    )]
    public function accountingCustomersIndex() {}

    #[OA\Post(
        path: '/api/pharmacy/accounting/customers',
        operationId: 'accountingCustomersStore',
        summary: 'إنشاء عميل',
        description: 'رقم هاتف مكرّر يعيد العميل الموجود. الهاتف نفسه مسموح عبر صيدليات مختلفة.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'تم الإنشاء / أُعيد الموجود'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function accountingCustomersStore() {}

    #[OA\Get(
        path: '/api/pharmacy/accounting/customers/{customer}',
        operationId: 'accountingCustomersShow',
        summary: 'تفاصيل عميل',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تفاصيل العميل + مديونيته')],
    )]
    public function accountingCustomersShow() {}

    #[OA\Post(
        path: '/api/pharmacy/accounting/customers/{customer}/payments',
        operationId: 'accountingCustomerPayment',
        summary: 'تسجيل دفعة عميل',
        description: 'تنقص الدين وتزيد الصندوق. يرفض المبالغ السالبة.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 201, description: 'تمت الدفعة'),
            new OA\Response(response: 422, description: 'مبلغ غير صالح'),
        ],
    )]
    public function accountingCustomerPayment() {}

    #[OA\Get(
        path: '/api/pharmacy/accounting/suppliers',
        operationId: 'accountingSuppliersIndex',
        summary: 'قائمة الموردين',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'موردو الصيدلية')],
    )]
    public function accountingSuppliersIndex() {}

    #[OA\Post(
        path: '/api/pharmacy/accounting/suppliers',
        operationId: 'accountingSuppliersStore',
        summary: 'إنشاء مورد',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'تم الإنشاء'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function accountingSuppliersStore() {}

    #[OA\Post(
        path: '/api/pharmacy/accounting/suppliers/{supplier}/payments',
        operationId: 'accountingSupplierPayment',
        summary: 'تسجيل دفعة لمورد',
        description: 'تنقص رصيد المورد وتزيد الصندوق.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'supplier', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 201, description: 'تمت الدفعة'),
            new OA\Response(response: 422, description: 'مبلغ غير صالح'),
        ],
    )]
    public function accountingSupplierPayment() {}

    /* ─────────────── الصندوق ─────────────── */

    #[OA\Get(
        path: '/api/pharmacy/accounting/cash',
        operationId: 'accountingCashShow',
        summary: 'عرض الصندوق',
        description: 'التدفّق خلال الفترة + الرصيد.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'حركات ورصيد الصندوق')],
    )]
    public function accountingCashShow() {}

    #[OA\Post(
        path: '/api/pharmacy/accounting/cash/adjustments',
        operationId: 'accountingCashAdjustment',
        summary: 'تسوية الصندوق (إيداع/سحب)',
        description: 'يتطلب سببًا. اتجاه وkind متطابقان وإلا يُرفض.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'تمت التسوية'),
            new OA\Response(response: 422, description: 'سبب مفقود / اتجاه غير متطابق'),
        ],
    )]
    public function accountingCashAdjustment() {}
}
