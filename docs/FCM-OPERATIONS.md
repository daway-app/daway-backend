# DAWAK — FCM Operations Guide

مرجع تشغيلي لـpush notifications. الكود في `app/Services/Fcm` و`app/Services/Notifications`.

---

## 1. دورة حياة الإشعار

```
حدث (طلب/استفسار/مخزون)
  └─ Notification::create  →  صف في قاعدة البيانات (المصدر الموثوق)
       └─ NotificationDispatcher::createAndPush
            └─ DeferredFcmSender  (يؤجّل إلى DB::afterCommit)
                 └─ FcmPushService::deliver  →  Firebase HTTP v1
                      └─ تسجيل push_status على الصف
```

**قاعدة ذهبية:** قاعدة البيانات أولًا دائمًا. فشل Firebase لا يُسقط السجل ولا العملية الأصلية.

---

## 2. حالات التسليم

| `push_status` | المعنى | هل تُعاد المحاولة؟ |
|---|---|---|
| `sent` | توكن واحد على الأقل قبل الرسالة | ❌ لا |
| `failed` | **كل** التوكنات رُفضت، أو استثناء | ✅ نعم حتى `--max` |
| `skipped` | لا أجهزة مسجّلة، أو SDK غير مهيأ | ❌ لا (لا شيء لإرساله) |
| `null` | لم تُحاول بعد | — |

**الوصول الجزئي = `sent`.** لو نجح جهاز وفشل آخر، إعادة المحاولة ستُكرر الإشعار لمن استلمه. الفشل الكامل وحده يُعاد.

`push_error_code` يحمل رموز FCM المعروفة فقط (`UNREGISTERED`, `UNAVAILABLE`, `QUOTA_EXCEEDED`, …) — لا نصوص من المزوّد.

---

## 3. إعادة المحاولة

```bash
php artisan fcm:retry-failed                 # حد 100 إشعار، 3 محاولات
php artisan fcm:retry-failed --limit=500 --max=5
```

- **قفل `Cache::lock('fcm:retry-failed')`** يمنع تشغيلين متزامنين — cron + يدوي كانا سيقرآن نفس الصفوف ويُرسلان مرتين.
- الإشعارات التي وصلت سقف المحاولات تُستثنى تلقائيًا.
- لا يحتاج Worker: الأمر يعمل داخل عملية مستقلة.

### التشغيل الدوري على Render

Render الحالي: خدمة `web` واحدة، `QUEUE_CONNECTION=database` بلا عامل، ولا `schedule:run` في `CMD`.

**الخيار الموصى به — Cron Job مستقل:**

```yaml
# render.yaml (لا يُطبَّق الآن)
  - type: cron
    name: daway-fcm-retry
    runtime: docker
    schedule: "*/15 * * * *"
    dockerCommand: php artisan fcm:retry-failed --limit=200
    envVars:
      - fromGroup: daway-backend-env
```

**بديل بلا خدمة إضافية:** استدعاء الأمر من جهاز/سيرفر خارجي عبر SSH أو endpoint محمي. غير مفضّل — يضيف سطح هجوم.

**بديل ثالث:** تشغيل `php artisan schedule:work` كخدمة `worker` — يحلّ أيضًا تذكيرات الاستحقاق وتنظيف الإيصالات اليتيمة، لكنه يستهلك خدمة إضافية.

> ⚠️ **لا تفعّل `schedule:run` داخل خدمة `web`**: مع أكثر من نسخة سيتكرر التنفيذ.

---

## 4. إعدادات مطلوبة على Render

| المتغير | الغرض |
|---|---|
| `FIREBASE_CREDENTIALS` | مسار/JSON حساب الخدمة. بدونه كل push = `skipped` |
| `CACHE_STORE` | يجب ألا يكون `array` — القفل يحتاج مخزنًا مشتركًا (`database`/`redis`) |
| `QUEUE_CONNECTION` | غير مستخدم في هذا التصميم (مقصود) |

**لا تُسجَّل الأسرار:** `FcmPushService` يسجّل اسم الصنف فقط (`exception => RuntimeException`)، لا مسار الشهادة ولا التوكن ولا جسم الطلب.

---

## 5. للواجهة (Flutter)

1. `POST /api/device-tokens` بعد الدخول، وكرّرها عند `onTokenRefresh`.
2. `DELETE /api/device-tokens/current` عند الخروج.
3. الإشعار الظاهر **عام**: «إشعار جديد من دواك». المحتوى من `GET /api/notifications`.
4. `data` تحمل: `notification_id`, `type`, `medicine_id`, `order_id`, `sub_order_id` (فارغة = `""` لا محذوفة).

---

## 6. قبل النشر

```bash
php artisan migrate --force   # إلزامي: أعمدة push_*
php artisan fcm:retry-failed  # تحقق يدوي من عمل الأمر
```
