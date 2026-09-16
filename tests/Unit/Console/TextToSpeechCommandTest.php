<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Config\TextToSpeechConfig;
use PhpLovesAi\Console\TextToSpeechCommand;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextToSpeechCommandTest extends TestCase
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
            ->install(Tool::TextToSpeech, FakeProject::FAKE_TEXT_TO_SPEECH)
            ->addModel('org/voice', ['config.json', 'model.safetensors']);
        $this->project->addFile('.local/models/org/voice/config.json', '{"model_type": "vits"}');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testWritesAudioIntoConfiguredOutputDir(): void
    {
        self::assertSame(TextToSpeechCommand::EXIT_OK, $this->runCommand(['org/voice', 'Hello there']));

        self::assertMatchesRegularExpression(
            '~^🗣️ Finding a voice with org/voice… .+\n'
            . 'If you wish to see all logs, re-run the command with the "--debug" option\.\n'
            . '🎉 Audio saved to /audio/\d{8}-\d{6}-[0-9a-f]{6}\.wav\n$~u',
            $this->contents($this->stdout),
        );
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testPassesOptionsToRunner(): void
    {
        $exitCode = $this->runCommand([
            'org/voice', 'Hello', '--debug',
            '--output=/tmp/hello.mp3', '--voice', 'v2/en_speaker_6', '--speed=0.8', '--seed=42', '--device=cpu',
        ]);

        self::assertSame(TextToSpeechCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString(
            "args: --model {$this->project->root}/.local/models/org/voice --text Hello --output /tmp/hello.mp3 "
            . '--voice v2/en_speaker_6 --speed 0.8 --seed 42 --device cpu',
            $this->contents($this->stderr),
        );
        self::assertStringContainsString('🎉 Audio saved to /tmp/hello.mp3', $this->contents($this->stdout));
    }

    public function testShowsHelp(): void
    {
        self::assertSame(TextToSpeechCommand::EXIT_OK, $this->runCommand(['--help']));
        self::assertStringContainsString('Usage: vendor/bin/loves-ai text-to-speech <model> <text> [options]', $this->contents($this->stdout));
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidUsages')]
    public function testRejectsInvalidUsage(array $args, string $expectedError): void
    {
        self::assertSame(TextToSpeechCommand::EXIT_USAGE, $this->runCommand($args));
        self::assertStringContainsString($expectedError, $this->contents($this->stderr));
        self::assertStringContainsString("Run 'vendor/bin/loves-ai text-to-speech --help' for usage.", $this->contents($this->stderr));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidUsages(): iterable
    {
        yield 'no text' => [['org/voice'], 'Both a model and a text are required.'];
        yield 'unquoted text' => [['org/voice', 'Hello', 'there'], 'Too many arguments; wrap the text in quotes.'];
        yield 'non-numeric speed' => [['org/voice', 'Hello', '--speed=fast'], 'Option --speed must be a number.'];
        yield 'non-integer seed' => [['org/voice', 'Hello', '--seed=random'], 'Option --seed must be an integer.'];
        yield 'speech option' => [['org/voice', 'Hello', '--timestamps'], 'Unknown option: --timestamps'];
    }

    public function testReportsUnsupportedModelBeforeStarting(): void
    {
        $this->project->addModel('org/whisper', ['config.json', 'model.safetensors']);
        $this->project->addFile('.local/models/org/whisper/config.json', '{"model_type": "whisper"}');

        self::assertSame(TextToSpeechCommand::EXIT_FAILURE, $this->runCommand(['org/whisper', 'Hello']));

        $stderr = $this->contents($this->stderr);
        self::assertStringStartsWith('Error: org/whisper cannot read text aloud: it transcribes speech instead of speaking', $stderr);
        self::assertStringEndsWith("\nTo try one: vendor/bin/loves-ai pull facebook/mms-tts-eng\n", $stderr);
        self::assertSame('', $this->contents($this->stdout), 'No intro is shown when the run cannot start.');
    }

    public function testReportsModelThatWasNotPulled(): void
    {
        self::assertSame(TextToSpeechCommand::EXIT_FAILURE, $this->runCommand(['org/missing', 'Hello']));

        self::assertStringEndsWith("Pull it first with: vendor/bin/loves-ai pull org/missing\n", $this->contents($this->stderr));
    }

    public function testReportsFailedRunWithDebugHint(): void
    {
        self::assertSame(TextToSpeechCommand::EXIT_FAILURE, $this->runCommand(['org/voice', 'fail']));

        self::assertSame(
            "Error: failed to read the text aloud with org/voice (exit code 1).\n"
            . "Re-run the command with the \"--debug\" option to see what went wrong.\n",
            $this->contents($this->stderr),
        );
    }

    public function testLogsRunnerOutputToLogFile(): void
    {
        $log = "{$this->project->root}/run.log";

        self::assertSame(TextToSpeechCommand::EXIT_FAILURE, $this->runCommand(['org/voice', 'fail', "--log-file={$log}"]));

        $contents = (string) file_get_contents($log);
        self::assertStringContainsString('] text-to-speech org/voice: fail', $contents);
        self::assertStringContainsString('produced no audio', $contents);
    }

    public function testRequiresSetupWhenRunnerIsNotInstalled(): void
    {
        $emptyProject = (new FakeProject())->addModel('org/voice', ['config.json', 'model.safetensors']);

        try {
            $exitCode = $this->runCommand(['org/voice', 'Hello'], $emptyProject->storage);
        } finally {
            $emptyProject->remove();
        }

        self::assertSame(TextToSpeechCommand::EXIT_FAILURE, $exitCode);
        self::assertSame(
            "Error: The text-to-speech runner is not installed yet.\nRun vendor/bin/loves-ai setup text-to-speech first to download it 🧰\n",
            $this->contents($this->stderr),
        );
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args, ?LocalStorage $storage = null): int
    {
        // Always the first intro, so assertions on the output are stable.
        $command = new TextToSpeechCommand(new TextToSpeechConfig('/audio'), $this->stdout, $this->stderr, static fn (): int => 0, $storage ?? $this->project->storage);

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
