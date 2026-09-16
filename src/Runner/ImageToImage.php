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
 * Turns an image into another image with models pulled into the project, through the runner installed by
 * `vendor/bin/loves-ai setup image-to-image`.
 *
 *     $bigger = (new ImageToImage())->transform('caidas/swin2SR-classical-sr-x2-64', 'photo.jpg', 'photo-2x.png');
 *
 * Two kinds of models work:
 *   - upscaling models (Hugging Face task "image-to-image", e.g. Swin2SR), which enlarge and clean up an image and
 *     take no prompt;
 *   - diffusers image-to-image pipelines (e.g. stabilityai/sd-turbo, timbrooks/instruct-pix2pix), which redraw the
 *     image following a prompt.
 *
 * Input is passed to the model as-is: values it cannot handle make the run fail with a RunFailedException.
 */
final class ImageToImage extends BinaryRunner
{
    /** A model the runner can load; used in error messages and hints. */
    public const EXAMPLE_MODEL = 'caidas/swin2SR-classical-sr-x2-64';

    /** Hugging Face models this runner can use. */
    public const COMPATIBLE_MODELS_URL = 'https://huggingface.co/models?pipeline_tag=image-to-image';

    /** Transformers architectures that turn an image into an image; diffusers pipelines are recognized separately. */
    private const SUPPORTED_ARCHITECTURE = 'SuperResolution';

    public static function tool(): Tool
    {
        return Tool::ImageToImage;
    }

    /**
     * @param string                        $model          Hugging Face model id, e.g. "caidas/swin2SR-classical-sr-x2-64"
     * @param string                        $imagePath      image file to read; a leading "~" is expanded
     * @param string                        $outputPath     image file to write, e.g. "big.png"; its extension picks the
     *                                                      format. A leading "~" is expanded
     * @param string|null                   $prompt         what the result should look like; needed by diffusers models,
     *                                                      and rejected by upscaling models
     * @param string|null                   $negativePrompt what the result should not contain (diffusers models)
     * @param float|null                    $strength       how much of the original to keep, 0 to 1; higher changes more
     * @param int|null                      $steps          inference steps; null uses the pipeline's default
     * @param float|null                    $guidanceScale  classifier-free guidance; null uses the pipeline's default
     * @param int|null                      $seed           random seed, for reproducible images
     * @param string|null                   $device         torch device such as "cpu", "cuda" or "mps"; null picks the best
     * @param (callable(string): void)|null $onOutput       receives the runner's progress output as it arrives
     *
     * @return string absolute path of the written image
     *
     * @throws ImageNotFoundException
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException when the model cannot turn an image into an image
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException e.g. when a prompt is missing, or given to a model that takes none
     */
    public function transform(
        string $model,
        string $imagePath,
        string $outputPath,
        ?string $prompt = null,
        ?string $negativePrompt = null,
        ?float $strength = null,
        ?int $steps = null,
        ?float $guidanceScale = null,
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
            '--output' => Path::expandHome($outputPath),
            '--prompt' => $prompt,
            '--negative-prompt' => $negativePrompt,
            '--strength' => $strength,
            '--steps' => $steps,
            '--guidance' => $guidanceScale,
            '--seed' => $seed,
            '--device' => $device,
        ], $onOutput);

        if (!is_string($result['output'] ?? null)) {
            throw new RunFailedException(0, 'The runner did not report the path of the written image.');
        }

        return $result['output'];
    }

    /**
     * Diffusers pipelines are taken as they are; transformers models must be built to output an image.
     */
    protected function ensureModelIsSupported(string $model, string $modelPath): void
    {
        if (TransformersModel::has($modelPath, 'model_index.json')) {
            return;
        }

        $problem = TransformersModel::problem($modelPath) ?? self::imageOutputProblem($modelPath);

        if ($problem === null) {
            return;
        }

        throw new UnsupportedModelException($model, $modelPath, sprintf(
            '%s cannot turn an image into an image: %s Use an image-to-image model instead, e.g. %s (browse: %s).',
            $model,
            $problem,
            self::EXAMPLE_MODEL,
            self::COMPATIBLE_MODELS_URL,
        ));
    }

    private static function imageOutputProblem(string $modelPath): ?string
    {
        $architectures = TransformersModel::config($modelPath)['architectures'] ?? [];

        if (is_array($architectures)) {
            foreach ($architectures as $architecture) {
                if (is_string($architecture) && str_contains($architecture, self::SUPPORTED_ARCHITECTURE)) {
                    return null;
                }
            }
        }

        return 'it does not produce images. Upscaling models (such as Swin2SR) and diffusers image-to-image pipelines do.';
    }
}
