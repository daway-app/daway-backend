<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * قسم فرعي تحت قسم رئيسي (Category).
 *
 * لماذا جدول منفصل وليس عموداً على categories:
 *  - الأقسام الرئيسية مستوى واحد وقد تكون مستقلة عن فلاتر الواجهة (تُدار من
 *    لوحة الأدمن بحرية)، بينما الأقسام الفرعية مرتبطة عضوياً بقسم رئيسي واحد.
 *  - group_key يمثّل "صف الفلتر" في الواجهة (الفيتامينات / المكملات)، وهو
 *    مفهوم عرض لا ينتمي لجدول الأقسام.
 */
class Subcategory extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'name_ar',
        'name_en',
        'slug',
        'group_key',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name_ar');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function categoryMedicineLinks(): HasMany
    {
        return $this->hasMany(CategoryMedicineLink::class);
    }
}
