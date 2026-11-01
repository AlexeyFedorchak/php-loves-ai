<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

/**
 * No face was found in an image that had to have one, e.g. one of the two images being matched.
 */
final class NoFaceDetectedException extends \RuntimeException implements PhpLovesAiException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct("No face was found in {$path}.");
    }
}
