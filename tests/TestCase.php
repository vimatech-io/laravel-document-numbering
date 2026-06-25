<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Vimatech\DocumentNumbering\Enums\ResetPolicy;
use Vimatech\DocumentNumbering\NumberingServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            NumberingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('numbering.connection', null);
        $app['config']->set('numbering.types', [
            'invoice' => [
                'pattern' => 'INV-{YYYY}-{seq:5}',
                'reset' => ResetPolicy::Yearly,
                'gap_free' => true,
            ],
            'quote' => [
                'pattern' => 'QUO-{YYYY}-{seq:5}',
                'reset' => ResetPolicy::Yearly,
                'gap_free' => false,
            ],
            'credit_note' => [
                'pattern' => 'CN-{YY}{MM}-{seq:4}',
                'reset' => ResetPolicy::Monthly,
                'gap_free' => true,
            ],
            'ticket' => [
                'pattern' => 'T{seq:3}',
                'reset' => ResetPolicy::Never,
                'gap_free' => true,
            ],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
