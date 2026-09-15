<?php

declare(strict_types=1);

namespace PhpLovesAi\Process;

use PhpLovesAi\Binary\BinaryStore;
use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotFoundException;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\HomeDirectoryNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Filesystem\Path;
use Symfony\Component\Process\Process;

/**
 * Runs the compiled text-to-image binary to generate an image with a locally pulled diffusion model.
 *
 * Input is passed to the model as-is: values it cannot handle (e.g. a prompt that is too long) make the run
 * fail with a RunFailedException.
 */
final class TextToImageRunner
{
    private readonly string $modelsDir;

    /**
     * @param string     $binaryPath path to the compiled text-to-image binary
     * @param string     $modelsDir  directory models were pulled into; a model is loaded from <modelsDir>/<model id>.
     *                               A leading "~" is expanded to the user's home directory
     * @param float|null $timeout    seconds before the run is aborted; null waits indefinitely
     *
     * @throws HomeDirectoryNotFoundException
     */
    public function __construct(
        private readonly string $binaryPath,
        string $modelsDir,
        private readonly ?float $timeout = null,
    ) {
        $this->modelsDir = Path::expandHome($modelsDir);
    }

    /**
     * A runner using the binary installed by `vendor/bin/setup text-to-image`.
     *
     * @param BinaryStore|null $store defaults to the standard install location
     *
     * @throws BinaryNotInstalledException
     * @throws HomeDirectoryNotFoundException
     */
    public static function installed(string $modelsDir, ?float $timeout = null, ?BinaryStore $store = null): self
    {
        $store ??= new BinaryStore();
        if (!$store->isInstalled(Tool::TextToImage)) {
            throw new BinaryNotInstalledException(Tool::TextToImage);
        }

        return new self($store->path(Tool::TextToImage), $modelsDir, $timeout);
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
     * @throws ModelNotFoundException
     * @throws BinaryNotFoundException
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
        $this->ensureCanRun($model);

        $command = [
            $this->binaryPath,
            '--model', $this->modelPath($model),
            '--prompt', $prompt,
            '--output', Path::expandHome($outputPath),
        ];

        $optional = [
            '--negative-prompt' => $negativePrompt,
            '--steps' => $steps,
            '--guidance' => $guidanceScale,
            '--width' => $width,
            '--height' => $height,
            '--seed' => $seed,
            '--device' => $device,
        ];
        foreach ($optional as $name => $value) {
            if ($value !== null) {
                array_push($command, $name, (string) $value);
            }
        }

        $process = new Process($command, timeout: $this->timeout);
        $process->run(static function (string $type, string $buffer) use ($onOutput): void {
            if ($onOutput !== null && $type === Process::ERR) {
                $onOutput($buffer);
            }
        });

        $exitCode = $process->getExitCode() ?? -1;
        $image = self::parseOutputPath($process->getOutput());

        if ($exitCode !== 0 || $image === null) {
            throw new RunFailedException($exitCode, $process->getErrorOutput());
        }

        return $image;
    }

    /**
     * Runs the checks generate() performs before starting the binary, without generating anything.
     *
     * @throws ModelNotFoundException
     * @throws BinaryNotFoundException
     */
    public function ensureCanRun(string $model): void
    {
        $modelPath = $this->modelPath($model);
        if (!is_dir($modelPath)) {
            throw new ModelNotFoundException($model, $modelPath);
        }

        if (!is_file($this->binaryPath) || !is_executable($this->binaryPath)) {
            throw BinaryNotFoundException::atPath($this->binaryPath);
        }
    }

    public function modelPath(string $model): string
    {
        return rtrim($this->modelsDir, '/\\') . '/' . $model;
    }

    /**
     * Libraries may print to stdout too, so the result is the last line that is the runner's JSON object.
     */
    private static function parseOutputPath(string $output): ?string
    {
        $lines = preg_split('/\R/', $output, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (array_reverse($lines) as $line) {
            $result = json_decode($line, true);

            if (is_array($result) && is_string($result['output'] ?? null)) {
                return $result['output'];
            }
        }

        return null;
    }
}
