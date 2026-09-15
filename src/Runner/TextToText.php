<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;

/**
 * Generates text with transformers models pulled into the project, through the runner installed by
 * `vendor/bin/setup text-to-text`.
 *
 *     $answer = (new TextToText())->generate('Qwen/Qwen2.5-0.5B-Instruct', 'Write a haiku about PHP.');
 *
 * Works with decoder-only models (Hugging Face task "text-generation", e.g. SmolLM, Qwen, Llama; chat models get the
 * prompt wrapped in their chat template) and encoder-decoder models ("text2text-generation", e.g. FLAN-T5).
 *
 * Input is passed to the model as-is: values it cannot handle make the run fail with a RunFailedException.
 */
final class TextToText extends BinaryRunner
{
    /** A model the runner can load; used in error messages and hints. */
    public const EXAMPLE_MODEL = 'Qwen/Qwen2.5-0.5B-Instruct';

    /** Hugging Face models this runner can use. */
    public const COMPATIBLE_MODELS_URL = 'https://huggingface.co/models?pipeline_tag=text-generation&library=transformers';

    public static function tool(): Tool
    {
        return Tool::TextToText;
    }

    /**
     * @param string                        $model        Hugging Face model id, e.g. "Qwen/Qwen2.5-0.5B-Instruct"
     * @param string                        $prompt       text to respond to (chat models) or continue
     * @param string|null                   $systemPrompt instructions for chat models, e.g. "You are a helpful assistant."
     * @param int|null                      $maxNewTokens maximum length of the generated text in tokens; null uses 256
     * @param float|null                    $temperature  randomness: 0 always picks the likeliest words, higher values
     *                                                    vary more; null uses the model's default
     * @param float|null                    $topP         nucleus sampling probability, e.g. 0.9; null uses the model's default
     * @param int|null                      $seed         random seed, for reproducible text
     * @param string|null                   $device       torch device such as "cpu", "cuda" or "mps";
     *                                                    null picks the best available
     * @param (callable(string): void)|null $onOutput     receives the runner's progress output and the text as it is
     *                                                    generated
     *
     * @return string the generated text
     *
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException when the model is not a transformers text model
     * @throws RunFailedException
     */
    public function generate(
        string $model,
        string $prompt,
        ?string $systemPrompt = null,
        ?int $maxNewTokens = null,
        ?float $temperature = null,
        ?float $topP = null,
        ?int $seed = null,
        ?string $device = null,
        ?callable $onOutput = null,
    ): string {
        $result = $this->run($model, [
            '--prompt' => $prompt,
            '--system' => $systemPrompt,
            '--max-new-tokens' => $maxNewTokens,
            '--temperature' => $temperature,
            '--top-p' => $topP,
            '--seed' => $seed,
            '--device' => $device,
        ], $onOutput);

        if (!is_string($result['text'] ?? null)) {
            throw new RunFailedException(0, 'The runner did not report the generated text.');
        }

        return $result['text'];
    }

    /**
     * The runner loads models with transformers, which needs config.json and PyTorch weights, and never runs code
     * shipped with a model.
     */
    protected function ensureModelIsSupported(string $model, string $modelPath): void
    {
        $unsupported = fn (string $reason): UnsupportedModelException => new UnsupportedModelException($model, $modelPath, sprintf(
            '%s cannot generate text: %s Use a transformers text generation model instead, e.g. %s (browse: %s).',
            $model,
            $reason,
            self::EXAMPLE_MODEL,
            self::COMPATIBLE_MODELS_URL,
        ));

        if (is_file("{$modelPath}/model_index.json")) {
            throw $unsupported('it is an image generation model, made for text-to-image.');
        }

        if (!is_file("{$modelPath}/config.json")) {
            throw $unsupported(self::has($modelPath, '*.gguf')
                ? 'it is in GGUF format, which is made for llama.cpp and Ollama, not transformers.'
                : "it is not a complete transformers model ({$modelPath}/config.json is missing). It may be an add-on such as a LoRA adapter, a model in another format, or a pull that did not finish.");
        }

        $config = json_decode((string) file_get_contents("{$modelPath}/config.json"), true);
        if (is_array($config) && isset($config['auto_map'])) {
            throw $unsupported('it needs its own Python code to run, which the runner does not execute for security reasons.');
        }

        if (!self::has($modelPath, '*.safetensors') && !self::has($modelPath, '*.bin')) {
            throw $unsupported(self::has($modelPath, '*.onnx') || self::has($modelPath, 'onnx/*.onnx')
                ? 'it only has ONNX weights, which the runner cannot load.'
                : 'its weights (*.safetensors or *.bin files) are missing. The pull may not have finished.');
        }
    }

    private static function has(string $dir, string $pattern): bool
    {
        return (glob("{$dir}/{$pattern}") ?: []) !== [];
    }
}
