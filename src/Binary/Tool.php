<?php

declare(strict_types=1);

namespace PhpLovesAi\Binary;

/**
 * The prebuilt binaries this package runs, with their release asset and install layout for the current platform.
 */
enum Tool: string
{
    case Puller = 'puller';
    case TextToImage = 'text-to-image';

    public function label(): string
    {
        return match ($this) {
            self::Puller => 'puller',
            self::TextToImage => 'text-to-image runner',
        };
    }

    /** Command that installs this binary. */
    public function setupCommand(): string
    {
        return match ($this) {
            self::Puller => 'vendor/bin/setup',
            self::TextToImage => 'vendor/bin/setup text-to-image',
        };
    }

    /** Release asset file name, e.g. "puller-darwin-arm64.tar.gz". */
    public function assetName(): string
    {
        return $this->value . '-' . Platform::current() . '.tar.gz';
    }

    /** Top-level entry inside the release archive: the binary itself, or the directory of a --onedir build. */
    public function archiveRoot(): string
    {
        return match ($this) {
            self::Puller => Platform::binaryName($this->value),
            self::TextToImage => $this->value . '-' . Platform::current(),
        };
    }

    /** Path of the executable relative to the directory the archive was unpacked into. */
    public function executablePath(): string
    {
        return match ($this) {
            self::Puller => $this->archiveRoot(),
            self::TextToImage => $this->archiveRoot() . '/' . Platform::binaryName($this->value),
        };
    }
}
