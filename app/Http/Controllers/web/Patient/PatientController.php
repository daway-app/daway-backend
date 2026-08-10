<?php

namespace App\Http\Controllers\web\Patient;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function index()
    {
        $patients = User::where('role', 'patient')->latest()->paginate(10);
        // You can add more detailed statistics here as needed
        $totalPatients = User::where('role', 'patient')->count();

        return view('patients.index', compact('patients', 'totalPatients'));
    }
}
