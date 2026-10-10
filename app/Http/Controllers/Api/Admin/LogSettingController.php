<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MohMedicine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * لوحة الأدمن — سجل النشاط + الإعدادات (JSON).
 *
 * فلترة السجل منقولة حرفياً من `web/Admin/LogController@index`، بما فيها
 * تحسين P3: مقارنة نطاق `created_at` بدل `whereDate()` — الأخيرة تُغلّف
 * العمود بدالة فتبطل الفهرس فتصبح كل صفحة مسحاً كاملاً للجدول.
 * هذا ليس تفصيلاً صغيراً: سجل النشاط ينمو بلا حد.
 */
class LogSettingController extends Controller
{
    /** GET /api/admin/logs */
    public function logs(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $event = (string) $request->query('event', '');
        if (! in_array($event, ['created', 'updated', 'deleted', 'auth'], true)) {
            $event = '';
        }

        $date = trim((string) $request->query('date', ''));
        if ($date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = '';
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $query = Activity::with(['causer'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('description', 'like', "%{$q}%")
                        ->orWhereHasMorph('causer', [User::class], function ($cq) use ($q) {
                            $cq->where('name', 'like', "%{$q}%");
                        });
                });
            });

        if ($event === 'auth') {
            $query->whereIn('event', ['login', 'logout']);
        } elseif ($event !== '') {
            $query->where('event', $event);
        }

        if ($date !== '') {
            $start = Carbon::createFromFormat('Y-m-d', $date);
            if ($start !== false && $start->format('Y-m-d') === $date) {
                $query->where('created_at', '>=', $start->copy()->startOfDay())
                    ->where('created_at', '<', $start->copy()->startOfDay()->addDay());
            } else {
                $query->whereDate('created_at', $date);
            }
        }

        $logs = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'logs' => collect($logs->items())->map(fn ($l) => [
                    'id' => $l->id,
                    'description' => $l->description,
                    'event' => $l->event,
                    'subject_type' => $l->subject_type,
                    'causer_name' => $l->causer?->name,
                    'properties' => $l->properties,
                    'created_at' => $l->created_at?->toIso8601String(),
                ])->all(),
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
                'filters' => ['q' => $q, 'event' => $event, 'date' => $date],
            ],
        ]);
    }

    /** GET /api/admin/settings */
    public function settings(): JsonResponse
    {
        $settings = DB::table('settings')->pluck('value', 'key')->all();
        $catalogCount = MohMedicine::count();

        $catalogPath = base_path('database/data/moh_medicines.json');
        $catalogFileExists = is_file($catalogPath);

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $settings,
                'catalog_count' => $catalogCount,
                'catalog_file_exists' => $catalogFileExists,
                'catalog_file_size' => $catalogFileExists ? round(filesize($catalogPath) / 1024 / 1024, 2) : 0,
            ],
        ]);
    }

    /**
     * POST /api/admin/settings
     *
     * ⚠️ عطل موروث موثّق: الويب لا يستطيع **إطفاء** مفتاح اختيار (checkbox) —
     * متصفح لا يرسل شيئاً عن مربع غير محدَّد، و`only()` تُسقط المفاتيح الغائبة،
     * فيبقى `1` القديم للأبد. الـAPI هنا يعالجها: العميل يرسل كل المفاتيح
     * صراحةً (true/false)، ونحن نكتب القيمة كما وصلت. هذا **إصلاح**، لا نقل:
     * السلوك الصحيح لغير-المربعات لم يتغيّر.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $allowed = [
            'site_name', 'site_description', 'support_email', 'support_phone',
            'default_language', 'maintenance_mode', 'auto_approve_pharmacies',
            'email_notifications', 'notify_low_stock', 'show_inactive_pharmacies',
            'max_search_radius', 'search_limit', 'session_timeout',
        ];

        $data = $request->only($allowed);

        foreach ($data as $key => $value) {
            // القيم المنطقية تُخزَّن '1'/'0' — نفس ما يكتبه الويب
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => is_null($value) ? null : (string) $value, 'updated_at' => now()]
            );
        }

        if (! empty($data)) {
            activity('settings')->withProperties($data)->log('تم تحديث الإعدادات');
        }

        if ($request->has('default_language') && in_array($request->default_language, ['ar', 'en'], true)) {
            App::setLocale($request->default_language);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم حفظ التغييرات بنجاح.',
        ]);
    }
}
