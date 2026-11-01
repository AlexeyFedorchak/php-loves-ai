<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Config\FaceConfig;
use PhpLovesAi\Console\FaceMatchCommand;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FaceMatchCommandTest extends TestCase
{
    /** @var list<string> */
    private const MODEL_FILES = ['detection/model.onnx', 'recognition/model.onnx'];

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private FakeProject $project;

    private string $first;

    private string $second;

    protected function setUp(): void
    {
        $this->stdout = self::memoryStream();
        $this->stderr = self::memoryStream();

        $this->project = (new FakeProject())
            ->install(Tool::Face, FakeProject::FAKE_FACE)
            ->addModel('org/pack', self::MODEL_FILES);

        $this->first = $this->project->addFile('passport.jpg', 'image bytes');
        $this->second = $this->project->addFile('selfie.jpg', 'image bytes');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testReportsAMatchWithTheCutOffItUsed(): void
    {
        self::assertSame(FaceMatchCommand::EXIT_OK, $this->runCommand(['org/pack', $this->first, $this->second]));

        self::assertSame(
            "🤝 Comparing the two faces with org/pack… Quick one — hold on to your cookie 🍪\n"
            . "If you wish to see all logs, re-run the command with the \"--debug\" option.\n"
            . "🎉 Same person — 71.2% similar (cut-off 40.0%)\n"
            . "   💡 2 face(s) in passport.jpg and 2 in selfie.jpg; the most confident of each was matched.\n",
            $this->contents($this->stdout),
        );
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testReportsDifferentPeopleWhenTheCutOffIsNotReached(): void
    {
        self::assertSame(FaceMatchCommand::EXIT_OK, $this->runCommand(['org/pack', $this->first, $this->second, '--threshold=0.9']));

        $output = $this->contents($this->stdout);
        self::assertStringContainsString('Different people — 71.2% similar (cut-off 90.0%)', $output);
    }

    public function testADifferentPersonIsNotAFailure(): void
    {
        self::assertSame(
            FaceMatchCommand::EXIT_OK,
            $this->runCommand(['org/pack', $this->first, $this->second, '--threshold=0.9']),
            'the exit code says the comparison ran, not what it found',
        );
    }

    public function testUsesTheConfiguredThreshold(): void
    {
        $config = new FaceConfig(threshold: 0.95);

        self::assertSame(FaceMatchCommand::EXIT_OK, $this->runCommand(['org/pack', $this->first, $this->second], $config));
        self::assertStringContainsString('Different people — 71.2% similar (cut-off 95.0%)', $this->contents($this->stdout));
    }

    public function testJsonPrintsNothingButTheObject(): void
    {
        self::assertSame(FaceMatchCommand::EXIT_OK, $this->runCommand(['org/pack', $this->first, $this->second, '--json']));

        $output = $this->contents($this->stdout);
        self::assertStringNotContainsString('🤝', $output);
        self::assertStringNotContainsString('🎉', $output);

        $result = json_decode(trim($output), true);
        self::assertIsArray($result);
        self::assertSame('org/pack', $result['model']);
        self::assertTrue($result['same_person']);
        self::assertSame(0.7123, $result['similarity']);
        self::assertSame(0.4, $result['threshold']);
        self::assertSame($this->first, $result['a']['image']);
        self::assertSame(2, $result['a']['faces']);
        self::assertSame('v1:256:3f8a21c4d9e07b15000000000000000000000000000000000000000000000000', $result['a']['id']);
    }

    public function testExplainsWhenAnImageHasNoFace(): void
    {
        $empty = $this->project->addFile('empty.jpg', 'image bytes');

        self::assertSame(FaceMatchCommand::EXIT_FAILURE, $this->runCommand(['org/pack', $this->first, $empty]));

        $errors = $this->contents($this->stderr);
        self::assertStringContainsString("No face was found in {$empty}.", $errors);
        self::assertStringContainsString('Lower --min-score, or raise --det-size if the face is small in the frame.', $errors);
    }

    public function testPassesOptionsToTheRunner(): void
    {
        self::assertSame(FaceMatchCommand::EXIT_OK, $this->runCommand([
            'org/pack', $this->first, $this->second, '--min-score=0.7', '--det-size=800', '--device=coreml', '--debug',
        ]));

        $errors = $this->contents($this->stderr);
        self::assertStringContainsString("--image {$this->first} --compare {$this->second}", $errors);
        self::assertStringContainsString('--threshold 0.4 --min-score 0.7', $errors);
        self::assertStringContainsString('--det-size 800 --no-embeddings --device coreml', $errors);
    }

    public function testReportsAFailedRun(): void
    {
        $broken = $this->project->addFile('fail.jpg', 'broken');

        self::assertSame(FaceMatchCommand::EXIT_FAILURE, $this->runCommand(['org/pack', $this->first, $broken]));

        self::assertStringContainsString('failed to compare the two images with org/pack (exit code 1)', $this->contents($this->stderr));
    }

    public function testPointsAtPullForAModelThatWasNotPulled(): void
    {
        self::assertSame(FaceMatchCommand::EXIT_FAILURE, $this->runCommand(['org/missing', $this->first, $this->second]));

        self::assertStringContainsString('Pull it first with: vendor/bin/loves-ai pull org/missing', $this->contents($this->stderr));
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('badUsage')]
    public function testRejectsBadUsage(array $args, string $message): void
    {
        self::assertSame(FaceMatchCommand::EXIT_USAGE, $this->runCommand($args));

        $errors = $this->contents($this->stderr);
        self::assertStringContainsString($message, $errors);
        self::assertStringContainsString("Run 'vendor/bin/loves-ai face-match --help' for usage.", $errors);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function badUsage(): iterable
    {
        yield 'no arguments' => [[], 'A model and two images are required.'];
        yield 'one image' => [['org/pack', 'a.jpg'], 'A model and two images are required.'];
        yield 'three images' => [['org/pack', 'a.jpg', 'b.jpg', 'c.jpg'], 'face-match compares two images.'];
        yield 'threshold is not a number' => [['org/pack', 'a.jpg', 'b.jpg', '--threshold=high'], 'Option --threshold must be a number.'];
        yield 'unknown option' => [['org/pack', 'a.jpg', 'b.jpg', '--hash-bits=256'], 'Unknown option: --hash-bits'];
    }

    public function testHelpWarnsAboutTheThreshold(): void
    {
        self::assertSame(FaceMatchCommand::EXIT_OK, $this->runCommand(['--help']));

        $output = $this->contents($this->stdout);
        self::assertStringContainsString('Usage: vendor/bin/loves-ai face-match <model> <image> <image> [options]', $output);
        self::assertStringContainsString('a starting point, not a safe', $output);
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args, ?FaceConfig $config = null, ?LocalStorage $storage = null): int
    {
        // Always the first intro, so assertions on the output are stable.
        $command = new FaceMatchCommand(
            $config ?? new FaceConfig(),
            $this->stdout,
            $this->stderr,
            static fn (): int => 0,
            $storage ?? $this->project->storage,
        );

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
