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

        // Fetch logs using the Activity model, ordered by the most recent, and load the user
        $logs = Activity::with('causer')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('description', 'like', "%{$q}%")
                        ->orWhereHasMorph('causer', [User::class], function ($cq) use ($q) {
                            $cq->where('name', 'like', "%{$q}%");
                        });
                });
            })
            ->latest()
            ->paginate(7)
            ->withQueryString();

        return view('logs.index', compact('logs', 'q'));
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
