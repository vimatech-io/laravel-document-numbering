<?php

declare(strict_types=1);

use Vimatech\DocumentNumbering\Enums\ResetPolicy;

return [

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | The connection used to read and write the sequence table. Leave null to
    | use the application's default connection. Allocation always happens on a
    | transactional, row-lock capable connection.
    |
    */

    'connection' => env('DOCUMENT_NUMBERING_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Sequence table
    |--------------------------------------------------------------------------
    |
    | The table that stores the last allocated value per (scope, type, period).
    |
    */

    'table' => 'document_number_sequences',

    /*
    |--------------------------------------------------------------------------
    | Lock retry attempts
    |--------------------------------------------------------------------------
    |
    | How many times the allocation transaction is retried when the database
    | reports a deadlock or a busy/locked error. Raise this for workloads with
    | heavy write contention on the same sequence. Must be at least 1.
    |
    */

    'lock_attempts' => 5,

    /*
    |--------------------------------------------------------------------------
    | Document types
    |--------------------------------------------------------------------------
    |
    | Each document type defines:
    |
    |  - pattern:  the rendered number. Supported tokens:
    |                {YYYY}   4-digit year   (2026)
    |                {YY}     2-digit year   (26)
    |                {MM}     2-digit month  (06)
    |                {seq:n}  zero-padded sequence, n digits ({seq:5} -> 00042)
    |              Everything else is treated as literal text.
    |
    |  - reset:    when the sequence restarts at 1:
    |                ResetPolicy::Yearly   -> per calendar year
    |                ResetPolicy::Monthly  -> per calendar month
    |                ResetPolicy::Never    -> single continuous sequence
    |
    |  - gap_free: true  -> the number is allocated inside the caller's
    |                       transaction; a rollback returns the number to the
    |                       pool. Legally required for invoices. Slower under
    |                       contention (the row lock is held until commit).
    |              false -> "fast sequential" mode: the number is committed
    |                       immediately and is NOT returned on rollback, so
    |                       gaps are possible. Lower contention.
    |
    */

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
