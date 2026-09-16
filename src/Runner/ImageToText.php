<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\HomeDirectoryNotFoundException;
use PhpLovesAi\Exception\ImageNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\Path;

/**
 * Describes images, or answers questions about them, with transformers models pulled into the project, through the
 * runner installed by `vendor/bin/loves-ai setup image-to-text`.
 *
 *     $caption = (new ImageToText())->generate('HuggingFaceTB/SmolVLM-256M-Instruct', __DIR__ . '/photo.jpg');
 *
 * Works with vision-language models (Hugging Face task "image-text-to-text", e.g. SmolVLM, Qwen2-VL, LLaVA), which
 * answer the prompt about the image, and captioning models ("image-to-text", e.g. BLIP, GIT, ViT-GPT2), for which a
 * prompt is the start of the caption.
 *
 * Input is passed to the model as-is: values it cannot handle make the run fail with a RunFailedException.
 */
final class ImageToText extends BinaryRunner
{
    /** A model the runner can load; used in error messages and hints. */
    public const EXAMPLE_MODEL = 'HuggingFaceTB/SmolVLM-256M-Instruct';

    /** Hugging Face models this runner can use. */
    public const COMPATIBLE_MODELS_URL = 'https://huggingface.co/models?pipeline_tag=image-text-to-text&library=transformers';

    public static function tool(): Tool
    {
        return Tool::ImageToText;
    }

    /**
     * @param string                        $model        Hugging Face model id, e.g. "HuggingFaceTB/SmolVLM-256M-Instruct"
     * @param string                        $imagePath    image file to read, e.g. "photo.jpg"; a leading "~" is expanded
     * @param string|null                   $prompt       question about the image for vision-language models (null asks
     *                                                    them to describe it), or the start of the caption for
     *                                                    captioning models
     * @param int|null                      $maxNewTokens maximum length of the text in tokens; null uses 256
     * @param float|null                    $temperature  randomness: 0 always picks the likeliest words; null uses the
     *                                                    model's default
     * @param int|null                      $seed         random seed, for reproducible text
     * @param string|null                   $device       torch device such as "cpu", "cuda" or "mps";
     *                                                    null picks the best available
     * @param (callable(string): void)|null $onOutput     receives the runner's progress output and the text as it is
     *                                                    generated
     *
     * @return string the description, caption or answer
     *
     * @throws ImageNotFoundException
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException when the model cannot read images or is not a transformers model
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException
     */
    public function generate(
        string $model,
        string $imagePath,
        ?string $prompt = null,
        ?int $maxNewTokens = null,
        ?float $temperature = null,
        ?int $seed = null,
        ?string $device = null,
        ?callable $onOutput = null,
    ): string {
        $imagePath = Path::expandHome($imagePath);
        if (!is_file($imagePath) || !is_readable($imagePath)) {
            throw new ImageNotFoundException($imagePath);
        }

        $result = $this->run($model, [
            '--image' => $imagePath,
            '--prompt' => $prompt,
            '--max-new-tokens' => $maxNewTokens,
            '--temperature' => $temperature,
            '--seed' => $seed,
            '--device' => $device,
        ], $onOutput);

        if (!is_string($result['text'] ?? null)) {
            throw new RunFailedException(0, 'The runner did not report the generated text.');
        }

        return $result['text'];
    }

    /**
     * Besides what every transformers runner needs, the model must come with an image processor, which text-only
     * models lack.
     */
    protected function ensureModelIsSupported(string $model, string $modelPath): void
    {
        $problem = TransformersModel::problem($modelPath);

        if ($problem === null
            && !TransformersModel::has($modelPath, 'preprocessor_config.json')
            && !TransformersModel::has($modelPath, 'processor_config.json')
        ) {
            $problem = 'it cannot read images (it has no preprocessor_config.json). If it is a text model, use it with text-to-text.';
        }

        if ($problem === null) {
            return;
        }

        throw new UnsupportedModelException($model, $modelPath, sprintf(
            '%s cannot describe images: %s Use a transformers image-to-text model instead, e.g. %s (browse: %s).',
            $model,
            $problem,
            self::EXAMPLE_MODEL,
            self::COMPATIBLE_MODELS_URL,
        ));
    }
}
