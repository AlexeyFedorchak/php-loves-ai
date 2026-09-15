<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Binary;

use PhpLovesAi\Binary\BinaryStore;
use PhpLovesAi\Binary\Platform;
use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Filesystem\Path;
use PHPUnit\Framework\TestCase;

final class BinaryStoreTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/binary-store-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        Path::remove($this->home);
    }

    public function testLaysOutBinariesByVersion(): void
    {
        $store = new BinaryStore($this->home, 'v1.2.3');
        $platform = Platform::current();

        self::assertSame("{$this->home}/bin/v1.2.3", $store->versionDir());
        self::assertSame("{$this->home}/bin/v1.2.3/" . Platform::binaryName('puller'), $store->path(Tool::Puller));
        self::assertSame(
            "{$this->home}/bin/v1.2.3/text-to-image-{$platform}/" . Platform::binaryName('text-to-image'),
            $store->path(Tool::TextToImage),
        );
    }

    public function testDetectsInstalledExecutables(): void
    {
        $store = new BinaryStore($this->home, 'v1.2.3');
        self::assertFalse($store->isInstalled(Tool::Puller));

        mkdir($store->versionDir(), 0777, true);
        file_put_contents($store->path(Tool::Puller), '');
        self::assertFalse($store->isInstalled(Tool::Puller), 'A file that is not executable does not count.');

        chmod($store->path(Tool::Puller), 0755);
        self::assertTrue($store->isInstalled(Tool::Puller));
    }

    public function testDefaultHomeIsInsideProjectRoot(): void
    {
        // This repository is the root package.
        $root = (string) realpath(__DIR__ . '/../../..');

        self::assertSame($root, BinaryStore::projectRoot());
        self::assertSame("{$root}/.local/share/php-loves-ai", BinaryStore::defaultHome());
        self::assertSame("{$root}/.local/share/php-loves-ai", (new BinaryStore())->home());
    }

    public function testDefaultHomeDoesNotDependOnEnvironmentOrWorkingDirectory(): void
    {
        $expected = BinaryStore::defaultHome();
        $cwd = (string) getcwd();
        $home = getenv('HOME');

        try {
            chdir(sys_get_temp_dir());
            putenv('HOME=/nonexistent-home');

            self::assertSame($expected, BinaryStore::defaultHome());
        } finally {
            chdir($cwd);
            putenv($home === false ? 'HOME' : "HOME={$home}");
        }
    }

    public function testDevelopmentInstallsUseLatestRelease(): void
    {
        // This repository is the root package, installed as a dev branch.
        self::assertSame(BinaryStore::LATEST, BinaryStore::installedVersion());
    }
}
