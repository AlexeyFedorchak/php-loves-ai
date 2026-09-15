<?php

declare(strict_types=1);

namespace PhpLovesAi\Filesystem;

use PhpLovesAi\Exception\HomeDirectoryNotFoundException;

final class Path
{
    /**
     * Expands a leading "~" to the user's home directory; any other path is returned unchanged.
     *
     * @throws HomeDirectoryNotFoundException
     */
    public static function expandHome(string $path): string
    {
        if ($path !== '~' && !str_starts_with($path, '~/')) {
            return $path;
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if ($home === false || $home === '') {
            throw HomeDirectoryNotFoundException::forPath($path);
        }

        return rtrim($home, '/\\') . substr($path, 1);
    }
}
