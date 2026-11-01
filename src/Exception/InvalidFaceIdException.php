<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

/**
 * Two face ids cannot be compared, or one is not a face id at all.
 */
final class InvalidFaceIdException extends \InvalidArgumentException implements PhpLovesAiException
{
    public static function malformed(string $id): self
    {
        return new self(sprintf(
            "'%s' is not a face id; they look like \"v1:256:<hex>\", as reported by `vendor/bin/loves-ai face`.",
            $id,
        ));
    }

    public static function notComparable(string $a, string $b): self
    {
        return new self(sprintf(
            'Face ids %s and %s cannot be compared: they differ in version or bit count, so they describe '
            . 'different hashes. Re-run `face` over the images with one --hash-bits value.',
            self::summarize($a),
            self::summarize($b),
        ));
    }

    /** Version and bit count only; the hash itself is not worth repeating in an error. */
    private static function summarize(string $id): string
    {
        $parts = explode(':', $id);

        return implode(':', [$parts[0], $parts[1] ?? '?']) . ':…';
    }
}
