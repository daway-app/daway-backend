<?php

namespace App\Http\Controllers\web\Patient;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    /**
     * A1-7: ترقيم على مستوى SQL بدل تحميل كل المرضى بالـ PHP + الكاش.
     */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $patients = User::where('role', 'patient')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%");
                });
            })
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $totalPatients = User::where('role', 'patient')->count();

        return view('patients.index', compact('patients', 'totalPatients', 'q'));
    }
}
