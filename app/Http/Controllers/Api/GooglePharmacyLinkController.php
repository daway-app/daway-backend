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
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class GooglePharmacyLinkController extends Controller
{
    public function challenge(Request $request, GoogleAuthChallenge $challenges): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $this->eligible($user) || ! $token instanceof PersonalAccessToken) {
            return response()->json(['message' => 'تعذر ربط الحساب.'], 403);
        }
        return response()->json(['nonce' => $challenges->issue('link_pharmacy', $user->id, $token->id), 'expires_in' => 300])
            ->header('Cache-Control', 'no-store');
    }

    public function link(Request $request, GoogleAuthChallenge $challenges, GoogleIdentityVerifier $verifier): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:10000'],
            'nonce' => ['required', 'string', 'size:64'],
            'current_password' => ['required', 'string', 'max:1024'],
        ]);
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $this->eligible($user) || ! $token instanceof PersonalAccessToken ||
            ! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'تعذر ربط الحساب.'], 403);
        }

        try {
            $identity = $verifier->verify($data['id_token'], $data['nonce']);
            $challenges->consume($data['nonce'], 'link_pharmacy', $user->id, $token->id);
            DB::transaction(function () use ($user, $token, $data, $identity): void {
                // Lock and re-check eligibility and the session at the actual write.
                $fresh = User::whereKey($user->id)->lockForUpdate()->first();
                $pharmacy = Pharmacy::where('user_id', $user->id)->lockForUpdate()->first();
                $session = PersonalAccessToken::whereKey($token->id)->lockForUpdate()->first();
                if (! $fresh || $fresh->role !== 'pharmacy' || ! $fresh->is_active || $fresh->closed_at !== null ||
                    ! $pharmacy || ! $pharmacy->is_active || $pharmacy->closed_at !== null ||
                    ! $session || (int) $session->tokenable_id !== (int) $fresh->id || $session->tokenable_type !== $fresh->getMorphClass() ||
                    ! Hash::check($data['current_password'], $fresh->password)) {
                    throw new \RuntimeException('Invalid link.');
                }
                // Both subject and user_id have unique DB constraints. Never link by email.
                DB::table('google_identities')->insert([
                    'user_id' => $fresh->id, 'google_sub' => $identity['sub'], 'email' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (Throwable) {
            // Generic response: do not reveal whether the identity belongs to another account.
            return response()->json(['message' => 'تعذر ربط الحساب.'], 422);
        }

        return response()->json(['message' => 'تم ربط حساب Google بنجاح.']);
    }

    private function eligible(?User $user): bool
    {
        if (! $user || $user->role !== 'pharmacy' || ! $user->is_active || $user->closed_at !== null) {
            return false;
        }
        $pharmacy = $user->pharmacy;
        return $pharmacy !== null && $pharmacy->is_active && $pharmacy->closed_at === null;
    }
}
