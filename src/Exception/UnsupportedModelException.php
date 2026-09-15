<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

/**
 * A pulled model cannot be used by the runner it was given to, e.g. an add-on or a model made for another task.
 */
final class UnsupportedModelException extends \RuntimeException implements PhpLovesAiException
{
    public function __construct(
        public readonly string $model,
        public readonly string $path,
        string $message,
    ) {
        parent::__construct($message);
    }
}
