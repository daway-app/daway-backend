<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    // تخزين الصور على Cloudinary (رفع غير موقّع عبر upload_preset)
    'cloudinary' => [
        'cloud' => env('CLOUDINARY_CLOUD_NAME'),
        'upload_preset' => env('CLOUDINARY_UPLOAD_PRESET'),
        // مجلد الجذر لكل صور التطبيق داخل Cloudinary
        'folder' => env('CLOUDINARY_FOLDER', 'daway'),
    ],

    // خدمة OCR (قراءة اسم الدواء من صورة العلبة)
    // المهلة الافتراضية 8 ثوانٍ (كانت 20) — C-1
    'daway_ocr' => [
        'base_url' => env('DAWAY_OCR_BASE_URL'),
        'timeout'  => (int) env('DAWAY_OCR_TIMEOUT', 8),
        'key'      => env('DAWAY_OCR_KEY'),
    ],

    // خدمة تحليل نية رسالة المريض (نص → intent + drug_name).
    // اختيارية بالكامل: عند غياب base_url يعمل المساعد بمستخرج محلي حتمي
    // بلا أي استدعاء خارجي. المهلة 8 ثوانٍ — لا نحتجز عمال PHP على اتصال بطيء.
    'daway_ai' => [
        'base_url' => env('DAWAY_AI_BASE_URL'),
        'timeout'  => (int) env('DAWAY_AI_TIMEOUT', 8),
        'key'      => env('DAWAY_AI_KEY'),
    ],

    // البروكسي الموثوق انتقل إلى config/trustedproxy.php (يقرؤه TrustProxies وقت الطلب) — H-2/H-3

    // Firebase Web Push — قيم العميل العامة (تُقرأ من layouts/app.blade.php عبر config())
    'firebase' => [
        'api_key'              => env('FIREBASE_API_KEY'),
        'auth_domain'          => env('FIREBASE_AUTH_DOMAIN'),
        'project_id'           => env('FIREBASE_PROJECT_ID'),
        'storage_bucket'       => env('FIREBASE_STORAGE_BUCKET'),
        'messaging_sender_id'  => env('FIREBASE_MESSAGING_SENDER_ID'),
        'app_id'               => env('FIREBASE_APP_ID'),
        'vapid_key'            => env('FIREBASE_VAPID_KEY'),
    ],

    // مزامنة كتالوج وزارة الصحة (moh:sync) — القيم الافتراضية هي العناوين الحالية المضمّنة في الأمر
    'moh' => [
        'ssl_verify' => env('MOH_SSL_VERIFY', false),
        'products_url' => env('MOH_PRODUCTS_URL', 'https://pharmacy.moh.ps/service/getRegisterProducts'),
        'prices_url'   => env('MOH_PRICES_URL', 'https://pharmacy.moh.ps/service/getDrugsPublic'),
    ],

    // SMSGate — مزوّد رسائل SMS للـ OTP والتنبيهات (https://sms-gate.app)
    // التوثيق: POST /3rdparty/v1/messages باستخدام Basic Auth
    // حالياً يُستخدم لإرسال OTP فقط — الفشل غير مسموح به للـ OTP delivery يُسجّل فقط.
    // C-1: القيم الفارغة = SMSGate غير مفعّل (no-op)
    'smsgate' => [
        'base_url'    => env('SMSGATE_BASE_URL'),
        'username'    => env('SMSGATE_USERNAME'),
        'password'    => env('SMSGATE_PASSWORD'),
        'sender'      => env('SMSGATE_SENDER', 'Daway'),
        'country_code' => env('SMSGATE_COUNTRY_CODE', '+970'),
    ],

    // Android SMS Gateway — يحوّل هاتف Android + SIM إلى SMS Gateway محلي.
    // يستخدم تطبيق: https://github.com/capcom6/android-sms-gateway
    // يُرسل OTP مباشرة عبر SIM بدلاً من SMSGate Cloud.
    // Priority: Android إذا enabled → SMSGate إذا enabled → no-op
    'android_sms_gateway' => [
        'enabled'  => env('ANDROID_SMS_GATEWAY_ENABLED', false),
        'base_url' => env('ANDROID_SMS_GATEWAY_BASE_URL'),
        'username' => env('ANDROID_SMS_GATEWAY_USERNAME'),
        'password' => env('ANDROID_SMS_GATEWAY_PASSWORD'),
        'timeout'  => (int) env('ANDROID_SMS_GATEWAY_TIMEOUT', 10),
        'country_code' => env('ANDROID_SMS_GATEWAY_COUNTRY_CODE', '+970'),
        'device_id' => env('ANDROID_SMS_GATEWAY_DEVICE_ID'),
    ],

];
