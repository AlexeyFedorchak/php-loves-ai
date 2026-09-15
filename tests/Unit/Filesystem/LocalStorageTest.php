<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Filesystem;

use PhpLovesAi\Binary\Platform;
use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\TestCase;

final class LocalStorageTest extends TestCase
{
    private FakeProject $project;

    protected function setUp(): void
    {
        $this->project = new FakeProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testLaysOutModelsAndRunnersInsideLocalDir(): void
    {
        $storage = $this->project->storage;
        $root = $this->project->root;
        $platform = Platform::current();

        self::assertSame("{$root}/.local", $storage->dir());
        self::assertSame("{$root}/.local/models", $storage->modelsDir());
        self::assertSame("{$root}/.local/runners", $storage->runnersDir());
        self::assertSame("{$root}/.local/models/stabilityai/sd-turbo", $storage->modelPath('stabilityai/sd-turbo'));
        self::assertSame("{$root}/.local/runners/" . Platform::binaryName('puller'), $storage->binaryPath(Tool::Puller));
        self::assertSame(
            "{$root}/.local/runners/text-to-image-{$platform}/" . Platform::binaryName('text-to-image'),
            $storage->binaryPath(Tool::TextToImage),
        );
    }

    public function testDetectsInstalledExecutables(): void
    {
        $storage = $this->project->storage;
        self::assertFalse($storage->isInstalled(Tool::Puller));

        mkdir($storage->runnersDir(), 0777, true);
        file_put_contents($storage->binaryPath(Tool::Puller), '');
        self::assertFalse($storage->isInstalled(Tool::Puller), 'A file that is not executable does not count.');

        chmod($storage->binaryPath(Tool::Puller), 0755);
        self::assertTrue($storage->isInstalled(Tool::Puller));
    }

    public function testCreatedDirectoriesAreKeptOutOfGit(): void
    {
        $storage = $this->project->storage;

        self::assertTrue($storage->ensureDirectory($storage->modelsDir()));

        self::assertDirectoryExists($storage->modelsDir());
        self::assertSame("*\n", file_get_contents("{$this->project->root}/.local/.gitignore"));
    }

    public function testDefaultsToProjectRoot(): void
    {
        // This repository is the root package.
        $root = (string) realpath(__DIR__ . '/../../..');

        self::assertSame($root, LocalStorage::projectRoot());
        self::assertSame("{$root}/.local/models", (new LocalStorage())->modelsDir());
        self::assertSame("{$root}/.local/runners", (new LocalStorage())->runnersDir());
    }

    public function testDoesNotDependOnEnvironmentOrWorkingDirectory(): void
    {
        $expected = (new LocalStorage())->dir();
        $cwd = (string) getcwd();
        $home = getenv('HOME');

        try {
            chdir(sys_get_temp_dir());
            putenv('HOME=/nonexistent-home');

            self::assertSame($expected, (new LocalStorage())->dir());
        } finally {
            chdir($cwd);
            putenv($home === false ? 'HOME' : "HOME={$home}");
        }
    }
}
