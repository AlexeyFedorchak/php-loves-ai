<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Runner\TextToVideo;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextToVideoTest extends TestCase
{
    private FakeProject $project;

    protected function setUp(): void
    {
        $this->project = (new FakeProject())
            ->install(Tool::TextToVideo, FakeProject::FAKE_TEXT_TO_VIDEO)
            ->addModel('org/video', ['model_index.json']);
        $this->project->addFile('.local/models/org/video/model_index.json', '{"_class_name": "LTXPipeline"}');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testWritesVideoFile(): void
    {
        $args = '';
        $file = $this->textToVideo()->generate('org/video', 'a cat walking', '/videos/cat.mp4', onOutput: static function (string $chunk) use (&$args): void {
            $args .= $chunk;
        });

        self::assertSame('/videos/cat.mp4', $file);
        self::assertSame("args: --model {$this->project->root}/.local/models/org/video --prompt a cat walking --output /videos/cat.mp4\n", $args);
    }

    public function testPassesOptionalParameters(): void
    {
        $args = '';
        $this->textToVideo()->generate(
            'org/video',
            'a cat walking',
            '/videos/cat.gif',
            negativePrompt: 'blurry',
            frames: 16,
            fps: 12,
            steps: 20,
            guidanceScale: 5.0,
            width: 512,
            height: 320,
            seed: 42,
            device: 'mps',
            onOutput: static function (string $chunk) use (&$args): void {
                $args .= $chunk;
            },
        );

        self::assertStringEndsWith(
            '--output /videos/cat.gif --negative-prompt blurry --frames 16 --fps 12 --steps 20 --guidance 5 '
            . '--width 512 --height 320 --seed 42 --device mps' . "\n",
            $args,
        );
    }

    public function testThrowsWhenRunFails(): void
    {
        try {
            $this->textToVideo()->generate('org/video', 'fail', '/videos/cat.mp4');
            self::fail('Expected RunFailedException.');
        } catch (RunFailedException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('out of memory', $e->getMessage());
        }
    }

    public function testRequiresPulledModel(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->textToVideo()->generate('org/missing', 'a cat', '/videos/cat.mp4');
    }

    public function testRequiresInstalledRunner(): void
    {
        $project = (new FakeProject())->addModel('org/video', ['model_index.json']);

        try {
            (new TextToVideo($project->storage))->generate('org/video', 'a cat', '/videos/cat.mp4');
            self::fail('Expected BinaryNotInstalledException.');
        } catch (BinaryNotInstalledException $e) {
            self::assertSame(Tool::TextToVideo, $e->tool);
        } finally {
            $project->remove();
        }
    }

    #[DataProvider('videoPipelines')]
    public function testAcceptsVideoPipelines(string $className): void
    {
        $this->project->addModel('org/other', ['model_index.json']);
        $this->project->addFile('.local/models/org/other/model_index.json', sprintf('{"_class_name": "%s"}', $className));

        self::assertSame('/videos/cat.mp4', $this->textToVideo()->generate('org/other', 'a cat', '/videos/cat.mp4'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function videoPipelines(): iterable
    {
        foreach (['WanPipeline', 'CogVideoXPipeline', 'AnimateDiffPipeline', 'TextToVideoSDPipeline', 'MochiPipeline', 'BrandNewPipeline'] as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param list<string> $files
     */
    #[DataProvider('unsupportedModels')]
    public function testRejectsUnsupportedModel(array $files, ?string $index, string $reason): void
    {
        $this->project->addModel('org/unsupported', $files);
        if ($index !== null) {
            $this->project->addFile('.local/models/org/unsupported/model_index.json', $index);
        }

        try {
            $this->textToVideo()->generate('org/unsupported', 'a cat', '/videos/cat.mp4');
            self::fail('Expected UnsupportedModelException.');
        } catch (UnsupportedModelException $e) {
            self::assertStringStartsWith("org/unsupported cannot generate video: {$reason}", str_replace("{$this->project->root}/.local/models/org/unsupported", '<path>', $e->getMessage()));
            self::assertStringEndsWith(
                'Use a diffusers video model instead, e.g. Wan-AI/Wan2.1-T2V-1.3B-Diffusers '
                . '(browse: https://huggingface.co/models?pipeline_tag=text-to-video&library=diffusers).',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{list<string>, string|null, string}>
     */
    public static function unsupportedModels(): iterable
    {
        yield 'still image pipeline' => [['model_index.json'], '{"_class_name": "StableDiffusionXLPipeline"}', 'it generates still images (StableDiffusionXLPipeline); use it with text-to-image.'];
        yield 'transformers model' => [['config.json', 'model.safetensors'], null, 'it is not a diffusers pipeline (<path>/model_index.json is missing).'];
        yield 'GGUF' => [['wan-q4.gguf'], null, 'it is in GGUF format, which is made for tools like ComfyUI, not diffusers.'];
    }

    private function textToVideo(): TextToVideo
    {
        return new TextToVideo($this->project->storage);
    }
}
