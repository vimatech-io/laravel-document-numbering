<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering\Exceptions;

final class UnknownDocumentType extends NumberingException
{
    public static function make(string $type): self
    {
        return new self(sprintf(
            'Document type [%s] is not configured. Add it under config/numbering.php "types".',
            $type,
        ));
    }
}
