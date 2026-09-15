<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

final class InvalidApiKeyException extends \InvalidArgumentException implements PhpLovesAiException
{
    public static function forKey(string $apiKey): self
    {
        // Only the start of a long value is shown: it may be a secret pasted by mistake.
        $shown = match (true) {
            $apiKey === '' => 'An empty value',
            strlen($apiKey) > 6 => "'" . substr($apiKey, 0, 6) . "…'",
            default => "'{$apiKey}'",
        };

        return new self("{$shown} is not a Hugging Face API key; keys start with hf_ and contain only letters and digits.");
    }
}
