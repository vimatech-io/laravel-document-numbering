<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering\Facades;

use Illuminate\Support\Facades\Facade;
use Vimatech\DocumentNumbering\NumberingManager;
use Vimatech\DocumentNumbering\PendingNumber;

/**
 * @method static PendingNumber for(string $scope, string $type)
 * @method static string next(string $scope, string $type)
 * @method static string peek(string $scope, string $type)
 * @method static bool knows(string $type)
 *
 * @see NumberingManager
 */
final class Numbering extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return NumberingManager::class;
    }
}
