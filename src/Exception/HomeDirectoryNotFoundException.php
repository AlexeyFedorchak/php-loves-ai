<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

final class HomeDirectoryNotFoundException extends \RuntimeException implements PhpLovesAiException
{
    public static function forPath(string $path): self
    {
        return new self("Cannot expand '~' in {$path}: neither HOME nor USERPROFILE is set.");
    }
}
