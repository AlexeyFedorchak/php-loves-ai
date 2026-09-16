<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Config\TextToVideoConfig;
use PhpLovesAi\Console\TextToVideoCommand;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextToVideoCommandTest extends TestCase
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
            ->install(Tool::TextToVideo, FakeProject::FAKE_TEXT_TO_VIDEO)
            ->addModel('org/video', ['model_index.json']);
        $this->project->addFile('.local/models/org/video/model_index.json', '{"_class_name": "LTXPipeline"}');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testWritesVideoIntoConfiguredOutputDir(): void
    {
        self::assertSame(TextToVideoCommand::EXIT_OK, $this->runCommand(['org/video', 'a cat walking']));

        self::assertMatchesRegularExpression(
            '~^🎬 Rolling the camera with org/video… .+\n'
            . 'If you wish to see all logs, re-run the command with the "--debug" option\.\n'
            . '🎉 Video saved to /videos/\d{8}-\d{6}-[0-9a-f]{6}\.mp4\n$~u',
            $this->contents($this->stdout),
        );
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testPassesOptionsToRunner(): void
    {
        $exitCode = $this->runCommand([
            'org/video', 'a cat walking', '--debug', '--output=/tmp/cat.webm',
            '--negative-prompt=blurry', '--frames=16', '--fps=12', '--steps=20', '--guidance=5',
            '--width=512', '--height=320', '--seed=42', '--device=cpu',
        ]);

        self::assertSame(TextToVideoCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString(
            "args: --model {$this->project->root}/.local/models/org/video --prompt a cat walking --output /tmp/cat.webm "
            . '--negative-prompt blurry --frames 16 --fps 12 --steps 20 --guidance 5 --width 512 --height 320 --seed 42 --device cpu',
            $this->contents($this->stderr),
        );
        self::assertStringContainsString('🎉 Video saved to /tmp/cat.webm', $this->contents($this->stdout));
    }

    public function testShowsHelp(): void
    {
        self::assertSame(TextToVideoCommand::EXIT_OK, $this->runCommand(['--help']));
        self::assertStringContainsString('Usage: vendor/bin/loves-ai text-to-video <model> <prompt> [options]', $this->contents($this->stdout));
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidUsages')]
    public function testRejectsInvalidUsage(array $args, string $expectedError): void
    {
        self::assertSame(TextToVideoCommand::EXIT_USAGE, $this->runCommand($args));
        self::assertStringContainsString($expectedError, $this->contents($this->stderr));
        self::assertStringContainsString("Run 'vendor/bin/loves-ai text-to-video --help' for usage.", $this->contents($this->stderr));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidUsages(): iterable
    {
        yield 'no prompt' => [['org/video'], 'Both a model and a prompt are required.'];
        yield 'unquoted prompt' => [['org/video', 'a', 'cat'], 'Too many arguments; wrap the prompt in quotes.'];
        yield 'non-integer frames' => [['org/video', 'a cat', '--frames=many'], 'Option --frames must be an integer.'];
        yield 'non-numeric guidance' => [['org/video', 'a cat', '--guidance=high'], 'Option --guidance must be a number.'];
        yield 'image option' => [['org/video', 'a cat', '--strength=0.5'], 'Unknown option: --strength=0.5'];
    }

    public function testReportsUnsupportedModelBeforeStarting(): void
    {
        $this->project->addModel('org/sd', ['model_index.json']);
        $this->project->addFile('.local/models/org/sd/model_index.json', '{"_class_name": "StableDiffusionPipeline"}');

        self::assertSame(TextToVideoCommand::EXIT_FAILURE, $this->runCommand(['org/sd', 'a cat walking']));

        $stderr = $this->contents($this->stderr);
        self::assertStringStartsWith('Error: org/sd cannot generate video: it generates still images (StableDiffusionPipeline); use it with text-to-image.', $stderr);
        self::assertStringEndsWith("\nTo try one: vendor/bin/loves-ai pull Wan-AI/Wan2.1-T2V-1.3B-Diffusers\n", $stderr);
        self::assertSame('', $this->contents($this->stdout), 'No intro is shown when the run cannot start.');
    }

    public function testReportsModelThatWasNotPulled(): void
    {
        self::assertSame(TextToVideoCommand::EXIT_FAILURE, $this->runCommand(['org/missing', 'a cat walking']));

        self::assertStringEndsWith("Pull it first with: vendor/bin/loves-ai pull org/missing\n", $this->contents($this->stderr));
    }

    public function testReportsFailedRunWithDebugHint(): void
    {
        self::assertSame(TextToVideoCommand::EXIT_FAILURE, $this->runCommand(['org/video', 'fail']));

        self::assertSame(
            "Error: failed to generate a video with org/video (exit code 1).\n"
            . "Re-run the command with the \"--debug\" option to see what went wrong.\n",
            $this->contents($this->stderr),
        );
    }

    public function testRequiresSetupWhenRunnerIsNotInstalled(): void
    {
        $emptyProject = (new FakeProject())->addModel('org/video', ['model_index.json']);

        try {
            $exitCode = $this->runCommand(['org/video', 'a cat walking'], $emptyProject->storage);
        } finally {
            $emptyProject->remove();
        }

        self::assertSame(TextToVideoCommand::EXIT_FAILURE, $exitCode);
        self::assertSame(
            "Error: The text-to-video runner is not installed yet.\nRun vendor/bin/loves-ai setup text-to-video first to download it 🧰\n",
            $this->contents($this->stderr),
        );
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args, ?LocalStorage $storage = null): int
    {
        // Always the first intro, so assertions on the output are stable.
        $command = new TextToVideoCommand(new TextToVideoConfig('/videos'), $this->stdout, $this->stderr, static fn (): int => 0, $storage ?? $this->project->storage);

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
