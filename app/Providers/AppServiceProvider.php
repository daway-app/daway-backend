<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {

        Relation::morphMap([
            'medicine' => \App\Models\Medicine::class,
            'first_aid' => \App\Models\FirstAid::class,
        ]);
    }
}
