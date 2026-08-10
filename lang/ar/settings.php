<?php

return [
    'title' => 'إعدادات النظام',
    'main_heading' => '⚙️ إعدادات النظام العامة',
    'main_description' => 'إدارة الخيارات التشغيلية وإعدادات التطبيق لـ :site_name.',
    'save_changes' => '💾 حفظ التغييرات',
    'general_data_tab' => '🌐 البيانات العامة',
    'pharmacies_tab' => '🏥 الصيدليات والبحث',
    'notifications_tab' => '🔔 الإشعارات والإنذارات',
    'security_tab' => '🛡️ الأمان والنسخ الاحتياطي',

    // General Tab
    'basic_app_info' => 'معلومات التطبيق الأساسية',
    'basic_app_info_desc' => 'البيانات التي تمثل هوية المنصة أمام المستخدمين والصيدليات',
    'platform_name' => 'اسم المنصة',
    'platform_name_default' => 'دوائي - Daway',
    'support_email' => 'البريد الإلكتروني للدعم الفني',
    'contact_whatsapp' => 'رقم التواصل / الواتساب',
    'default_language' => 'اللغة الافتراضية للنظام',
    'lang_ar' => 'العربية (Arabic)',
    'lang_en' => 'الإنجليزية (English)',
    'short_description' => 'وصف مختصر للنظام',
    'short_description_default' => 'منصة إلكترونية متكاملة للبحث عن الأدوية وإدارة الصيدليات والوصول السريع للعلاجات.',

    // Pharmacies Tab
    'pharmacy_controls_search_rules' => 'ضوابط الصيدليات وقواعد البحث',
    'pharmacy_controls_search_rules_desc' => 'التحكم بنطاق البحث الجغرافي وقواعد انضمام الصيدليات',
    'max_search_radius' => 'أقصى شعاع بحث عن صيدلية (كيلومتر)',
    'max_medicine_results' => 'الحد الأقصى لنتائج الأدوية بالبحث',
    'auto_approve_pharmacies' => 'الموافقة التلقائية على الصيدليات الجديدة',
    'auto_approve_pharmacies_desc' => 'تفعيل هذا الخيار يتيح للصيدليات البدء مباشرة بعد التسجيل بدون مراجعة الإدارة.',
    'show_inactive_pharmacies' => 'إظهار الصيدليات غير النشطة في البحث',
    'show_inactive_pharmacies_desc' => 'عرض الصيدليات المغلقة أو التي لا تملك مخزوناً كافياً ضمن نتائج البحث.',

    // Notifications Tab
    'alerts_messages_settings' => 'إعدادات التنبيهات والرسائل',
    'alerts_messages_settings_desc' => 'التحكم بالإشعارات المرسلة للصيدليات والمستخدمين',
    'low_stock_alert' => 'تنبيه نقص المخزون للصيدليات',
    'low_stock_alert_desc' => 'إرسال إشعار تلقائي للصيدلية عندما يقل مخزون دواء معين عن 5 عبوات.',
    'email_notifications_new_operations' => 'إشعارات البريد الإلكتروني للعمليات الجديدة',
    'email_notifications_new_operations_desc' => 'تلقي بريد عند تسجيل صيدلية جديدة أو طلب دعم فني.',

    // Security Tab
    'maintenance_security' => 'وضع الصيانة وحماية النظام',
    'maintenance_security_desc' => 'إدارة صلاحيات الدخول وإتاحة النظام للمستخدمين',
    'enable_maintenance_mode' => 'تفعيل وضع الصيانة (Maintenance Mode)',
    'enable_maintenance_mode_desc' => 'إغلاق التطبيق مؤقتاً أمام جميع المستخدمين والصيدليات باستثناء مدراء النظام.',
    'session_timeout' => 'مهلة انتهاء الجلسة (بالدقائق)',
    'next_backup' => 'النسخ الاحتياطي القادم',
    'next_backup_value' => 'يومياً الساعة 12:00 منتصف الليل',
    'cancel_changes' => 'إلغاء التغييرات',
];
