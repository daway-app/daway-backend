<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Medicine extends Model
{
    protected $fillable = ['trade_name', 'active_ingredient_id', 'description', 'image'];

    public function activeIngredient()
    {
        return $this->belongsTo(ActiveIngredient::class);
    }

    public function getAlternativesAttribute()
    {
        return Medicine::where('active_ingredient_id', $this->active_ingredient_id)
                       ->where('id', '!=', $this->id)
                       ->get();
    }

    public function favorites()
    {
        return $this->morphMany(Favorite::class, 'favoritable');
    }
}
