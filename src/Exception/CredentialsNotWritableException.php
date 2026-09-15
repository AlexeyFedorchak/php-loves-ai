<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

final class CredentialsNotWritableException extends \RuntimeException implements PhpLovesAiException
{
    public static function atPath(string $path): self
    {
        return new self("Cannot save the Hugging Face API key to {$path}");
    }
}
