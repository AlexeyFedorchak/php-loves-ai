<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\Installer;
use PhpLovesAi\Binary\Platform;
use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Console\SetupCommand;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Filesystem\Path;
use PhpLovesAi\HuggingFace\Credentials;
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

    private LocalStorage $storage;

    protected function setUp(): void
    {
        $this->stdout = self::memoryStream();
        $this->stderr = self::memoryStream();

        $this->tempDir = sys_get_temp_dir() . '/setup-command-test-' . bin2hex(random_bytes(4));
        $this->release = new FakeRelease("{$this->tempDir}/release");
        $this->storage = new LocalStorage("{$this->tempDir}/project");
    }

    protected function tearDown(): void
    {
        Path::remove($this->tempDir);
    }

    public function testInstallsPullerByDefault(): void
    {
        $this->release->publish(Tool::Puller);

        self::assertSame(SetupCommand::EXIT_OK, $this->runCommand([]));

        self::assertTrue($this->storage->isInstalled(Tool::Puller));
        self::assertFalse($this->storage->isInstalled(Tool::TextToImage));
        self::assertSame(
            '🧰 Setting up php-loves-ai (v1.0.0) for ' . Platform::current() . "\n"
            . "💡 No Hugging Face API key saved: only public models can be pulled. Add one any time with: vendor/bin/setup --token=<your Hugging Face API key>\n"
            . "✅ The puller is installed at {$this->storage->binaryPath(Tool::Puller)}\n"
            . "🎉 All set! Happy hacking 🍪\n"
            . "👉 Pull a model: vendor/bin/pull <model>\n"
            . "💡 Want to generate images too? Run: vendor/bin/setup text-to-image (a few hundred MB)\n"
            . "💡 Want to generate text too? Run: vendor/bin/setup text-to-text (a few hundred MB)\n"
            . "💡 Want to describe images too? Run: vendor/bin/setup image-to-text (a few hundred MB)\n"
            . "💡 Want to transcribe audio too? Run: vendor/bin/setup speech-to-text (a few hundred MB)\n",
            $this->contents($this->stdout),
        );
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testInstallsRequestedBinaries(): void
    {
        $this->release->publish(Tool::Puller);
        $this->release->publish(Tool::TextToImage);
        $this->release->publish(Tool::TextToText);
        $this->release->publish(Tool::ImageToText);
        $this->release->publish(Tool::SpeechToText);

        self::assertSame(SetupCommand::EXIT_OK, $this->runCommand(['puller', 'text-to-image', 'text-to-text', 'image-to-text', 'speech-to-text']));

        self::assertTrue($this->storage->isInstalled(Tool::Puller));
        self::assertTrue($this->storage->isInstalled(Tool::TextToImage));
        self::assertTrue($this->storage->isInstalled(Tool::TextToText));
        self::assertStringContainsString("✅ The text-to-text runner is installed at {$this->storage->binaryPath(Tool::TextToText)}", $this->contents($this->stdout));
        self::assertStringContainsString('👉 Generate text: vendor/bin/text-to-text <model> "<prompt>"', $this->contents($this->stdout));
        self::assertStringContainsString('👉 Describe an image: vendor/bin/image-to-text <model> <image>', $this->contents($this->stdout));
        self::assertStringContainsString('👉 Transcribe audio: vendor/bin/speech-to-text <model> <audio>', $this->contents($this->stdout));
        self::assertStringNotContainsString('💡 Want to', $this->contents($this->stdout));
        self::assertStringContainsString("✅ The text-to-image runner is installed at {$this->storage->binaryPath(Tool::TextToImage)}", $this->contents($this->stdout));
        self::assertStringContainsString('👉 Generate an image: vendor/bin/text-to-image <model> "<prompt>"', $this->contents($this->stdout));
    }

    public function testSkipsInstalledBinariesUnlessForced(): void
    {
        $this->release->publish(Tool::Puller);
        $this->runCommand([]);

        $this->stdout = self::memoryStream();
        self::assertSame(SetupCommand::EXIT_OK, $this->runCommand([]));
        self::assertStringContainsString("✅ The puller is already installed at {$this->storage->binaryPath(Tool::Puller)}", $this->contents($this->stdout));

        $this->stdout = self::memoryStream();
        self::assertSame(SetupCommand::EXIT_OK, $this->runCommand(['--force']));
        self::assertStringContainsString("✅ The puller is installed at {$this->storage->binaryPath(Tool::Puller)}", $this->contents($this->stdout));
    }

    public function testDebugShowsWhereBinariesComeFrom(): void
    {
        $this->release->publish(Tool::Puller);

        $this->runCommand(['--debug']);

        $stdout = $this->contents($this->stdout);
        self::assertStringContainsString("Installing into {$this->tempDir}/project/.local/runners", $stdout);
        self::assertStringContainsString("Downloading {$this->release->url()}/" . Tool::Puller->assetName(), $stdout);
    }

    public function testRejectsUnknownBinary(): void
    {
        self::assertSame(SetupCommand::EXIT_USAGE, $this->runCommand(['text-to-speech']));
        self::assertStringContainsString("Error: Unknown binary 'text-to-speech'. Available: puller, text-to-image, text-to-text, image-to-text, speech-to-text.", $this->contents($this->stderr));
    }

    public function testReportsFailedDownload(): void
    {
        self::assertSame(SetupCommand::EXIT_FAILURE, $this->runCommand(['text-to-image']));

        self::assertStringStartsWith('Error: Download of ' . $this->release->url(), $this->contents($this->stderr));
        self::assertStringContainsString('Check your internet connection and try again.', $this->contents($this->stderr));
        self::assertFalse($this->storage->isInstalled(Tool::TextToImage));
    }

    public function testAsksForApiKeyAndSavesIt(): void
    {
        $this->release->publish(Tool::Puller);

        self::assertSame(SetupCommand::EXIT_OK, $this->runCommand([], input: "hf_pasted\n"));

        $credentials = new Credentials($this->storage);
        self::assertSame('hf_pasted', $credentials->apiKey());
        $stdout = $this->contents($this->stdout);
        self::assertStringContainsString("🔑 Hugging Face API key (optional)\n", $stdout);
        self::assertStringContainsString('Paste your key (hidden), or press Enter to use public models only: ', $stdout);
        self::assertStringContainsString("✅ Saved your Hugging Face API key to {$credentials->path()}\n", $stdout);
        self::assertStringNotContainsString('hf_pasted', $stdout);
    }

    public function testAsksAgainAfterInvalidApiKey(): void
    {
        $this->release->publish(Tool::Puller);

        self::assertSame(SetupCommand::EXIT_OK, $this->runCommand([], input: "not-a-key\nhf_second\n"));

        self::assertStringContainsString("'not-a-…' is not a Hugging Face API key", $this->contents($this->stdout));
        self::assertSame('hf_second', (new Credentials($this->storage))->apiKey());
    }

    public function testRemembersSkippedApiKey(): void
    {
        $this->release->publish(Tool::Puller);

        self::assertSame(SetupCommand::EXIT_OK, $this->runCommand([], input: "\n"));
        self::assertStringContainsString('👌 Continuing without a key: only public models can be pulled.', $this->contents($this->stdout));

        $this->stdout = self::memoryStream();
        self::assertSame(SetupCommand::EXIT_OK, $this->runCommand([], input: "hf_never_read\n"));

        $stdout = $this->contents($this->stdout);
        self::assertStringNotContainsString('Paste your key', $stdout, 'Setup does not ask again.');
        self::assertStringContainsString('🔑 No Hugging Face API key: only public models can be pulled.', $stdout);
        self::assertNull((new Credentials($this->storage))->apiKey());
    }

    public function testSavesTokenOptionWithoutAsking(): void
    {
        $this->release->publish(Tool::Puller);
        (new Credentials($this->storage))->declineApiKey();

        self::assertSame(SetupCommand::EXIT_OK, $this->runCommand(['--token=hf_given'], input: ''));

        $credentials = new Credentials($this->storage);
        self::assertSame('hf_given', $credentials->apiKey());
        self::assertStringContainsString("🔑 Saved your Hugging Face API key to {$credentials->path()}", $this->contents($this->stdout));

        $this->stdout = self::memoryStream();
        $this->runCommand([]);
        self::assertStringContainsString("🔑 Using the Hugging Face API key saved in {$credentials->path()}", $this->contents($this->stdout));
    }

    public function testRejectsInvalidTokenOption(): void
    {
        self::assertSame(SetupCommand::EXIT_USAGE, $this->runCommand(['--token=oops']));
        self::assertStringContainsString("'oops' is not a Hugging Face API key", $this->contents($this->stderr));
    }

    /**
     * @param list<string> $args
     * @param string|null  $input what the user types; null runs non-interactively
     */
    private function runCommand(array $args, ?string $input = null): int
    {
        $installer = new Installer($this->storage, 'v1.0.0', $this->release->url());

        $stdin = self::memoryStream();
        fwrite($stdin, $input ?? '');
        rewind($stdin);

        return (new SetupCommand($installer, $this->stdout, $this->stderr, $stdin, $input !== null))->run($args);
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
