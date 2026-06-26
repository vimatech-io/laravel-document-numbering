# Laravel Document Numbering

[![CI](https://github.com/vimatech-io/laravel-document-numbering/actions/workflows/ci.yml/badge.svg)](https://github.com/vimatech-io/laravel-document-numbering/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

**Sequential, gap-free, concurrency-safe document numbers for Laravel.**

Allocate legally-compliant numbers for invoices, quotes and credit notes —
under concurrent requests, two callers can never take the same number or leave a
hole in the sequence.

## Why Laravel Document Numbering?

Gap-free numbering is a **legal requirement** for invoices in most
jurisdictions: the sequence may not skip values. Getting that right under load
is harder than it looks. Most Laravel apps eventually need to answer:

- How do I guarantee invoice numbers never skip a value?
- How do two concurrent requests avoid taking the same number?
- Can each company/tenant keep its own independent sequence?
- How do I reset the counter every year or month?
- What happens to the number if the surrounding transaction rolls back?

Laravel Document Numbering provides a small, database-backed layer for exactly
that — it leans on database transactions and row locks rather than hoping races
never happen.

## Feature Matrix

| Feature | Supported |
|---|---|
| Configurable patterns (`INV-{YYYY}-{seq:5}`) | ✅ |
| Per-scope counters (company / tenant / branch) | ✅ |
| Period resets (`yearly`, `monthly`, `never`) | ✅ |
| Gap-free mode (transaction-bound) | ✅ |
| Fast-sequential mode | ✅ |
| Concurrency-safe (row locks) | ✅ |
| Eloquent trait, event & facade | ✅ |
| Database-portable (MySQL, PostgreSQL, SQLite) | ✅ |
| Octane / FrankenPHP safe | ✅ |
| UI | ❌ |

## Requirements

- PHP 8.3+
- Laravel 11, 12 or 13

## Installation

```bash
composer require vimatech/laravel-document-numbering
```

Publish and run the migration:

```bash
php artisan vendor:publish --tag=numbering-migrations
php artisan migrate
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag=numbering-config
```

## Configuration

`config/numbering.php` defines a counter strategy per document type:

```php
use Vimatech\DocumentNumbering\Enums\ResetPolicy;

return [
    'connection' => env('DOCUMENT_NUMBERING_CONNECTION'),
    'table' => 'document_number_sequences',

    // Times the allocation transaction is retried on a deadlock / locked error.
    'lock_attempts' => 5,

    'types' => [
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
    ],
];
```

### Pattern tokens

| Token     | Meaning                              | Example   |
| --------- | ------------------------------------ | --------- |
| `{YYYY}`  | 4-digit year                         | `2026`    |
| `{YY}`    | 2-digit year                         | `26`      |
| `{MM}`    | 2-digit month                        | `06`      |
| `{seq:n}` | sequence value, zero-padded to `n`   | `00042`   |

Everything outside a token is literal text. A sequence longer than its padding
is **not** truncated (`{seq:3}` with value `12345` → `12345`).

### Reset policies

The reset policy decides when the counter restarts at `1`, by computing a
*period key*. Two allocations share a counter only when their
`(scope, type, period_key)` match.

| Policy                   | Period key (for 2026-06) | Restarts        |
| ------------------------ | ------------------------ | --------------- |
| `ResetPolicy::Never`     | `all`                    | never           |
| `ResetPolicy::Yearly`    | `2026`                   | each year       |
| `ResetPolicy::Monthly`   | `2026-06`                | each month      |

> Tip: include the matching date token in the pattern (`{YYYY}` for yearly,
> `{YY}{MM}` for monthly) so reused sequence values stay unique across periods.

### Scopes

A **scope** is an arbitrary string that isolates counters — typically a
company, tenant or branch id. `acme` and `globex` each get their own
`INV-2026-00001`.

When you configure a scope column on a model (`$documentNumberScopeColumn`),
that attribute **must be set before saving**. A missing value throws a
`LogicException` rather than silently falling back to the global scope, which
would otherwise mix one tenant's numbers into another's sequence.

## Usage

### Via the facade

```php
use Vimatech\DocumentNumbering\Facades\Numbering;

$number = Numbering::for($companyId, 'invoice')->next(); // "INV-2026-00001"

// Preview the next value without consuming it (advisory under concurrency):
$preview = Numbering::for($companyId, 'invoice')->peek();
```

### Via the Eloquent trait

Add `HasDocumentNumber` to a model and tell it which type and scope to use. The
number is assigned automatically on `creating`.

```php
use Illuminate\Database\Eloquent\Model;
use Vimatech\DocumentNumbering\Concerns\HasDocumentNumber;

class Invoice extends Model
{
    use HasDocumentNumber;

    protected string $documentNumberType = 'invoice';
    protected string $documentNumberColumn = 'number';        // default: 'number'
    protected string $documentNumberScopeColumn = 'company_id'; // optional
}

$invoice = Invoice::create(['company_id' => 42]);
$invoice->number; // "INV-2026-00001"
```

You can replace the property hooks with method overrides for full control:

```php
public function documentNumberType(): string { return 'invoice'; }
public function documentNumberScope(): string { return (string) $this->team_id; }
public function documentNumberColumn(): string { return 'reference'; }
```

For **gap-free** types the trait wraps the first `save()` in a database
transaction, so the number allocation and the row `INSERT` commit together — if
the insert fails, the number is released back into the sequence.

## Complete Example

```php
use Illuminate\Database\Eloquent\Model;
use Vimatech\DocumentNumbering\Concerns\HasDocumentNumber;
use Vimatech\DocumentNumbering\Enums\ResetPolicy;

// 1. Configure the type (config/numbering.php)
'types' => [
    'invoice' => [
        'pattern'  => 'INV-{YYYY}-{seq:5}',
        'reset'    => ResetPolicy::Yearly,
        'gap_free' => true,
    ],
],

// 2. Add the trait to your model
class Invoice extends Model
{
    use HasDocumentNumber;

    protected string $documentNumberType = 'invoice';
    protected string $documentNumberScopeColumn = 'company_id';
}

// 3. Use it
$invoice = Invoice::create(['company_id' => 42]); // company 42
$invoice->number;                                 // "INV-2026-00001"

$next = Invoice::create(['company_id' => 42]);
$next->number;                                    // "INV-2026-00002"

$other = Invoice::create(['company_id' => 99]);   // different scope
$other->number;                                   // "INV-2026-00001"
```

## Concurrency & gap-free guarantees

Each `(scope, type, period_key)` owns one row in `document_number_sequences`.
Allocation runs inside a transaction and takes a `lockForUpdate()` lock on that
row, so concurrent callers **serialise on the row** rather than racing the
counter. The first allocation for a new period inserts the row at `0`; the
unique index on `(scope, type, period_key)` makes a lost insert race harmless.

### `gap_free: true` (legally safe, default)

The number is allocated **inside the caller's transaction**. The row lock is
held until that transaction commits, so:

- no other allocation for the same sequence can proceed until you commit, and
- if you roll back, the increment is undone and the number is reused.

This is what invoices need. The cost is contention: the lock is held for the
lifetime of the surrounding transaction, so keep those transactions short.

When using the facade directly, wrap your write and the allocation together:

```php
DB::transaction(function () use ($companyId, $payload) {
    $number = Numbering::for($companyId, 'invoice')->next();

    Invoice::create([...$payload, 'number' => $number]);
});
```

The `HasDocumentNumber` trait does this wrapping for you.

### `gap_free: false` (fast sequential)

The number is committed as soon as it is allocated and is **not** returned on a
later rollback, so gaps are possible. Choose this only for sequences where the
law does not require gap-freeness (e.g. internal quote drafts) and throughput
matters more than a perfectly dense sequence.

### FrankenPHP / Laravel Octane (worker mode)

The package is safe under long-lived worker runtimes (FrankenPHP worker mode,
Octane with Swoole/RoadRunner), where the application is booted once and reused
across many requests. Specifically:

- **The `NumberingManager` is a stateless singleton.** It holds no per-request
  data: the document-type configuration is read from the config repository on
  demand, and the database connection is resolved *per call* through the
  connection resolver. It never caches a `Connection`/PDO handle, so a
  reconnect between requests (which Octane performs) is picked up automatically.
- **No accumulating static state.** Nothing grows in memory across requests.
- **The `HasDocumentNumber` trait registers its `creating` hook once** per
  worker, via Eloquent's standard trait-boot mechanism — there is no
  per-request re-registration or listener leak.
- **Correctness is unchanged.** Worker mode runs several workers concurrently,
  exactly like PHP-FPM. Gap-free safety still comes from the database row lock,
  which serialises allocation across all workers and processes.

No special configuration is required. If you register an event listener for
`NumberAllocated`, follow the usual Octane guidance and avoid capturing
request-scoped state in long-lived listeners.

### Database notes

- **MySQL / PostgreSQL**: `lockForUpdate()` issues `SELECT ... FOR UPDATE`;
  row-level locks are held until commit. Recommended for production.
- **SQLite**: write transactions lock the database file, which serialises
  writers and provides the same guarantees with coarser granularity. Great for
  tests and small single-writer apps.

If the database aborts a statement while waiting for the lock (lock-wait
timeout or deadlock), a `Vimatech\DocumentNumbering\Exceptions\SequenceLocked`
exception is thrown and the transaction is rolled back — no number is consumed.

## Events

`Vimatech\DocumentNumbering\Events\NumberAllocated` is dispatched after each
allocation:

```php
final class NumberAllocated
{
    public string $scope;
    public string $type;
    public string $periodKey;
    public int $sequence;
    public string $number;
}
```

For gap-free types this fires inside the caller's transaction, so a rollback
also discards anything a listener did transactionally.

## Testing

```bash
composer test       # Pest
composer format     # Pint
composer analyse    # PHPStan (level max)
```

The suite includes a concurrency test that forks multiple worker processes
against a shared SQLite database and asserts that the allocated numbers contain
**no duplicates and no gaps**.

## Design Principles

- **Correctness first** — gap-free safety comes from database row locks, not
  optimistic hoping.
- **Backend-only, UI agnostic** — no controllers, no views, no opinions on your
  frontend.
- **No domain assumptions** — works for invoices, quotes, credit notes or any
  document type you define.
- **Laravel-native API** — a trait, an event, a facade and a config file.
- **Database-portable** — the same guarantees on MySQL, PostgreSQL and SQLite.
- **Worker-safe** — stateless singleton, no accumulating static state, ready for
  Octane and FrankenPHP.

## Possible Future Extensions

- Per-type custom formatters (callables)
- Daily reset policy
- Numbering audit log
- Filament integration

Future extensions may be released as separate packages to keep the core small
and focused.

## Contributing

Contributions are welcome.

Please ensure:
- Tests pass (`composer test`)
- PHPStan passes (`composer analyse`)
- Code style is formatted with Pint (`composer format`)

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review our [Security Policy](SECURITY.md) for reporting vulnerabilities.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Credits

Built and maintained by [Vimatech](https://vimatech.io).
Created by [Adel Zemzemi](https://github.com/adelzemzemi).
