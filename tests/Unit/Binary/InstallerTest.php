<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Binary;

use PhpLovesAi\Binary\BinaryStore;
use PhpLovesAi\Binary\Installer;
use PhpLovesAi\Binary\Platform;
use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\InstallFailedException;
use PhpLovesAi\Filesystem\Path;
use PhpLovesAi\Tests\Support\FakeRelease;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class InstallerTest extends TestCase
{
    private string $tempDir;

    private FakeRelease $release;

    private BinaryStore $store;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/installer-test-' . bin2hex(random_bytes(4));
        $this->release = new FakeRelease("{$this->tempDir}/release");
        $this->store = new BinaryStore("{$this->tempDir}/home", 'v1.0.0');
    }

    protected function tearDown(): void
    {
        Path::remove($this->tempDir);
    }

    public function testInstallsBothBinaryLayouts(): void
    {
        $this->release->publish(Tool::Puller, 'puller works');
        $this->release->publish(Tool::TextToImage, 'runner works');
        $installer = new Installer($this->store, $this->release->url());

        self::assertSame($this->store->path(Tool::Puller), $installer->install(Tool::Puller));
        self::assertSame($this->store->path(Tool::TextToImage), $installer->install(Tool::TextToImage));

        self::assertSame("puller works\n", (new Process([$this->store->path(Tool::Puller)]))->mustRun()->getOutput());
        self::assertSame("runner works\n", (new Process([$this->store->path(Tool::TextToImage)]))->mustRun()->getOutput());
        self::assertSame([Platform::binaryName('puller'), 'text-to-image-' . Platform::current()], self::entries($this->store->versionDir()), 'No temporary files are left behind.');
    }

    public function testKeepsBinariesOutOfGit(): void
    {
        $this->release->publish(Tool::Puller);

        (new Installer($this->store, $this->release->url()))->install(Tool::Puller);

        self::assertSame("*\n", file_get_contents("{$this->tempDir}/home/.gitignore"));
    }

    public function testReportsProgress(): void
    {
        $this->release->publish(Tool::Puller);
        $calls = [];

        (new Installer($this->store, $this->release->url()))->install(Tool::Puller, static function (int $downloaded, ?int $total) use (&$calls): void {
            $calls[] = $downloaded;
        });

        self::assertNotEmpty($calls);
        self::assertSame(filesize("{$this->release->dir}/" . Tool::Puller->assetName()), end($calls));
    }

    public function testReplacesExistingInstallation(): void
    {
        $installer = new Installer($this->store, $this->release->url());
        $this->release->publish(Tool::Puller, 'old');
        $installer->install(Tool::Puller);

        $this->release->publish(Tool::Puller, 'new');
        $installer->install(Tool::Puller);

        self::assertSame("new\n", (new Process([$this->store->path(Tool::Puller)]))->mustRun()->getOutput());
    }

    public function testRejectsChecksumMismatchAndKeepsNothing(): void
    {
        $this->release->publish(Tool::Puller, checksum: str_repeat('0', 64));

        try {
            (new Installer($this->store, $this->release->url()))->install(Tool::Puller);
            self::fail('Expected InstallFailedException.');
        } catch (InstallFailedException $e) {
            self::assertStringContainsString('Checksum of', $e->getMessage());
        }

        self::assertFalse($this->store->isInstalled(Tool::Puller));
        self::assertSame([], self::entries($this->store->versionDir()));
    }

    public function testFailsWhenAssetIsMissing(): void
    {
        $this->expectException(InstallFailedException::class);
        $this->expectExceptionMessage('Download of');

        (new Installer($this->store, $this->release->url()))->install(Tool::TextToImage);
    }

    public function testBuildsGithubReleaseUrls(): void
    {
        putenv(Installer::DOWNLOAD_URL_ENV);
        $asset = Tool::Puller->assetName();

        self::assertSame(
            "https://github.com/AlexeyFedorchak/php-loves-ai/releases/download/v1.0.0/{$asset}",
            (new Installer($this->store))->assetUrl(Tool::Puller),
        );
        self::assertSame(
            "https://github.com/AlexeyFedorchak/php-loves-ai/releases/latest/download/{$asset}",
            (new Installer(new BinaryStore($this->tempDir, BinaryStore::LATEST)))->assetUrl(Tool::Puller),
        );
    }

    public function testDownloadUrlCanBeOverriddenByEnvironment(): void
    {
        putenv(Installer::DOWNLOAD_URL_ENV . '=https://mirror.example/assets/');

        try {
            self::assertSame(
                'https://mirror.example/assets/' . Tool::Puller->assetName(),
                (new Installer($this->store))->assetUrl(Tool::Puller),
            );
        } finally {
            putenv(Installer::DOWNLOAD_URL_ENV);
        }
    }

    /**
     * @return list<string>
     */
    private static function entries(string $dir): array
    {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }
}
