<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Binary\BinaryStore;
use PhpLovesAi\Config\TextToImageConfig;
use PhpLovesAi\Exception\BinaryNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Runner\TextToImage;

/**
 * CLI entry point behind `vendor/bin/text-to-image`: generates one image via TextToImage.
 */
final class TextToImageCommand extends Command
{
    /**
     * Cozy messages shown before generating, one picked at random: [emoji, lead ("{model}" is replaced), follow-up].
     *
     * @var list<array{string, string, string}>
     */
    public const INTROS = [
        ['🎨', 'Painting your image with {model}…', 'Masterpieces take a moment — perfect time for a hot chocolate and a cookie 🍪'],
        ['🖌️', 'Mixing the colors with {model}…', 'Grab a cup of tea and let the magic happen ☕✨'],
        ['🌈', 'Dreaming up your picture with {model}…', 'Cozy up with some cookies — this may take a bit 🍪'],
        ['🧁', 'Baking your image with {model}…', 'Fresh pixels are in the oven — how about a warm drink while you wait? ☕'],
    ];

    protected const NAME = 'text-to-image';

    protected const OPTIONS = [
        'dir', 'output', 'negative-prompt', 'steps', 'guidance', 'width', 'height', 'seed', 'device', 'log-file',
    ];

    protected const USAGE = <<<'TXT'
        Usage: text-to-image <model> <prompt> [options]

        Generate an image from a text prompt with a pulled diffusion model.

        Arguments:
          model                   Hugging Face model id, e.g. stabilityai/sd-turbo (pull it first)
          prompt                  Text describing the image; wrap it in quotes

        Options:
          --dir=DIR               Directory the model was pulled into (default: 'models_dir' in config/text-to-image.php)
          --output=PATH           Image file to write (default: a timestamped .png in 'output_dir' in config/text-to-image.php)
          --negative-prompt=TEXT  Text describing what the image should not contain
          --steps=N               Inference steps (default: the model's own)
          --guidance=SCALE        Guidance scale, e.g. 7.5; turbo models use 0 (default: the model's own)
          --width=PX              Image width (default: the model's native size)
          --height=PX             Image height (default: the model's native size)
          --seed=N                Random seed, for reproducible images
          --device=DEVICE         Torch device: cpu, cuda, mps... (default: the best available)
          --log-file=PATH         Append the runner's output to this file (default: 'log_file' in config/text-to-image.php)
          --debug                 Show the runner's output while generating
          -h, --help              Show this help

        Values are passed to the model as-is; ones it cannot handle make the run fail.

        Environment:
          NO_COLOR                Disable colored output when set

        TXT;

    /**
     * @param TextToImageConfig|null    $config    defaults to the package's config/text-to-image.php
     * @param resource|null             $stdout
     * @param resource|null             $stderr
     * @param (\Closure(int): int)|null $pickIntro see Command::__construct()
     * @param BinaryStore|null          $store     where to find the installed runner; defaults to the project's own
     */
    public function __construct(
        private ?TextToImageConfig $config = null,
        $stdout = null,
        $stderr = null,
        ?\Closure $pickIntro = null,
        private readonly ?BinaryStore $store = null,
    ) {
        parent::__construct($stdout, $stderr, $pickIntro);
    }

    protected function execute(array $positional, array $options): int
    {
        if (count($positional) !== 2) {
            throw new \InvalidArgumentException(count($positional) < 2
                ? 'Both a model and a prompt are required.'
                : 'Too many arguments; wrap the prompt in quotes.');
        }

        [$model, $prompt] = $positional;
        $config = $this->config ??= TextToImageConfig::load();

        $modelsDir = $options['dir'] ?? $config->modelsDir;
        $runner = $config->binary !== null
            ? new TextToImage($config->binary, $modelsDir)
            : TextToImage::installed($modelsDir, store: $this->store);
        $output = $options['output']
            ?? rtrim($config->outputDir, '/\\') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.png';

        $steps = self::intOption($options, 'steps');
        $guidance = self::floatOption($options, 'guidance');
        $width = self::intOption($options, 'width');
        $height = self::intOption($options, 'height');
        $seed = self::intOption($options, 'seed');

        $runner->ensureCanRun($model);

        $this->startLog($options['log-file'] ?? $config->logFile, "text-to-image {$model}: {$prompt}");
        $this->writeIntro(self::INTROS, ['{model}' => $model]);

        try {
            $image = $runner->generate(
                $model,
                $prompt,
                $output,
                negativePrompt: $options['negative-prompt'] ?? null,
                steps: $steps,
                guidanceScale: $guidance,
                width: $width,
                height: $height,
                seed: $seed,
                device: $options['device'] ?? null,
                onOutput: $this->binaryOutputHandler(),
            );
        } catch (RunFailedException $e) {
            return $this->binaryFailed("Error: failed to generate an image with {$model} (exit code {$e->exitCode}).");
        }

        return $this->succeeded("Image saved to {$image}");
    }

    protected function hintFor(PhpLovesAiException $e): ?string
    {
        return match (true) {
            $e instanceof ModelNotFoundException => "Pull it first with: pull {$e->model} (or pass --dir if it was pulled elsewhere).",
            $e instanceof BinaryNotFoundException => "Check 'binary' in config/text-to-image.php, or set it to null to use the one installed by vendor/bin/setup text-to-image.",
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
