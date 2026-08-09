<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FirstAid extends Model
{
    protected $fillable = [
        'title', 'category', 'instructions_steps', 'image_icon'
    ];

    public function favorites()
    {
        return $this->morphMany(Favorite::class, 'favoritable');
    }
}
