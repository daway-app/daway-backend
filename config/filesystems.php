<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Settlement transfer receipts
    |--------------------------------------------------------------------------
    |
    | إشعارات التحويل المالي تُخزَّن على قرص منفصل قابل للتغيير من متغير بيئة
    | واحد. الافتراضي `local` (تخزين داخل الحاوية) — وهو مؤقت على Render:
    | تُفقد الملفات عند كل إعادة نشر. للإنتاج اضبط:
    |     SETTLEMENT_RECEIPT_DISK=r2
    | بعد تهيئة متغيرات R2_* أدناه. الكود يفشل صراحة (500) بدل تسجيل تحويل
    | بلا إثبات لو كان القرص غير مهيّأ أو عامًّا.
    |
    */

    'settlement_receipt_disk' => env('SETTLEMENT_RECEIPT_DISK', 'local'),


    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => public_path('uploads'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/uploads',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Cloudflare R2 — تخزين دائم لإشعارات التحويل
        |----------------------------------------------------------------------
        |
        | R2 متوافق مع S3، فيُستخدم محرك `s3` نفسه عبر league/flysystem-aws-s3-v3.
        | ملاحظات إلزامية:
        |  · region = auto (R2 لا يستخدم مناطق AWS).
        |  · endpoint = https://<ACCOUNT_ID>.r2.cloudflarestorage.com
        |  · visibility = private ← ممنوع أي وصول عام؛ التنزيل يمر عبر متحكم
        |    محمي بالمصادقة فقط (أدمن، أو الصيدلية المالكة).
        |  · throw = true ← نريد استثناءً صريحًا عند فشل الكتابة بدل `false` صامت.
        |  · لا تُضف `url` (لا يوجد رابط عام أصلًا، ولا نريد توليد روابط موقّعة
        |    تلقائيًا).
        |
        | المتغيرات: R2_ACCESS_KEY_ID · R2_SECRET_ACCESS_KEY · R2_BUCKET ·
        |            R2_ENDPOINT · R2_REGION (اختياري، افتراضي auto)
        |
        */
        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => env('R2_REGION', 'auto'),
            'bucket' => env('R2_BUCKET'),
            'endpoint' => env('R2_ENDPOINT'),
            'use_path_style_endpoint' => env('R2_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
