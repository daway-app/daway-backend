# Daway Error Codes

| Code                   | HTTP Status | Description (Message)                          |
| ---------------------- | ----------- | ---------------------------------------------- |
| **200**                | 200         | Request successful (Login, OTP verify, etc.)   |
| VALIDATION_ERROR       | 422         | بيانات غير صحيحة (Request body format invalid) |
| UNAUTHORIZED           | 401         | يجب تسجيل الدخول (Token missing or expired)    |
| FORBIDDEN              | 403         | ليس لديك صلاحية (Access denied)                |
| NOT_FOUND              | 404         | المورد غير موجود (Resource not found)          |
| PHONE_EXISTS           | 409         | الرقم مسجل مسبقاً (Duplicate phone number)     |
| PHARMACY_NOT_FOUND     | 404         | الصيدلية غير موجودة                            |
| MEDICINE_NOT_FOUND     | 404         | الدواء غير موجود                               |
| INSUFFICIENT_STOCK     | 400         | الكمية غير كافية                               |
| AI_SERVICE_UNAVAILABLE | 503         | خدمة الذكاء الاصطناعي غير متاحة                |
| TOKEN_EXPIRED          | 401         | انتهت صلاحية التوكن                            |
| ACCOUNT_INACTIVE       | 403         | الحساب موقوف (Account blocked)                 |
| INVALID_CREDENTIALS    | 401         | بيانات اعتماد غير صحيحة (Wrong phone/password) |
| OTP_INVALID            | 400         | رمز التحقق غير صحيح                            |
| OTP_EXPIRED            | 400         | انتهت صلاحية رمز التحقق                        |
| PHARMACY_ID_INVALID    | 400         | معرف الصيدلية غير صحيح                         |
| WORKING_HOURS_INVALID  | 400         | ساعات العمل غير صحيحة                          |
| LOCATION_REQUIRED      | 400         | الموقع مطلوب                                   |
| RATING_INVALID         | 400         | قيمة التقييم غير صحيحة (يجب أن تكون بين 1-5)   |
| FAVORITE_EXISTS        | 409         | العنصر موجود بالفعل في المفضلة                 |
| REMINDER_INVALID       | 400         | بيانات التذكير غير صحيحة                       |
