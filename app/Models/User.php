<?php

namespace App\Models;

// نستدعي الكلاس الأساسي لموديلات المصادقة (Authentication) بدل Model العادي
// لأنه هاد الموديل هو يلي رح يسجل دخول فيه المستخدمين (patient / pharmacy / admin)
use Illuminate\Foundation\Auth\User as Authenticatable;

// Trait خاص بتوليد بيانات وهمية للتجارب (Factory) — مفيد بالـ Seeding والاختبارات
use Illuminate\Database\Eloquent\Factories\HasFactory;

// Trait بيعطي المستخدم القدرة يستخدم Laravel Sanctum لتوليد API Tokens (لو رح تستخدم Sanctum لل API)
use Laravel\Sanctum\HasApiTokens;

// نستدعي كلاس العلاقات المختلفة يلي رح نستخدمها تحت (HasOne, HasMany)
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    // نفعّل الـ Traits يلي حكينا عنها فوق جوا الكلاس
    use HasApiTokens, HasFactory;

    /**
     * $table: بنحدد اسم الجدول بالداتابيز يلي هاد الموديل بيمثله.
     * أصلاً Laravel رح يخمن "users" تلقائياً من اسم الكلاس User، بس منحطها صراحة
     * حتى الكود يضل واضح ومفهوم لأي حد يفتحه.
     */
    protected $table = 'users';

    /**
     * $fillable: قائمة الأعمدة يلي مسموح تنعبى بشكل جماعي (Mass Assignment)
     * عن طريق User::create([...]) مثلاً. أي عمود مش موجود هون، ما رح يقبل Laravel
     * يعبيه من مصفوفة الـ input مباشرة (حماية أمنية).
     */
    protected $fillable = [
        'name',              // اسم المستخدم
        'phone',              // رقم الهاتف (unique بالجدول)
        'email',              // الإيميل (اختياري، unique بالجدول)
        'password',           // كلمة المرور (رح تتخزن مشفرة تلقائياً بفضل الـ cast تحت)
        'role',               // نوع المستخدم: patient / pharmacy / admin
        'status',             // 1 = مفعّل، 0 = موقوف
        'phone_verified_at',  // تاريخ توثيق رقم الهاتف
    ];

    /**
     * $hidden: أعمدة ما بدنا نظهرها لما نحول الموديل لـ JSON
     * (مثلاً لما نرجع response من الـ API). كلمة السر لازم تنخفي دايماً.
     */
    protected $hidden = [
        'password',
    ];

    /**
     * $casts: تحويل تلقائي لنوع العمود لما نقرأه أو نكتبه.
     * - password: Laravel رح يشفرها تلقائياً (hashed) كل مرة نحطلها قيمة جديدة
     * - phone_verified_at: يتحول تلقائياً لكائن Carbon (تاريخ) بدل نص عادي
     * - status: يتحول لـ boolean (true/false) بدل 1/0
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => 'boolean',
        ];
    }

    /**
     * علاقة: كل مستخدم عنده صيدلية واحدة بس (لو دوره pharmacy).
     * hasOne لأنه بجدول pharmacies فيه عمود user_id واحد لكل صيدلية (unique).
     * يعني: $user->pharmacy رح يرجعلك سطر Pharmacy واحد.
     */
    public function pharmacy(): HasOne
    {
        return $this->hasOne(Pharmacy::class);
    }

    /**
     * علاقة: كل مستخدم عنده ملف طبي واحد بس (medical_profiles.user_id هو unique).
     * $user->medicalProfile رح يرجعلك سطر MedicalProfile واحد.
     */
    public function medicalProfile(): HasOne
    {
        return $this->hasOne(MedicalProfile::class);
    }

    /**
     * علاقة: مستخدم واحد ممكن يكون عندو أكتر من تذكير دواء (reminders.user_id).
     * $user->reminders رح ترجعلك Collection فيها كل تذكيراته.
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /**
     * علاقة: مستخدم واحد ممكن يعمل أكتر من تقييم لصيدليات مختلفة (ratings.user_id).
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    /**
     * علاقة: مستخدم واحد ممكن يضيف أكتر من عنصر للمفضلة (favorites.user_id).
     * لاحظ إنه الـ Favorite نفسه فيه علاقة polymorphic (favoritable) رح نشرحها
     * بموديل Favorite لحاله.
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * علاقة: مستخدم واحد ممكن توصلو أكتر من إشعار (notifications.user_id).
     */
    public function notifications_custom(): HasMany
    {
        // سميناها notifications_custom بدل notifications عشان ما تتعارض
        // مع دالة notifications() الجاهزة يلي Laravel بيوفرها لنظام الإشعارات المدمج فيه.
        // إذا مش ناوي تستخدم نظام Laravel Notifications الجاهز، ممكن ترجعها notifications() عادي.
        return $this->hasMany(Notification::class);
    }
}
