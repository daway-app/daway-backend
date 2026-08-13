<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class SearchLog extends Model
{
    protected $fillable = ['user_id', 'query', 'source'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * سجّل عملية بحث واحدة (مع منع التكرار: نفس المستخدم/نفس الاستعلام خلال 60 ثانية).
     */
    public static function track(?string $query, string $source = 'api'): void
    {
        $q = trim((string) $query);
        if (mb_strlen($q) < 2) {
            return;
        }

        $user = Auth::user();
        $identity = $user ? 'u'.$user->id : 'ip'.request()->ip();
        $key = 'search_log_dedupe_'.md5($identity.'|'.$q);

        if (Cache::add($key, 1, 60)) {
            self::create([
                'user_id' => $user?->id,
                'query' => mb_substr($q, 0, 255),
                'source' => $source,
            ]);
        }
    }
}
