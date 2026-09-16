<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Exception\ImageNotFoundException;
use PhpLovesAi\Runner\ImageToVideo;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageToVideoTest extends TestCase
{
    private FakeProject $project;

    private string $image;

    protected function setUp(): void
    {
        $this->project = (new FakeProject())
            ->install(Tool::ImageToVideo, FakeProject::FAKE_IMAGE_TO_VIDEO)
            ->addModel('org/video', ['model_index.json']);
        $this->project->addFile('.local/models/org/video/model_index.json', '{"_class_name": "StableVideoDiffusionPipeline"}');
        $this->image = $this->project->addFile('photos/cats.png', 'png bytes');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testWritesVideoFile(): void
    {
        $args = '';
        $file = $this->imageToVideo()->generate('org/video', $this->image, '/videos/cat.mp4', onOutput: static function (string $chunk) use (&$args): void {
            $args .= $chunk;
        });

        self::assertSame('/videos/cat.mp4', $file);
        self::assertSame("args: --model {$this->project->root}/.local/models/org/video --image {$this->image} --output /videos/cat.mp4\n", $args);
    }

    public function testRequiresReadableImageBeforeStarting(): void
    {
        $output = '';

        try {
            $this->imageToVideo()->generate('org/video', "{$this->project->root}/missing.png", '/videos/cat.mp4', onOutput: static function (string $chunk) use (&$output): void {
                $output .= $chunk;
            });
            self::fail('Expected ImageNotFoundException.');
        } catch (ImageNotFoundException $e) {
            self::assertSame("{$this->project->root}/missing.png", $e->path);
        }

        self::assertSame('', $output, 'The runner binary is not started.');
    }

    public function testPassesOptionalParameters(): void
    {
        $args = '';
        $this->imageToVideo()->generate(
            'org/video',
            $this->image,
            '/videos/cat.gif',
            prompt: 'the cats move',
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
            '--output /videos/cat.gif --prompt the cats move --negative-prompt blurry --frames 16 --fps 12 --steps 20 '
            . '--guidance 5 --width 512 --height 320 --seed 42 --device mps' . "\n",
            $args,
        );
    }

    public function testThrowsWhenRunFails(): void
    {
        try {
            $this->imageToVideo()->generate('org/video', $this->project->addFile('photos/fail.png', 'png bytes'), '/videos/cat.mp4');
            self::fail('Expected RunFailedException.');
        } catch (RunFailedException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('takes no prompt', $e->getMessage());
        }
    }

    public function testRequiresPulledModel(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->imageToVideo()->generate('org/missing', $this->image, '/videos/cat.mp4');
    }

    public function testRequiresInstalledRunner(): void
    {
        $project = (new FakeProject())->addModel('org/video', ['model_index.json']);

        try {
            (new ImageToVideo($project->storage))->generate('org/video', $this->image, '/videos/cat.mp4');
            self::fail('Expected BinaryNotInstalledException.');
        } catch (BinaryNotInstalledException $e) {
            self::assertSame(Tool::ImageToVideo, $e->tool);
        } finally {
            $project->remove();
        }
    }

    #[DataProvider('videoPipelines')]
    public function testAcceptsVideoPipelines(string $className): void
    {
        $this->project->addModel('org/other', ['model_index.json']);
        $this->project->addFile('.local/models/org/other/model_index.json', sprintf('{"_class_name": "%s"}', $className));

        self::assertSame('/videos/cat.mp4', $this->imageToVideo()->generate('org/other', $this->image, '/videos/cat.mp4'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function videoPipelines(): iterable
    {
        foreach (['WanImageToVideoPipeline', 'CogVideoXImageToVideoPipeline', 'LTXImageToVideoPipeline', 'StableVideoDiffusionPipeline', 'BrandNewPipeline'] as $class) {
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
            $this->imageToVideo()->generate('org/unsupported', $this->image, '/videos/cat.mp4');
            self::fail('Expected UnsupportedModelException.');
        } catch (UnsupportedModelException $e) {
            self::assertStringStartsWith("org/unsupported cannot animate an image: {$reason}", str_replace("{$this->project->root}/.local/models/org/unsupported", '<path>', $e->getMessage()));
            self::assertStringEndsWith(
                'Use a diffusers image-to-video model instead, e.g. stabilityai/stable-video-diffusion-img2vid-xt '
                . '(browse: https://huggingface.co/models?pipeline_tag=image-to-video&library=diffusers).',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{list<string>, string|null, string}>
     */
    public static function unsupportedModels(): iterable
    {
        yield 'still image pipeline' => [['model_index.json'], '{"_class_name": "StableDiffusionXLPipeline"}', 'it generates still images (StableDiffusionXLPipeline); use it with image-to-image.'];
        yield 'transformers model' => [['config.json', 'model.safetensors'], null, 'it is not a diffusers pipeline (<path>/model_index.json is missing).'];
        yield 'GGUF' => [['wan-q4.gguf'], null, 'it is in GGUF format, which is made for tools like ComfyUI, not diffusers.'];
    }

    private function imageToVideo(): ImageToVideo
    {
        return new ImageToVideo($this->project->storage);
    }
}
