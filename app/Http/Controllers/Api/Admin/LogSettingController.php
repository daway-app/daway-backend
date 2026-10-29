<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exports\LogsExport;
use App\Http\Controllers\Controller;
use App\Models\MohMedicine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
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
    /**
     * Build the filtered activity query shared by `logs()` and `exportLogs()`.
     *
     * 🔴 THE EXPORT MUST USE THIS TOO. If the export re-derived its own query,
     * the button would download a file that does not match the table the admin
     * is looking at — the single worst failure mode for an export.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Builder, 1: array<string, string>}
     */
    private function filteredLogQuery(Request $request): array
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

        return [$query, ['q' => $q, 'event' => $event, 'date' => $date]];
    }

    /** GET /api/admin/logs */
    public function logs(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        [$query, $filters] = $this->filteredLogQuery($request);

        $logs = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'logs' => collect($logs->items())->map(fn ($l) => [
                    'id' => $l->id,
                    'description' => $l->description,
                    'event' => $l->event,
                    'subject_type' => $l->subject_type,
                    // The Blade table renders `class_basename(subject_type) #id`.
                    // `subject_type` alone is not enough to reproduce it, so the
                    // id travels too — and only the id, never the loaded subject:
                    // hydrating every polymorphic subject for a read-only audit
                    // list would cost a query per row for data nothing displays.
                    'subject_id' => $l->subject_id,
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
                'filters' => $filters,
            ],
        ]);
    }

    /**
     * GET /api/admin/logs/export — the xlsx download behind the header button.
     *
     * Reuses `App\Exports\LogsExport` unchanged, and honours the same filters as
     * `logs()` (see `filteredLogQuery()`). NOT paginated: an export that carried
     * only the visible page would defeat its own purpose.
     *
     * The web route serves this from a session-authenticated GET, which an SPA
     * cannot use — it holds a bearer token, and a bare `<a href>` would send no
     * Authorization header. The client therefore fetches the bytes with its
     * token and saves the Blob itself.
     */
    public function exportLogs(Request $request)
    {
        [$query] = $this->filteredLogQuery($request);

        $logs = $query->latest()->get();

        $timestamp = Carbon::now()->format('Y-m-d_H-i');
        $filename = "logs_report_{$timestamp}.xlsx";

        return Excel::download(new LogsExport($logs), $filename);
    }

    /** GET /api/admin/settings */
    public function settings(): JsonResponse    {
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
