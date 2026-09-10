<?php

namespace App\Http\Controllers\web\Patient;

use App\Http\Controllers\Controller;
use App\Models\User;

class PatientController extends Controller
{
    /**
     * A1-7: ترقيم على مستوى SQL بدل تحميل كل المرضى بالـ PHP + الكاش.
     */
    public function index()
    {
        $patients = User::where('role', 'patient')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $totalPatients = $patients->total();

        return view('patients.index', compact('patients', 'totalPatients'));
    }
}
