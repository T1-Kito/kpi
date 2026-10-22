<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        \Illuminate\Database\Connection::resolverFor('sqlsrv',
            fn($pdo,$database,$prefix,$config)=>new \App\Support\SqlServerInstallConnection($pdo,$database,$prefix,$config));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
