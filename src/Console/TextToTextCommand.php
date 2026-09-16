<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Config\TextToTextConfig;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Runner\TextToText;

/**
 * CLI entry point behind `vendor/bin/loves-ai text-to-text`: generates text via TextToText and prints it.
 */
final class TextToTextCommand extends Command
{
    /**
     * Cozy messages shown before generating, one picked at random: [emoji, lead ("{model}" is replaced), follow-up].
     *
     * @var list<array{string, string, string}>
     */
    public const INTROS = [
        ['✍️', 'Writing with {model}…', 'Good words take a moment — perfect time for a cup of tea and a cookie 🍪'],
        ['📝', 'Thinking it over with {model}…', 'Grab a warm drink while the words find their place ☕'],
        ['🪶', 'Putting pen to paper with {model}…', 'Cozy up with some cookies — this may take a bit 🍪'],
        ['💭', 'Gathering thoughts with {model}…', 'How about a hot chocolate while you wait? ☕'],
    ];

    protected const NAME = 'text-to-text';

    protected const DESCRIPTION = 'Generate text: answer a prompt or continue it';

    protected const OPTIONS = ['system', 'max-new-tokens', 'temperature', 'top-p', 'seed', 'device', 'log-file'];

    protected const USAGE = <<<'TXT'
        Usage: vendor/bin/loves-ai text-to-text <model> <prompt> [options]

        Generate text from a prompt with a transformers model pulled into .local/models in the project root.
        Chat models answer the prompt; other models continue it.

        Arguments:
          model                 Hugging Face model id, e.g. Qwen/Qwen2.5-0.5B-Instruct (pull it first)
          prompt                Text to respond to or continue; wrap it in quotes

        Options:
          --system=TEXT         Instructions for chat models, e.g. "You are a helpful assistant."
          --max-new-tokens=N    Maximum length of the answer in tokens (default: 256)
          --temperature=T       Randomness: 0 always picks the likeliest words (default: the model's own)
          --top-p=P             Nucleus sampling probability, e.g. 0.9 (default: the model's own)
          --seed=N              Random seed, for reproducible text
          --device=DEVICE       Torch device: cpu, cuda, mps... (default: the best available)
          --log-file=PATH       Append the runner's output to this file (default: 'log_file' in config/text-to-text.php)
          --debug               Show the runner's output, and the text as it is written
          -h, --help            Show this help

        Values are passed to the model as-is; ones it cannot handle make the run fail.

        Environment:
          NO_COLOR              Disable colored output when set

        TXT;

    /**
     * @param TextToTextConfig|null     $config    defaults to the package's config/text-to-text.php
     * @param resource|null             $stdout
     * @param resource|null             $stderr
     * @param (\Closure(int): int)|null $pickIntro see Command::__construct()
     * @param LocalStorage|null         $storage   defaults to the project's own
     */
    public function __construct(
        private ?TextToTextConfig $config = null,
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
        $config = $this->config ??= TextToTextConfig::load();

        $runner = new TextToText($this->storage);

        $maxNewTokens = self::intOption($options, 'max-new-tokens');
        $temperature = self::floatOption($options, 'temperature');
        $topP = self::floatOption($options, 'top-p');
        $seed = self::intOption($options, 'seed');

        $runner->ensureCanRun($model);

        $this->startLog($options['log-file'] ?? $config->logFile, "text-to-text {$model}: {$prompt}");
        $this->writeIntro(self::INTROS, ['{model}' => $model]);

        try {
            $text = $runner->generate(
                $model,
                $prompt,
                systemPrompt: $options['system'] ?? null,
                maxNewTokens: $maxNewTokens,
                temperature: $temperature,
                topP: $topP,
                seed: $seed,
                device: $options['device'] ?? null,
                onOutput: $this->binaryOutputHandler(),
            );
        } catch (RunFailedException $e) {
            return $this->binaryFailed("Error: failed to generate text with {$model} (exit code {$e->exitCode}).");
        }

        $exitCode = $this->succeeded("{$model} wrote:");
        $this->writeLine($text);

        return $exitCode;
    }

    protected function hintFor(PhpLovesAiException $e): ?string
    {
        return match (true) {
            $e instanceof ModelNotFoundException => "Pull it first with: vendor/bin/loves-ai pull {$e->model}",
            $e instanceof UnsupportedModelException => 'To try one: vendor/bin/loves-ai pull ' . TextToText::EXAMPLE_MODEL,
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
