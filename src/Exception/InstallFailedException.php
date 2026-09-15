<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

final class InstallFailedException extends \RuntimeException implements PhpLovesAiException
{
    public static function downloadFailed(string $url, string $reason): self
    {
        return new self("Download of {$url} failed: {$reason}");
    }

    public static function invalidChecksum(string $url): self
    {
        return new self("{$url} does not contain a SHA-256 checksum.");
    }

    public static function checksumMismatch(string $url): self
    {
        return new self("Checksum of {$url} does not match; the download is corrupted or was tampered with.");
    }

    public static function extractFailed(string $url, string $reason): self
    {
        return new self("Unpacking {$url} failed: " . trim($reason));
    }

    public static function unexpectedArchive(string $url, string $expectedEntry): self
    {
        return new self("{$url} does not contain {$expectedEntry}.");
    }

    public static function cannotWrite(string $path): self
    {
        return new self("Cannot write {$path}");
    }

    public static function notExecutable(string $path): self
    {
        return new self("Installed binary is not executable: {$path}");
    }
}
