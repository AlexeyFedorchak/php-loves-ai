<?php

declare(strict_types=1);

namespace PhpLovesAi\Binary;

/**
 * Names prebuilt binaries after the platform they were built for, e.g. "puller-darwin-arm64".
 * Must stay in sync with the naming in python/puller/build.sh.
 */
final class Platform
{
    public static function current(): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            'Windows' => 'windows',
            default => strtolower(PHP_OS_FAMILY),
        };

        $machine = strtolower(php_uname('m'));
        $arch = match ($machine) {
            'x86_64', 'amd64' => 'x86_64',
            'arm64', 'aarch64' => 'arm64',
            default => $machine,
        };

        return "{$os}-{$arch}";
    }

    public static function binaryName(string $tool): string
    {
        return $tool . '-' . self::current() . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }
}
