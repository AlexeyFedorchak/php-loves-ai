<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

/**
 * A runner binary exited unsuccessfully, e.g. because the model rejected the given input.
 */
final class RunFailedException extends \RuntimeException implements PhpLovesAiException
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $errorOutput,
    ) {
        $details = trim($errorOutput);

        parent::__construct(
            "Runner exited with code {$exitCode}" . ($details !== '' ? ":\n{$details}" : '.'),
        );
    }
}
