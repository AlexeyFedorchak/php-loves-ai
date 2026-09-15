<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

final class ModelNotFoundException extends \RuntimeException implements PhpLovesAiException
{
    public function __construct(
        public readonly string $model,
        public readonly string $path,
    ) {
        parent::__construct("Model {$model} not found at {$path}.");
    }
}
