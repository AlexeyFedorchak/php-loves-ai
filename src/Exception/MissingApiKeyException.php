<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

final class MissingApiKeyException extends \RuntimeException implements PhpLovesAiException
{
    public static function forVariable(string $name): self
    {
        return new self("Environment variable {$name} is not set; it must contain your Hugging Face API key.");
    }
}
