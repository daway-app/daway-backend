<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActiveIngredient extends Model
{
    protected $fillable = ['name', 'description'];

    public function medicines()
    {
        return $this->hasMany(Medicine::class);
    }
}
