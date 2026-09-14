<?php

/*
|--------------------------------------------------------------------------
| نصوص الاستيراد الجماعي للمخزون — عربي
|--------------------------------------------------------------------------
| كل النصوص التي تظهر للصيدلي أو تُخزَّن في السجل/الإشعارات.
| مفاتيح الحالة والقرارات ثابتة تقنياً (constants) — وهنا ترجمتها للعرض فقط.
*/

return [

    // --- عام ---
    'title' => 'استيراد المخزون بالجملة',
    'subtitle' => 'حدّث مخزون صيدليتك كاملاً من ملف Excel أو CSV واحد، مع مراجعة كل سطر قبل الحفظ.',
    'back_to_inventory' => 'رجوع إلى المخزون',

    // --- الخطوات ---
    'step_download' => '١) نزّل القالب',
    'step_upload' => '٢) ارفع الملف',
    'step_review' => '٣) راجع النتائج',
    'step_confirm' => '٤) أكّد الاستيراد',

    // --- القالب ---
    'template_title' => 'قالب الملف',
    'template_hint' => 'استخدم القالب الفارغ للبدء من الصفر، أو نزّل مخزونك الحالي وعدّل عليه.',
    'download_empty_template' => 'تحميل قالب فارغ',
    'download_current_inventory' => 'تحميل المخزون الحالي',
    'column_guide_title' => 'الأعمدة',
    'column_guide_hint' => 'الأعمدة المعلَّمة بـ (*) إلزامية. أي عمود آخر يُهمل مع تنبيه.',

    // --- الأعمدة ---
    'col_trade_name' => 'الاسم التجاري (إنجليزي)',
    'col_trade_name_ar' => 'الاسم التجاري (عربي)',
    'col_active_ingredient' => 'المادة الفعالة',
    'col_price' => 'السعر',
    'col_quantity' => 'الكمية',
    'col_barcode' => 'الباركود',
    'col_min_stock' => 'حد المخزون المنخفض',
    'col_is_available' => 'متوفر',

    // --- الرفع ---
    'upload_title' => 'رفع الملف',
    'upload_hint' => 'الصيغ المدعومة: xlsx، xls، csv — بحد أقصى :max ميجابايت و:rows سطر.',
    'upload_choose' => 'اختر ملفاً',
    'upload_submit' => 'تحليل الملف',
    'upload_processing' => 'جاري تحليل الملف… قد يستغرق لحظات.',

    // --- الملخّص ---
    'summary_title' => 'ملخّص المعاينة',
    'summary_total' => 'إجمالي الأسطر',
    'summary_matched' => 'مطابقة مؤكدة',
    'summary_review' => 'تحتاج مراجعة',
    'summary_unmatched' => 'غير معروفة',
    'summary_duplicate' => 'أسطر مكرّرة',
    'summary_error' => 'أسطر بها أخطاء',
    'summary_matched_hint' => 'عُثر عليها في الكتالوج مباشرة — ستُحدَّث بلا سؤال.',
    'summary_review_hint' => 'اقتراحات غير مؤكدة أو مطابقة من كتالوج الوزارة — تحتاج قرارك.',
    'summary_unmatched_hint' => 'لا يوجد لها مقابل — يمكنك ربطها بدواء موجود أو إنشاء دواء جديد أو تجاهلها.',
    'summary_duplicate_hint' => 'أكثر من سطر يشير لنفس الدواء — اختر أيّ سطر يفوز.',
    'summary_error_hint' => 'بيانات غير صالحة — لن تُستورد. نزّل تقرير الأخطاء لتصحيحها.',

    // --- تنبيهات عامة ---
    'unknown_columns_notice' => 'أعمدة غير معروفة تم تجاهلها: :columns',
    'file_name' => 'الملف',
    'session_expires' => 'تنتهي صلاحية هذه الجلسة في :at.',

    // --- الجدول ---
    'table_title' => 'أسطر الملف',
    'filter_all' => 'الكل',
    'filter_needs_decision' => 'تحتاج قراراً',
    'filter_errors' => 'أخطاء',
    'row_number' => 'السطر',
    'input_name' => 'المكتوب في الملف',
    'matched_medicine' => 'الدواء المطابق',
    'row_status' => 'الحالة',
    'row_decision' => 'القرار',
    'no_rows' => 'لا توجد أسطر لعرضها.',

    // --- حالات الصف ---
    'status_EXACT_EN' => 'مطابقة تامة (إنجليزي)',
    'status_EXACT_AR_NORMALIZED' => 'مطابقة تامة (عربي)',
    'status_ALIAS_MATCH' => 'مرادف محفوظ',
    'status_MOH_MATCH' => 'كتالوج الوزارة',
    'status_FUZZY_MATCH' => 'مطابقة تقريبية',
    'status_REVIEW_REQUIRED' => 'تحتاج مراجعة',
    'status_UNMATCHED' => 'غير معروف',
    'status_INVALID_ROW' => 'سطر غير صالح',
    'status_DUPLICATE' => 'مكرّر',

    // --- القرارات ---
    'decision_link' => 'ربط بدواء موجود',
    'decision_create' => 'إنشاء دواء جديد',
    'decision_skip' => 'تجاهل السطر',
    'decision_pending' => 'بانتظار قرارك',
    'decision_search_placeholder' => 'ابحث في الكتالوج…',
    'decision_no_results' => 'لا نتائج مطابقة.',
    'decision_create_hint' => 'سيُنشأ الدواء في الكتالوج العام باستخدام الاسم والمادة الفعالة.',

    // --- أخطاء الصفوف ---
    'err_missing_name' => 'الاسم التجاري (إنجليزي أو عربي) مطلوب.',
    'err_invalid_price' => 'السعر غير صالح — لا يقبل السالب أو النص.',
    'err_invalid_quantity' => 'الكمية مطلوبة ويجب أن تكون عدداً صحيحاً غير سالب.',
    'err_invalid_min_stock' => 'حد المخزون المنخفض غير صالح — عدد صحيح غير سالب.',
    'err_invalid_is_available' => 'قيمة التوفّر غير مفهومة (استخدم نعم/لا).',

    // --- تحذيرات الصفوف ---
    'warn_missing_price' => 'السعر فارغ — سيُسجَّل صفراً.',
    'warn_price_deviation' => 'السعر مختلف كثيراً عن السعر الرسمي — تأكد أنه مقصود.',

    // --- الدمج ---
    'merge_title' => 'أسطر مكرّرة',
    'merge_hint' => 'الكمية هنا قيمة مطلقة، فلا نجمع الأسطر تلقائياً. اختر السطر الذي يمثّل الواقع.',
    'merge_keep_last' => 'اعتمد السطر الأخير',
    'merge_keep_first' => 'اعتمد السطر الأول',
    'merge_skip_all' => 'تجاهل كل الأسطر',
    'merge_group_label' => 'الدواء: :name',
    'merge_rows_label' => 'الأسطر: :rows',
    'merge_pending' => 'لم تحدّد قراراً لهذه المجموعة بعد.',
    'duplicate_winner' => 'سيُعتمد — الفائز في المجموعة',
    'duplicate_loser' => 'سيُتجاهل — خسر قرار الدمج',

    // --- التأكيد ---
    'confirm_title' => 'تأكيد الاستيراد',
    'confirm_hint' => 'لن يُحفظ أي شيء في المخزون قبل ضغطك على الزر أدناه.',
    'confirm_commit' => 'تنفيذ الاستيراد',
    'confirm_pending_notice' => 'ما زال هناك :count سطر بانتظار قرارك.',
    'confirm_creating_notice' => 'سيُنشأ :count دواء جديد في الكتالوج العام.',
    'confirm_irreversible' => 'العملية تُحدّث كميات المخزون مباشرةً.',
    'cancel_import' => 'إلغاء الجلسة',
    'cancel_confirm' => 'سيُلغى هذا الاستيراد ولن تُحفظ أي تغييرات. متابعة؟',
    'download_errors' => 'تنزيل تقرير الأخطاء',

    // --- نتيجة التنفيذ ---
    'done_title' => 'تم الاستيراد',
    'done_committed_rows' => 'أسطر حُدّثت',
    'done_skipped_rows' => 'أسطر تُجوهلت',
    'done_created_medicines' => 'أدوية جديدة',
    'done_learned_aliases' => 'مرادفات محفوظة',
    'done_low_stock' => 'أصناف تحت حد النقص',
    'done_back' => 'العودة إلى المخزون',

    // --- الإشعار ---
    'notif_import_finished' => 'تم استيراد مخزونك: :count صنف محدَّث، و:low صنف تحت حد النقص.',

    // --- أخطاء الخدمة (تُعرض للمستخدم) ---
    'error_not_previewed' => 'لا يمكن تطبيق القرارات قبل تحليل الملف. ارفع الملف أولاً.',
    'error_invalid_merge' => 'قرار دمج غير صالح.',
    'error_invalid_action' => 'قرار غير صالح على أحد الأسطر.',
    'error_unknown_medicine' => 'الدواء المختار في السطر :row غير موجود في الكتالوج.',
    'error_unknown_moh' => 'عنصر كتالوج الوزارة المختار في السطر :row ليس من اقتراحات هذا السطر.',
    'error_create_needs_ingredient' => 'لا يمكن إنشاء دواء من السطر :row بلا اسم تجاري ومادة فعالة.',
    'error_invalid_row' => 'السطر :row غير صالح ولا يمكن إنشاء دواء منه.',
    'error_pending_rows' => 'ما زال هناك :count سطر بانتظار قرارك قبل التنفيذ.',
    'error_already_committed' => 'هذه الجلسة نُفِّذت مسبقاً.',
    'error_commit_failed' => 'فشل تنفيذ الاستيراد ولم يُحفظ أي تغيير. جرّب مرة أخرى.',
    'error_expired' => 'انتهت صلاحية جلسة الاستيراد. ارفع الملف من جديد.',
    'error_upload_failed' => 'تعذّر رفع الملف. جرّب مرة أخرى.',
    'error_bad_extension' => 'صيغة الملف غير مدعومة. المسموح: :allowed.',
    'error_file_too_large' => 'حجم الملف أكبر من الحد المسموح (:max ميجابايت).',
    'error_bad_mime' => 'نوع الملف غير مطابق لصيغته. تأكد أنه ملف Excel أو CSV حقيقي.',
    'error_empty_file' => 'الملف فارغ.',
    'error_missing_header' => 'لم نتعرّف على صف العناوين. تأكد من استخدام القالب.',
    'error_missing_columns' => 'أعمدة إلزامية ناقصة: :columns.',
    'error_too_many_rows' => 'عدد الأسطر يتجاوز الحد المسموح (:max سطر).',
    'error_no_data_rows' => 'لا توجد أسطر بيانات في الملف.',
    'error_unreadable_file' => 'تعذّر قراءة الملف. تأكد أنه غير تالف وأنه بصيغة مدعومة.',

    // --- رؤوس تقرير الأخطاء ---
    'export_errors_title' => 'تقرير أخطاء الاستيراد',
    'export_status' => 'الحالة',
    'export_errors' => 'الأخطاء',
    'export_warnings' => 'التحذيرات',
    'export_suggestions' => 'الاقتراحات',
    'export_matched' => 'المطابق',
    'export_template_title' => 'قالب استيراد المخزون',
    'export_current_title' => 'المخزون الحالي',
    'export_no_value' => '',
    'export_list_separator' => ' | ',
];
