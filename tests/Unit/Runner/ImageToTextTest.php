<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\ImageNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Runner\ImageToText;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageToTextTest extends TestCase
{
    private FakeProject $project;

    private string $image;

    protected function setUp(): void
    {
        $this->project = (new FakeProject())
            ->install(Tool::ImageToText, FakeProject::FAKE_IMAGE_TO_TEXT)
            ->addModel('org/vlm', ['config.json', 'model.safetensors', 'preprocessor_config.json']);
        $this->image = $this->project->addFile('photos/cats.jpg', 'jpeg bytes');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testDescribesImage(): void
    {
        $args = '';
        $text = $this->imageToText()->generate('org/vlm', $this->image, onOutput: static function (string $chunk) use (&$args): void {
            $args .= $chunk;
        });

        self::assertSame("Looked at cats.jpg\nPrompt: (none)", $text);
        self::assertSame("args: --model {$this->project->root}/.local/models/org/vlm --image {$this->image}\n", $args);
    }

    public function testPassesPromptAndOptionalParameters(): void
    {
        $args = '';
        $text = $this->imageToText()->generate(
            'org/vlm',
            $this->image,
            'How many cats?',
            maxNewTokens: 32,
            temperature: 0.0,
            seed: 7,
            device: 'mps',
            onOutput: static function (string $chunk) use (&$args): void {
                $args .= $chunk;
            },
        );

        self::assertSame("Looked at cats.jpg\nPrompt: How many cats?", $text);
        self::assertStringEndsWith("--image {$this->image} --prompt How many cats? --max-new-tokens 32 --temperature 0 --seed 7 --device mps\n", $args);
    }

    public function testAcceptsModelsWithCombinedProcessorConfig(): void
    {
        $this->project->addModel('org/other-vlm', ['config.json', 'model-00001-of-00002.safetensors', 'processor_config.json']);

        self::assertStringStartsWith('Looked at cats.jpg', $this->imageToText()->generate('org/other-vlm', $this->image));
    }

    public function testRequiresReadableImageBeforeStarting(): void
    {
        $output = '';

        try {
            $this->imageToText()->generate('org/vlm', "{$this->project->root}/missing.jpg", onOutput: static function (string $chunk) use (&$output): void {
                $output .= $chunk;
            });
            self::fail('Expected ImageNotFoundException.');
        } catch (ImageNotFoundException $e) {
            self::assertSame("{$this->project->root}/missing.jpg", $e->path);
            self::assertSame("Image not found or not readable: {$this->project->root}/missing.jpg", $e->getMessage());
        }

        self::assertSame('', $output, 'The runner binary is not started.');
    }

    public function testThrowsWhenRunFails(): void
    {
        try {
            $this->imageToText()->generate('org/vlm', $this->image, 'fail');
            self::fail('Expected RunFailedException.');
        } catch (RunFailedException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('cannot identify image file', $e->getMessage());
        }
    }

    public function testRequiresPulledModel(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->imageToText()->generate('org/missing', $this->image);
    }

    public function testRequiresInstalledRunner(): void
    {
        $project = (new FakeProject())->addModel('org/vlm', ['config.json', 'model.safetensors', 'preprocessor_config.json']);

        try {
            (new ImageToText($project->storage))->generate('org/vlm', $this->image);
            self::fail('Expected BinaryNotInstalledException.');
        } catch (BinaryNotInstalledException $e) {
            self::assertSame(Tool::ImageToText, $e->tool);
        } finally {
            $project->remove();
        }
    }

    /**
     * @param list<string> $files
     */
    #[DataProvider('unsupportedModels')]
    public function testRejectsUnsupportedModel(array $files, string $reason): void
    {
        $this->project->addModel('org/unsupported', $files);

        try {
            $this->imageToText()->generate('org/unsupported', $this->image);
            self::fail('Expected UnsupportedModelException.');
        } catch (UnsupportedModelException $e) {
            self::assertStringStartsWith("org/unsupported cannot describe images: {$reason}", $e->getMessage());
            self::assertStringEndsWith(
                'Use a transformers image-to-text model instead, e.g. HuggingFaceTB/SmolVLM-256M-Instruct '
                . '(browse: https://huggingface.co/models?pipeline_tag=image-text-to-text&library=transformers).',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function unsupportedModels(): iterable
    {
        yield 'text model' => [['config.json', 'model.safetensors', 'tokenizer.json'], 'it cannot read images (it has no preprocessor_config.json). If it is a text model, use it with text-to-text.'];
        yield 'image generation model' => [['model_index.json'], 'it is an image generation model, made for text-to-image.'];
        yield 'GGUF' => [['model-q4_k_m.gguf', 'mmproj-f16.gguf'], 'it is in GGUF format, which is made for llama.cpp and Ollama'];
        yield 'no weights' => [['config.json', 'preprocessor_config.json'], 'its weights (*.safetensors or *.bin files) are missing.'];
    }

    private function imageToText(): ImageToText
    {
        return new ImageToText($this->project->storage);
    }
}
