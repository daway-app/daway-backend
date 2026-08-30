<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PharmacyAlternativeController extends Controller
{
    /**
     * قائمة أدوية الصيدلية التي لها أدوية بديلة مسجلة.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $pharmacyMedicines = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->whereHas('medicine.alternatives')
            ->with(['medicine', 'medicine.alternatives'])
            ->orderByDesc('id')
            ->get()
            ->map(function (PharmacyMedicine $pm) {
                $medicine = $pm->medicine;

                return [
                    'id' => $pm->id,
                    'pharmacy_id' => $pm->pharmacy_id,
                    'medicine_id' => $pm->medicine_id,
                    'price' => (float) $pm->price,
                    'quantity' => (int) $pm->quantity,
                    'medicine' => [
                        'id' => $medicine?->id,
                        'trade_name' => $medicine?->trade_name,
                        'active_ingredient' => $medicine?->active_ingredient,
                    ],
                    'alternatives' => $medicine?->alternatives
                        ->map(fn (Medicine $a) => [
                            'id' => $a->id,
                            'trade_name' => $a->trade_name,
                            'active_ingredient' => $a->active_ingredient,
                        ])
                        ->values(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب البدائل بنجاح',
            'data' => $pharmacyMedicines,
        ]);
    }

    /**
     * ربط دواء بديل بدواء ضمن مخزون الصيدلية.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $data = $request->validate([
            'base_medicine_id' => 'required|integer|exists:pharmacy_medicines,id',
            'alternative_id' => 'required|integer|exists:medicines,id',
        ]);

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $base = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('id', $data['base_medicine_id'])
            ->with('medicine')
            ->first();

        if (! $base) {
            return response()->json(['success' => false, 'message' => 'الدواء الأساسي غير موجود في مخزون الصيدلية'], 404);
        }

        $baseMedicine = $base->medicine;
        if (! $baseMedicine) {
            return response()->json(['success' => false, 'message' => 'الدواء الأساسي غير موجود'], 404);
        }

        if ((int) $data['alternative_id'] === (int) $baseMedicine->id) {
            return response()->json(['success' => false, 'message' => 'لا يمكن اختيار نفس الدواء كبديل'], 422);
        }

        $exists = DB::table('alternative_medicine')
            ->where('medicine_id', $baseMedicine->id)
            ->where('alternative_id', $data['alternative_id'])
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'message' => 'الدواء البديل مضاف مسبقاً'], 409);
        }

        // H6: منع الزوج المعكوس — إذا (بديل ← أساس) مسجلة، لا تُضف (أساس ← بديل)
        // لتجنب التكرار في نتائج alternatives().
        $reverseExists = DB::table('alternative_medicine')
            ->where('medicine_id', $data['alternative_id'])
            ->where('alternative_id', $baseMedicine->id)
            ->exists();

        if ($reverseExists) {
            return response()->json(['success' => false, 'message' => 'هذا الدواء مسجل مسبقاً كأساس لهذا البديل'], 409);
        }

        $baseMedicine->alternatives()->attach($data['alternative_id']);

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة الدواء البديل بنجاح',
        ], 201);
    }

    /**
     * فك ربط دواء بديل من مخزون الصيدلية.
     */
    public function destroy(Request $request, PharmacyMedicine $base, Medicine $alternative): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        if (! $pharmacy || $base->pharmacy_id !== $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'الدواء غير موجود في مخزون الصيدلية'], 404);
        }

        $base->loadMissing('medicine');
        $baseMedicine = $base->medicine;
        if (! $baseMedicine) {
            return response()->json(['success' => false, 'message' => 'الدواء الأساسي غير موجود'], 404);
        }

        $deleted = DB::table('alternative_medicine')
            ->where('medicine_id', $baseMedicine->id)
            ->where('alternative_id', $alternative->id)
            ->delete();

        if (! $deleted) {
            return response()->json(['success' => false, 'message' => 'البديل غير موجود'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الدواء البديل بنجاح',
        ]);
    }
}
