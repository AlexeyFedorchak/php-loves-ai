<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Process;

use PhpLovesAi\Exception\BinaryNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Process\TextToImageRunner;
use PHPUnit\Framework\TestCase;

final class TextToImageRunnerTest extends TestCase
{
    private const FAKE_RUNNER = __DIR__ . '/../../Fixtures/fake-text-to-image';

    private string $modelsDir;

    protected function setUp(): void
    {
        $this->modelsDir = sys_get_temp_dir() . '/text-to-image-runner-test-' . bin2hex(random_bytes(4));
        mkdir("{$this->modelsDir}/org/model", 0777, true);
    }

    protected function tearDown(): void
    {
        rmdir("{$this->modelsDir}/org/model");
        rmdir("{$this->modelsDir}/org");
        rmdir($this->modelsDir);
    }

    public function testReturnsGeneratedImagePath(): void
    {
        $args = '';
        $image = (new TextToImageRunner(self::FAKE_RUNNER, $this->modelsDir))->generate(
            'org/model',
            'a cozy cat',
            '/images/cat.png',
            onOutput: static function (string $chunk) use (&$args): void {
                $args .= $chunk;
            },
        );

        self::assertSame('/images/cat.png', $image);
        self::assertSame("args: --model {$this->modelsDir}/org/model --prompt a cozy cat --output /images/cat.png\n", $args);
    }

    public function testPassesOptionalParameters(): void
    {
        $args = '';
        (new TextToImageRunner(self::FAKE_RUNNER, $this->modelsDir))->generate(
            'org/model',
            'a cozy cat',
            '/images/cat.png',
            negativePrompt: 'dogs',
            steps: 4,
            guidanceScale: 0.0,
            width: 512,
            height: 256,
            seed: 42,
            device: 'mps',
            onOutput: static function (string $chunk) use (&$args): void {
                $args .= $chunk;
            },
        );

        self::assertStringEndsWith(
            '--negative-prompt dogs --steps 4 --guidance 0 --width 512 --height 256 --seed 42 --device mps' . "\n",
            $args,
        );
    }

    public function testThrowsWhenRunFails(): void
    {
        try {
            (new TextToImageRunner(self::FAKE_RUNNER, $this->modelsDir))->generate('org/model', 'fail', '/images/cat.png');
            self::fail('Expected RunFailedException.');
        } catch (RunFailedException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('prompt is too long', $e->getMessage());
        }
    }

    public function testRequiresPulledModel(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage("Model org/missing not found at {$this->modelsDir}/org/missing.");

        (new TextToImageRunner(self::FAKE_RUNNER, $this->modelsDir))->generate('org/missing', 'a cat', '/images/cat.png');
    }

    public function testRequiresExistingBinary(): void
    {
        $this->expectException(BinaryNotFoundException::class);

        (new TextToImageRunner('/nonexistent/text-to-image', $this->modelsDir))->generate('org/model', 'a cat', '/images/cat.png');
    }
}
