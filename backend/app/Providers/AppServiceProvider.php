<?php

namespace App\Providers;

use App\Services\PeriodLockService;
use App\Services\PermissionService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Satu instance per request agar matriks hak akses cukup dibaca sekali.
        $this->app->scoped(PermissionService::class);
        // Daftar bulan tutup buku di-cache per request; dikosongkan otomatis saat PeriodLock berubah.
        $this->app->scoped(PeriodLockService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
