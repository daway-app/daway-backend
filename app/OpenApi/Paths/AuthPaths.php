<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * توثيق مسارات المصادقة والتسجيل (AuthController).
 *
 * المصدر: app/Http/Controllers/Api/AuthController.php + routes/api.php
 */
class AuthPaths
{
    #[OA\Post(
        path: '/api/otp/send',
        operationId: 'authSendOtp',
        summary: 'إرسال رمز OTP',
        description: 'يُرسل رمزًا مكوّنًا من 6 أرقام إلى رقم الهاتف. في البيئة الحالية يُعاد الرمز في الحقل `otp` لأغراض الاختبار. الرقم يجب أن يكون 10 خانات.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone'],
                properties: [
                    new OA\Property(property: 'phone', type: 'string', example: '0599123456', description: 'رقم هاتف من 10 خانات'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم إرسال الرمز',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'OTP sent successfully'),
                    new OA\Property(property: 'otp', type: 'string', example: '123456'),
                    new OA\Property(property: 'is_registered', type: 'boolean', example: true),
                ]),
            ),
            new OA\Response(response: 400, description: 'رقم هاتف غير صالح', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Invalid phone number'),
            ])),
            new OA\Response(response: 429, description: 'تجاوز حد المحاولات (throttle:otp)'),
        ],
    )]
    public function sendOtp() {}

    #[OA\Post(
        path: '/api/otp/verify',
        operationId: 'authVerifyOtp',
        summary: 'التحقق من OTP (دخول أو إكمال تسجيل)',
        description: 'يتفرّع السلوك حسب وجود الرقم: رقم موجود ⇒ تسجيل دخول وإرجاع توكن. رقم جديد ⇒ يتطلب بيانات التسجيل ويُعاد `registration_required: true`.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone', 'otp'],
                properties: [
                    new OA\Property(property: 'phone', type: 'string', example: '0599123456'),
                    new OA\Property(property: 'otp', type: 'string', example: '123456', description: '6 أرقام'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'نجاح — يعيد التوكن للمستخدم الموجود',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'token', type: 'string', description: 'Sanctum plain-text token'),
                ]),
            ),
            new OA\Response(response: 400, description: 'صيغة OTP غير صحيحة (للمستخدم الموجود)', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Invalid OTP format'),
            ])),
            new OA\Response(response: 422, description: 'مطلوب بيانات تسجيل (رقم جديد)', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'registration_required', type: 'boolean', example: true),
                new OA\Property(property: 'errors', type: 'object'),
            ])),
            new OA\Response(response: 429, description: 'تجاوز حد المحاولات (throttle:otp-verify)'),
        ],
    )]
    public function verifyOtp() {}

    #[OA\Post(
        path: '/api/login/pharmacy',
        operationId: 'authPharmacyLogin',
        summary: 'تسجيل دخول صيدلية',
        description: 'يُصادق بواسطة `pharmacy_id` (المعرّف المخصّص) + كلمة المرور. يعيد توكن Sanctum. الحساب غير المفعّل يُعيد 403 مع `code: account_inactive`.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['pharmacy_id', 'password'],
                properties: [
                    new OA\Property(property: 'pharmacy_id', type: 'string', example: 'PH-1001', description: 'pharmacies.pharmacy_custom_id'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم الدخول',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'Logged in successfully'),
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'user', type: 'object', properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'pharmacy_id', type: 'string'),
                            new OA\Property(property: 'role', type: 'string', example: 'pharmacy'),
                            new OA\Property(property: 'must_change_password', type: 'boolean'),
                        ]),
                        new OA\Property(property: 'token', type: 'string'),
                    ]),
                ]),
            ),
            new OA\Response(response: 401, description: 'بيانات دخول غير صحيحة', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Invalid login credentials'),
            ])),
            new OA\Response(response: 403, description: 'الحساب غير مفعّل', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Account is inactive'),
                new OA\Property(property: 'code', type: 'string', example: 'account_inactive'),
            ])),
            new OA\Response(response: 429, description: 'تجاوز حد المحاولات'),
        ],
    )]
    public function pharmacyLogin() {}

    #[OA\Post(
        path: '/api/register/pharmacy',
        operationId: 'authPharmacyRegister',
        summary: 'تسجيل حساب صيدلية جديد',
        description: 'يُنشئ حسابًا بحالة `pending_approval` (غير مفعّل). بعد موافقة الإدارة تُرسل بيانات الدخول للصيدلية.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['pharmacy_name', 'phone', 'region', 'password'],
                properties: [
                    new OA\Property(property: 'pharmacy_name', type: 'string', maxLength: 150),
                    new OA\Property(property: 'phone', type: 'string', maxLength: 20, description: 'فريد في users.phone'),
                    new OA\Property(property: 'region', type: 'string', maxLength: 150),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'تم إنشاء الحساب (بانتظار الموافقة)', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'pharmacy_name', type: 'string'),
                    new OA\Property(property: 'phone', type: 'string'),
                    new OA\Property(property: 'is_active', type: 'boolean', example: false),
                    new OA\Property(property: 'status', type: 'string', example: 'pending_approval'),
                ]),
            ])),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة / رقم مستخدم مسبقًا'),
            new OA\Response(response: 429, description: 'تجاوز حد المحاولات (throttle:register)'),
        ],
    )]
    public function pharmacyRegister() {}

    #[OA\Post(
        path: '/api/register/patient',
        operationId: 'authPatientRegister',
        summary: 'تسجيل مريض — الخطوة 1',
        description: 'يخزّن بيانات التسجيل مؤقتًا (10 دقائق) ويرسل OTP. الخطوة 2 = POST /api/otp/verify بـ phone+otp فقط.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone', 'name', 'age', 'terms_accepted'],
                properties: [
                    new OA\Property(property: 'phone', type: 'string', example: '0599123456', description: '10 خانات ويبدأ بـ 056-059، فريد'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'age', type: 'integer', minimum: 1, maximum: 120),
                    new OA\Property(property: 'birth_date', type: 'string', format: 'date', nullable: true, description: 'قبل أو يساوي اليوم'),
                    new OA\Property(property: 'terms_accepted', type: 'boolean', example: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'تم إرسال OTP', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'otp', type: 'string'),
                new OA\Property(property: 'is_registered', type: 'boolean', example: false),
            ])),
            new OA\Response(response: 422, description: 'بيانات ناقصة/غير صحيحة أو الرقم مسجّل', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'registration_required', type: 'boolean'),
                new OA\Property(property: 'is_registered', type: 'boolean'),
            ])),
            new OA\Response(response: 429, description: 'تجاوز حد المحاولات'),
        ],
    )]
    public function patientRegister() {}

    #[OA\Post(
        path: '/api/logout',
        operationId: 'authLogout',
        summary: 'تسجيل الخروج',
        description: 'يحذف التوكن الحالي فقط.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'تم الخروج', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Logged out successfully'),
            ])),
            new OA\Response(response: 401, description: 'غير مصادق'),
        ],
    )]
    public function logout() {}

    #[OA\Post(
        path: '/api/refresh-token',
        operationId: 'authRefreshToken',
        summary: 'تجديد التوكن',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'توكن جديد', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'token', type: 'string'),
            ])),
            new OA\Response(response: 401, description: 'غير مصادق'),
        ],
    )]
    public function refreshToken() {}
}
