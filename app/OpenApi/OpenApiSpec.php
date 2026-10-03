<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * جذر توثيق OpenAPI لمشروع Daway.
 *
 * هذا الملف هو المصدر الوحيد لبيانات الـ API العامة (info · servers ·
 * security scheme) — لا يمسّ أي منطق تشغيلي. بقية التوثيق موزّع على
 * Controllers/DTOs في `app/OpenApi/`.
 *
 * المصادقة: Laravel Sanctum عبر توكن Bearer في ترويسة `Authorization`،
 * وهو الأسلوب الفعلي المطبّق في `routes/api.php` (`auth:sanctum`).
 * لا تُدرج أي توكن أو سر حقيقي هنا.
 */
#[OA\OpenApi(
    info: new OA\Info(
        version: '1.0.0',
        title: 'Daway Backend API',
        description: <<<'TXT'
        واجهة Daway الخلفية — تربط المريض بالصيدلية *قبل* أن يتحرك
        (البحث عن دواء + تأكيد توفره).

        المصادقة عبر **Laravel Sanctum**: تُرسل التوكن في ترويسة
        `Authorization: Bearer <token>`. اضغط زر **Authorize** في الأعلى
        والصق التوكن (بدون كلمة Bearer — يضيفها Swagger تلقائيًا).

        ملاحظات:
        - المسارات المعلَّمة بـ 🔒 تتطلب مصادقة.
        - بعض المسارات مقيّدة بالدور (`role:pharmacy` أو `role:patient`)
          وتُرجع 403 عند استدعائها بدور غير مناسب.
        - كل الاستجابات بصيغة JSON وتتضمّن الحقل `success` عند الاقتضاء.
        TXT,
        contact: new OA\Contact(name: 'Daway'),
        license: new OA\License(name: 'Proprietary'),
    ),
    servers: [
        new OA\Server(url: '/', description: 'Current host'),
    ],
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'apiKey',
    in: 'header',
    name: 'Authorization',
    description: 'Laravel Sanctum bearer token. Paste only the token value from the login/OTP response; Swagger prefixes it with "Bearer ".',
)]
class OpenApiSpec
{
}
