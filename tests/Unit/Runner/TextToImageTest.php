<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Runner\TextToImage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\TestCase;

final class TextToImageTest extends TestCase
{
    private FakeProject $project;

    protected function setUp(): void
    {
        $this->project = (new FakeProject())
            ->install(Tool::TextToImage, FakeProject::FAKE_TEXT_TO_IMAGE)
            ->addModel('org/model');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testFindsModelAndRunnerInProject(): void
    {
        $args = '';
        $image = $this->textToImage()->generate(
            'org/model',
            'a cozy cat',
            '/images/cat.png',
            onOutput: static function (string $chunk) use (&$args): void {
                $args .= $chunk;
            },
        );

        self::assertSame('/images/cat.png', $image);
        self::assertSame("args: --model {$this->project->root}/.local/models/org/model --prompt a cozy cat --output /images/cat.png\n", $args);
    }

    public function testPassesOptionalParameters(): void
    {
        $args = '';
        $this->textToImage()->generate(
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
            $this->textToImage()->generate('org/model', 'fail', '/images/cat.png');
            self::fail('Expected RunFailedException.');
        } catch (RunFailedException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('prompt is too long', $e->getMessage());
        }
    }

    public function testRequiresPulledModel(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage("Model org/missing not found at {$this->project->root}/.local/models/org/missing.");

        $this->textToImage()->generate('org/missing', 'a cat', '/images/cat.png');
    }

    public function testRequiresInstalledRunner(): void
    {
        $project = (new FakeProject())->addModel('org/model');

        try {
            (new TextToImage($project->storage))->generate('org/model', 'a cat', '/images/cat.png');
            self::fail('Expected BinaryNotInstalledException.');
        } catch (BinaryNotInstalledException $e) {
            self::assertSame(Tool::TextToImage, $e->tool);
        } finally {
            $project->remove();
        }
    }

    private function textToImage(): TextToImage
    {
        return new TextToImage($this->project->storage);
    }
}
