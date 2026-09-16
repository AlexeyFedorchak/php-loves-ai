<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Config\ImageToImageConfig;
use PhpLovesAi\Console\ImageToImageCommand;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageToImageCommandTest extends TestCase
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
            ->install(Tool::ImageToImage, FakeProject::FAKE_IMAGE_TO_IMAGE)
            ->addModel('org/upscaler', ['config.json', 'model.safetensors']);
        $this->project->addFile('.local/models/org/upscaler/config.json', '{"architectures": ["Swin2SRForImageSuperResolution"]}');
        $this->image = $this->project->addFile('small.png', 'png bytes');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testWritesImageIntoConfiguredOutputDir(): void
    {
        self::assertSame(ImageToImageCommand::EXIT_OK, $this->runCommand(['org/upscaler', $this->image]));

        self::assertMatchesRegularExpression(
            '~^🪄 Reworking your picture with org/upscaler… .+\n'
            . 'If you wish to see all logs, re-run the command with the "--debug" option\.\n'
            . '🎉 Image saved to /images/\d{8}-\d{6}-[0-9a-f]{6}\.png\n$~u',
            $this->contents($this->stdout),
        );
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testPassesOptionsToRunner(): void
    {
        $exitCode = $this->runCommand([
            'org/upscaler', $this->image, '--debug', '--output=/tmp/big.png',
            '--prompt', 'a watercolor painting', '--negative-prompt=blurry', '--strength=0.6',
            '--steps=4', '--guidance=0', '--seed=42', '--device=cpu',
        ]);

        self::assertSame(ImageToImageCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString(
            "args: --model {$this->project->root}/.local/models/org/upscaler --image {$this->image} --output /tmp/big.png "
            . '--prompt a watercolor painting --negative-prompt blurry --strength 0.6 --steps 4 --guidance 0 --seed 42 --device cpu',
            $this->contents($this->stderr),
        );
        self::assertStringContainsString('🎉 Image saved to /tmp/big.png', $this->contents($this->stdout));
    }

    public function testShowsHelp(): void
    {
        self::assertSame(ImageToImageCommand::EXIT_OK, $this->runCommand(['--help']));
        self::assertStringContainsString('Usage: image-to-image <model> <image> [options]', $this->contents($this->stdout));
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidUsages')]
    public function testRejectsInvalidUsage(array $args, string $expectedError): void
    {
        self::assertSame(ImageToImageCommand::EXIT_USAGE, $this->runCommand($args));
        self::assertStringContainsString($expectedError, $this->contents($this->stderr));
        self::assertStringContainsString("Run 'image-to-image --help' for usage.", $this->contents($this->stderr));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidUsages(): iterable
    {
        yield 'no image' => [['org/upscaler'], 'Both a model and an image are required.'];
        yield 'extra argument' => [['org/upscaler', 'a.png', 'b.png'], 'Too many arguments; wrap file names with spaces in quotes.'];
        yield 'non-numeric strength' => [['org/upscaler', 'a.png', '--strength=lots'], 'Option --strength must be a number.'];
        yield 'non-integer steps' => [['org/upscaler', 'a.png', '--steps=many'], 'Option --steps must be an integer.'];
        yield 'speech option' => [['org/upscaler', 'a.png', '--voice=narrator'], 'Unknown option: --voice=narrator'];
    }

    public function testReportsMissingImage(): void
    {
        self::assertSame(ImageToImageCommand::EXIT_FAILURE, $this->runCommand(['org/upscaler', "{$this->project->root}/gone.png"]));

        self::assertStringContainsString("Error: Image not found or not readable: {$this->project->root}/gone.png", $this->contents($this->stderr));
    }

    public function testReportsUnsupportedModelBeforeStarting(): void
    {
        $this->project->addModel('org/classifier', ['config.json', 'model.safetensors']);
        $this->project->addFile('.local/models/org/classifier/config.json', '{"architectures": ["ViTForImageClassification"]}');

        self::assertSame(ImageToImageCommand::EXIT_FAILURE, $this->runCommand(['org/classifier', $this->image]));

        $stderr = $this->contents($this->stderr);
        self::assertStringStartsWith('Error: org/classifier cannot turn an image into an image: it does not produce images.', $stderr);
        self::assertStringEndsWith("\nTo try one: vendor/bin/pull caidas/swin2SR-classical-sr-x2-64\n", $stderr);
        self::assertSame('', $this->contents($this->stdout), 'No intro is shown when the run cannot start.');
    }

    public function testReportsModelThatWasNotPulled(): void
    {
        self::assertSame(ImageToImageCommand::EXIT_FAILURE, $this->runCommand(['org/missing', $this->image]));

        self::assertStringEndsWith("Pull it first with: vendor/bin/pull org/missing\n", $this->contents($this->stderr));
    }

    public function testReportsFailedRunWithDebugHint(): void
    {
        $failing = $this->project->addFile('fail.png', 'png bytes');

        self::assertSame(ImageToImageCommand::EXIT_FAILURE, $this->runCommand(['org/upscaler', $failing]));

        self::assertSame(
            "Error: failed to transform {$failing} with org/upscaler (exit code 1).\n"
            . "Re-run the command with the \"--debug\" option to see what went wrong.\n",
            $this->contents($this->stderr),
        );
    }

    public function testRequiresSetupWhenRunnerIsNotInstalled(): void
    {
        $emptyProject = (new FakeProject())->addModel('org/upscaler', ['model_index.json']);

        try {
            $exitCode = $this->runCommand(['org/upscaler', $this->image], $emptyProject->storage);
        } finally {
            $emptyProject->remove();
        }

        self::assertSame(ImageToImageCommand::EXIT_FAILURE, $exitCode);
        self::assertSame(
            "Error: The image-to-image runner is not installed yet.\nRun vendor/bin/setup image-to-image first to download it 🧰\n",
            $this->contents($this->stderr),
        );
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args, ?LocalStorage $storage = null): int
    {
        // Always the first intro, so assertions on the output are stable.
        $command = new ImageToImageCommand(new ImageToImageConfig('/images'), $this->stdout, $this->stderr, static fn (): int => 0, $storage ?? $this->project->storage);

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
