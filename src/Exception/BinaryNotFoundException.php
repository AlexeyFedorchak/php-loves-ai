<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

final class BinaryNotFoundException extends \RuntimeException implements PhpLovesAiException
{
    public static function atPath(string $path): self
    {
        return new self("Binary not found or not executable: {$path}");
    }
}
