<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Auth\GoogleAuthChallenge;
use App\Services\Auth\GoogleIdentityVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Throwable;

class GoogleAuthController extends Controller
{
    public function challenge(Request $request, GoogleAuthChallenge $challenges): JsonResponse
    {
        $data = $request->validate(['purpose' => ['required', 'in:login,register_patient,register_pharmacy,recover_pharmacy']]);
        return response()->json(['nonce' => $challenges->issue($data['purpose']), 'expires_in' => 300])
            ->header('Cache-Control', 'no-store');
    }

    public function registerPharmacy(Request $request, GoogleAuthChallenge $challenges, GoogleIdentityVerifier $verifier): JsonResponse
    {
        $data = $request->validate([
            'pharmacy_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'region' => ['required', 'string', 'max:150'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
            'id_token' => ['required', 'string', 'max:10000'],
            'nonce' => ['required', 'string', 'size:64'],
        ]);
        try {
            $identity = $verifier->verify($data['id_token'], $data['nonce']);
            $pharmacy = DB::transaction(function () use ($data, $identity, $challenges): Pharmacy {
                $challenges->consume($data['nonce'], 'register_pharmacy');
                $customId = Pharmacy::generateUniqueCustomId();
                if ($customId === null) throw new \RuntimeException('Could not create account.');
                $user = new User(['name' => $data['pharmacy_name'], 'phone' => $data['phone'],
                    'password' => Hash::make($data['password'])]);
                $user->role = 'pharmacy';
                $user->is_active = false;
                $user->must_change_password = false;
                $user->save();
                $user->syncRoles(['pharmacy']);
                $pharmacy = new Pharmacy(['pharmacy_name' => $data['pharmacy_name'],
                    'region' => $data['region'], 'phone_number' => $data['phone']]);
                $pharmacy->user_id = $user->id;
                $pharmacy->pharmacy_custom_id = $customId;
                $pharmacy->is_active = false;
                // Never save a reversible copy of the password.
                $pharmacy->pending_password = null;
                $pharmacy->save();
                DB::table('google_identities')->insert(['user_id' => $user->id, 'google_sub' => $identity['sub'],
                    'email' => null, 'created_at' => now(), 'updated_at' => now()]);
                return $pharmacy;
            });
        } catch (Throwable) {
            return response()->json(['message' => 'تعذر إنشاء الحساب. تحقق من البيانات وحاول من جديد.'], 422);
        }
        return response()->json(['success' => true, 'data' => [
            'pharmacy_id' => $pharmacy->pharmacy_custom_id, 'status' => 'pending_approval', 'is_active' => false,
        ]], 201);
    }

    public function registerPatient(Request $request, GoogleAuthChallenge $challenges, GoogleIdentityVerifier $verifier): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'digits:10', 'regex:/^05[6-9]/', 'unique:users,phone'],
            'age' => ['required', 'integer', 'min:1', 'max:120'],
            'terms_accepted' => ['required', 'accepted'],
            'id_token' => ['required', 'string', 'max:10000'], 'nonce' => ['required', 'string', 'size:64'],
        ]);
        try {
            $identity = $verifier->verify($data['id_token'], $data['nonce']);
            // Google registration cannot attach by matching an existing email.
            if (! $identity['email_verified'] || ! is_string($identity['email']) ||
                strcasecmp($identity['email'], $data['email']) !== 0) {
                throw new \RuntimeException('Invalid account.');
            }
            $result = DB::transaction(function () use ($data, $identity, $challenges): array {
                $challenges->consume($data['nonce'], 'register_patient');
                $user = new User(['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'],
                    'password' => Hash::make(bin2hex(random_bytes(32))),
                    'birth_date' => now()->subYears((int) $data['age'])->toDateString(),
                    'terms_accepted' => true, 'terms_accepted_at' => now()]);
                $user->role = 'patient';
                $user->is_active = true;
                $user->save();
                $user->syncRoles(['patient']);
                DB::table('google_identities')->insert(['user_id' => $user->id, 'google_sub' => $identity['sub'],
                    'email' => null, 'created_at' => now(), 'updated_at' => now()]);
                return ['user' => ['id' => $user->id, 'name' => $user->name, 'role' => 'patient'],
                    'token' => $user->createToken('auth_token')->plainTextToken];
            });
        } catch (Throwable) {
            return response()->json(['message' => 'تعذر إنشاء الحساب. تحقق من البيانات وحاول من جديد.'], 422);
        }
        return response()->json(['success' => true, 'data' => $result], 201);
    }

    public function login(Request $request, GoogleAuthChallenge $challenges, GoogleIdentityVerifier $verifier): JsonResponse
    {
        $data = $request->validate(['id_token' => ['required', 'string', 'max:10000'], 'nonce' => ['required', 'string', 'size:64']]);
        try {
            $identity = $verifier->verify($data['id_token'], $data['nonce']);
            $result = DB::transaction(function () use ($data, $identity, $challenges): array {
                $challenges->consume($data['nonce'], 'login');
                $identityRow = DB::table('google_identities')->where('google_sub', $identity['sub'])->lockForUpdate()->first();
                $user = $identityRow ? User::whereKey($identityRow->user_id)->lockForUpdate()->first() : null;
                if (! $user || ! $user->is_active || $user->closed_at !== null || ! in_array($user->role, ['patient', 'pharmacy'], true)) {
                    throw new \RuntimeException('Invalid account.');
                }
                $userData = ['id' => $user->id, 'name' => $user->name, 'role' => $user->role];
                if ($user->role === 'pharmacy') {
                    $pharmacy = Pharmacy::where('user_id', $user->id)->lockForUpdate()->first();
                    if (! $pharmacy || ! $pharmacy->is_active || $pharmacy->closed_at !== null) throw new \RuntimeException('Invalid account.');
                    $userData['pharmacy_id'] = $pharmacy->pharmacy_custom_id;
                    $userData['must_change_password'] = (bool) $user->must_change_password;
                }
                return ['user' => $userData, 'token' => $user->createToken('auth_token')->plainTextToken];
            });
        } catch (Throwable) {
            return response()->json(['message' => 'تعذر تسجيل الدخول باستخدام Google.'], 401);
        }
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function recoverPharmacy(Request $request, GoogleAuthChallenge $challenges, GoogleIdentityVerifier $verifier): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:10000'], 'nonce' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);
        try {
            $identity = $verifier->verify($data['id_token'], $data['nonce']);
            DB::transaction(function () use ($data, $identity, $challenges): void {
                $challenges->consume($data['nonce'], 'recover_pharmacy');
                $identityRow = DB::table('google_identities')->where('google_sub', $identity['sub'])->lockForUpdate()->first();
                $user = $identityRow ? User::whereKey($identityRow->user_id)->lockForUpdate()->first() : null;
                if (! $user || $user->role !== 'pharmacy' || ! $user->is_active || $user->closed_at !== null) throw new \RuntimeException('Invalid account.');
                $pharmacy = Pharmacy::where('user_id', $user->id)->lockForUpdate()->first();
                if (! $pharmacy || ! $pharmacy->is_active || $pharmacy->closed_at !== null) throw new \RuntimeException('Invalid account.');
                $user->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false])->save();
                $user->tokens()->delete();
            });
        } catch (Throwable) {
            return response()->json(['message' => 'تعذرت استعادة الحساب باستخدام Google.'], 422);
        }
        return response()->json(['message' => 'تم تغيير كلمة المرور. سجّل الدخول من جديد.']);
    }
}
