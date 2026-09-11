<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Medicine;
use App\Support\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redirect;

class MedicineController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * A1-7: ترقيم وتجميع على مستوى SQL بدل تحميل الكتالوج كاملاً + كل صفوف
     * المخزون بالـ PHP (كان: Cache::remember للجدولين + array_slice).
     */
    public function index()
    {
        $perPage = 10;

        // لكل دواء: مجموع الكميات، عدد الصيدليات، أقل سعر موجب
        $perMedicine = DB::table('pharmacy_medicines')
            ->selectRaw('medicine_id, SUM(quantity) as stock, COUNT(*) as pharmacy_count, MIN(CASE WHEN price > 0 THEN price END) as min_price')
            ->groupBy('medicine_id');

        // الأساس المشترك (joins فقط) — التركيبة تُبنى لكل غرض على حدة:
        // وضع selectRaw إضافي على نسخة clone من استعلام يحتوي أعمدة غير مجمّعة
        // يجعل MySQL (ONLY_FULL_GROUP_BY) يرمي خطأ 1140 — sqlite يتساهل فكانت
        // الاختبارات تنجح بينما الإنتاج يرجع 500.
        $base = DB::table('medicines as m')
            ->leftJoinSub($perMedicine, 'pm', 'pm.medicine_id', '=', 'm.id');

        $query = (clone $base)
            ->selectRaw('m.id, m.trade_name, m.active_ingredient, m.is_available,
                COALESCE(pm.stock, 0) as stock,
                COALESCE(pm.pharmacy_count, 0) as pharmacy_count,
                pm.min_price')
            ->orderByDesc('m.created_at');

        $medicines = $query->paginate($perPage)->withQueryString();

        // الإحصائيات العامة — كاش 60 ثانية بمفتاح versioned (تعديل دواء يرفع
        // med_medicines_version → كاش جديد تلقائياً). يوفر joinSub كامل كل فتح.
        $statsRow = Cache::remember(
            'admin_medicines_stats|v'.Cache::get('med_medicines_version', 1),
            60,
            fn () => (clone $base)->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN COALESCE(pm.stock, 0) <= 0 THEN 1 ELSE 0 END) as out_c,
                SUM(CASE WHEN COALESCE(pm.stock, 0) > 0 AND COALESCE(pm.stock, 0) <= 10 THEN 1 ELSE 0 END) as low_c,
                SUM(CASE WHEN COALESCE(pm.pharmacy_count, 0) > 0 THEN 1 ELSE 0 END) as in_pharmacy
            ")->first()
        );

        $total = (int) ($statsRow->total ?? 0);
        $out = (int) ($statsRow->out_c ?? 0);
        $low = (int) ($statsRow->low_c ?? 0);
        $available = max(0, $total - $out - $low);
        $inPharmacy = (int) ($statsRow->in_pharmacy ?? 0);

        $pct = fn ($count) => $total > 0 ? round(($count / $total) * 100) : 0;

        $stats = [
            'total' => $total,
            'available' => $available,
            'low' => $low,
            'out' => $out,
            'available_pct' => $pct($available),
            'low_pct' => $pct($low),
            'out_pct' => $pct($out),
            'in_pharmacy_pct' => $pct($inPharmacy),
            'not_in_pharmacy_pct' => $pct($total - $inPharmacy),
        ];

        return view('medicines.index', compact('medicines', 'stats'));
    }

    private function clearMedicinesIndexCache()
    {
        Cache::forget('medicines_list_cache');
        Cache::forget('medicines_list_cache_v2');
        Cache::forget('medicines_list_cache_v3');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $allMedicines = Medicine::all();

        return view('medicines.create', compact('allMedicines'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name_ar' => 'required|string|max:255',
            'active_ingredient' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'alternatives' => 'nullable|array',
            'alternatives.*' => 'exists:medicines,id',
        ]);

        $medicine = Medicine::create([
            'trade_name' => $request->name_ar,
            'active_ingredient' => $request->active_ingredient,
            'description' => $request->description,
        ]);

        if ($request->hasFile('image')) {
            $medicine->image = Cloudinary::upload($request->file('image'), 'medicines');
            $medicine->save();
        }

        if ($request->has('alternatives')) {
            $medicine->alternatives()->sync($request->alternatives);
        }

        $this->clearMedicinesIndexCache();

        Cache::add('med_medicines_version', 1, 3600 * 24 * 30);
        Cache::increment('med_medicines_version');

        return Redirect::route('medicines.index')->with('success', 'تم إضافة الدواء بنجاح!');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $medicine = Medicine::with(['alternatives', 'pharmacyMedicines.pharmacy'])->findOrFail($id);

        return view('medicines.show', compact('medicine'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $medicine = Medicine::with('alternatives')->findOrFail($id);
        $allMedicines = Medicine::where('id', '!=', $id)->get();

        return view('medicines.edit', compact('medicine', 'allMedicines'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $medicine = Medicine::findOrFail($id);

        $request->validate([
            'name_ar' => 'required|string|max:255',
            'active_ingredient' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'alternatives' => 'nullable|array',
            'alternatives.*' => 'exists:medicines,id',
        ]);

        $medicine->update([
            'trade_name' => $request->name_ar,
            'active_ingredient' => $request->active_ingredient,
            'description' => $request->description,
        ]);

        if ($request->hasFile('image')) {
            Cloudinary::deleteLocal($medicine->image);
            $medicine->image = Cloudinary::upload($request->file('image'), 'medicines');
            $medicine->save();
        }

        if ($request->has('alternatives')) {
            $medicine->alternatives()->sync($request->alternatives);
        } else {
            $medicine->alternatives()->detach();
        }

        $this->clearMedicinesIndexCache();

        Cache::add('med_medicines_version', 1, 3600 * 24 * 30);
        Cache::increment('med_medicines_version');

        return Redirect::route('medicines.index')->with('success', 'تم تحديث الدواء بنجاح!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        // M-25: favorites polymorphic — لا FK ممكن، تنظيف يدوي قبل الحذف
        Favorite::where('favoritable_type', Medicine::class)
            ->where('favoritable_id', $id)
            ->delete();

        Medicine::destroy($id);
        $this->clearMedicinesIndexCache();

        Cache::add('med_medicines_version', 1, 3600 * 24 * 30);
        Cache::increment('med_medicines_version');

        return Redirect::route('medicines.index')->with('success', 'تم حذف الدواء بنجاح!');
    }
}
