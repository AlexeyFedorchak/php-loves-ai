<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\BinaryStore;
use PhpLovesAi\Binary\Installer;
use PhpLovesAi\Binary\Platform;
use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Console\SetupCommand;
use PhpLovesAi\Filesystem\Path;
use PhpLovesAi\Tests\Support\FakeRelease;
use PHPUnit\Framework\TestCase;

final class SetupCommandTest extends TestCase
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private string $tempDir;

    private FakeRelease $release;

    private BinaryStore $store;

    protected function setUp(): void
    {
        $this->stdout = self::memoryStream();
        $this->stderr = self::memoryStream();

        $this->tempDir = sys_get_temp_dir() . '/setup-command-test-' . bin2hex(random_bytes(4));
        $this->release = new FakeRelease("{$this->tempDir}/release");
        $this->store = new BinaryStore("{$this->tempDir}/home", 'v1.0.0');
    }

    protected function tearDown(): void
    {
        Path::remove($this->tempDir);
    }

    public function testInstallsPullerByDefault(): void
    {
        $this->release->publish(Tool::Puller);

        self::assertSame(SetupCommand::EXIT_OK, $this->runCommand([]));

        self::assertTrue($this->store->isInstalled(Tool::Puller));
        self::assertFalse($this->store->isInstalled(Tool::TextToImage));
        self::assertSame(
            '🧰 Setting up php-loves-ai (v1.0.0) for ' . Platform::current() . "\n"
            . "✅ The puller is installed.\n"
            . "🎉 All set! Happy hacking 🍪\n"
            . "👉 Pull a model: vendor/bin/pull <model>\n"
            . "💡 Want to generate images too? Run: vendor/bin/setup text-to-image (a few hundred MB)\n",
            $this->contents($this->stdout),
        );
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testInstallsRequestedBinaries(): void
    {
        $this->release->publish(Tool::Puller);
        $this->release->publish(Tool::TextToImage);

        self::assertSame(SetupCommand::EXIT_OK, $this->runCommand(['puller', 'text-to-image']));

        self::assertTrue($this->store->isInstalled(Tool::Puller));
        self::assertTrue($this->store->isInstalled(Tool::TextToImage));
        self::assertStringContainsString('👉 Generate an image: vendor/bin/text-to-image <model> "<prompt>"', $this->contents($this->stdout));
    }

    public function testSkipsInstalledBinariesUnlessForced(): void
    {
        $this->release->publish(Tool::Puller);
        $this->runCommand([]);

        $this->stdout = self::memoryStream();
        self::assertSame(SetupCommand::EXIT_OK, $this->runCommand([]));
        self::assertStringContainsString('✅ The puller is already installed.', $this->contents($this->stdout));

        $this->stdout = self::memoryStream();
        self::assertSame(SetupCommand::EXIT_OK, $this->runCommand(['--force']));
        self::assertStringContainsString('✅ The puller is installed.', $this->contents($this->stdout));
    }

    public function testDebugShowsWhereBinariesComeFrom(): void
    {
        $this->release->publish(Tool::Puller);

        $this->runCommand(['--debug']);

        $stdout = $this->contents($this->stdout);
        self::assertStringContainsString("Installing into {$this->store->versionDir()}", $stdout);
        self::assertStringContainsString("Downloading {$this->release->url()}/" . Tool::Puller->assetName(), $stdout);
    }

    public function testRejectsUnknownBinary(): void
    {
        self::assertSame(SetupCommand::EXIT_USAGE, $this->runCommand(['image-to-text']));
        self::assertStringContainsString("Error: Unknown binary 'image-to-text'. Available: puller, text-to-image.", $this->contents($this->stderr));
    }

    public function testReportsFailedDownload(): void
    {
        self::assertSame(SetupCommand::EXIT_FAILURE, $this->runCommand(['text-to-image']));

        self::assertStringStartsWith('Error: Download of ' . $this->release->url(), $this->contents($this->stderr));
        self::assertStringContainsString('Check your internet connection and try again.', $this->contents($this->stderr));
        self::assertFalse($this->store->isInstalled(Tool::TextToImage));
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args): int
    {
        $installer = new Installer($this->store, $this->release->url());

        return (new SetupCommand($installer, $this->stdout, $this->stderr))->run($args);
    }

    /**
     * @param resource $stream
     */
    private function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * @return resource
     */
    private static function memoryStream()
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        return $stream;
    }
}
