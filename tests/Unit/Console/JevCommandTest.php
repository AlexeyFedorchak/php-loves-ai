<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Config\JevConfig;
use PhpLovesAi\Console\JevCommand;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JevCommandTest extends TestCase
{
    private const MODEL_FILES = ['config.json', 'model.safetensors', 'processor_config.json', 'head.pt', 'decision_config.json'];

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
            ->install(Tool::Jev, FakeProject::FAKE_JEV)
            ->addModel('org/jev', self::MODEL_FILES);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testPrintsChoiceAndProbabilities(): void
    {
        self::assertSame(JevCommand::EXIT_OK, $this->runCommand(['org/jev', 'Has the meeting started?', 'Yes', 'Not yet']));

        self::assertSame(
            "🤔 Weighing the options with org/jev… Perfect time for a cup of tea and a cookie 🍪\n"
            . "If you wish to see all logs, re-run the command with the \"--debug\" option.\n"
            . "🎉 org/jev chose: Not yet (75.0%)\n"
            . "      Yes       25.0%\n"
            . "   👉 Not yet   75.0%\n",
            $this->contents($this->stdout),
        );
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testPassesStateMediaAndOptionsToRunner(): void
    {
        $audio = $this->project->addFile('talk.m4a', 'audio bytes');

        $exitCode = $this->runCommand([
            'org/jev', 'Is the speaker happy?', 'Yes', 'No', '--debug',
            '--state=A phone call.', "--media={$audio}", '--modality=audio', '--frames=4', '--device=cpu',
        ]);

        self::assertSame(JevCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString(
            "args: --model {$this->project->root}/.local/models/org/jev --question Is the speaker happy? --options [\"Yes\",\"No\"] "
            . "--state A phone call. --media {$audio} --modality audio --frames 4 --device cpu",
            $this->contents($this->stderr),
        );
    }

    public function testShowsHelp(): void
    {
        self::assertSame(JevCommand::EXIT_OK, $this->runCommand(['--help']));
        self::assertStringContainsString('Usage: vendor/bin/loves-ai jev <model> <question> <option> <option> [option ...] [options]', $this->contents($this->stdout));
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidUsages')]
    public function testRejectsInvalidUsage(array $args, string $expectedError): void
    {
        self::assertSame(JevCommand::EXIT_USAGE, $this->runCommand($args));
        self::assertStringContainsString($expectedError, $this->contents($this->stderr));
        self::assertStringContainsString("Run 'vendor/bin/loves-ai jev --help' for usage.", $this->contents($this->stderr));
        self::assertSame('', $this->contents($this->stdout), 'No intro is shown when the run cannot start.');
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidUsages(): iterable
    {
        yield 'no question' => [['org/jev'], 'A model and a question are required.'];
        yield 'one option' => [['org/jev', 'Is it?', 'Yes'], 'At least two options are needed to choose from.'];
        yield 'duplicate options' => [['org/jev', 'Is it?', 'Yes', 'Yes'], 'Every option must be different.'];
        yield 'unknown modality' => [['org/jev', 'Is it?', 'Yes', 'No', '--modality=smell'], "Unknown modality 'smell'; use one of: image, audio, video."];
        yield 'zero frames' => [['org/jev', 'Is it?', 'Yes', 'No', '--frames=0'], 'Option --frames must be a positive integer.'];
        yield 'text option' => [['org/jev', 'Is it?', 'Yes', 'No', '--temperature=0'], 'Unknown option: --temperature=0'];
    }

    public function testReportsMissingMedia(): void
    {
        self::assertSame(JevCommand::EXIT_FAILURE, $this->runCommand(['org/jev', 'Is it?', 'Yes', 'No', "--media={$this->project->root}/dog.jpg"]));

        self::assertSame("Error: Media file not found or not readable: {$this->project->root}/dog.jpg\n", $this->contents($this->stderr));
    }

    public function testReportsUnsupportedModelBeforeStarting(): void
    {
        $this->project->addModel('org/chat-model', ['config.json', 'model.safetensors']);

        self::assertSame(JevCommand::EXIT_FAILURE, $this->runCommand(['org/chat-model', 'Is it?', 'Yes', 'No']));

        $stderr = $this->contents($this->stderr);
        self::assertStringStartsWith('Error: org/chat-model cannot answer questions with options: it has no decision head', $stderr);
        self::assertStringEndsWith("\nTo try one: vendor/bin/loves-ai pull akhilaaa3/Jev-Omni\n", $stderr);
        self::assertSame('', $this->contents($this->stdout), 'No intro is shown when the run cannot start.');
    }

    public function testReportsModelThatWasNotPulled(): void
    {
        self::assertSame(JevCommand::EXIT_FAILURE, $this->runCommand(['org/missing', 'Is it?', 'Yes', 'No']));

        self::assertStringEndsWith("Pull it first with: vendor/bin/loves-ai pull org/missing\n", $this->contents($this->stderr));
    }

    public function testReportsFailedRunWithDebugHint(): void
    {
        self::assertSame(JevCommand::EXIT_FAILURE, $this->runCommand(['org/jev', 'fail', 'Yes', 'No']));

        self::assertSame(
            "Error: failed to answer the question with org/jev (exit code 1).\n"
            . "Re-run the command with the \"--debug\" option to see what went wrong.\n",
            $this->contents($this->stderr),
        );
    }

    public function testRequiresSetupWhenRunnerIsNotInstalled(): void
    {
        $emptyProject = (new FakeProject())->addModel('org/jev', self::MODEL_FILES);

        try {
            $exitCode = $this->runCommand(['org/jev', 'Is it?', 'Yes', 'No'], $emptyProject->storage);
        } finally {
            $emptyProject->remove();
        }

        self::assertSame(JevCommand::EXIT_FAILURE, $exitCode);
        self::assertSame(
            "Error: The jev runner is not installed yet.\nRun vendor/bin/loves-ai setup jev first to download it 🧰\n",
            $this->contents($this->stderr),
        );
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args, ?LocalStorage $storage = null): int
    {
        // Always the first intro, so assertions on the output are stable.
        $command = new JevCommand(new JevConfig(), $this->stdout, $this->stderr, static fn (): int => 0, $storage ?? $this->project->storage);

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
