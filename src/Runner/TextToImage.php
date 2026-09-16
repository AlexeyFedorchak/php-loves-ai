<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\HomeDirectoryNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\Path;

/**
 * Generates images with diffusion models pulled into the project, through the runner installed by
 * `vendor/bin/loves-ai setup text-to-image`.
 *
 *     $image = (new TextToImage())->generate('stabilityai/sd-turbo', 'a cozy cat', __DIR__ . '/cat.png');
 *
 * Input is passed to the model as-is: values it cannot handle (e.g. a prompt that is too long) make the run
 * fail with a RunFailedException.
 */
final class TextToImage extends BinaryRunner
{
    /** A model the runner can load, e.g. stabilityai/sd-turbo; used in error messages. */
    public const EXAMPLE_MODEL = 'stabilityai/sd-turbo';

    /** Hugging Face models this runner can use. */
    public const COMPATIBLE_MODELS_URL = 'https://huggingface.co/models?pipeline_tag=text-to-image&library=diffusers';

    public static function tool(): Tool
    {
        return Tool::TextToImage;
    }

    /**
     * The runner loads models with diffusers' DiffusionPipeline, which needs the model_index.json describing the
     * pipeline. Repositories without it are typically add-ons (embeddings, LoRAs) or single-file checkpoints.
     */
    protected function ensureModelIsSupported(string $model, string $modelPath): void
    {
        if (is_file("{$modelPath}/model_index.json")) {
            return;
        }

        throw new UnsupportedModelException($model, $modelPath, sprintf(
            '%s cannot generate images: it is not a complete Diffusers text-to-image model (%s/model_index.json is missing). '
            . 'It may be an add-on such as embeddings or a LoRA, which only works on top of a base model, a model in another '
            . 'format, or a pull that did not finish. Use a Diffusers text-to-image model instead, e.g. %s (browse: %s).',
            $model,
            $modelPath,
            self::EXAMPLE_MODEL,
            self::COMPATIBLE_MODELS_URL,
        ));
    }

    /**
     * @param string                        $model          Hugging Face model id, e.g. "stabilityai/sd-turbo"
     * @param string                        $prompt         text describing the image
     * @param string                        $outputPath     image file to write, e.g. "cat.png"; its extension picks
     *                                                      the format. A leading "~" is expanded
     * @param string|null                   $negativePrompt text describing what the image should not contain
     * @param int|null                      $steps          inference steps; null uses the pipeline's default
     * @param float|null                    $guidanceScale  classifier-free guidance; null uses the pipeline's default
     * @param int|null                      $width          image width in pixels; null uses the model's native size
     * @param int|null                      $height         image height in pixels; null uses the model's native size
     * @param int|null                      $seed           random seed, for reproducible images
     * @param string|null                   $device         torch device such as "cpu", "cuda" or "mps";
     *                                                      null picks the best available
     * @param (callable(string): void)|null $onOutput       receives the runner's progress output as it arrives
     *
     * @return string absolute path of the generated image
     *
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException when the model is not a Diffusers text-to-image model
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException
     */
    public function generate(
        string $model,
        string $prompt,
        string $outputPath,
        ?string $negativePrompt = null,
        ?int $steps = null,
        ?float $guidanceScale = null,
        ?int $width = null,
        ?int $height = null,
        ?int $seed = null,
        ?string $device = null,
        ?callable $onOutput = null,
    ): string {
        $result = $this->run($model, [
            '--prompt' => $prompt,
            '--output' => Path::expandHome($outputPath),
            '--negative-prompt' => $negativePrompt,
            '--steps' => $steps,
            '--guidance' => $guidanceScale,
            '--width' => $width,
            '--height' => $height,
            '--seed' => $seed,
            '--device' => $device,
        ], $onOutput);

        if (!is_string($result['output'] ?? null)) {
            throw new RunFailedException(0, 'The runner did not report the path of the generated image.');
        }

        return $result['output'];
    }
}
