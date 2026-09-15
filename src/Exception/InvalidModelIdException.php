<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

final class InvalidModelIdException extends \InvalidArgumentException implements PhpLovesAiException
{
    public static function forId(string $id): self
    {
        return new self("Invalid Hugging Face model id: '{$id}' (expected 'name' or 'namespace/name').");
    }
}
