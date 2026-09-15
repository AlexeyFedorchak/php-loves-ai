<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

final class AudioNotFoundException extends \RuntimeException implements PhpLovesAiException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct("Audio file not found or not readable: {$path}");
    }
}
