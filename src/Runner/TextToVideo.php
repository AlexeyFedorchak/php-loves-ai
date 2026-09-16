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
 * Generates videos from a text prompt with diffusers pipelines pulled into the project, through the runner installed
 * by `vendor/bin/setup text-to-video`.
 *
 *     $clip = (new TextToVideo())->generate('Wan-AI/Wan2.1-T2V-1.3B-Diffusers', 'a cat walking', 'cat.mp4');
 *
 * Works with diffusers text-to-video pipelines, e.g. Wan, LTX-Video, CogVideoX or AnimateDiff. The output file's
 * extension picks the format: .mp4, .webm, .mkv or .gif.
 *
 * Input is passed to the pipeline as-is: values it cannot handle make the run fail with a RunFailedException.
 */
final class TextToVideo extends BinaryRunner
{
    /** A model the runner can load; used in error messages and hints. */
    public const EXAMPLE_MODEL = 'Wan-AI/Wan2.1-T2V-1.3B-Diffusers';

    /** Hugging Face models this runner can use. */
    public const COMPATIBLE_MODELS_URL = 'https://huggingface.co/models?pipeline_tag=text-to-video&library=diffusers';

    public static function tool(): Tool
    {
        return Tool::TextToVideo;
    }

    /**
     * @param string                        $model          Hugging Face model id, e.g. "Wan-AI/Wan2.1-T2V-1.3B-Diffusers"
     * @param string                        $prompt         text describing the video
     * @param string                        $outputPath     video file to write, e.g. "clip.mp4"; its extension picks the
     *                                                      format. A leading "~" is expanded
     * @param string|null                   $negativePrompt text describing what the video should not contain
     * @param int|null                      $frames         number of frames to generate; null uses the pipeline's default
     * @param int|null                      $fps            frames per second of the written video; null writes 8
     * @param int|null                      $steps          inference steps; null uses the pipeline's default
     * @param float|null                    $guidanceScale  classifier-free guidance; null uses the pipeline's default
     * @param int|null                      $width          frame width in pixels; null uses the pipeline's default
     * @param int|null                      $height         frame height in pixels; null uses the pipeline's default
     * @param int|null                      $seed           random seed, for reproducible videos
     * @param string|null                   $device         torch device such as "cpu", "cuda" or "mps"; null picks the best
     * @param (callable(string): void)|null $onOutput       receives the runner's progress output as it arrives
     *
     * @return string absolute path of the written video
     *
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException when the model is not a diffusers video pipeline
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException e.g. for an output format the runner cannot write
     */
    public function generate(
        string $model,
        string $prompt,
        string $outputPath,
        ?string $negativePrompt = null,
        ?int $frames = null,
        ?int $fps = null,
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
            '--frames' => $frames,
            '--fps' => $fps,
            '--steps' => $steps,
            '--guidance' => $guidanceScale,
            '--width' => $width,
            '--height' => $height,
            '--seed' => $seed,
            '--device' => $device,
        ], $onOutput);

        if (!is_string($result['output'] ?? null)) {
            throw new RunFailedException(0, 'The runner did not report the path of the written video.');
        }

        return $result['output'];
    }

    /**
     * The runner needs a diffusers pipeline; still-image pipelines belong to text-to-image.
     */
    protected function ensureModelIsSupported(string $model, string $modelPath): void
    {
        $problem = self::videoPipelineProblem($modelPath, 'text-to-image');

        if ($problem === null) {
            return;
        }

        throw new UnsupportedModelException($model, $modelPath, sprintf(
            '%s cannot generate video: %s Use a diffusers video model instead, e.g. %s (browse: %s).',
            $model,
            $problem,
            self::EXAMPLE_MODEL,
            self::COMPATIBLE_MODELS_URL,
        ));
    }

    /**
     * Shared with ImageToVideo: what stops this model from being used as a video pipeline.
     *
     * @param string $stillImageRunner the runner to suggest for pipelines that make a single image
     */
    public static function videoPipelineProblem(string $modelPath, string $stillImageRunner): ?string
    {
        if (!DiffusersModel::isPipeline($modelPath)) {
            return TransformersModel::has($modelPath, '*.gguf')
                ? 'it is in GGUF format, which is made for tools like ComfyUI, not diffusers.'
                : "it is not a diffusers pipeline ({$modelPath}/model_index.json is missing). It may be a single-file checkpoint, a LoRA, or a pull that did not finish.";
        }

        if (DiffusersModel::isStillImagePipeline($modelPath)) {
            return sprintf('it generates still images (%s); use it with %s.', DiffusersModel::className($modelPath), $stillImageRunner);
        }

        return null;
    }
}
