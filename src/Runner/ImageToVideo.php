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
 * Animates an image into a video with diffusers pipelines pulled into the project, through the runner installed by
 * `vendor/bin/setup image-to-video`.
 *
 *     $clip = (new ImageToVideo())->generate('Wan-AI/Wan2.1-I2V-14B-480P-Diffusers', 'photo.jpg', 'clip.mp4');
 *
 * Works with diffusers image-to-video pipelines: some animate the image on their own (Stable Video Diffusion), while
 * others also follow a prompt (Wan I2V, CogVideoX I2V, LTX-Video). The output file's extension picks the format:
 * .mp4, .webm, .mkv or .gif.
 *
 * Input is passed to the pipeline as-is: values it cannot handle make the run fail with a RunFailedException.
 */
final class ImageToVideo extends BinaryRunner
{
    /** A model the runner can load; used in error messages and hints. */
    public const EXAMPLE_MODEL = 'stabilityai/stable-video-diffusion-img2vid-xt';

    /** Hugging Face models this runner can use. */
    public const COMPATIBLE_MODELS_URL = 'https://huggingface.co/models?pipeline_tag=image-to-video&library=diffusers';

    public static function tool(): Tool
    {
        return Tool::ImageToVideo;
    }

    /**
     * @param string                        $model          Hugging Face model id, e.g. "stabilityai/stable-video-diffusion-img2vid-xt"
     * @param string                        $imagePath      image to animate; a leading "~" is expanded
     * @param string                        $outputPath     video file to write, e.g. "clip.mp4"; its extension picks the
     *                                                      format. A leading "~" is expanded
     * @param string|null                   $prompt         text describing the video, for models that take one; models
     *                                                      that animate the image on their own reject it
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
     * @throws ImageNotFoundException
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException when the model is not a diffusers video pipeline
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException e.g. when the model takes no prompt, or for an output format it cannot write
     */
    public function generate(
        string $model,
        string $imagePath,
        string $outputPath,
        ?string $prompt = null,
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
        $imagePath = Path::expandHome($imagePath);
        if (!is_file($imagePath) || !is_readable($imagePath)) {
            throw new ImageNotFoundException($imagePath);
        }

        $result = $this->run($model, [
            '--image' => $imagePath,
            '--output' => Path::expandHome($outputPath),
            '--prompt' => $prompt,
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
     * The runner needs a diffusers pipeline; still-image pipelines belong to image-to-image.
     */
    protected function ensureModelIsSupported(string $model, string $modelPath): void
    {
        $problem = TextToVideo::videoPipelineProblem($modelPath, 'image-to-image');

        if ($problem === null) {
            return;
        }

        throw new UnsupportedModelException($model, $modelPath, sprintf(
            '%s cannot animate an image: %s Use a diffusers image-to-video model instead, e.g. %s (browse: %s).',
            $model,
            $problem,
            self::EXAMPLE_MODEL,
            self::COMPATIBLE_MODELS_URL,
        ));
    }

}
