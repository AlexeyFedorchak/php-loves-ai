<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Config\ImageToVideoConfig;
use PhpLovesAi\Console\ImageToVideoCommand;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageToVideoCommandTest extends TestCase
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private FakeProject $project;

    private string $image;

    protected function setUp(): void
    {
        $this->stdout = self::memoryStream();
        $this->stderr = self::memoryStream();

        $this->project = (new FakeProject())
            ->install(Tool::ImageToVideo, FakeProject::FAKE_IMAGE_TO_VIDEO)
            ->addModel('org/video', ['model_index.json']);
        $this->project->addFile('.local/models/org/video/model_index.json', '{"_class_name": "StableVideoDiffusionPipeline"}');
        $this->image = $this->project->addFile('cats.png', 'png bytes');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testWritesVideoIntoConfiguredOutputDir(): void
    {
        self::assertSame(ImageToVideoCommand::EXIT_OK, $this->runCommand(['org/video', $this->image]));

        self::assertMatchesRegularExpression(
            '~^🎬 Bringing your picture to life with org/video… .+\n'
            . 'If you wish to see all logs, re-run the command with the "--debug" option\.\n'
            . '🎉 Video saved to /videos/\d{8}-\d{6}-[0-9a-f]{6}\.mp4\n$~u',
            $this->contents($this->stdout),
        );
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testPassesOptionsToRunner(): void
    {
        $exitCode = $this->runCommand([
            'org/video', $this->image, '--debug', '--output=/tmp/cat.webm', '--prompt', 'the cats move',
            '--negative-prompt=blurry', '--frames=16', '--fps=12', '--steps=20', '--guidance=5',
            '--width=512', '--height=320', '--seed=42', '--device=cpu',
        ]);

        self::assertSame(ImageToVideoCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString(
            "args: --model {$this->project->root}/.local/models/org/video --image {$this->image} --output /tmp/cat.webm "
            . '--prompt the cats move --negative-prompt blurry --frames 16 --fps 12 --steps 20 --guidance 5 '
            . '--width 512 --height 320 --seed 42 --device cpu',
            $this->contents($this->stderr),
        );
        self::assertStringContainsString('🎉 Video saved to /tmp/cat.webm', $this->contents($this->stdout));
    }

    public function testShowsHelp(): void
    {
        self::assertSame(ImageToVideoCommand::EXIT_OK, $this->runCommand(['--help']));
        self::assertStringContainsString('Usage: image-to-video <model> <image> [options]', $this->contents($this->stdout));
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidUsages')]
    public function testRejectsInvalidUsage(array $args, string $expectedError): void
    {
        self::assertSame(ImageToVideoCommand::EXIT_USAGE, $this->runCommand($args));
        self::assertStringContainsString($expectedError, $this->contents($this->stderr));
        self::assertStringContainsString("Run 'image-to-video --help' for usage.", $this->contents($this->stderr));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidUsages(): iterable
    {
        yield 'no image' => [['org/video'], 'Both a model and an image are required.'];
        yield 'extra argument' => [['org/video', 'a.png', 'b.png'], 'Too many arguments; wrap file names with spaces in quotes.'];
        yield 'non-integer frames' => [['org/video', 'a.png', '--frames=many'], 'Option --frames must be an integer.'];
        yield 'non-numeric guidance' => [['org/video', 'a.png', '--guidance=high'], 'Option --guidance must be a number.'];
        yield 'image-to-image option' => [['org/video', 'a.png', '--strength=0.5'], 'Unknown option: --strength=0.5'];
    }

    public function testReportsUnsupportedModelBeforeStarting(): void
    {
        $this->project->addModel('org/sd', ['model_index.json']);
        $this->project->addFile('.local/models/org/sd/model_index.json', '{"_class_name": "StableDiffusionPipeline"}');

        self::assertSame(ImageToVideoCommand::EXIT_FAILURE, $this->runCommand(['org/sd', $this->image]));

        $stderr = $this->contents($this->stderr);
        self::assertStringStartsWith('Error: org/sd cannot animate an image: it generates still images (StableDiffusionPipeline); use it with image-to-image.', $stderr);
        self::assertStringEndsWith("\nTo try one: vendor/bin/pull stabilityai/stable-video-diffusion-img2vid-xt\n", $stderr);
        self::assertSame('', $this->contents($this->stdout), 'No intro is shown when the run cannot start.');
    }

    public function testReportsModelThatWasNotPulled(): void
    {
        self::assertSame(ImageToVideoCommand::EXIT_FAILURE, $this->runCommand(['org/missing', $this->image]));

        self::assertStringEndsWith("Pull it first with: vendor/bin/pull org/missing\n", $this->contents($this->stderr));
    }

    public function testReportsFailedRunWithDebugHint(): void
    {
        $failing = $this->project->addFile('fail.png', 'png bytes');

        self::assertSame(ImageToVideoCommand::EXIT_FAILURE, $this->runCommand(['org/video', $failing]));

        self::assertSame(
            "Error: failed to animate {$failing} with org/video (exit code 1).\n"
            . "Re-run the command with the \"--debug\" option to see what went wrong.\n",
            $this->contents($this->stderr),
        );
    }

    public function testRequiresSetupWhenRunnerIsNotInstalled(): void
    {
        $emptyProject = (new FakeProject())->addModel('org/video', ['model_index.json']);

        try {
            $exitCode = $this->runCommand(['org/video', $this->image], $emptyProject->storage);
        } finally {
            $emptyProject->remove();
        }

        self::assertSame(ImageToVideoCommand::EXIT_FAILURE, $exitCode);
        self::assertSame(
            "Error: The image-to-video runner is not installed yet.\nRun vendor/bin/setup image-to-video first to download it 🧰\n",
            $this->contents($this->stderr),
        );
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args, ?LocalStorage $storage = null): int
    {
        // Always the first intro, so assertions on the output are stable.
        $command = new ImageToVideoCommand(new ImageToVideoConfig('/videos'), $this->stdout, $this->stderr, static fn (): int => 0, $storage ?? $this->project->storage);

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
