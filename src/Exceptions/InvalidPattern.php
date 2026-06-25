<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering\Exceptions;

final class InvalidPattern extends NumberingException
{
    public static function unknownToken(string $pattern, string $token): self
    {
        return new self(sprintf(
            'Pattern [%s] contains an unknown token [%s]. Supported tokens: {YYYY}, {YY}, {MM}, {seq:n}.',
            $pattern,
            $token,
        ));
    }
}
