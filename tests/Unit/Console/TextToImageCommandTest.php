<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\BinaryStore;
use PhpLovesAi\Config\TextToImageConfig;
use PhpLovesAi\Console\TextToImageCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextToImageCommandTest extends TestCase
{
    private const FAKE_RUNNER = __DIR__ . '/../../Fixtures/fake-text-to-image';

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->stdout = self::memoryStream();
        $this->stderr = self::memoryStream();

        $this->tempDir = sys_get_temp_dir() . '/text-to-image-command-test-' . bin2hex(random_bytes(4));
        mkdir("{$this->tempDir}/models/org/model", 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (["{$this->tempDir}/run.log"] as $file) {
            is_file($file) && unlink($file);
        }
        foreach (["{$this->tempDir}/models/org/model", "{$this->tempDir}/models/org", "{$this->tempDir}/models", $this->tempDir] as $dir) {
            rmdir($dir);
        }
    }

    public function testGeneratesImageIntoConfiguredOutputDir(): void
    {
        self::assertSame(TextToImageCommand::EXIT_OK, $this->runCommand(['org/model', 'a cozy cat']));

        self::assertMatchesRegularExpression(
            '~^🎨 Painting your image with org/model… .+\n'
            . 'If you wish to see all logs, re-run the command with the "--debug" option\.\n'
            . '🎉 Image saved to /images/\d{8}-\d{6}-[0-9a-f]{6}\.png\n$~u',
            $this->contents($this->stdout),
        );
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testPassesOptionsToRunner(): void
    {
        $exitCode = $this->runCommand([
            'org/model', 'a cozy cat', '--debug',
            '--output=/tmp/cat.png', '--negative-prompt', 'dogs', '--steps=4', '--guidance=0',
            '--width=512', '--height=256', '--seed=42', '--device=cpu',
        ]);

        self::assertSame(TextToImageCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString(
            "args: --model {$this->tempDir}/models/org/model --prompt a cozy cat --output /tmp/cat.png "
            . '--negative-prompt dogs --steps 4 --guidance 0 --width 512 --height 256 --seed 42 --device cpu',
            $this->contents($this->stderr),
        );
        self::assertStringContainsString('🎉 Image saved to /tmp/cat.png', $this->contents($this->stdout));
    }

    public function testShowsHelp(): void
    {
        self::assertSame(TextToImageCommand::EXIT_OK, $this->runCommand(['--help']));
        self::assertStringContainsString('Usage: text-to-image <model> <prompt> [options]', $this->contents($this->stdout));
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidUsages')]
    public function testRejectsInvalidUsage(array $args, string $expectedError): void
    {
        self::assertSame(TextToImageCommand::EXIT_USAGE, $this->runCommand($args));
        self::assertStringContainsString($expectedError, $this->contents($this->stderr));
        self::assertStringContainsString("Run 'text-to-image --help' for usage.", $this->contents($this->stderr));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidUsages(): iterable
    {
        yield 'no prompt' => [['org/model'], 'Both a model and a prompt are required.'];
        yield 'unquoted prompt' => [['org/model', 'a', 'cat'], 'Too many arguments; wrap the prompt in quotes.'];
        yield 'non-integer steps' => [['org/model', 'a cat', '--steps=many'], 'Option --steps must be an integer.'];
        yield 'non-numeric guidance' => [['org/model', 'a cat', '--guidance=high'], 'Option --guidance must be a number.'];
        yield 'unknown option' => [['org/model', 'a cat', '--revision=main'], 'Unknown option: --revision=main'];
    }

    public function testReportsModelThatWasNotPulled(): void
    {
        self::assertSame(TextToImageCommand::EXIT_FAILURE, $this->runCommand(['org/missing', 'a cat']));

        self::assertSame(
            "Error: Model org/missing not found at {$this->tempDir}/models/org/missing.\n"
            . "Pull it first with: pull org/missing (or pass --dir if it was pulled elsewhere).\n",
            $this->contents($this->stderr),
        );
        self::assertSame('', $this->contents($this->stdout));
    }

    public function testReportsFailedRunWithDebugHint(): void
    {
        self::assertSame(TextToImageCommand::EXIT_FAILURE, $this->runCommand(['org/model', 'fail']));

        self::assertSame(
            "Error: failed to generate an image with org/model (exit code 1).\n"
            . "Re-run the command with the \"--debug\" option to see what went wrong.\n",
            $this->contents($this->stderr),
        );
    }

    public function testLogsRunnerOutputToLogFile(): void
    {
        $log = "{$this->tempDir}/run.log";

        self::assertSame(TextToImageCommand::EXIT_FAILURE, $this->runCommand(['org/model', 'fail', "--log-file={$log}"]));

        self::assertStringContainsString("See {$log} for details.", $this->contents($this->stderr));
        $contents = (string) file_get_contents($log);
        self::assertStringContainsString('] text-to-image org/model: fail', $contents);
        self::assertStringContainsString('prompt is too long', $contents);
    }

    public function testReportsMissingBinary(): void
    {
        $config = new TextToImageConfig('/nonexistent/text-to-image', "{$this->tempDir}/models", '/images');

        self::assertSame(TextToImageCommand::EXIT_FAILURE, $this->runCommand(['org/model', 'a cat'], $config));
        self::assertStringContainsString(
            "Check 'binary' in config/text-to-image.php, or set it to null to use the one installed by vendor/bin/setup text-to-image.",
            $this->contents($this->stderr),
        );
    }

    public function testRequiresSetupWhenRunnerIsNotInstalled(): void
    {
        $exitCode = $this->runCommand(
            ['org/model', 'a cat'],
            new TextToImageConfig(null, "{$this->tempDir}/models", '/images'),
            new BinaryStore("{$this->tempDir}/empty-home", 'v1.0.0'),
        );

        self::assertSame(TextToImageCommand::EXIT_FAILURE, $exitCode);
        self::assertSame(
            "Error: The text-to-image runner is not installed yet.\nRun vendor/bin/setup text-to-image first to download it 🧰\n",
            $this->contents($this->stderr),
        );
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args, ?TextToImageConfig $config = null, ?BinaryStore $store = null): int
    {
        $config ??= new TextToImageConfig(self::FAKE_RUNNER, "{$this->tempDir}/models", '/images');

        // Always the first intro, so assertions on the output are stable.
        return (new TextToImageCommand($config, $this->stdout, $this->stderr, static fn (): int => 0, $store))->run($args);
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
