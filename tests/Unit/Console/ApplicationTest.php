<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Console\Application;
use PhpLovesAi\Console\Command;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
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
        $this->project = new FakeProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('overviewArguments')]
    public function testListsCommandsWithoutASubcommand(array $args): void
    {
        self::assertSame(Command::EXIT_OK, $this->runApp($args));

        $stdout = $this->contents($this->stdout);
        self::assertStringContainsString('Usage: vendor/bin/loves-ai <command> [arguments] [options]', $stdout);
        self::assertStringContainsString('setup', $stdout);
        self::assertStringContainsString('Pull a model from the Hugging Face Hub', $stdout);
        self::assertStringContainsString('Transcribe speech in an audio or video file', $stdout);
        self::assertStringContainsString("Run 'vendor/bin/loves-ai <command> --help' for a command's arguments and options.", $stdout);
        self::assertSame('', $this->contents($this->stderr));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function overviewArguments(): iterable
    {
        yield 'no arguments' => [[]];
        yield 'help' => [['help']];
        yield '--help' => [['--help']];
        yield '-h' => [['-h']];
    }

    public function testMarksInstalledRunners(): void
    {
        $this->project->install(Tool::TextToImage, FakeProject::FAKE_TEXT_TO_IMAGE);

        $this->runApp([]);

        $stdout = $this->contents($this->stdout);
        self::assertMatchesRegularExpression('~✅ text-to-image~u', $stdout);
        self::assertMatchesRegularExpression('~\s{3}text-to-text~u', $stdout, 'Runners that are not installed have no mark.');
    }

    public function testShowsVersion(): void
    {
        self::assertSame(Command::EXIT_OK, $this->runApp(['--version']));

        // This repository is the root package, installed as a dev branch.
        self::assertSame("loves-ai latest\n", $this->contents($this->stdout));
    }

    public function testRunsASubcommand(): void
    {
        self::assertSame(Command::EXIT_OK, $this->runApp(['pull', '--help']));

        self::assertStringContainsString('Usage: vendor/bin/loves-ai pull <model> [options]', $this->contents($this->stdout));
    }

    public function testPassesArgumentsToTheSubcommand(): void
    {
        self::assertSame(Command::EXIT_USAGE, $this->runApp(['text-to-image', 'org/model']));

        self::assertSame(
            "Error: Both a model and a prompt are required.\nRun 'vendor/bin/loves-ai text-to-image --help' for usage.\n",
            $this->contents($this->stderr),
        );
    }

    public function testReportsUnknownCommand(): void
    {
        self::assertSame(Command::EXIT_USAGE, $this->runApp(['text-to-music', 'something']));

        self::assertSame(
            "Error: unknown command 'text-to-music'. Available: setup, pull, text-to-image, image-to-image, "
            . "text-to-video, image-to-video, text-to-text, image-to-text, speech-to-text, text-to-speech.\n"
            . "Run 'vendor/bin/loves-ai --help' to see what each one does.\n",
            $this->contents($this->stderr),
        );
        self::assertSame('', $this->contents($this->stdout));
    }

    /**
     * @param list<string> $args
     */
    private function runApp(array $args): int
    {
        return (new Application($this->stdout, $this->stderr, $this->project->storage))->run($args);
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
