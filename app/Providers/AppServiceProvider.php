<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;   // <-- EKLE
use Illuminate\Support\Facades\Log;  // <-- EKLE
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
 
    public function boot(): void
{
     Schema::defaultStringLength(191);
    if (app()->environment('local')) {
        DB::listen(function ($query) {
            Log::debug('[SQL]', [
                'sql'      => $query->sql,
                'bindings' => $query->bindings,
                'time_ms'  => $query->time,
            ]);
        });
    }
}
}
