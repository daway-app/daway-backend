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
        $query = Activity::with('causer')
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
            $query->whereDate('created_at', $date);
        }

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
