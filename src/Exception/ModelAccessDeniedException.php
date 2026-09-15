<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

/**
 * Hugging Face refused a model: it is gated, private or does not exist, and the API key (if any) gives no access.
 */
final class ModelAccessDeniedException extends \RuntimeException implements PhpLovesAiException
{
    /** The model needs a key whose account accepted the model's terms. Must stay in sync with python/puller/puller.py. */
    public const GATED = 'gated';

    /** The model does not exist, or is private. Must stay in sync with python/puller/puller.py. */
    public const NOT_FOUND = 'not_found';

    /**
     * @param string                $reason       self::GATED or self::NOT_FOUND
     * @param bool                  $apiKeyUsed   whether the pull was made with an API key
     * @param array<string, string> $pulled       models pulled before the failure: model id => local path
     */
    public function __construct(
        public readonly string $model,
        public readonly string $reason,
        public readonly bool $apiKeyUsed,
        public readonly array $pulled = [],
    ) {
        parent::__construct(match (true) {
            $reason === self::GATED && !$apiKeyUsed => "{$model} is not available: it is a gated model, which needs a Hugging Face API key.",
            $reason === self::GATED => "{$model} is not available with your Hugging Face API key: it is a gated model. "
                . "Open https://huggingface.co/{$model}, accept its terms with the account the key belongs to, and try again.",
            !$apiKeyUsed => "{$model} is not available: it does not exist, or it is private and needs a Hugging Face API key.",
            default => "{$model} is not available: it does not exist, or your Hugging Face API key has no access to it.",
        });
    }
}
