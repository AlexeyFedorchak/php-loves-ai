<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

/**
 * Checks the pulled files of a model for what the transformers-based runners (text-to-text, image-to-text) can load:
 * config.json and PyTorch weights, without code shipped with the model.
 */
final class TransformersModel
{
    /**
     * Explains, for a user who may not know Hugging Face formats, why the model in $modelPath cannot be loaded.
     *
     * @return string|null a reason starting with "it", e.g. "it is in GGUF format, …"; null when loadable
     */
    public static function problem(string $modelPath): ?string
    {
        if (self::has($modelPath, 'model_index.json')) {
            return 'it is an image generation model, made for text-to-image.';
        }

        if (!self::has($modelPath, 'config.json')) {
            return self::has($modelPath, '*.gguf')
                ? 'it is in GGUF format, which is made for llama.cpp and Ollama, not transformers.'
                : "it is not a complete transformers model ({$modelPath}/config.json is missing). It may be an add-on such as a LoRA adapter, a model in another format, or a pull that did not finish.";
        }

        if (isset(self::config($modelPath)['auto_map'])) {
            return 'it needs its own Python code to run, which the runner does not execute for security reasons.';
        }

        if (!self::has($modelPath, '*.safetensors') && !self::has($modelPath, '*.bin')) {
            return self::has($modelPath, '*.onnx') || self::has($modelPath, 'onnx/*.onnx')
                ? 'it only has ONNX weights, which the runner cannot load.'
                : 'its weights (*.safetensors or *.bin files) are missing. The pull may not have finished.';
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    public static function config(string $modelPath): array
    {
        $config = json_decode((string) @file_get_contents("{$modelPath}/config.json"), true);

        return is_array($config) ? $config : [];
    }

    public static function has(string $modelPath, string $pattern): bool
    {
        return (glob("{$modelPath}/{$pattern}") ?: []) !== [];
    }
}
