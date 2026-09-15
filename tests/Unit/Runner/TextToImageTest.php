<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Runner;

use PhpLovesAi\Binary\BinaryStore;
use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotFoundException;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Filesystem\Path;
use PhpLovesAi\Runner\TextToImage;
use PHPUnit\Framework\TestCase;

final class TextToImageTest extends TestCase
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
        $image = (new TextToImage(self::FAKE_RUNNER, $this->modelsDir))->generate(
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
        (new TextToImage(self::FAKE_RUNNER, $this->modelsDir))->generate(
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
            (new TextToImage(self::FAKE_RUNNER, $this->modelsDir))->generate('org/model', 'fail', '/images/cat.png');
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

        (new TextToImage(self::FAKE_RUNNER, $this->modelsDir))->generate('org/missing', 'a cat', '/images/cat.png');
    }

    public function testRequiresExistingBinary(): void
    {
        $this->expectException(BinaryNotFoundException::class);

        (new TextToImage('/nonexistent/text-to-image', $this->modelsDir))->generate('org/model', 'a cat', '/images/cat.png');
    }

    public function testInstalledUsesBinaryFromStore(): void
    {
        $store = new BinaryStore("{$this->modelsDir}/home", 'v1.2.3');
        $binary = $store->path(Tool::TextToImage);
        mkdir(dirname($binary), 0777, true);
        copy(self::FAKE_RUNNER, $binary);
        chmod($binary, 0755);

        try {
            $image = TextToImage::installed($this->modelsDir, store: $store)->generate('org/model', 'a cat', '/images/cat.png');
        } finally {
            Path::remove("{$this->modelsDir}/home");
        }

        self::assertSame('/images/cat.png', $image);
    }

    public function testInstalledRequiresSetup(): void
    {
        try {
            TextToImage::installed($this->modelsDir, store: new BinaryStore("{$this->modelsDir}/home", 'v1.2.3'));
            self::fail('Expected BinaryNotInstalledException.');
        } catch (BinaryNotInstalledException $e) {
            self::assertSame(Tool::TextToImage, $e->tool);
        }
    }
}
