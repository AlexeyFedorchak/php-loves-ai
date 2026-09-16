<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\ImageNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Runner\ImageToImage;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageToImageTest extends TestCase
{
    private FakeProject $project;

    private string $image;

    protected function setUp(): void
    {
        $this->project = (new FakeProject())
            ->install(Tool::ImageToImage, FakeProject::FAKE_IMAGE_TO_IMAGE)
            ->addModel('org/upscaler', ['config.json', 'model.safetensors', 'preprocessor_config.json']);
        $this->project->addFile('.local/models/org/upscaler/config.json', '{"model_type": "swin2sr", "architectures": ["Swin2SRForImageSuperResolution"], "upscale": 2}');
        $this->image = $this->project->addFile('photos/small.png', 'png bytes');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testEnlargesImage(): void
    {
        $args = '';
        $file = $this->imageToImage()->transform('org/upscaler', $this->image, '/images/big.png', onOutput: static function (string $chunk) use (&$args): void {
            $args .= $chunk;
        });

        self::assertSame('/images/big.png', $file);
        self::assertSame("args: --model {$this->project->root}/.local/models/org/upscaler --image {$this->image} --output /images/big.png\n", $args);
    }

    public function testAcceptsDiffusersPipelineWithPrompt(): void
    {
        $this->project->addModel('org/sd', ['model_index.json']);

        $args = '';
        $this->imageToImage()->transform(
            'org/sd',
            $this->image,
            '/images/art.png',
            prompt: 'a watercolor painting',
            negativePrompt: 'blurry',
            strength: 0.6,
            steps: 4,
            guidanceScale: 0.0,
            seed: 42,
            device: 'mps',
            onOutput: static function (string $chunk) use (&$args): void {
                $args .= $chunk;
            },
        );

        self::assertStringEndsWith(
            '--output /images/art.png --prompt a watercolor painting --negative-prompt blurry --strength 0.6 '
            . '--steps 4 --guidance 0 --seed 42 --device mps' . "\n",
            $args,
        );
    }

    public function testRequiresReadableImageBeforeStarting(): void
    {
        $output = '';

        try {
            $this->imageToImage()->transform('org/upscaler', "{$this->project->root}/missing.png", '/images/big.png', onOutput: static function (string $chunk) use (&$output): void {
                $output .= $chunk;
            });
            self::fail('Expected ImageNotFoundException.');
        } catch (ImageNotFoundException $e) {
            self::assertSame("{$this->project->root}/missing.png", $e->path);
        }

        self::assertSame('', $output, 'The runner binary is not started.');
    }

    public function testThrowsWhenRunFails(): void
    {
        $failing = $this->project->addFile('photos/fail.png', 'png bytes');

        try {
            $this->imageToImage()->transform('org/upscaler', $failing, '/images/big.png');
            self::fail('Expected RunFailedException.');
        } catch (RunFailedException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('needs a prompt', $e->getMessage());
        }
    }

    public function testRequiresPulledModel(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->imageToImage()->transform('org/missing', $this->image, '/images/big.png');
    }

    public function testRequiresInstalledRunner(): void
    {
        $project = (new FakeProject())->addModel('org/upscaler', ['model_index.json']);

        try {
            (new ImageToImage($project->storage))->transform('org/upscaler', $this->image, '/images/big.png');
            self::fail('Expected BinaryNotInstalledException.');
        } catch (BinaryNotInstalledException $e) {
            self::assertSame(Tool::ImageToImage, $e->tool);
        } finally {
            $project->remove();
        }
    }

    /**
     * @param list<string> $files
     */
    #[DataProvider('unsupportedModels')]
    public function testRejectsUnsupportedModel(array $files, ?string $config, string $reason): void
    {
        $this->project->addModel('org/unsupported', $files);
        if ($config !== null) {
            $this->project->addFile('.local/models/org/unsupported/config.json', $config);
        }

        try {
            $this->imageToImage()->transform('org/unsupported', $this->image, '/images/big.png');
            self::fail('Expected UnsupportedModelException.');
        } catch (UnsupportedModelException $e) {
            self::assertStringStartsWith("org/unsupported cannot turn an image into an image: {$reason}", $e->getMessage());
            self::assertStringEndsWith(
                'Use an image-to-image model instead, e.g. caidas/swin2SR-classical-sr-x2-64 '
                . '(browse: https://huggingface.co/models?pipeline_tag=image-to-image).',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{list<string>, string|null, string}>
     */
    public static function unsupportedModels(): iterable
    {
        yield 'image classifier' => [['config.json', 'model.safetensors'], '{"model_type": "vit", "architectures": ["ViTForImageClassification"]}', 'it does not produce images.'];
        yield 'text model' => [['config.json', 'model.safetensors'], '{"model_type": "qwen2", "architectures": ["Qwen2ForCausalLM"]}', 'it does not produce images.'];
        yield 'GGUF' => [['model-q4.gguf'], null, 'it is in GGUF format'];
        yield 'no weights' => [['config.json'], '{"architectures": ["Swin2SRForImageSuperResolution"]}', 'its weights (*.safetensors or *.bin files) are missing.'];
    }

    private function imageToImage(): ImageToImage
    {
        return new ImageToImage($this->project->storage);
    }
}
