<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Config\TextToTextConfig;
use PhpLovesAi\Console\TextToTextCommand;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextToTextCommandTest extends TestCase
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
            ->install(Tool::TextToText, FakeProject::FAKE_TEXT_TO_TEXT)
            ->addModel('org/model', ['config.json', 'model.safetensors']);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testPrintsGeneratedText(): void
    {
        self::assertSame(TextToTextCommand::EXIT_OK, $this->runCommand(['org/model', 'Hello there']));

        self::assertSame(
            "✍️ Writing with org/model… Good words take a moment — perfect time for a cup of tea and a cookie 🍪\n"
            . "If you wish to see all logs, re-run the command with the \"--debug\" option.\n"
            . "🎉 org/model wrote:\n"
            . "You said: Hello there\nThat is all.\n",
            $this->contents($this->stdout),
        );
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testPassesOptionsToRunner(): void
    {
        $exitCode = $this->runCommand([
            'org/model', 'Hello', '--debug',
            '--system', 'Be brief.', '--max-new-tokens=64', '--temperature=0.7', '--top-p=0.9', '--seed=42', '--device=cpu',
        ]);

        self::assertSame(TextToTextCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString(
            "args: --model {$this->project->root}/.local/models/org/model --prompt Hello "
            . '--system Be brief. --max-new-tokens 64 --temperature 0.7 --top-p 0.9 --seed 42 --device cpu',
            $this->contents($this->stderr),
        );
    }

    public function testShowsHelp(): void
    {
        self::assertSame(TextToTextCommand::EXIT_OK, $this->runCommand(['--help']));
        self::assertStringContainsString('Usage: text-to-text <model> <prompt> [options]', $this->contents($this->stdout));
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidUsages')]
    public function testRejectsInvalidUsage(array $args, string $expectedError): void
    {
        self::assertSame(TextToTextCommand::EXIT_USAGE, $this->runCommand($args));
        self::assertStringContainsString($expectedError, $this->contents($this->stderr));
        self::assertStringContainsString("Run 'text-to-text --help' for usage.", $this->contents($this->stderr));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidUsages(): iterable
    {
        yield 'no prompt' => [['org/model'], 'Both a model and a prompt are required.'];
        yield 'unquoted prompt' => [['org/model', 'Hello', 'there'], 'Too many arguments; wrap the prompt in quotes.'];
        yield 'non-integer max tokens' => [['org/model', 'Hello', '--max-new-tokens=lots'], 'Option --max-new-tokens must be an integer.'];
        yield 'non-numeric temperature' => [['org/model', 'Hello', '--temperature=warm'], 'Option --temperature must be a number.'];
        yield 'image option' => [['org/model', 'Hello', '--steps=4'], 'Unknown option: --steps=4'];
    }

    public function testReportsModelThatWasNotPulled(): void
    {
        self::assertSame(TextToTextCommand::EXIT_FAILURE, $this->runCommand(['org/missing', 'Hello']));

        self::assertSame(
            "Error: Model org/missing not found at {$this->project->root}/.local/models/org/missing.\n"
            . "Pull it first with: vendor/bin/pull org/missing\n",
            $this->contents($this->stderr),
        );
        self::assertSame('', $this->contents($this->stdout));
    }

    public function testReportsUnsupportedModelBeforeStarting(): void
    {
        $this->project->addModel('org/gguf', ['model-q4_k_m.gguf']);

        self::assertSame(TextToTextCommand::EXIT_FAILURE, $this->runCommand(['org/gguf', 'Hello']));

        $stderr = $this->contents($this->stderr);
        self::assertStringStartsWith('Error: org/gguf cannot generate text: it is in GGUF format', $stderr);
        self::assertStringEndsWith("\nTo try one: vendor/bin/pull Qwen/Qwen2.5-0.5B-Instruct\n", $stderr);
        self::assertSame('', $this->contents($this->stdout), 'No intro is shown when the run cannot start.');
    }

    public function testReportsFailedRunWithDebugHint(): void
    {
        self::assertSame(TextToTextCommand::EXIT_FAILURE, $this->runCommand(['org/model', 'fail']));

        self::assertSame(
            "Error: failed to generate text with org/model (exit code 1).\n"
            . "Re-run the command with the \"--debug\" option to see what went wrong.\n",
            $this->contents($this->stderr),
        );
    }

    public function testLogsRunnerOutputToLogFile(): void
    {
        $log = "{$this->project->root}/run.log";

        self::assertSame(TextToTextCommand::EXIT_FAILURE, $this->runCommand(['org/model', 'fail', "--log-file={$log}"]));

        self::assertStringContainsString("See {$log} for details.", $this->contents($this->stderr));
        $contents = (string) file_get_contents($log);
        self::assertStringContainsString('] text-to-text org/model: fail', $contents);
        self::assertStringContainsString('out of memory', $contents);
    }

    public function testRequiresSetupWhenRunnerIsNotInstalled(): void
    {
        $emptyProject = (new FakeProject())->addModel('org/model', ['config.json', 'model.safetensors']);

        try {
            $exitCode = $this->runCommand(['org/model', 'Hello'], $emptyProject->storage);
        } finally {
            $emptyProject->remove();
        }

        self::assertSame(TextToTextCommand::EXIT_FAILURE, $exitCode);
        self::assertSame(
            "Error: The text-to-text runner is not installed yet.\nRun vendor/bin/setup text-to-text first to download it 🧰\n",
            $this->contents($this->stderr),
        );
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args, ?LocalStorage $storage = null): int
    {
        // Always the first intro, so assertions on the output are stable.
        $command = new TextToTextCommand(new TextToTextConfig(), $this->stdout, $this->stderr, static fn (): int => 0, $storage ?? $this->project->storage);

        return $command->run($args);
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
