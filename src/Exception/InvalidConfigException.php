<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

final class InvalidConfigException extends \UnexpectedValueException implements PhpLovesAiException
{
    public static function missingFile(string $file): self
    {
        return new self("Config file not found: {$file}");
    }

    public static function invalidValue(string $file, string $key, string $expected): self
    {
        return new self("Config file {$file}: '{$key}' must be {$expected}.");
    }
}
