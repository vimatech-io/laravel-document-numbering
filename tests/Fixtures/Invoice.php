<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Vimatech\DocumentNumbering\Concerns\HasDocumentNumber;

/**
 * @property string $number
 * @property string $company_id
 */
final class Invoice extends Model
{
    use HasDocumentNumber;

    protected $table = 'invoices';

    protected $guarded = [];

    public $timestamps = false;

    protected string $documentNumberType = 'invoice';

    protected string $documentNumberScopeColumn = 'company_id';
}
