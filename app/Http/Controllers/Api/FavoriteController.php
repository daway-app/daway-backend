<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Support\Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function medicines(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $favorites = Favorite::where('user_id', $user->id)
            ->where('favoritable_type', Medicine::class)
            ->with('favoritable')
            ->latest('created_at')
            ->paginate(20);

        $data = $favorites->getCollection()->map(function (Favorite $favorite) {
            $medicine = $favorite->favoritable;

            return [
                'id' => $favorite->id,
                'favoritable_type' => $favorite->favoritable_type,
                'favoritable_id' => $favorite->favoritable_id,
                'medicine_id' => $medicine?->id,
                'trade_name' => $medicine?->trade_name,
                'trade_name_ar' => $medicine?->trade_name_ar,
                'image_url' => $medicine ? Image::url($medicine->image) : null,
                'created_at' => $favorite->created_at,
            ];
        })->all();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب المفضلة من الأدوية بنجاح',
            'data' => $data,
            'pagination' => [
                'total' => $favorites->total(),
                'per_page' => $favorites->perPage(),
                'current_page' => $favorites->currentPage(),
                'last_page' => $favorites->lastPage(),
            ],
        ]);
    }

    public function storeMedicine(Request $request, Medicine $medicine): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $existing = Favorite::where('user_id', $user->id)
            ->where('favoritable_type', Medicine::class)
            ->where('favoritable_id', $medicine->id)
            ->exists();

        Favorite::firstOrCreate([
            'user_id' => $user->id,
            'favoritable_type' => Medicine::class,
            'favoritable_id' => $medicine->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => $existing
                ? 'الدواء موجود بالفعل في المفضلة'
                : 'تمت إضافة الدواء إلى المفضلة بنجاح',
            'data' => [
                'medicine_id' => $medicine->id,
            ],
        ], $existing ? 200 : 201);
    }

    public function destroyMedicine(Request $request, Medicine $medicine): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        Favorite::where('user_id', $user->id)
            ->where('favoritable_type', Medicine::class)
            ->where('favoritable_id', $medicine->id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الدواء من المفضلة بنجاح',
            'data' => [
                'medicine_id' => $medicine->id,
            ],
        ]);
    }

    public function pharmacies(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $favorites = Favorite::where('user_id', $user->id)
            ->where('favoritable_type', Pharmacy::class)
            ->with('favoritable')
            ->latest('created_at')
            ->paginate(20);

        $data = $favorites->getCollection()->map(function (Favorite $favorite) {
            $pharmacy = $favorite->favoritable;

            return [
                'id' => $favorite->id,
                'favoritable_type' => $favorite->favoritable_type,
                'favoritable_id' => $favorite->favoritable_id,
                'pharmacy_id' => $pharmacy?->id,
                'pharmacy_name' => $pharmacy?->pharmacy_name,
                'address' => $pharmacy?->address,
                'region' => $pharmacy?->region,
                'logo_url' => $pharmacy ? Image::url($pharmacy->logo) : null,
                'created_at' => $favorite->created_at,
            ];
        })->all();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب المفضلة من الصيدليات بنجاح',
            'data' => $data,
            'pagination' => [
                'total' => $favorites->total(),
                'per_page' => $favorites->perPage(),
                'current_page' => $favorites->currentPage(),
                'last_page' => $favorites->lastPage(),
            ],
        ]);
    }

    public function storePharmacy(Request $request, Pharmacy $pharmacy): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $existing = Favorite::where('user_id', $user->id)
            ->where('favoritable_type', Pharmacy::class)
            ->where('favoritable_id', $pharmacy->id)
            ->exists();

        Favorite::firstOrCreate([
            'user_id' => $user->id,
            'favoritable_type' => Pharmacy::class,
            'favoritable_id' => $pharmacy->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => $existing
                ? 'الصيدلية موجودة بالفعل في المفضلة'
                : 'تمت إضافة الصيدلية إلى المفضلة بنجاح',
            'data' => [
                'pharmacy_id' => $pharmacy->id,
            ],
        ], $existing ? 200 : 201);
    }

    public function destroyPharmacy(Request $request, Pharmacy $pharmacy): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        Favorite::where('user_id', $user->id)
            ->where('favoritable_type', Pharmacy::class)
            ->where('favoritable_id', $pharmacy->id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الصيدلية من المفضلة بنجاح',
            'data' => [
                'pharmacy_id' => $pharmacy->id,
            ],
        ]);
    }
}