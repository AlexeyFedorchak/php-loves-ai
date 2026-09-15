<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Support;

use PhpLovesAi\Binary\Tool;
use Symfony\Component\Process\Process;

/**
 * Builds release assets in a local directory, laid out like the GitHub release: "<asset>.tar.gz" + "<asset>.sha256".
 * Point an Installer at "file://<dir>" to install from it.
 */
final class FakeRelease
{
    public function __construct(public readonly string $dir)
    {
        is_dir($dir) || mkdir($dir, 0777, true);
    }

    public function url(): string
    {
        return 'file://' . $this->dir;
    }

    /**
     * Publishes an asset whose executable prints $marker.
     */
    public function publish(Tool $tool, string $marker = 'fake binary', ?string $checksum = null): void
    {
        $staging = "{$this->dir}/staging-{$tool->value}";
        $executable = "{$staging}/{$tool->executablePath()}";
        is_dir(dirname($executable)) || mkdir(dirname($executable), 0777, true);
        file_put_contents($executable, "#!/bin/sh\necho '{$marker}'\n");
        chmod($executable, 0755);

        $asset = "{$this->dir}/{$tool->assetName()}";
        (new Process(['tar', '-czf', $asset, '-C', $staging, $tool->archiveRoot()]))->mustRun();
        file_put_contents("{$asset}.sha256", ($checksum ?? hash_file('sha256', $asset)) . "  {$tool->assetName()}\n");

        (new Process(['rm', '-rf', $staging]))->mustRun();
    }
}
