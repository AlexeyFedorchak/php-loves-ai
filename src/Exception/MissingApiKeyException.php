<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

/**
 * A puller from before API keys became optional refused to pull without one.
 */
final class MissingApiKeyException extends \RuntimeException implements PhpLovesAiException
{
    public static function forOutdatedPuller(): self
    {
        return new self('The installed puller needs a Hugging Face API key to pull any model, including public ones.');
    }
}
