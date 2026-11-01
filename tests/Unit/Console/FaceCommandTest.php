<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Console;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Config\FaceConfig;
use PhpLovesAi\Console\FaceCommand;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FaceCommandTest extends TestCase
{
    /** @var list<string> */
    private const MODEL_FILES = ['detection/model.onnx', 'recognition/model.onnx'];

    private const ID_ONE = 'v1:256:3f8a21c4d9e07b15000000000000000000000000000000000000000000000000';

    private const ID_TWO = 'v1:256:7c1e03ba55d9f402000000000000000000000000000000000000000000000000';

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
            ->install(Tool::Face, FakeProject::FAKE_FACE)
            ->addModel('org/pack', self::MODEL_FILES);

        $this->image = $this->project->addFile('photo.jpg', 'image bytes');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testPrintsEveryFaceWithItsIdBelowIt(): void
    {
        self::assertSame(FaceCommand::EXIT_OK, $this->runCommand(['org/pack', $this->image]));

        self::assertSame(
            "🙂 Looking for faces with org/pack… Should be quick — no time for a whole cookie 🍪\n"
            . "If you wish to see all logs, re-run the command with the \"--debug\" option.\n"
            . "🎉 Found 2 faces in photo.jpg\n"
            . "   👤 1  97.3% confident, 90×112 px at 120,84\n"
            . '      ' . self::ID_ONE . "\n"
            . "   👤 2  88.1% confident, 74×95 px at 310,96\n"
            . '      ' . self::ID_TWO . "\n",
            $this->contents($this->stdout),
        );
        self::assertSame('', $this->contents($this->stderr));
    }

    public function testSaysSoWhenThereIsOneFace(): void
    {
        self::assertSame(FaceCommand::EXIT_OK, $this->runCommand(['org/pack', $this->image, '--max-faces=1']));

        // The fixture reports both faces whatever --max-faces says; the wording is what is tested.
        self::assertStringContainsString('Found 2 faces in photo.jpg', $this->contents($this->stdout));
    }

    public function testReportsAnImageWithoutFaces(): void
    {
        $empty = $this->project->addFile('empty.jpg', 'image bytes');

        self::assertSame(FaceCommand::EXIT_OK, $this->runCommand(['org/pack', $empty]));
        self::assertStringContainsString('No faces found in empty.jpg', $this->contents($this->stdout));
    }

    public function testCountOnlyPrintsTheCount(): void
    {
        self::assertSame(FaceCommand::EXIT_OK, $this->runCommand(['org/pack', $this->image, '--count']));

        $output = $this->contents($this->stdout);
        self::assertStringContainsString('Found 2 faces in photo.jpg', $output);
        self::assertStringNotContainsString('👤', $output, 'counting reports no individual faces');
        self::assertStringNotContainsString(self::ID_ONE, $output);
    }

    public function testJsonPrintsNothingButTheObject(): void
    {
        self::assertSame(FaceCommand::EXIT_OK, $this->runCommand(['org/pack', $this->image, '--json']));

        $output = $this->contents($this->stdout);
        self::assertStringNotContainsString('🙂', $output, 'the intro would break JSON parsing');
        self::assertStringNotContainsString('🎉', $output);

        $result = json_decode(trim($output), true);
        self::assertIsArray($result);
        self::assertSame('org/pack', $result['model']);
        self::assertSame($this->image, $result['image']);
        self::assertSame(2, $result['count']);
        self::assertCount(2, $result['faces']);
        self::assertSame(self::ID_ONE, $result['faces'][0]['id']);
        self::assertSame([120, 84, 210, 196], $result['faces'][0]['box']);
        self::assertSame(0.9731, $result['faces'][0]['score']);
        self::assertArrayNotHasKey('embedding', $result['faces'][0], 'embeddings need --embeddings');
    }

    public function testJsonCarriesEmbeddingsWhenAskedFor(): void
    {
        self::assertSame(FaceCommand::EXIT_OK, $this->runCommand(['org/pack', $this->image, '--json', '--embeddings']));

        $result = json_decode(trim($this->contents($this->stdout)), true);
        self::assertIsArray($result);
        self::assertSame([0.1, -0.2, 0.3], $result['faces'][0]['embedding']);
    }

    public function testJsonForAnImageWithoutFaces(): void
    {
        $empty = $this->project->addFile('empty.jpg', 'image bytes');

        self::assertSame(FaceCommand::EXIT_OK, $this->runCommand(['org/pack', $empty, '--json']));

        $result = json_decode(trim($this->contents($this->stdout)), true);
        self::assertIsArray($result);
        self::assertSame(0, $result['count']);
        self::assertSame([], $result['faces']);
    }

    public function testPassesOptionsToTheRunner(): void
    {
        self::assertSame(FaceCommand::EXIT_OK, $this->runCommand([
            'org/pack', $this->image, '--min-score=0.8', '--hash-bits=128', '--det-size=1024', '--device=cpu', '--debug',
        ]));

        // --no-embeddings is there because --embeddings was not given.
        self::assertStringContainsString('--min-score 0.8 --hash-bits 128 --det-size 1024 --no-embeddings --device cpu', $this->contents($this->stderr));
    }

    public function testUsesTheConfiguredDefaults(): void
    {
        $config = new FaceConfig(hashBits: 512, threshold: 0.6, minScore: 0.25, detectionSize: 320);

        self::assertSame(FaceCommand::EXIT_OK, $this->runCommand(['org/pack', $this->image, '--debug'], $config));

        self::assertStringContainsString('--min-score 0.25 --hash-bits 512 --det-size 320', $this->contents($this->stderr));
    }

    public function testReportsAFailedRun(): void
    {
        $broken = $this->project->addFile('fail.jpg', 'broken');

        self::assertSame(FaceCommand::EXIT_FAILURE, $this->runCommand(['org/pack', $broken]));

        self::assertStringContainsString("failed to look for faces in {$broken} with org/pack (exit code 1)", $this->contents($this->stderr));
    }

    public function testPointsAtPullForAModelThatWasNotPulled(): void
    {
        self::assertSame(FaceCommand::EXIT_FAILURE, $this->runCommand(['org/missing', $this->image]));

        self::assertStringContainsString('Pull it first with: vendor/bin/loves-ai pull org/missing', $this->contents($this->stderr));
    }

    public function testPointsAtAnExamplePackForAModelItCannotUse(): void
    {
        $this->project->addModel('org/chat', ['config.json', 'model.safetensors']);

        self::assertSame(FaceCommand::EXIT_FAILURE, $this->runCommand(['org/chat', $this->image]));

        $errors = $this->contents($this->stderr);
        self::assertStringContainsString('it is a transformers model, not a face recognition pack', $errors);
        self::assertStringContainsString('To try one: vendor/bin/loves-ai pull immich-app/buffalo_l', $errors);
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('badUsage')]
    public function testRejectsBadUsage(array $args, string $message): void
    {
        self::assertSame(FaceCommand::EXIT_USAGE, $this->runCommand($args));

        $errors = $this->contents($this->stderr);
        self::assertStringContainsString($message, $errors);
        self::assertStringContainsString("Run 'vendor/bin/loves-ai face --help' for usage.", $errors);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function badUsage(): iterable
    {
        yield 'no arguments' => [[], 'Both a model and an image are required.'];
        yield 'no image' => [['org/pack'], 'Both a model and an image are required.'];
        yield 'two images' => [['org/pack', 'a.jpg', 'b.jpg'], 'face looks at one image. To match two, use face-match.'];
        yield 'unknown option' => [['org/pack', 'a.jpg', '--nope'], 'Unknown option: --nope'];
        yield 'score is not a number' => [['org/pack', 'a.jpg', '--min-score=high'], 'Option --min-score must be a number.'];
        yield 'bits are not an integer' => [['org/pack', 'a.jpg', '--hash-bits=many'], 'Option --hash-bits must be an integer.'];
        yield 'count takes no value' => [['org/pack', 'a.jpg', '--count=2'], 'Option --count does not take a value.'];
    }

    public function testHelpNeedsNoModel(): void
    {
        self::assertSame(FaceCommand::EXIT_OK, $this->runCommand(['--help']));

        $output = $this->contents($this->stdout);
        self::assertStringContainsString('Usage: vendor/bin/loves-ai face <model> <image> [options]', $output);
        self::assertStringContainsString('A face id is a fingerprint, not a primary key', $output);
    }

    /**
     * @param list<string> $args
     */
    private function runCommand(array $args, ?FaceConfig $config = null, ?LocalStorage $storage = null): int
    {
        // Always the first intro, so assertions on the output are stable.
        $command = new FaceCommand(
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
