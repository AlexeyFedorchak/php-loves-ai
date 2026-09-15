<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Runner\TextToText;
use PhpLovesAi\Tests\Support\FakeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextToTextTest extends TestCase
{
    private FakeProject $project;

    protected function setUp(): void
    {
        $this->project = (new FakeProject())
            ->install(Tool::TextToText, FakeProject::FAKE_TEXT_TO_TEXT)
            ->addModel('org/model', ['config.json', 'model.safetensors']);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testReturnsGeneratedText(): void
    {
        $args = '';
        $text = $this->textToText()->generate(
            'org/model',
            'Hello there',
            onOutput: static function (string $chunk) use (&$args): void {
                $args .= $chunk;
            },
        );

        self::assertSame("You said: Hello there\nThat is all.", $text);
        self::assertSame("args: --model {$this->project->root}/.local/models/org/model --prompt Hello there\n", $args);
    }

    public function testPassesOptionalParameters(): void
    {
        $args = '';
        $this->textToText()->generate(
            'org/model',
            'Hello',
            systemPrompt: 'Be brief.',
            maxNewTokens: 64,
            temperature: 0.0,
            topP: 0.9,
            seed: 42,
            device: 'mps',
            onOutput: static function (string $chunk) use (&$args): void {
                $args .= $chunk;
            },
        );

        self::assertStringEndsWith(
            '--prompt Hello --system Be brief. --max-new-tokens 64 --temperature 0 --top-p 0.9 --seed 42 --device mps' . "\n",
            $args,
        );
    }

    public function testThrowsWhenRunFails(): void
    {
        try {
            $this->textToText()->generate('org/model', 'fail');
            self::fail('Expected RunFailedException.');
        } catch (RunFailedException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('out of memory', $e->getMessage());
        }
    }

    public function testRequiresPulledModel(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->textToText()->generate('org/missing', 'Hello');
    }

    public function testRequiresInstalledRunner(): void
    {
        $project = (new FakeProject())->addModel('org/model', ['config.json', 'model.safetensors']);

        try {
            (new TextToText($project->storage))->generate('org/model', 'Hello');
            self::fail('Expected BinaryNotInstalledException.');
        } catch (BinaryNotInstalledException $e) {
            self::assertSame(Tool::TextToText, $e->tool);
        } finally {
            $project->remove();
        }
    }

    public function testAcceptsShardedPytorchWeights(): void
    {
        $this->project->addModel('org/sharded', ['config.json', 'pytorch_model-00001-of-00002.bin', 'pytorch_model-00002-of-00002.bin']);

        self::assertSame("You said: Hello\nThat is all.", $this->textToText()->generate('org/sharded', 'Hello'));
    }

    /**
     * @param list<string> $files
     */
    #[DataProvider('unsupportedModels')]
    public function testRejectsUnsupportedModelBeforeStarting(array $files, string $reason, string $config = '{}'): void
    {
        $this->project->addModel('org/unsupported', $files);
        $modelPath = "{$this->project->root}/.local/models/org/unsupported";
        if (in_array('config.json', $files, true)) {
            file_put_contents("{$modelPath}/config.json", $config);
        }
        $output = '';

        try {
            $this->textToText()->generate('org/unsupported', 'Hello', onOutput: static function (string $chunk) use (&$output): void {
                $output .= $chunk;
            });
            self::fail('Expected UnsupportedModelException.');
        } catch (UnsupportedModelException $e) {
            self::assertSame('org/unsupported', $e->model);
            self::assertStringStartsWith("org/unsupported cannot generate text: {$reason}", str_replace($modelPath, '<path>', $e->getMessage()));
            self::assertStringEndsWith(
                'Use a transformers text generation model instead, e.g. Qwen/Qwen2.5-0.5B-Instruct '
                . '(browse: https://huggingface.co/models?pipeline_tag=text-generation&library=transformers).',
                $e->getMessage(),
            );
        }

        self::assertSame('', $output, 'The runner binary is not started.');
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string, 2?: string}>
     */
    public static function unsupportedModels(): iterable
    {
        yield 'image model' => [['model_index.json'], 'it is an image generation model, made for text-to-image.'];
        yield 'GGUF' => [['README.md', 'qwen2.5-0.5b-instruct-q4_k_m.gguf'], 'it is in GGUF format, which is made for llama.cpp and Ollama'];
        yield 'LoRA adapter' => [['adapter_config.json', 'adapter_model.safetensors'], 'it is not a complete transformers model (<path>/config.json is missing).'];
        yield 'custom code' => [['config.json', 'model.safetensors'], 'it needs its own Python code to run', '{"auto_map": {"AutoModelForCausalLM": "modeling.Model"}}'];
        yield 'ONNX only' => [['config.json', 'model.onnx'], 'it only has ONNX weights, which the runner cannot load.'];
        yield 'no weights' => [['config.json', 'tokenizer.json'], 'its weights (*.safetensors or *.bin files) are missing.'];
    }

    private function textToText(): TextToText
    {
        return new TextToText($this->project->storage);
    }
}
