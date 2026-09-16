<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Config\TextToSpeechConfig;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Runner\TextToSpeech;

/**
 * CLI entry point behind `vendor/bin/text-to-speech`: reads text aloud into an audio file via TextToSpeech.
 */
final class TextToSpeechCommand extends Command
{
    /**
     * Cozy messages shown before speaking, one picked at random: [emoji, lead ("{model}" is replaced), follow-up].
     *
     * @var list<array{string, string, string}>
     */
    public const INTROS = [
        ['🗣️', 'Finding a voice with {model}…', 'Perfect time for a cup of tea and a cookie 🍪'],
        ['🎤', 'Warming up the voice of {model}…', 'Grab a warm drink while the words are spoken ☕'],
        ['📢', 'Reading it out loud with {model}…', 'Cozy up with some cookies — this may take a bit 🍪'],
        ['🎵', 'Turning your words into sound with {model}…', 'How about a hot chocolate while you wait? ☕'],
    ];

    protected const NAME = 'text-to-speech';

    protected const OPTIONS = ['output', 'voice', 'speed', 'seed', 'device', 'log-file'];

    protected const USAGE = <<<'TXT'
        Usage: text-to-speech <model> <text> [options]

        Read text aloud into an audio file with a transformers model pulled into .local/models in the project root.

        Arguments:
          model                 Hugging Face model id, e.g. facebook/mms-tts-eng (pull it first)
          text                  Text to read aloud; wrap it in quotes

        Options:
          --output=PATH         Audio file to write; its extension picks the format: .wav, .mp3, .m4a, .flac or .ogg
                                (default: a timestamped .wav in 'output_dir' in config/text-to-speech.php)
          --voice=VOICE         Voice of models that have several, e.g. a Bark preset like v2/en_speaker_6,
                                or a speaker number
          --speed=RATE          Speaking rate of VITS-style models, e.g. 0.8 slower, 1.2 faster (default: the model's own)
          --seed=N              Random seed, for reproducible audio
          --device=DEVICE       Torch device: cpu, cuda, mps... (default: the best available)
          --log-file=PATH       Append the runner's output to this file (default: 'log_file' in config/text-to-speech.php)
          --debug               Show the runner's output while speaking
          -h, --help            Show this help

        Values are passed to the model as-is; ones it cannot handle make the run fail.

        Environment:
          NO_COLOR              Disable colored output when set

        TXT;

    /**
     * @param TextToSpeechConfig|null   $config    defaults to the package's config/text-to-speech.php
     * @param resource|null             $stdout
     * @param resource|null             $stderr
     * @param (\Closure(int): int)|null $pickIntro see Command::__construct()
     * @param LocalStorage|null         $storage   defaults to the project's own
     */
    public function __construct(
        private ?TextToSpeechConfig $config = null,
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
                ? 'Both a model and a text are required.'
                : 'Too many arguments; wrap the text in quotes.');
        }

        [$model, $text] = $positional;
        $config = $this->config ??= TextToSpeechConfig::load();

        $runner = new TextToSpeech($this->storage);
        $output = $options['output']
            ?? rtrim($config->outputDir, '/\\') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.wav';
        $speed = self::floatOption($options, 'speed');
        $seed = self::intOption($options, 'seed');

        $runner->ensureCanRun($model);

        $this->startLog($options['log-file'] ?? $config->logFile, "text-to-speech {$model}: {$text}");
        $this->writeIntro(self::INTROS, ['{model}' => $model]);

        try {
            $file = $runner->speak(
                $model,
                $text,
                $output,
                voice: $options['voice'] ?? null,
                speed: $speed,
                seed: $seed,
                device: $options['device'] ?? null,
                onOutput: $this->binaryOutputHandler(),
            );
        } catch (RunFailedException $e) {
            return $this->binaryFailed("Error: failed to read the text aloud with {$model} (exit code {$e->exitCode}).");
        }

        return $this->succeeded("Audio saved to {$file}");
    }

    protected function hintFor(PhpLovesAiException $e): ?string
    {
        return match (true) {
            $e instanceof ModelNotFoundException => "Pull it first with: vendor/bin/pull {$e->model}",
            $e instanceof UnsupportedModelException => 'To try one: vendor/bin/pull ' . TextToSpeech::EXAMPLE_MODEL,
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
