<?php

namespace App\Http\Controllers\web\Patient;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PatientController extends Controller
{
    public function index()
    {
        $perPage = 10;
        $page = (int) request()->get('page', 1);

        $data = Cache::remember('patients_list_cache', 30, function () {
            $rows = User::where('role', 'patient')->latest()->get()->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'phone' => $u->phone,
                    'is_active' => $u->is_active,
                    'created_at' => $u->created_at ? $u->created_at->format('Y-m-d H:i:s') : null,
                ];
            })->values()->all();
            return ['rows' => $rows, 'total' => count($rows), 'totalPatients' => count($rows)];
        });

        $items = array_map(function ($row) {
            $obj = (object) $row;
            if (!empty($obj->created_at)) {
                $obj->created_at = \Illuminate\Support\Carbon::parse($obj->created_at);
            }
            return $obj;
        }, array_slice($data['rows'], ($page - 1) * $perPage, $perPage));

        $patients = new \Illuminate\Pagination\LengthAwarePaginator($items, $data['total'], $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
        $totalPatients = $data['totalPatients'];

        return view('patients.index', compact('patients', 'totalPatients'));
    }
}