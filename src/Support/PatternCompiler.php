<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering\Support;

use DateTimeInterface;
use Vimatech\DocumentNumbering\Exceptions\InvalidPattern;

/**
 * Renders a document number pattern into a concrete string.
 *
 * Supported tokens:
 *   {YYYY}   4-digit year
 *   {YY}     2-digit year
 *   {MM}     2-digit month
 *   {seq:n}  the sequence value, zero-padded to n digits
 *
 * Any character outside of a token is emitted verbatim.
 */
final class PatternCompiler
{
    /**
     * Matches a single `{...}` token. The body is validated separately so we
     * can raise a helpful error on unknown tokens instead of silently
     * leaving them in the output.
     */
    private const TOKEN = '/\{([^}]*)\}/';

    public function compile(string $pattern, int $sequence, DateTimeInterface $at): string
    {
        $result = preg_replace_callback(
            self::TOKEN,
            function (array $matches) use ($pattern, $sequence, $at): string {
                return $this->render($pattern, $matches[1], $sequence, $at);
            },
            $pattern,
        );

        // preg_replace_callback only returns null on a malformed PCRE pattern,
        // which cannot happen with the constant above, but keep the contract.
        return $result ?? $pattern;
    }

    /**
     * Validate that a pattern only uses known tokens. Useful for failing fast
     * at boot or in tests rather than at allocation time.
     */
    public function validate(string $pattern): void
    {
        if (preg_match_all(self::TOKEN, $pattern, $matches) === 0) {
            return;
        }

        foreach ($matches[1] as $body) {
            $this->assertKnown($pattern, $body);
        }
    }

    private function render(string $pattern, string $body, int $sequence, DateTimeInterface $at): string
    {
        if (preg_match('/^seq:(\d+)$/', $body, $m) === 1) {
            return str_pad((string) $sequence, (int) $m[1], '0', STR_PAD_LEFT);
        }

        return match ($body) {
            'YYYY' => $at->format('Y'),
            'YY' => $at->format('y'),
            'MM' => $at->format('m'),
            default => throw InvalidPattern::unknownToken($pattern, '{'.$body.'}'),
        };
    }

    private function assertKnown(string $pattern, string $body): void
    {
        $known = $body === 'YYYY'
            || $body === 'YY'
            || $body === 'MM'
            || preg_match('/^seq:(\d+)$/', $body) === 1;

        if (! $known) {
            throw InvalidPattern::unknownToken($pattern, '{'.$body.'}');
        }
    }
}
