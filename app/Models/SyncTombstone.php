<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M-16: قبر صف مخزون محذوف — يسمح للـ PWA بالعلم بالحذف عند الـ pull
 * (deletion propagation). تُحذف تلقائياً بعد 30 يوماً عند كل pull.
 */
class SyncTombstone extends Model
{
    public $timestamps = false;

    protected $fillable = ['pharmacy_id', 'pharmacy_medicine_id', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
