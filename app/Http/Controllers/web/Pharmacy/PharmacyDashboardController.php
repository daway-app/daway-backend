<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PharmacyDashboardController extends Controller
{
    public function index()
    {
        return view('pharmacy.dashboard');
    }
}
