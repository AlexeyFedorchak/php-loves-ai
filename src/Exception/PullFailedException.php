<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

final class PullFailedException extends \RuntimeException implements PhpLovesAiException
{
    /**
     * @param array<string, string> $pulled models that were pulled before the failure: model id => local path
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $errorOutput,
        public readonly array $pulled = [],
    ) {
        $details = trim($errorOutput);

        parent::__construct(
            "Puller exited with code {$exitCode}" . ($details !== '' ? ":\n{$details}" : '.'),
        );
    }
}
