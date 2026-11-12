<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class PatientPasswordAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'digits:10', 'regex:/^05[6-9]/', 'unique:users,phone'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)],
            'age' => ['required', 'integer', 'min:1', 'max:120'],
            'terms_accepted' => ['required', 'accepted'],
        ])->validate();

        $user = User::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'birth_date' => now()->subYears((int) $data['age'])->toDateString(),
            'terms_accepted' => true,
            'terms_accepted_at' => now(),
        ]);
        $user->role = 'patient';
        $user->is_active = true;
        $user->save();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role],
                'token' => $user->createToken('auth_token')->plainTextToken,
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ])->validate();

        $user = User::where('email', $data['identifier'])
            ->orWhere('phone', $data['identifier'])->first();

        if (! $user || $user->role !== 'patient' || ! $user->is_active ||
            ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid login credentials'], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role],
                'token' => $user->createToken('auth_token')->plainTextToken,
            ],
        ]);
    }
}
