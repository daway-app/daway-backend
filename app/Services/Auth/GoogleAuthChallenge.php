<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Server-created, short-lived and one-use Google ID-token nonces. */
class GoogleAuthChallenge
{
    private const PURPOSES = ['login', 'register_patient', 'register_pharmacy', 'recover_pharmacy', 'link_pharmacy'];

    public function issue(string $purpose, ?int $userId = null, ?int $accessTokenId = null): string
    {
        if (! in_array($purpose, self::PURPOSES, true) ||
            ($purpose === 'link_pharmacy' && ($userId === null || $userId <= 0 || $accessTokenId === null || $accessTokenId <= 0)) ||
            ($purpose !== 'link_pharmacy' && ($userId !== null || $accessTokenId !== null))) {
            throw new InvalidArgumentException('Invalid challenge context.');
        }
        $nonce = Str::random(64);
        DB::table('google_auth_challenges')->insert([
            'nonce_hash' => hash('sha256', $nonce), 'purpose' => $purpose,
            'user_id' => $userId, 'access_token_id' => $accessTokenId,
            'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $nonce;
    }

    /** Consume only after signature, claims and nonce verification succeeds. */
    public function consume(string $nonce, string $purpose, ?int $userId = null, ?int $accessTokenId = null): void
    {
        if (strlen($nonce) !== 64) {
            throw new RuntimeException('Invalid or expired Google challenge.');
        }
        $consumed = DB::transaction(function () use ($nonce, $purpose, $userId, $accessTokenId): bool {
            $row = DB::table('google_auth_challenges')->where('nonce_hash', hash('sha256', $nonce))->lockForUpdate()->first();
            if (! $row || $row->purpose !== $purpose || $row->consumed_at !== null ||
                ($row->user_id === null ? null : (int) $row->user_id) !== $userId ||
                ($row->access_token_id === null ? null : (int) $row->access_token_id) !== $accessTokenId ||
                now()->greaterThanOrEqualTo(\Illuminate\Support\Carbon::parse($row->expires_at))) {
                return false;
            }
            DB::table('google_auth_challenges')->where('id', $row->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            return true;
        });
        if (! $consumed) {
            throw new RuntimeException('Invalid or expired Google challenge.');
        }
    }
}
