<?php

namespace Intraset\LaravelScoutDatabaseDriver;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationFrom(__DIR__ . '/../database/migrations');
    }
}
