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

    /**
     * Deletes a file or a directory with everything in it; a missing path is ignored. Symlinks are removed, not followed.
     */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }
}
