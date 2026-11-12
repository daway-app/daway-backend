<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ApiPasswordResetNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

class EmailPasswordResetController extends Controller
{
    private const ELIGIBLE_ROLES = ['patient', 'admin', 'rider'];

    public function requestLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $user = User::where('email', $data['email'])->first();
        $url = (string) config('app.frontend_password_reset_url', '');

        // Never issue email resets for pharmacies, closed or inactive accounts.
        // The public response is identical for all account states and delivery errors.
        if ($user && $user->is_active && $user->closed_at === null &&
            in_array($user->role, self::ELIGIBLE_ROLES, true) && $this->validResetUrl($url)) {
            try {
                PasswordBroker::broker()->sendResetLink(['email' => $user->email],
                    function (User $recipient, string $token) use ($url): void {
                        $separator = str_contains($url, '?') ? '&' : '?';
                        $recipient->notify(new ApiPasswordResetNotification(
                            $url.$separator.'token='.rawurlencode($token).'&email='.rawurlencode($recipient->email),
                        ));
                    });
            } catch (Throwable) {
                // Do not include email, token, or the exception in logs.
                Log::warning('password_reset_delivery_failed');
            }
        }

        return response()->json(['message' => 'إذا كان الحساب مؤهلاً، فسيصلك رابط استعادة كلمة المرور.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);

        // Serialise consumption of a token so concurrent requests cannot both
        // succeed. The framework broker still checks its hashed value and expiry.
        $status = DB::transaction(function () use ($data): string {
            $table = config('auth.passwords.users.table', 'password_reset_tokens');
            DB::table($table)->where('email', $data['email'])->lockForUpdate()->first();
            $user = User::where('email', $data['email'])->lockForUpdate()->first();
            if (! $user || ! $user->is_active || $user->closed_at !== null ||
                ! in_array($user->role, self::ELIGIBLE_ROLES, true)) {
                return PasswordBroker::INVALID_TOKEN;
            }

            return PasswordBroker::broker()->reset($data, function (User $account, string $password): void {
                $account->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                $account->tokens()->delete();
                event(new PasswordReset($account));
            });
        });

        return $status === PasswordBroker::PASSWORD_RESET
            ? response()->json(['message' => 'تم تغيير كلمة المرور. سجّل الدخول من جديد.'])
            : response()->json(['message' => 'رابط الاستعادة غير صالح أو منتهي الصلاحية.'], 422);
    }

    private function validResetUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        return ($parts['scheme'] ?? '') === 'https';
    }
}
