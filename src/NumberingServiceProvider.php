<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Vimatech\DocumentNumbering\Support\PatternCompiler;

final class NumberingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'numbering');

        $this->app->singleton(PatternCompiler::class, static fn (): PatternCompiler => new PatternCompiler);

        $this->app->singleton(NumberingManager::class, static function (Container $app): NumberingManager {
            return new NumberingManager(
                $app->make(ConnectionResolverInterface::class),
                $app->make(Dispatcher::class),
                $app->make(PatternCompiler::class),
                $app->make(Repository::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => $this->app->configPath('numbering.php'),
            ], 'numbering-config');

            $this->publishesMigrations([
                $this->migrationsPath() => $this->app->databasePath('migrations'),
            ], 'numbering-migrations');
        }
    }

    private function configPath(): string
    {
        return __DIR__.'/../config/numbering.php';
    }

    private function migrationsPath(): string
    {
        return __DIR__.'/../database/migrations';
    }
}
