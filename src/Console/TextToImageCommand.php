<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Config\TextToImageConfig;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\LocalStorage;
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
        'output', 'negative-prompt', 'steps', 'guidance', 'width', 'height', 'seed', 'device', 'log-file',
    ];

    protected const USAGE = <<<'TXT'
        Usage: text-to-image <model> <prompt> [options]

        Generate an image from a text prompt with a diffusion model pulled into .local/models in the project root.

        Arguments:
          model                   Hugging Face model id, e.g. stabilityai/sd-turbo (pull it first)
          prompt                  Text describing the image; wrap it in quotes

        Options:
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
     * @param LocalStorage|null         $storage   defaults to the project's own
     */
    public function __construct(
        private ?TextToImageConfig $config = null,
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
                ? 'Both a model and a prompt are required.'
                : 'Too many arguments; wrap the prompt in quotes.');
        }

        [$model, $prompt] = $positional;
        $config = $this->config ??= TextToImageConfig::load();

        $runner = new TextToImage($this->storage);
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
            $e instanceof ModelNotFoundException => "Pull it first with: vendor/bin/pull {$e->model}",
            $e instanceof UnsupportedModelException => 'To try one: vendor/bin/pull ' . TextToImage::EXAMPLE_MODEL,
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
