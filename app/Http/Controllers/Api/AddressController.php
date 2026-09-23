<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $addresses = Address::where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب العناوين بنجاح',
            'data' => $addresses->map(fn (Address $a) => $this->payload($a)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $data = $request->validate([
            'label' => 'nullable|string|max:100',
            'recipient_name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:30',
            'address' => 'required|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'is_default' => 'sometimes|boolean',
        ]);

        $data['user_id'] = $user->id;

        if (isset($data['is_default']) && $data['is_default'] === true) {
            Address::where('user_id', $user->id)
                ->where('id', '!=', $request->input('id', null))
                ->update(['is_default' => false]);
        }

        $address = Address::create($data);

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة العنوان بنجاح',
            'data' => $this->payload($address),
        ], 201);
    }

    public function show(Request $request, Address $address): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);
        abort_unless($address->user_id === $user->id, 403);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب العنوان بنجاح',
            'data' => $this->payload($address),
        ]);
    }

    public function update(Request $request, Address $address): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);
        abort_unless($address->user_id === $user->id, 403);

        $data = $request->validate([
            'label' => 'nullable|string|max:100',
            'recipient_name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:30',
            'address' => 'required|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'is_default' => 'sometimes|boolean',
        ]);

        if (isset($data['is_default']) && $data['is_default'] === true) {
            Address::where('user_id', $user->id)
                ->where('id', '!=', $address->id)
                ->update(['is_default' => false]);
        }

        $address->update($data);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث العنوان بنجاح',
            'data' => $this->payload($address->fresh()),
        ]);
    }

    public function destroy(Request $request, Address $address): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);
        abort_unless($address->user_id === $user->id, 403);

        $address->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف العنوان بنجاح',
        ]);
    }

    private function payload(Address $address): array
    {
        return [
            'id' => $address->id,
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'address' => $address->address,
            'latitude' => $address->latitude !== null ? (float) $address->latitude : null,
            'longitude' => $address->longitude !== null ? (float) $address->longitude : null,
            'is_default' => (bool) $address->is_default,
        ];
    }
}