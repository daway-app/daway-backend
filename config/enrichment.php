<?php

/**
 * إعدادات نظام إثراء بيانات الأدوية (Medicine Data Enrichment).
 * المبادئ: الأصل له أولوية، لا تخمين، لا كتابة قبل التحقق، ومطابقة متعددة الإشارات.
 */
return [
    // الدرجات المعتبرة (Constant placeholders وليست hard-coded في المنطق).
    'thresholds' => [
        // >= هذه القيمة → auto-accept (كتابة مباشرة إن الحقل فارغ)
        'auto_accept' => 0.90,
        // من هذه القيمة وما فوق حتى auto_accept → apply مع تسجيل low-confidence
        // 0.80–0.89 → جيد، 0.70–0.79 → مراجعة، أقل → لا قبول آلي إطلاقاً
        'review' => 0.70,
    ],

    // أوزان إشارات المطابقة (configurable — ليست معطلة بالكود)
    'signals' => [
        'barcode_exact' => 1.00,           // أقوى إشارة (matches directly)
        'manufacturer_exact' => 0.95,      // strong
        'strength_exact' => 0.95,          // strong
        'pack_size_exact' => 0.90,         // strong
        'active_ingredient_exact' => 0.90, // strong (generic_name في الكتالوج)
        'dosage_form_exact' => 0.70,       // medium
        'name_en_similarity' => 0.60,      // medium (يُعامل كإشارة مهمة جداً بواسطة الخوارزمية)
        'name_ar_similarity' => 0.60,      // medium
        'alias_match' => 0.55,             // weak/medium
    ],

    // مزوّد DrugsAPI الخارجي — معطّل افتراضياً حتى توفر الـcredentials
    'drugs_api' => [
        'enabled' => env('DRUGS_API_ENABLED', false),
        'base_url' => env('DRUGS_API_BASE_URL'),
        'key' => env('DRUGS_API_KEY'),
        // مسارات قابلة للضبط — لا يوجد endpoint مختلق؛ إنها placeholder
        // يجب تأكيدها من documentation المزوّد قبل التشغيل الفعلي.
        'search_path' => env('DRUGS_API_SEARCH_PATH'),
        'barcode_path' => env('DRUGS_API_BARCODE_PATH'),
        'timeout' => (int) env('DRUGS_API_TIMEOUT', 15),
        'max_retries' => (int) env('DRUGS_API_MAX_RETRIES', 2),
        // حماية من الضغط: طلبات/دقيقة لكل مزوّد
        'rate_limit' => (int) env('DRUGS_API_RATE_LIMIT', 60),
    ],

    // manual provider فقط مضمن (mapping محلي وأسماء عربية من mirror).
    // لا يقدم barcode خارجي أو صور خارجية - وقدماً هذا في وثائل التقرير.
    'manual_provider' => [
        'enabled' => env('ENRICHMENT_MANUAL_ENABLED', true),
        'mapping_file' => 'database/data/chatbot_medicines.json',
    ],
];
