<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

final class LogFileNotWritableException extends \RuntimeException implements PhpLovesAiException
{
    public static function atPath(string $path): self
    {
        return new self("Cannot write log file: {$path}");
    }
}
