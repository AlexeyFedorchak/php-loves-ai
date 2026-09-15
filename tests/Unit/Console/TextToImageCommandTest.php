<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Config\TextToImageConfig;
use PhpLovesAi\Console\TextToImageCommand;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextToImageCommandTest extends TestCase
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private FakeProject $project;

    protected function setUp(): void
    {
        $this->stdout = self::memoryStream();
        $this->stderr = self::memoryStream();

        $this->project = (new FakeProject())
            ->install(Tool::TextToImage, FakeProject::FAKE_TEXT_TO_IMAGE)
            ->addModel('org/model');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
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
            "args: --model {$this->project->root}/.local/models/org/model --prompt a cozy cat --output /tmp/cat.png "
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
        yield 'dir is not an option' => [['org/model', 'a cat', '--dir=/models'], 'Unknown option: --dir=/models'];
    }

    public function testReportsModelThatWasNotPulled(): void
    {
        self::assertSame(TextToImageCommand::EXIT_FAILURE, $this->runCommand(['org/missing', 'a cat']));

        self::assertSame(
            "Error: Model org/missing not found at {$this->project->root}/.local/models/org/missing.\n"
            . "Pull it first with: vendor/bin/pull org/missing\n",
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
        $log = "{$this->project->root}/run.log";

        self::assertSame(TextToImageCommand::EXIT_FAILURE, $this->runCommand(['org/model', 'fail', "--log-file={$log}"]));

        self::assertStringContainsString("See {$log} for details.", $this->contents($this->stderr));
        $contents = (string) file_get_contents($log);
        self::assertStringContainsString('] text-to-image org/model: fail', $contents);
        self::assertStringContainsString('prompt is too long', $contents);
    }

    public function testRequiresSetupWhenRunnerIsNotInstalled(): void
    {
        $emptyProject = (new FakeProject())->addModel('org/model');

        try {
            $exitCode = $this->runCommand(['org/model', 'a cat'], $emptyProject->storage);
        } finally {
            $emptyProject->remove();
        }

        self::assertSame(TextToImageCommand::EXIT_FAILURE, $exitCode);
        self::assertSame(
            "Error: The text-to-image runner is not installed yet.\nRun vendor/bin/setup text-to-image first to download it 🧰\n",
            $this->contents($this->stderr),
        );
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args, ?LocalStorage $storage = null): int
    {
        $config = new TextToImageConfig('/images');

        // Always the first intro, so assertions on the output are stable.
        return (new TextToImageCommand($config, $this->stdout, $this->stderr, static fn (): int => 0, $storage ?? $this->project->storage))->run($args);
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
