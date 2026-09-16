<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Config\ImageToTextConfig;
use PhpLovesAi\Console\ImageToTextCommand;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageToTextCommandTest extends TestCase
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
            ->install(Tool::ImageToText, FakeProject::FAKE_IMAGE_TO_TEXT)
            ->addModel('org/vlm', ['config.json', 'model.safetensors', 'preprocessor_config.json']);
        $this->image = $this->project->addFile('cats.jpg', 'jpeg bytes');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testPrintsDescription(): void
    {
        self::assertSame(ImageToTextCommand::EXIT_OK, $this->runCommand(['org/vlm', $this->image]));

        self::assertSame(
            "🔍 Taking a close look with org/vlm… Perfect time for a cup of tea and a cookie 🍪\n"
            . "If you wish to see all logs, re-run the command with the \"--debug\" option.\n"
            . "🎉 org/vlm says:\n"
            . "Looked at cats.jpg\nPrompt: (none)\n",
            $this->contents($this->stdout),
        );
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testPassesPromptAndOptionsToRunner(): void
    {
        $exitCode = $this->runCommand([
            'org/vlm', $this->image, 'What color is the couch?', '--debug',
            '--max-new-tokens=32', '--temperature=0', '--seed=7', '--device=cpu',
        ]);

        self::assertSame(ImageToTextCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString(
            "args: --model {$this->project->root}/.local/models/org/vlm --image {$this->image} --prompt What color is the couch? "
            . '--max-new-tokens 32 --temperature 0 --seed 7 --device cpu',
            $this->contents($this->stderr),
        );
        self::assertStringEndsWith("Prompt: What color is the couch?\n", $this->contents($this->stdout));
    }

    public function testShowsHelp(): void
    {
        self::assertSame(ImageToTextCommand::EXIT_OK, $this->runCommand(['--help']));
        self::assertStringContainsString('Usage: vendor/bin/loves-ai image-to-text <model> <image> [prompt] [options]', $this->contents($this->stdout));
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidUsages')]
    public function testRejectsInvalidUsage(array $args, string $expectedError): void
    {
        self::assertSame(ImageToTextCommand::EXIT_USAGE, $this->runCommand($args));
        self::assertStringContainsString($expectedError, $this->contents($this->stderr));
        self::assertStringContainsString("Run 'vendor/bin/loves-ai image-to-text --help' for usage.", $this->contents($this->stderr));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidUsages(): iterable
    {
        yield 'no image' => [['org/vlm'], 'Both a model and an image are required.'];
        yield 'unquoted prompt' => [['org/vlm', 'cats.jpg', 'How', 'many?'], 'Too many arguments; wrap the prompt in quotes.'];
        yield 'non-integer max tokens' => [['org/vlm', 'cats.jpg', '--max-new-tokens=lots'], 'Option --max-new-tokens must be an integer.'];
        yield 'non-numeric temperature' => [['org/vlm', 'cats.jpg', '--temperature=warm'], 'Option --temperature must be a number.'];
        yield 'text option' => [['org/vlm', 'cats.jpg', '--system=Be brief'], 'Unknown option: --system=Be brief'];
    }

    public function testReportsMissingImage(): void
    {
        self::assertSame(ImageToTextCommand::EXIT_FAILURE, $this->runCommand(['org/vlm', "{$this->project->root}/dog.jpg"]));

        self::assertSame("Error: Image not found or not readable: {$this->project->root}/dog.jpg\n", $this->contents($this->stderr));
    }

    public function testReportsUnsupportedModelBeforeStarting(): void
    {
        $this->project->addModel('org/text-model', ['config.json', 'model.safetensors']);

        self::assertSame(ImageToTextCommand::EXIT_FAILURE, $this->runCommand(['org/text-model', $this->image]));

        $stderr = $this->contents($this->stderr);
        self::assertStringStartsWith('Error: org/text-model cannot describe images: it cannot read images', $stderr);
        self::assertStringEndsWith("\nTo try one: vendor/bin/loves-ai pull HuggingFaceTB/SmolVLM-256M-Instruct\n", $stderr);
        self::assertSame('', $this->contents($this->stdout), 'No intro is shown when the run cannot start.');
    }

    public function testReportsModelThatWasNotPulled(): void
    {
        self::assertSame(ImageToTextCommand::EXIT_FAILURE, $this->runCommand(['org/missing', $this->image]));

        self::assertStringEndsWith("Pull it first with: vendor/bin/loves-ai pull org/missing\n", $this->contents($this->stderr));
    }

    public function testReportsFailedRunWithDebugHint(): void
    {
        self::assertSame(ImageToTextCommand::EXIT_FAILURE, $this->runCommand(['org/vlm', $this->image, 'fail']));

        self::assertSame(
            "Error: failed to read the image with org/vlm (exit code 1).\n"
            . "Re-run the command with the \"--debug\" option to see what went wrong.\n",
            $this->contents($this->stderr),
        );
    }

    public function testRequiresSetupWhenRunnerIsNotInstalled(): void
    {
        $emptyProject = (new FakeProject())->addModel('org/vlm', ['config.json', 'model.safetensors', 'preprocessor_config.json']);

        try {
            $exitCode = $this->runCommand(['org/vlm', $this->image], $emptyProject->storage);
        } finally {
            $emptyProject->remove();
        }

        self::assertSame(ImageToTextCommand::EXIT_FAILURE, $exitCode);
        self::assertSame(
            "Error: The image-to-text runner is not installed yet.\nRun vendor/bin/loves-ai setup image-to-text first to download it 🧰\n",
            $this->contents($this->stderr),
        );
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args, ?LocalStorage $storage = null): int
    {
        // Always the first intro, so assertions on the output are stable.
        $command = new ImageToTextCommand(new ImageToTextConfig(), $this->stdout, $this->stderr, static fn (): int => 0, $storage ?? $this->project->storage);

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
