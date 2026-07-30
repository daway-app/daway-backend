---

## 📄 **الملف 2: `docs/ERROR_CODES.md`**

```markdown
# Daway Error Codes

| Code                   | HTTP Status | Description                     |
| ---------------------- | ----------- | ------------------------------- |
| VALIDATION_ERROR       | 422         | بيانات غير صحيحة                |
| UNAUTHORIZED           | 401         | يجب تسجيل الدخول                |
| FORBIDDEN              | 403         | ليس لديك صلاحية                 |
| NOT_FOUND              | 404         | المورد غير موجود                |
| PHONE_EXISTS           | 409         | الرقم مسجل مسبقاً               |
| PHARMACY_NOT_FOUND     | 404         | الصيدلية غير موجودة             |
| MEDICINE_NOT_FOUND     | 404         | الدواء غير موجود                |
| INSUFFICIENT_STOCK     | 400         | الكمية غير كافية                |
| AI_SERVICE_UNAVAILABLE | 503         | خدمة الذكاء الاصطناعي غير متاحة |
| TOKEN_EXPIRED          | 401         | انتهت صلاحية التوكن             |
| ACCOUNT_INACTIVE       | 403         | الحساب موقوف                    |
| INVALID_CREDENTIALS    | 401         | بيانات اعتماد غير صحيحة         |
| OTP_INVALID            | 400         | رمز التحقق غير صحيح             |
| OTP_EXPIRED            | 400         | انتهت صلاحية رمز التحقق         |
| PHARMACY_ID_INVALID    | 400         | معرف الصيدلية غير صحيح          |
| WORKING_HOURS_INVALID  | 400         | ساعات العمل غير صحيحة           |
| LOCATION_REQUIRED      | 400         | الموقع مطلوب                    |
| RATING_INVALID         | 400         | قيمة التقييم غير صحيحة (1-5)    |
| FAVORITE_EXISTS        | 409         | العنصر موجود بالفعل في المفضلة  |
| REMINDER_INVALID       | 400         | بيانات التذكير غير صحيحة        |
```
