<?php

namespace App\Http\Controllers\web\Admin;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\LogsExport;
use Spatie\Activitylog\Models\Activity;
use Carbon\Carbon;

class LogController extends Controller
{
    /**
     * Display a listing of the logs.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        // Fetch logs using the Activity model, ordered by the most recent, and load the user
        $logs = Activity::with('causer')->latest()->paginate(20);

        return view('logs.index', compact('logs'));
    }

    /**
     * Export logs to an Excel file.
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
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
