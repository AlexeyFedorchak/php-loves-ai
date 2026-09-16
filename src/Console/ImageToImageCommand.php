<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Config\ImageToImageConfig;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Runner\ImageToImage;

/**
 * CLI entry point behind `vendor/bin/loves-ai image-to-image`: enlarges or redraws an image via ImageToImage.
 */
final class ImageToImageCommand extends Command
{
    /**
     * Cozy messages shown before transforming, one picked at random: [emoji, lead ("{model}" is replaced), follow-up].
     *
     * @var list<array{string, string, string}>
     */
    public const INTROS = [
        ['🪄', 'Reworking your picture with {model}…', 'Perfect time for a cup of tea and a cookie 🍪'],
        ['🖼️', 'Touching up your image with {model}…', 'Grab a warm drink while the pixels settle ☕'],
        ['✨', 'Polishing every pixel with {model}…', 'Cozy up with some cookies — this may take a bit 🍪'],
        ['🔎', 'Making it bigger and better with {model}…', 'How about a hot chocolate while you wait? ☕'],
    ];

    protected const NAME = 'image-to-image';

    protected const DESCRIPTION = 'Enlarge an image, or redraw it following a prompt';

    protected const OPTIONS = ['output', 'prompt', 'negative-prompt', 'strength', 'steps', 'guidance', 'seed', 'device', 'log-file'];

    protected const USAGE = <<<'TXT'
        Usage: vendor/bin/loves-ai image-to-image <model> <image> [options]

        Enlarge an image, or redraw it following a prompt, with a model pulled into .local/models in the project root.

        Arguments:
          model                   Hugging Face model id, e.g. caidas/swin2SR-classical-sr-x2-64 (pull it first)
          image                   Image file to read, e.g. photo.jpg

        Options:
          --output=PATH           Image file to write (default: a timestamped .png in 'output_dir' in config/image-to-image.php)
          --prompt=TEXT           What the result should look like; needed by diffusers models such as sd-turbo,
                                  and not accepted by upscaling models
          --negative-prompt=TEXT  What the result should not contain (diffusers models)
          --strength=N            How much of the original to keep, 0 to 1; higher changes more (diffusers models)
          --steps=N               Inference steps (default: the pipeline's own)
          --guidance=SCALE        Guidance scale; turbo models use 0 (default: the pipeline's own)
          --seed=N                Random seed, for reproducible images
          --device=DEVICE         Torch device: cpu, cuda, mps... (default: the best available)
          --log-file=PATH         Append the runner's output to this file (default: 'log_file' in config/image-to-image.php)
          --debug                 Show the runner's output while working
          -h, --help              Show this help

        Values are passed to the model as-is; ones it cannot handle make the run fail.

        Environment:
          NO_COLOR                Disable colored output when set

        TXT;

    /**
     * @param ImageToImageConfig|null   $config    defaults to the package's config/image-to-image.php
     * @param resource|null             $stdout
     * @param resource|null             $stderr
     * @param (\Closure(int): int)|null $pickIntro see Command::__construct()
     * @param LocalStorage|null         $storage   defaults to the project's own
     */
    public function __construct(
        private ?ImageToImageConfig $config = null,
        $stdout = null,
        $stderr = null,
        ?\Closure $pickIntro = null,
        private readonly ?LocalStorage $storage = null,
    ) {
        parent::__construct($stdout, $stderr, $pickIntro);
    }

    protected function execute(array $positional, array $options): int
    {
        if (count($positional) !== 2) {
            throw new \InvalidArgumentException(count($positional) < 2
                ? 'Both a model and an image are required.'
                : 'Too many arguments; wrap file names with spaces in quotes.');
        }

        [$model, $image] = $positional;
        $config = $this->config ??= ImageToImageConfig::load();

        $runner = new ImageToImage($this->storage);
        $output = $options['output']
            ?? rtrim($config->outputDir, '/\\') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.png';

        $strength = self::floatOption($options, 'strength');
        $steps = self::intOption($options, 'steps');
        $guidance = self::floatOption($options, 'guidance');
        $seed = self::intOption($options, 'seed');

        $runner->ensureCanRun($model);

        $this->startLog($options['log-file'] ?? $config->logFile, "image-to-image {$model}: {$image}");
        $this->writeIntro(self::INTROS, ['{model}' => $model]);

        try {
            $file = $runner->transform(
                $model,
                $image,
                $output,
                prompt: $options['prompt'] ?? null,
                negativePrompt: $options['negative-prompt'] ?? null,
                strength: $strength,
                steps: $steps,
                guidanceScale: $guidance,
                seed: $seed,
                device: $options['device'] ?? null,
                onOutput: $this->binaryOutputHandler(),
            );
        } catch (RunFailedException $e) {
            return $this->binaryFailed("Error: failed to transform {$image} with {$model} (exit code {$e->exitCode}).");
        }

        return $this->succeeded("Image saved to {$file}");
    }

    protected function hintFor(PhpLovesAiException $e): ?string
    {
        return match (true) {
            $e instanceof ModelNotFoundException => "Pull it first with: vendor/bin/loves-ai pull {$e->model}",
            $e instanceof UnsupportedModelException => 'To try one: vendor/bin/loves-ai pull ' . ImageToImage::EXAMPLE_MODEL,
            default => parent::hintFor($e),
        };
    }

    /**
     * @param array<string, string> $options
     */
    private static function intOption(array $options, string $name): ?int
    {
        if (!isset($options[$name])) {
            return null;
        }

        $value = filter_var($options[$name], FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new \InvalidArgumentException("Option --{$name} must be an integer.");
        }

        return $value;
    }

    /**
     * @param array<string, string> $options
     */
    private static function floatOption(array $options, string $name): ?float
    {
        if (!isset($options[$name])) {
            return null;
        }

        $value = filter_var($options[$name], FILTER_VALIDATE_FLOAT);
        if ($value === false) {
            throw new \InvalidArgumentException("Option --{$name} must be a number.");
        }

        return $value;
    }
}
