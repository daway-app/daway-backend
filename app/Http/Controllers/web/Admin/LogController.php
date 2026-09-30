<?php

namespace App\Http\Controllers\web\Admin;

use App\Exports\LogsExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LogController extends Controller
{
    /**
     * Display a listing of the logs.
     *
     * @return View
     */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        // فلتر نوع النشاط: القيم المقبولة تطابق عمود event في activity_log
        // (Spatie يكتب created/updated/deleted)، و'auth' تُترجم لأحداث الدخول/الخروج.
        $event = (string) $request->query('event', '');
        if (! in_array($event, ['created', 'updated', 'deleted', 'auth'], true)) {
            $event = '';
        }

        // فلتر التاريخ: <input type="date"> يرسل Y-m-d فقط؛ أي قيمة أخرى تُهمل.
        $date = trim((string) $request->query('date', ''));
        if ($date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = '';
        }

        // Fetch logs using the Activity model, ordered by the most recent, and load the user
        $query = Activity::with(['causer', 'subject'])
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
            // P3: `whereDate('created_at', …)` تُغلّف العمود بدالة `date()` ⇒ تُبطل
            // فهرس `created_at` (الخطة كانت `type=index` = مسح كل الصفوف).
            // البديل: مقارنة نطاق قابلة للفهرسة (`type=range`) بنفس الدلالة تمامًا:
            // [بداية اليوم، بداية اليوم التالي) — تغطي اليوم كاملًا بما فيه الكسور
            // الثانية، ولا تتأثر بأي تحويل منطقة زمنية لأن التخزين datetime بلا tz.
            $start = Carbon::createFromFormat('Y-m-d', $date);
            if ($start !== false && $start->format('Y-m-d') === $date) {
                $query->where('created_at', '>=', $start->copy()->startOfDay())
                    ->where('created_at', '<', $start->copy()->startOfDay()->addDay());
            } else {
                // تاريخ مطابق للـregex لكنه غير صالح تقويميًا (مثل 2026-13-45) —
                // نُبقي المسار الأصلي حرفيًا حتى لا تتغيّر دلالة الحالة الحدّية.
                $query->whereDate('created_at', $date);
            }
        }

        // P3: `subject` كان يُحمَّل كسولًا لكل صف (morphTo) ⇒ 50 استعلامًا إضافيًا
        // لكل صفحة (logs/index.blade.php يستخدم `$log->subject`). تحميله مسبقًا
        // يجعل Eloquent يجلب كل نوع morph باستعلام واحد (≤ عدد الأنواع).
        // `causer` فارغ في معظم الصفوف — Eloquent يتجاهل النوع الفارغ بأمان.
        $logs = $query->latest()->paginate(50)->withQueryString();

        return view('logs.index', compact('logs', 'q', 'event', 'date'));
    }

    /**
     * Export logs to an Excel file.
     *
     * @return BinaryFileResponse
     */
    public function exportExcel()
    {
        // Fetch all logs for the export, and load the user
        $logs = Activity::with('causer')->latest()->get();

        // Generate the timestamp string
        $timestamp = Carbon::now()->format('Y-m-d_H-i');
        $filename = "logs_report_{$timestamp}.xlsx";

        return Excel::download(new LogsExport($logs), $filename);
    }
}
