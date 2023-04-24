<?php

namespace Intraset\LaravelScoutDatabaseDriver;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laravel\Scout\EngineManager;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->app->make(EngineManager::class)->extend('database', function () {
            return new Engines\DatabaseEngine(config('scout.soft_delete'));
        }
    }
}
