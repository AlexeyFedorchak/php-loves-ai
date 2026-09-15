<?php

declare(strict_types=1);

namespace PhpLovesAi\Binary;

use PhpLovesAi\Exception\InstallFailedException;
use PhpLovesAi\Filesystem\Path;
use Symfony\Component\Process\Process;

/**
 * Downloads a binary's release asset for the current platform, verifies its SHA-256 checksum and unpacks it into
 * the BinaryStore.
 *
 * Every asset "<tool>-<os>-<arch>.tar.gz" is published next to "<asset>.sha256".
 */
final class Installer
{
    /** Overrides the base URL assets are downloaded from, e.g. a mirror or a local build server. */
    public const DOWNLOAD_URL_ENV = 'PHP_LOVES_AI_DOWNLOAD_URL';

    public const REPOSITORY = 'AlexeyFedorchak/php-loves-ai';

    private const CHUNK_SIZE = 1 << 20;

    /**
     * @param string|null $downloadUrl base URL of the assets; defaults to PHP_LOVES_AI_DOWNLOAD_URL, else the GitHub
     *                                 release matching the store's version
     */
    public function __construct(
        private readonly BinaryStore $store,
        private readonly ?string $downloadUrl = null,
    ) {
    }

    public function store(): BinaryStore
    {
        return $this->store;
    }

    public function assetUrl(Tool $tool): string
    {
        $base = $this->downloadUrl ?? (getenv(self::DOWNLOAD_URL_ENV) ?: null);

        if ($base === null) {
            $version = $this->store->version();
            $base = $version === BinaryStore::LATEST
                ? 'https://github.com/' . self::REPOSITORY . '/releases/latest/download'
                : 'https://github.com/' . self::REPOSITORY . '/releases/download/' . rawurlencode($version);
        }

        return rtrim($base, '/') . '/' . $tool->assetName();
    }

    /**
     * Installs (or reinstalls) $tool; a previously installed copy is replaced only once the new one is verified.
     *
     * @param (\Closure(int, int|null): void)|null $onProgress receives bytes downloaded so far and the total when known
     *
     * @return string path of the installed executable
     *
     * @throws InstallFailedException
     */
    public function install(Tool $tool, ?\Closure $onProgress = null): string
    {
        $url = $this->assetUrl($tool);
        $dir = $this->store->versionDir();

        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw InstallFailedException::cannotWrite($dir);
        }

        $temp = "{$dir}/.{$tool->value}-" . bin2hex(random_bytes(4));
        $archive = "{$temp}.tar.gz";

        try {
            // The small checksum file first, so a missing asset fails before a long download.
            $expectedHash = $this->fetchChecksum("{$url}.sha256");
            $this->download($url, $archive, $onProgress);

            if (!hash_equals($expectedHash, (string) hash_file('sha256', $archive))) {
                throw InstallFailedException::checksumMismatch($url);
            }

            if (!@mkdir($temp)) {
                throw InstallFailedException::cannotWrite($temp);
            }

            $tar = new Process(['tar', '-xzf', $archive, '-C', $temp]);
            $tar->run();
            if (!$tar->isSuccessful()) {
                throw InstallFailedException::extractFailed($url, $tar->getErrorOutput());
            }

            $root = $tool->archiveRoot();
            if (!file_exists("{$temp}/{$root}")) {
                throw InstallFailedException::unexpectedArchive($url, $root);
            }

            $target = "{$dir}/{$root}";
            Path::remove($target);
            if (!@rename("{$temp}/{$root}", $target)) {
                throw InstallFailedException::cannotWrite($target);
            }
        } finally {
            Path::remove($archive);
            Path::remove($temp);
        }

        $path = $this->store->path($tool);
        if (!$this->store->isInstalled($tool)) {
            throw InstallFailedException::notExecutable($path);
        }

        return $path;
    }

    /**
     * @throws InstallFailedException
     */
    private function fetchChecksum(string $url): string
    {
        [$stream] = $this->open($url);
        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        // Accepts both a bare hash and `sha256sum` output ("<hash>  <file>").
        $hash = strtolower((string) strtok(trim($contents), " \t\r\n"));
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw InstallFailedException::invalidChecksum($url);
        }

        return $hash;
    }

    /**
     * @param (\Closure(int, int|null): void)|null $onProgress
     *
     * @throws InstallFailedException
     */
    private function download(string $url, string $destination, ?\Closure $onProgress): void
    {
        [$in, $total] = $this->open($url);

        $out = @fopen($destination, 'wb');
        if ($out === false) {
            fclose($in);
            throw InstallFailedException::cannotWrite($destination);
        }

        $downloaded = 0;

        try {
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK_SIZE);

                if ($chunk === false || stream_get_meta_data($in)['timed_out']) {
                    throw InstallFailedException::downloadFailed($url, 'the connection was interrupted');
                }

                if ($chunk === '') {
                    continue;
                }

                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw InstallFailedException::cannotWrite($destination);
                }

                $downloaded += strlen($chunk);
                if ($onProgress !== null) {
                    $onProgress($downloaded, $total);
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        if ($total !== null && $downloaded !== $total) {
            throw InstallFailedException::downloadFailed($url, "incomplete download ({$downloaded} of {$total} bytes)");
        }
    }

    /**
     * @return array{resource, int|null} readable stream and the content length when known
     *
     * @throws InstallFailedException
     */
    private function open(string $url): array
    {
        $context = stream_context_create(['http' => [
            'follow_location' => 1,
            'max_redirects' => 10,
            'ignore_errors' => true,
            'timeout' => 60,
            'user_agent' => 'php-loves-ai-setup',
        ]]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw InstallFailedException::downloadFailed($url, error_get_last()['message'] ?? 'unknown error');
        }

        // Headers of every redirect hop are listed in order; the last status line and its headers win.
        $status = null;
        $length = null;
        foreach (stream_get_meta_data($stream)['wrapper_data'] ?? [] as $header) {
            if (!is_string($header)) {
                continue;
            }

            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $match) === 1) {
                $status = (int) $match[1];
                $length = null;
            } elseif (preg_match('~^Content-Length:\s*(\d+)~i', $header, $match) === 1) {
                $length = (int) $match[1];
            }
        }

        if ($status !== null && $status >= 400) {
            fclose($stream);
            throw InstallFailedException::downloadFailed($url, "HTTP {$status}");
        }

        return [$stream, $length];
    }
}
