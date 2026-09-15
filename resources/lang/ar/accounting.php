<?php

/**
 * نصوص وحدة المحاسبة (Pharmacy Accounting).
 *
 * ملاحظة معمارية: هذه الوحدة Frontend-only حاليًا — لا يوجد Backend محاسبة.
 * البيانات المعروضة mock من App\Support\Accounting\AccountingMockData،
 * والنصوص هنا جاهزة كما هي عند ربط الـAPI لاحقًا.
 */
return [
    'sidebar' => [
        'section_title' => 'المحاسبة',
        'overview' => 'نظرة عامة',
        'sales' => 'المبيعات',
        'purchases' => 'المشتريات',
        'expenses' => 'المصروفات',
        'suppliers' => 'الموردون',
        'customers' => 'العملاء',
        'cash_register' => 'الصندوق',
        'payments' => 'المدفوعات',
        'profit_loss' => 'الأرباح والخسائر',
        'daily_closing' => 'الإغلاق اليومي',
        'reports' => 'التقارير',
        'soon' => 'قريبًا',
    ],

    'common' => [
        'currency' => '₪',
        'search' => 'بحث',
        'clear_filters' => 'مسح الفلاتر',
        'all' => 'الكل',
        'date' => 'التاريخ',
        'type' => 'النوع',
        'reference' => 'المرجع',
        'description' => 'الوصف',
        'amount' => 'المبلغ',
        'payment_method' => 'طريقة الدفع',
        'status' => 'الحالة',
        'actions' => 'الإجراءات',
        'view' => 'عرض',
        'print' => 'طباعة',
        'export' => 'تصدير',
        'refund' => 'إرجاع',
        'record_payment' => 'تسجيل دفعة',
        'save' => 'حفظ',
        'cancel' => 'إلغاء',
        'close' => 'إغلاق',
        'apply' => 'تطبيق',
        'from' => 'من',
        'to' => 'إلى',
        'total' => 'الإجمالي',
        'subtotal' => 'المجموع الفرعي',
        'discount' => 'الخصم',
        'paid' => 'المدفوع',
        'remaining' => 'المتبقي',
        'quantity' => 'الكمية',
        'qty' => 'الكمية',
        'price' => 'السعر',
        'unit_price' => 'سعر الوحدة',
        'medicine' => 'الدواء',
        'barcode' => 'الباركود',
        'remove' => 'إزالة',
        'today' => 'اليوم',
        'last_7_days' => 'آخر 7 أيام',
        'last_30_days' => 'آخر 30 يومًا',
        'this_month' => 'هذا الشهر',
        'results_count' => ':count نتيجة',
        'mock_notice' => 'بيانات تجريبية — الواجهة جاهزة للربط بالـAPI',
    ],

    'payment_methods' => [
        'cash' => 'نقدًا',
        'bank_transfer' => 'حوالة بنكية',
        'card' => 'بطاقة',
        'credit' => 'آجل',
        'other' => 'أخرى',
    ],

    'payment_types' => [
        'sale' => 'مبيعات',
        'purchase' => 'مشتريات',
        'expense' => 'مصروفات',
        'deposit' => 'إيداع',
        'withdrawal' => 'سحب',
        'customer_payment' => 'دفعة عميل',
        'supplier_payment' => 'دفعة مورد',
    ],

    'statuses' => [
        'paid' => 'مدفوع',
        'partially_paid' => 'مدفوع جزئيًا',
        'unpaid' => 'غير مدفوع',
        'refunded' => 'مُرجَع',
        'cancelled' => 'ملغى',
        'open' => 'مفتوح',
        'closed' => 'مغلق',
        'overdue' => 'متأخر',
    ],

    'overview' => [
        'title' => 'نظرة عامة على المحاسبة',
        'heading' => 'المحاسبة',
        'subtitle' => 'ملخص مالي لصيدلية :pharmacy',
        'kpi_today_sales' => 'مبيعات اليوم',
        'kpi_today_purchases' => 'مشتريات اليوم',
        'kpi_today_expenses' => 'مصروفات اليوم',
        'kpi_today_profit' => 'أرباح اليوم',
        'kpi_cash_balance' => 'رصيد الصندوق',
        'kpi_outstanding_debts' => 'ديون مستحقة',
        'chart_sales_title' => 'حركة المبيعات',
        'chart_sales_desc' => 'إجمالي المبيعات خلال الفترة المختارة',
        'chart_expenses_title' => 'توزيع المصروفات',
        'chart_expenses_desc' => 'المصروفات حسب الفئة خلال الشهر الحالي',
        'recent_transactions' => 'آخر الحركات',
        'recent_transactions_desc' => 'أحدث العمليات المالية المسجّلة',
        'alerts_title' => 'تنبيهات',
        'alerts_desc' => 'أمور تحتاج انتباهك',
        'alert_supplier_due' => 'دفعة مورد مستحقة',
        'alert_supplier_due_desc' => 'مستحق لـ:supplier بمبلغ :amount',
        'alert_customer_overdue' => 'دين عميل متأخر',
        'alert_customer_overdue_desc' => ':customer — متأخر منذ :days يومًا',
        'alert_low_cash' => 'رصيد الصندوق منخفض',
        'alert_low_cash_desc' => 'الرصيد الحالي :amount — أقل من الحد الآمن',
        'alert_register_unclosed' => 'صندوق غير مُغلق',
        'alert_register_unclosed_desc' => 'الصندوق رقم :register مفتوح منذ :time',
        'no_alerts' => 'لا توجد تنبيهات',
        'no_alerts_desc' => 'كل شيء تحت السيطرة',
        'no_transactions' => 'لا توجد حركات بعد',
        'no_transactions_desc' => 'ستظهر هنا أول عملية مالية تسجّلها',
        'quick_actions' => 'إجراءات سريعة',
        'new_sale' => 'فاتورة بيع جديدة',
        'add_expense' => 'إضافة مصروف',
        'view_reports' => 'عرض التقارير',
    ],

    'sales' => [
        'title' => 'المبيعات',
        'heading' => 'المبيعات',
        'subtitle' => 'كل فواتير البيع وقيم التحصيل',
        'new_sale' => 'فاتورة جديدة',
        'search_placeholder' => 'ابحث برقم الفاتورة أو اسم العميل...',
        'date_range' => 'الفترة',
        'filter_payment' => 'طريقة الدفع',
        'filter_status' => 'الحالة',
        'col_invoice' => 'رقم الفاتورة',
        'col_date' => 'التاريخ',
        'col_customer' => 'العميل',
        'col_items' => 'الأصناف',
        'col_subtotal' => 'المجموع الفرعي',
        'col_discount' => 'الخصم',
        'col_total' => 'الإجمالي',
        'col_paid' => 'المدفوع',
        'col_remaining' => 'المتبقي',
        'col_payment' => 'الدفع',
        'col_status' => 'الحالة',
        'col_actions' => 'الإجراءات',
        'empty' => 'لا توجد مبيعات بعد',
        'empty_desc' => 'ابدأ بأول فاتورة بيع من زر «فاتورة جديدة»',
        'no_results' => 'لا نتائج مطابقة',
        'no_results_desc' => 'جرّب تغيير الفلاتر أو كلمة البحث',
        'walk_in' => 'زائر نقدي',
        'items_count' => ':count صنف',
    ],

    'pos' => [
        'title' => 'فاتورة بيع جديدة',
        'heading' => 'فاتورة بيع جديدة',
        'subtitle' => 'امسح الباركود أو ابحث عن الدواء ثم أضفه للسلة',
        'barcode_label' => 'مسح الباركود',
        'barcode_placeholder' => 'امسح الباركود هنا أو الصقه ثم Enter...',
        'barcode_hint' => 'يدعم EAN-13 · EAN-8 · UPC-A · GTIN-14',
        'barcode_ready' => 'حقل الباركود جاهز للماسح',
        'barcode_looking' => 'جارٍ البحث عن الدواء...',
        'barcode_found' => 'تمت إضافة :name',
        'barcode_not_found' => 'لا يوجد دواء بهذا الباركود',
        'barcode_invalid' => 'تنسيق باركود غير معروف',
        'barcode_not_in_stock' => 'الدواء موجود لكن غير مُسجّل في مخزونك',
        'barcode_out_of_stock' => ':name نافد من المخزون',
        'search_label' => 'البحث عن دواء',
        'search_placeholder' => 'اكتب اسم الدواء أو المادة الفعّالة...',
        'search_hint' => 'اكتب حرفين على الأقل للبحث',
        'search_no_results' => 'لا يوجد دواء مطابق',
        'search_error' => 'تعذّر البحث — حاول مرة أخرى',
        'search_results' => 'نتائج البحث',
        'cart_title' => 'السلة',
        'cart_empty' => 'السلة فارغة',
        'cart_empty_desc' => 'امسح باركود أو ابحث عن دواء لإضافته',
        'cart_items' => 'أصناف السلة',
        'clear_cart' => 'إفراغ السلة',
        'clear_cart_confirm' => 'هل أنت متأكد من إفراغ السلة؟',
        'customer_label' => 'العميل',
        'customer_walk_in' => 'زائر نقدي',
        'customer_search_placeholder' => 'ابحث عن عميل أو اكتب اسمًا...',
        'invoice_title' => 'الفاتورة',
        'invoice_number' => 'رقم الفاتورة',
        'invoice_number_pending' => 'يُولَّد عند الحفظ',
        'subtotal' => 'المجموع الفرعي',
        'discount' => 'الخصم',
        'tax' => 'الضريبة',
        'tax_not_supported' => 'غير مدعومة في النظام الحالي',
        'total' => 'الإجمالي',
        'paid' => 'المدفوع',
        'remaining' => 'المتبقي',
        'payment_method' => 'طريقة الدفع',
        'complete_sale' => 'إتمام البيع',
        'processing' => 'جارٍ الحفظ...',
        'sale_saved' => 'تم تسجيل الفاتورة بنجاح',
        'sale_no_backend' => 'لم تُحفظ الفاتورة: نظام المحاسبة الخلفي غير موجود بعد. الواجهة فقط.',
        'sale_failed' => 'تعذّر إتمام الفاتورة',
        'stock_available' => 'متوفر: :qty',
        'stock_none' => 'غير متوفر',
        'not_in_inventory' => 'غير مُسجّل بمخزونك',
        'out_of_stock_warn' => 'الكمية المطلوبة تتجاوز المتوفر (:qty)',
        'invalid_qty' => 'أدخل كمية صحيحة أكبر من صفر',
        'invalid_amount' => 'أدخل مبلغًا صحيحًا',
        'paid_exceeds_total' => 'المدفوع أكبر من الإجمالي',
        'lines_required' => 'أضف صنفًا واحدًا على الأقل قبل إتمام البيع',
    ],

    'expenses' => [
        'title' => 'المصروفات',
        'heading' => 'المصروفات',
        'category' => [
            'salaries' => 'رواتب',
            'rent' => 'إيجار',
            'electricity' => 'كهرباء',
            'water' => 'ماء',
            'internet' => 'إنترنت',
            'transport' => 'مواصلات',
            'maintenance' => 'صيانة',
            'taxes' => 'ضرائب',
            'other' => 'أخرى',
        ],
    ],

    'invoice' => [
        'title' => 'تفاصيل الفاتورة',
        'heading' => 'فاتورة :number',
        'pharmacy' => 'الصيدلية',
        'customer' => 'العميل',
        'item' => 'الصنف',
        'items' => 'الأصناف',
        'payment_history' => 'سجل الدفعات',
        'created_by' => 'أنشأها',
        'created_at' => 'تاريخ الإنشاء',
        'print_ready' => 'جاهزة للطباعة',
        'download_soon' => 'تنزيل PDF غير مدعوم في النظام الحالي',
        'not_found' => 'الفاتورة غير موجودة',
        'no_payments' => 'لا توجد دفعات مسجّلة على هذه الفاتورة',
        'items_pending' => 'بنود الفاتورة غير متوفّرة',
        'items_pending_desc' => 'الفاتورة تحتوي :count صنفًا — تفاصيل البنود تظهر عند ربط نظام المحاسبة الخلفي.',
    ],

    /*
    |----------------------------------------------------------------------
    | الباركود — مفردات الحالة و الرسائل
    |----------------------------------------------------------------------
    |
    | المبدأ الحاكم لهذا القسم: **قاعدة moh_medicines لا تحتوي باركود كاملًا.**
    | التغطية تُبنى تدريجيًا من الصيدليات نفسها، لذا «باركود غير معروف» حالة
    | طبيعية يمرّ بها الصيدلي كل يوم — لا خطأ.
    |
    | ⚠️ قيود حقيقية في المخطط تفرض الصياغة:
    |   - medicine_barcodes لا يحتوي pharmacy_id (لا يمكن نسب الإضافة لصيدلية)
    |   - barcode فريد عالميًا (نفس الرقم لا يُربط بدواءين)
    | لذلك لا نستعمل أبدًا عبارات مثل «تمت إضافته لمخزونك» أو «سيراه كل الصيدليات».
    | الرسالة المعتمدة: «تم حفظ ربط الباركود» — صادقة و لا تَعِد بما لا يحدث.
    */
    'barcode' => [
        'field_label' => 'الباركود',
        'field_placeholder' => 'امسح الباركود أو اكتبه…',
        'scan_button' => 'مسح الباركود',
        'scan_button_lg' => 'امسح الباركود',
        'manual_button' => 'إدخال يدوي',
        'scanned_code' => 'الباركود الممسوح',
        'clear' => 'تفريغ',
        'search_another' => 'ابحث عن دواء آخر',

        // المبدأ — يُعرض كتلميح دائم تحت الحقل ليصبح السلوك متوقّعًا لا مفاجئًا
        'not_every_medicine_hint' => 'لا تملك كل الأدوية باركودًا مسجّلًا بعد. لو لم يظهر شيء، اربطه بدواء موجود.',
        'codes_count' => '{1} باركود واحد|[2,10] :count باركودات|[11,*] :count باركود',

        'status' => [
            'unknown' => 'غير مرتبط بعد',
            'pending' => 'بانتظار التوثيق',
            'verified' => 'موثَّق',
            'conflict' => 'مرتبط بدواء آخر',
        ],

        'status_hint' => [
            'unknown' => 'لم يُسجَّل هذا الباركود في القاعدة بعد — يمكنك ربطه بدواء موجود.',
            'pending' => 'الباركود مسجَّل لكنه لم يُوثَّق. يمكنك البيع به مباشرة.',
            'verified' => 'الباركود مسجَّل وموثَّق.',
            'conflict' => 'هذا الباركود مسجَّل لدواء آخر ويحتاج مراجعة قبل الاستخدام.',
        ],

        // نتيجة المسح
        'found_title' => 'تم التعرّف على الدواء',
        'found_add' => 'أضف إلى الفاتورة',
        'found_added' => 'أُضيف إلى الفاتورة',
        'looking' => 'جارٍ البحث عن الباركود…',
        'lookup_failed' => 'تعذّر البحث عن الباركود. تحقّق من الاتصال ثم أعد المحاولة.',
        'empty_code' => 'امسح باركود أو اكتبه أولًا.',
        'no_stock_warning' => 'هذا الدواء غير متوفّر في مخزونك حاليًا.',

        // نافذة الربط — المسار الطبيعي للباركود المجهول
        'link_modal_title' => 'ربط الباركود بدواء',
        'link_modal_sub' => 'اختر الدواء الذي ينتمي إليه هذا الباركود.',
        'link_note_title' => 'هذا الباركود غير مسجَّل بعد',
        'link_note_body' => 'القاعدة تُبنى تدريجيًا. اربط الباركود بدواء موجود ليُعرَف من المرة القادمة.',
        'link_search_label' => 'ابحث عن الدواء',
        'link_search_placeholder' => 'الاسم التجاري عربي أو إنجليزي…',
        'link_search_help' => 'الاسم العلمي أو التجاري — النتائج من قاعدة الأدوية الحالية.',
        'link_search_empty' => 'اكتب حرفين على الأقل للبحث.',
        'link_no_results' => 'لا توجد نتائج مطابقة.',
        'link_chosen' => 'الدواء المختار:',
        'link_change' => 'تغيير',
        'link_save' => 'حفظ الربط',
        'link_saved' => 'تم حفظ ربط الباركود.',
        'link_saved_hint' => 'سيُعرَف هذا الباركود في عمليات المسح القادمة.',
        'link_need_choice' => 'اختر دواءً أولًا.',
        'link_unavailable_title' => 'الربط غير متاح حاليًا',
        'link_unavailable_body' => 'حفظ الروابط يحتاج نقطة خدمة غير مضافة بعد. لا تُسجَّل أي بيانات الآن.',

        // التعارض — لا يُتجاوز من الواجهة أبدًا
        'conflict_title' => 'الباركود مرتبط بدواء آخر',
        'conflict_desc' => 'هذا الرقم مسجَّل مسبقًا لدواء مختلف. لا يمكن ربط الرقم الواحد بدواءين.',
        'conflict_linked_to' => 'مسجَّل حاليًا لـ',
        'conflict_you_tried' => 'تحاول ربطه بـ',
        'conflict_note_title' => 'ما الذي يحدث الآن؟',
        'conflict_note_body' => 'لن نغيّر الربط تلقائيًا. راجع الحالة أولًا — إما أن الباركود خاطئ، أو أن الدواء مكرّر.',
        'conflict_review' => 'مراجعة',

        // بطاقة التغطية — كل الأرقام تأتي من الباك-إند عند توفّره
        'coverage_title' => 'تغطية الباركود',
        'coverage_subtitle' => 'كم صنفًا في مخزونك مرتبط بباركود مسجَّل',
        'coverage_linked' => 'مرتبط بباركود',
        'coverage_unlinked' => 'بلا باركود',
        'coverage_percent' => 'نسبة التغطية',
        'coverage_mock_notice' => 'أرقام توضيحية — تُحسب من مخزونك عند تفعيل نقطة الخدمة.',
        'coverage_of' => 'من :total صنفًا',
        'coverage_all_done' => 'كل أصنافك مرتبطة بباركود.',

        // آخر نشاط
        'activity_title' => 'آخر نشاط الباركود',
        'activity_subtitle' => 'عمليات المسح والربط في هذه الجلسة',
        'activity_empty_title' => 'لا نشاط بعد',
        'activity_empty_desc' => 'عمليات المسح والربط التي تجريها ستظهر هنا.',

        // المخزون
        'inventory_column' => 'الباركود',
        'inventory_link_action' => 'ربط',
        'inventory_linked_action' => 'إدارة',
        'inventory_filter_all' => 'الكل',
        'inventory_filter_linked' => 'مرتبط',
        'inventory_filter_unlinked' => 'بلا باركود',
        'inventory_no_barcode' => 'بلا باركود',
        'inventory_multiple' => ':count باركودات',
        'inventory_add_fields' => 'بيانات الباركود',
    ],

    /*
    |----------------------------------------------------------------------
    | المسح بالهاتف — الهاتف كقارئ باركود عن بُعد للويب
    |----------------------------------------------------------------------
    |
    | ⚠️ حقائق تقنية تحكم هذه النصوص:
    |   - **الهاتف** هو من يشغّل الكاميرا ويُفكّ ترميز الباركود.
    |     الويب يستقبل **نصًّا** فقط — لا صور ولا فيديو.
    |   - الجلسة مؤقتة وقصيرة العمر، ولا تُبنى على «نفس الحساب» وحدها بل على
    |     pharmacy + user/session + جلسة مسح مؤقتة.
    |   - لا نعرض في الواجهة أي توكن دائم أو مفتاح API أو كلمة مرور:
    |     رمز الاقتران، اسم الجهاز، وحالة الاتصال فقط.
    */
    'scanner' => [
        'button' => 'المسح بالهاتف',
        'modal_title' => 'وصّل هاتفك',
        'modal_sub' => 'استخدم كاميرا الهاتف لمسح الباركود، وتصل النتيجة هنا مباشرة.',

        'step_1' => 'افتح تطبيق Daway على هاتفك.',
        'step_2' => 'امسح رمز الـQR أو أدخل رمز الاقتران.',
        'step_3' => 'اترك هذه النافذة مفتوحة أثناء المسح.',

        // الحالات الثمانية
        'state_waiting' => 'بانتظار الهاتف…',
        'state_connecting' => 'جارٍ الاتصال…',
        'state_connected' => 'الهاتف متصل — جاهز للمسح',
        'state_scanning' => 'وصل باركود، جارٍ البحث…',
        'state_received' => 'تم استلام الباركود',
        'state_disconnected' => 'انقطع الاتصال بالهاتف',
        'state_expired' => 'انتهت صلاحية الجلسة',
        'state_error' => 'خطأ في الاتصال',

        'pairing_code' => 'رمز الاقتران',
        'pairing_code_hint' => 'أدخله في تطبيق الهاتف إن تعذّر مسح الـQR.',
        'qr_alt' => 'رمز اقتران جلسة المسح',
        'qr_caption' => 'امسحه بكاميرا الهاتف للاقتران',
        'qr_pending' => 'سيظهر رمز الـQR عند تفعيل نقطة الاقتران.',
        'qr_pending_alt' => 'رمز الـQR غير متاح بعد',

        'received_label' => 'تم استلام الباركود',
        'queue_label' => 'طابور المسح',

        'devices_title' => 'أجهزة المسح',
        'devices_empty' => 'لا جهاز مرتبط بعد.',
        'device_active' => 'الجهاز النشط',
        'disconnect' => 'فصل',
        'device_request_title' => 'جهاز آخر يريد الاتصال',
        'device_allow' => 'سماح',
        'device_reject' => 'رفض',

        'reconnect' => 'إعادة الاتصال',
        'new_session' => 'جلسة جديدة',
        'close_scanner' => 'إغلاق الماسح',
        'simulate' => 'محاكاة مسح',

        'no_camera_note' => 'الكاميرا تعمل على الهاتف فقط. هذه النافذة تستقبل رقم الباركود ولا تفتح كاميرا.',
        'session_expired_note' => 'الـQR القديم لم يعد صالحًا. أنشئ جلسة جديدة للاستمرار.',
        'disconnected_note' => 'سلتك محفوظة كما هي.',

        'mock_title' => 'وضع تجريبي',
        'mock_body' => 'نقاط خدمة جلسة المسح غير مضافة بعد. ما تراه هنا للعرض فقط ولا يُسجَّل في النظام.',
    ],

    'nav' => [
        'breadcrumb_dashboard' => 'لوحة الصيدلية',
        'breadcrumb_accounting' => 'المحاسبة',
    ],
];
