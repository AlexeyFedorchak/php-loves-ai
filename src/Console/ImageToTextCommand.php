<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Config\ImageToTextConfig;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Runner\ImageToText;

/**
 * CLI entry point behind `vendor/bin/image-to-text`: describes an image, or answers a question about it, via
 * ImageToText and prints the text.
 */
final class ImageToTextCommand extends Command
{
    /**
     * Cozy messages shown before generating, one picked at random: [emoji, lead ("{model}" is replaced), follow-up].
     *
     * @var list<array{string, string, string}>
     */
    public const INTROS = [
        ['🔍', 'Taking a close look with {model}…', 'Perfect time for a cup of tea and a cookie 🍪'],
        ['👀', 'Studying your picture with {model}…', 'Grab a warm drink while it finds the right words ☕'],
        ['🖼️', 'Admiring your image with {model}…', 'Cozy up with some cookies — this may take a bit 🍪'],
        ['🧐', 'Looking at every detail with {model}…', 'How about a hot chocolate while you wait? ☕'],
    ];

    protected const NAME = 'image-to-text';

    protected const OPTIONS = ['max-new-tokens', 'temperature', 'seed', 'device', 'log-file'];

    protected const USAGE = <<<'TXT'
        Usage: image-to-text <model> <image> [prompt] [options]

        Describe an image, or answer a question about it, with a transformers model pulled into .local/models in the
        project root.

        Arguments:
          model                 Hugging Face model id, e.g. HuggingFaceTB/SmolVLM-256M-Instruct (pull it first)
          image                 Image file to read, e.g. photo.jpg
          prompt                Question about the image, wrapped in quotes (default: describe it); for captioning
                                models such as BLIP, the start of the caption instead

        Options:
          --max-new-tokens=N    Maximum length of the text in tokens (default: 256)
          --temperature=T       Randomness: 0 always picks the likeliest words (default: the model's own)
          --seed=N              Random seed, for reproducible text
          --device=DEVICE       Torch device: cpu, cuda, mps... (default: the best available)
          --log-file=PATH       Append the runner's output to this file (default: 'log_file' in config/image-to-text.php)
          --debug               Show the runner's output, and the text as it is written
          -h, --help            Show this help

        Values are passed to the model as-is; ones it cannot handle make the run fail.

        Environment:
          NO_COLOR              Disable colored output when set

        TXT;

    /**
     * @param ImageToTextConfig|null    $config    defaults to the package's config/image-to-text.php
     * @param resource|null             $stdout
     * @param resource|null             $stderr
     * @param (\Closure(int): int)|null $pickIntro see Command::__construct()
     * @param LocalStorage|null         $storage   defaults to the project's own
     */
    public function __construct(
        private ?ImageToTextConfig $config = null,
        $stdout = null,
        $stderr = null,
        ?\Closure $pickIntro = null,
        private readonly ?LocalStorage $storage = null,
    ) {
        parent::__construct($stdout, $stderr, $pickIntro);
    }

    protected function execute(array $positional, array $options): int
    {
        if (count($positional) < 2 || count($positional) > 3) {
            throw new \InvalidArgumentException(count($positional) < 2
                ? 'Both a model and an image are required.'
                : 'Too many arguments; wrap the prompt in quotes.');
        }

        [$model, $image] = $positional;
        $prompt = $positional[2] ?? null;
        $config = $this->config ??= ImageToTextConfig::load();

        $runner = new ImageToText($this->storage);

        $maxNewTokens = self::intOption($options, 'max-new-tokens');
        $temperature = self::floatOption($options, 'temperature');
        $seed = self::intOption($options, 'seed');

        $runner->ensureCanRun($model);

        $this->startLog($options['log-file'] ?? $config->logFile, "image-to-text {$model}: {$image}" . ($prompt !== null ? " ({$prompt})" : ''));
        $this->writeIntro(self::INTROS, ['{model}' => $model]);

        try {
            $text = $runner->generate(
                $model,
                $image,
                $prompt,
                maxNewTokens: $maxNewTokens,
                temperature: $temperature,
                seed: $seed,
                device: $options['device'] ?? null,
                onOutput: $this->binaryOutputHandler(),
            );
        } catch (RunFailedException $e) {
            return $this->binaryFailed("Error: failed to read the image with {$model} (exit code {$e->exitCode}).");
        }

        $exitCode = $this->succeeded("{$model} says:");
        $this->writeLine($text);

        return $exitCode;
    }

    protected function hintFor(PhpLovesAiException $e): ?string
    {
        return match (true) {
            $e instanceof ModelNotFoundException => "Pull it first with: vendor/bin/pull {$e->model}",
            $e instanceof UnsupportedModelException => 'To try one: vendor/bin/pull ' . ImageToText::EXAMPLE_MODEL,
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
