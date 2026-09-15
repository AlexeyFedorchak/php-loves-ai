<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Config\SpeechToTextConfig;
use PhpLovesAi\Console\SpeechToTextCommand;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpeechToTextCommandTest extends TestCase
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private FakeProject $project;

    private string $audio;

    protected function setUp(): void
    {
        $this->stdout = self::memoryStream();
        $this->stderr = self::memoryStream();

        $this->project = (new FakeProject())
            ->install(Tool::SpeechToText, FakeProject::FAKE_SPEECH_TO_TEXT)
            ->addModel('org/whisper', ['config.json', 'model.safetensors']);
        $this->project->addFile('.local/models/org/whisper/preprocessor_config.json', '{"feature_extractor_type": "WhisperFeatureExtractor"}');
        $this->project->addFile('.local/models/org/whisper/generation_config.json', '{"lang_to_id": {"<|uk|>": 50280}}');
        $this->audio = $this->project->addFile('talk.m4a', 'm4a bytes');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testPrintsTranscript(): void
    {
        self::assertSame(SpeechToTextCommand::EXIT_OK, $this->runCommand(['org/whisper', $this->audio]));

        self::assertSame(
            "🎧 Listening carefully with org/whisper… Perfect time for a cup of tea and a cookie 🍪\n"
            . "If you wish to see all logs, re-run the command with the \"--debug\" option.\n"
            . "🎉 Transcript by org/whisper:\n"
            . "Hello from talk.m4a. Goodbye.\n",
            $this->contents($this->stdout),
        );
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testPrintsTimestampedSegments(): void
    {
        self::assertSame(SpeechToTextCommand::EXIT_OK, $this->runCommand(['org/whisper', $this->audio, '--timestamps']));

        self::assertStringEndsWith(
            "🎉 Transcript by org/whisper:\n"
            . "[00:00.00 → 00:01.50] Hello from talk.m4a.\n"
            . "[1:02:03.46 → …] Goodbye.\n",
            $this->contents($this->stdout),
        );
    }

    public function testPassesOptionsToRunner(): void
    {
        $exitCode = $this->runCommand(['org/whisper', $this->audio, '--debug', '--language=uk', '--translate', '--device', 'cpu']);

        self::assertSame(SpeechToTextCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString(
            "args: --model {$this->project->root}/.local/models/org/whisper --audio {$this->audio} --language uk --translate --device cpu",
            $this->contents($this->stderr),
        );
    }

    public function testShowsHelp(): void
    {
        self::assertSame(SpeechToTextCommand::EXIT_OK, $this->runCommand(['--help']));
        self::assertStringContainsString('Usage: speech-to-text <model> <audio> [options]', $this->contents($this->stdout));
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidUsages')]
    public function testRejectsInvalidUsage(array $args, string $expectedError): void
    {
        self::assertSame(SpeechToTextCommand::EXIT_USAGE, $this->runCommand($args));
        self::assertStringContainsString($expectedError, $this->contents($this->stderr));
        self::assertStringContainsString("Run 'speech-to-text --help' for usage.", $this->contents($this->stderr));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidUsages(): iterable
    {
        yield 'no audio' => [['org/whisper'], 'Both a model and an audio file are required.'];
        yield 'unquoted file name' => [['org/whisper', 'my', 'talk.m4a'], 'Too many arguments; wrap file names with spaces in quotes.'];
        yield 'flag with value' => [['org/whisper', 'talk.m4a', '--translate=yes'], 'Option --translate does not take a value.'];
        yield 'text option' => [['org/whisper', 'talk.m4a', '--temperature=0'], 'Unknown option: --temperature=0'];
    }

    public function testReportsMissingAudio(): void
    {
        self::assertSame(SpeechToTextCommand::EXIT_FAILURE, $this->runCommand(['org/whisper', "{$this->project->root}/nope.mp3"]));

        self::assertSame("Error: Audio file not found or not readable: {$this->project->root}/nope.mp3\n", $this->contents($this->stderr));
    }

    public function testReportsUnsupportedModelBeforeStarting(): void
    {
        $this->project->addModel('org/whisper-cpp', ['ggml-base.bin']);

        self::assertSame(SpeechToTextCommand::EXIT_FAILURE, $this->runCommand(['org/whisper-cpp', $this->audio]));

        $stderr = $this->contents($this->stderr);
        self::assertStringStartsWith('Error: org/whisper-cpp cannot transcribe speech: it is in whisper.cpp (GGML) format', $stderr);
        self::assertStringEndsWith("\nTo try one: vendor/bin/pull openai/whisper-tiny\n", $stderr);
        self::assertSame('', $this->contents($this->stdout), 'No intro is shown when the run cannot start.');
    }

    public function testReportsLanguageOptionForSingleLanguageModelBeforeStarting(): void
    {
        $this->project->addModel('org/wav2vec2', ['config.json', 'model.safetensors']);
        $this->project->addFile('.local/models/org/wav2vec2/preprocessor_config.json', '{"sampling_rate": 16000}');

        self::assertSame(SpeechToTextCommand::EXIT_FAILURE, $this->runCommand(['org/wav2vec2', $this->audio, '--translate']));

        self::assertStringStartsWith('Error: org/wav2vec2 cannot be told the spoken language or translate', $this->contents($this->stderr));
        self::assertSame('', $this->contents($this->stdout), 'No intro is shown when the run cannot start.');
    }

    public function testReportsModelThatWasNotPulled(): void
    {
        self::assertSame(SpeechToTextCommand::EXIT_FAILURE, $this->runCommand(['org/missing', $this->audio]));

        self::assertStringEndsWith("Pull it first with: vendor/bin/pull org/missing\n", $this->contents($this->stderr));
    }

    public function testReportsFailedRunWithDebugHint(): void
    {
        $audio = $this->project->addFile('fail.wav');

        self::assertSame(SpeechToTextCommand::EXIT_FAILURE, $this->runCommand(['org/whisper', $audio]));

        self::assertSame(
            "Error: failed to transcribe {$audio} with org/whisper (exit code 1).\n"
            . "Re-run the command with the \"--debug\" option to see what went wrong.\n",
            $this->contents($this->stderr),
        );
    }

    public function testRequiresSetupWhenRunnerIsNotInstalled(): void
    {
        $emptyProject = (new FakeProject())->addModel('org/whisper', ['config.json', 'model.safetensors']);

        try {
            $exitCode = $this->runCommand(['org/whisper', $this->audio], $emptyProject->storage);
        } finally {
            $emptyProject->remove();
        }

        self::assertSame(SpeechToTextCommand::EXIT_FAILURE, $exitCode);
        self::assertSame(
            "Error: The speech-to-text runner is not installed yet.\nRun vendor/bin/setup speech-to-text first to download it 🧰\n",
            $this->contents($this->stderr),
        );
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args, ?LocalStorage $storage = null): int
    {
        // Always the first intro, so assertions on the output are stable.
        $command = new SpeechToTextCommand(new SpeechToTextConfig(), $this->stdout, $this->stderr, static fn (): int => 0, $storage ?? $this->project->storage);

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
