<?php

/**
 * إعدادات نظام إثراء بيانات الأدوية (Medicine Data Enrichment).
 * المبادئ: الأصل له أولوية، لا تخمين، لا كتابة قبل التحقق، ومطابقة متعددة الإشارات.
 *
 * Cost Guard: النظام术后 مأبا FAIL-CLOSED — لا مزوّد مدفوع يعمل إلا بتفعيل
 * صريح من env. الافتراضي: صفر دولار — مزوّدات مجانية فقط.
 */
return [
    // آر पर偾 الدفع: يجب أن يظل false — أي مزوّد مدفوع مستقبلاً لا يعمل
    // إلا بتفعيل صريح (manual + conscious). لو true بالخطأ، providers المضافة
    // ستعمل (drugs_api المدفوع مثلًا) — لكن لا شيء يفعّله في الافتراضي.
    'paid_providers' => env('ENRICHMENT_PAID_PROVIDERS', false),

    // الدرجات المعتبرة (Constantplaceholders وليست hard-coded في المنطق).
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

    // ============ Provider FREE (مجاني بالكامل) =========================
    // في التووقع: الترتيب حسب الأولوية (أدمن الداتا المحلي أعلاه)؛
    // المنطق لا يعتمد فُرز الترتيب لكنه يُدرج bias matcher للحبل الأعلى score.

    // (1) LocalProvider — بيانات دِaway الموجودة (mapping + trade_name_ar + images مستودعة)
    'local' => [
        'enabled' => env('ENRICHMENT_LOCAL_ENABLED', true),
        'mapping_file' => 'database/data/chatbot_medicines.json',
    ],

    // (2) PalestinianProvider — مستلِم مصدر فلسطيني مرجعي (بدل مستخرجة endpoints غير موثوقة)
    // يُستخدم انحجاجـ مستند: services.moh.products_url (مستلَّم مسبقاً بواسطة moh:import).
    'palestinian' => [
        'enabled' => env('ENRICHMENT_PALESTINIAN_ENABLED', false),
    ],

    // (3) RxNorm / RxNav — NLM، مجاني بلا key (Public Domain).
    'rxnorm' => [
        'enabled' => env('ENRICHMENT_RXNORM_ENABLED', true),
        'base_url' => 'https://rxnav.nlm.nih.gov/REST',
        'timeout' => (int) env('ENRICHMENT_RXNORM_TIMEOUT', 10),
        'retries' => 1,
        'rate_limit' => 120, // مجاني لكن نحترم الخدمة العامة
    ],

    // (4) openFDA — مجاني (مفتاح API key مجاني، غير مطلوب بالآجل). لا الترويج NDC → EAN.
    'openfda' => [
        'enabled' => env('ENRICHMENT_OPENFDA_ENABLED', true),
        'base_url' => 'https://api.fda.gov',
        'timeout' => (int) env('ENRICHMENT_OPENFDA_TIMEOUT', 10),
        'retries' => 1,
        // مفتاح مجاني اختياري (النظام يعمل بلا مفتاح عند السقف الافتراضي 240/دقيقة)
        'key' => env('OPENFDA_API_KEY'),
        'rate_limit' => 240,
    ],

    // (5) DailyMed — NLM، مجاني بلا مفتاح (REST v2 JSON). source参考 = SETID
    'dailymed' => [
        'enabled' => env('ENRICHMENT_DAILYMED_ENABLED', true),
        'base_url' => 'https://dailymed.nlm.nih.gov/dailymed/services/v2',
        'timeout' => (int) env('ENRICHMENT_DAILYMED_TIMEOUT', 10),
        'retries' => 1,
        'rate_limit' => 120,
    ],

    // (6) Wikidata — مجاني بلا مفتاح. آر بالـwbsearchentities API فقط (onia SPARQL)
    'wikidata' => [
        'enabled' => env('ENRICHMENT_WIKIDATA_ENABLED', true),
        'base_url' => 'https://www.wikidata.org',
        'timeout' => (int) env('ENRICHMENT_WIKIDATA_TIMEOUT', 10),
        'retries' => 1,
        'rate_limit' => 240,
    ],

    // ============ PAID — معتنّل وغير مسموح (Cost Guard) ==================
    'drugs_api' => [
        'enabled' => env('DRUGS_API_ENABLED', false),
        'base_url' => env('DRUGS_API_BASE_URL'),
        'key' => env('DRUGS_API_KEY'),
        'search_path' => env('DRUGS_API_SEARCH_PATH'),
        'barcode_path' => env('DRUGS_API_BARCODE_PATH'),
        'timeout' => (int) env('DRUGS_API_TIMEOUT', 15),
        'max_retries' => (int) env('DRUGS_API_MAX_RETRIES', 2),
        'rate_limit' => (int) env('DRUGS_API_RATE_LIMIT', 60),
        'paid' => true,
    ],
];
