<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Config\JevConfig;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Runner\Jev;

/**
 * CLI entry point behind `vendor/bin/loves-ai jev`: answers a question by choosing one of its options via Jev, and
 * prints the choice with the probability of every option.
 */
final class JevCommand extends Command
{
    /**
     * Cozy messages shown before deciding, one picked at random: [emoji, lead ("{model}" is replaced), follow-up].
     *
     * @var list<array{string, string, string}>
     */
    public const INTROS = [
        ['🤔', 'Weighing the options with {model}…', 'Perfect time for a cup of tea and a cookie 🍪'],
        ['⚖️', 'Thinking it over with {model}…', 'Grab a warm drink while it makes up its mind ☕'],
        ['🎯', 'Picking the best answer with {model}…', 'Cozy up with some cookies — this may take a bit 🍪'],
        ['🧐', 'Considering every option with {model}…', 'How about a hot chocolate while you wait? ☕'],
    ];

    protected const NAME = 'jev';

    protected const DESCRIPTION = 'Answer a question by choosing one of its options';

    protected const OPTIONS = ['state', 'media', 'modality', 'frames', 'device', 'log-file'];

    protected const USAGE = <<<'TXT'
        Usage: vendor/bin/loves-ai jev <model> <question> <option> <option> [option ...] [options]

        Answer a question by choosing one of its options, with a JEV decision classifier pulled into .local/models in
        the project root. Every option gets a probability; the likeliest is the answer.

        Arguments:
          model                 Hugging Face model id, e.g. akhilaaa3/Jev-Omni (pull it first)
          question              The question, wrapped in quotes, e.g. "Has the meeting started?"
          option                Two or more answers to choose from, e.g. Yes No; wrap ones with spaces in quotes

        Options:
          --state=TEXT          What the question is about, e.g. "The meeting starts at 10 AM. It is now 9 AM."
          --media=PATH          Image, audio or video file the question is about
          --modality=KIND       What the media is: image, audio or video (default: told by the file's extension)
          --frames=N            Frames sampled from a video (default: 16)
          --device=DEVICE       Torch device: cpu, cuda, mps... (default: the best available)
          --log-file=PATH       Append the runner's output to this file (default: 'log_file' in config/jev.php)
          --debug               Show the runner's output while deciding
          -h, --help            Show this help

        Audio is read up to its first 30 seconds. Models answer best with up to 20 options.

        Environment:
          NO_COLOR              Disable colored output when set

        TXT;

    /**
     * @param JevConfig|null            $config    defaults to the package's config/jev.php
     * @param resource|null             $stdout
     * @param resource|null             $stderr
     * @param (\Closure(int): int)|null $pickIntro see Command::__construct()
     * @param LocalStorage|null         $storage   defaults to the project's own
     */
    public function __construct(
        private ?JevConfig $config = null,
        $stdout = null,
        $stderr = null,
        ?\Closure $pickIntro = null,
        private readonly ?LocalStorage $storage = null,
    ) {
        parent::__construct($stdout, $stderr, $pickIntro);
    }

    protected function execute(array $positional, array $options): int
    {
        if (count($positional) < 4) {
            throw new \InvalidArgumentException(count($positional) < 2
                ? 'A model and a question are required.'
                : 'At least two options are needed to choose from.');
        }

        [$model, $question] = $positional;
        $choices = array_slice($positional, 2);
        $modality = $options['modality'] ?? null;
        $config = $this->config ??= JevConfig::load();

        $runner = new Jev($this->storage);

        $frames = null;
        if (isset($options['frames'])) {
            $frames = filter_var($options['frames'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($frames === false) {
                throw new \InvalidArgumentException('Option --frames must be a positive integer.');
            }
        }

        $runner->ensureCanDecide($model, $choices, $modality);

        $this->startLog($options['log-file'] ?? $config->logFile, "jev {$model}: {$question} [" . implode(' | ', $choices) . ']');
        $this->writeIntro(self::INTROS, ['{model}' => $model]);

        try {
            $decision = $runner->decide(
                $model,
                $question,
                $choices,
                state: $options['state'] ?? null,
                mediaPath: $options['media'] ?? null,
                modality: $modality,
                frames: $frames,
                device: $options['device'] ?? null,
                onOutput: $this->binaryOutputHandler(),
            );
        } catch (RunFailedException $e) {
            return $this->binaryFailed("Error: failed to answer the question with {$model} (exit code {$e->exitCode}).");
        }

        $exitCode = $this->succeeded(sprintf('%s chose: %s (%s)', $model, $decision->prediction, self::percent($decision->confidence)));

        // By position: options such as "1" become integer keys of $decision->probabilities.
        $width = max(array_map(mb_strlen(...), $choices));
        foreach (array_values($decision->probabilities) as $i => $probability) {
            $padding = str_repeat(' ', $width - mb_strlen($choices[$i]));
            $this->writeLine(sprintf('   %s %s%s  %6s', $i === $decision->index ? '👉' : '  ', $choices[$i], $padding, self::percent($probability)));
        }

        return $exitCode;
    }

    protected function hintFor(PhpLovesAiException $e): ?string
    {
        return match (true) {
            $e instanceof ModelNotFoundException => "Pull it first with: vendor/bin/loves-ai pull {$e->model}",
            $e instanceof UnsupportedModelException => 'To try one: vendor/bin/loves-ai pull ' . Jev::EXAMPLE_MODEL,
            default => parent::hintFor($e),
        };
    }

    private static function percent(float $probability): string
    {
        return sprintf('%.1f%%', $probability * 100);
    }
}
