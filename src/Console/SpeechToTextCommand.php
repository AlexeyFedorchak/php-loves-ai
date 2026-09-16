<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Config\SpeechToTextConfig;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Runner\SpeechToText;

/**
 * CLI entry point behind `vendor/bin/loves-ai speech-to-text`: transcribes an audio or video file via SpeechToText and prints
 * the transcript.
 */
final class SpeechToTextCommand extends Command
{
    /**
     * Cozy messages shown before transcribing, one picked at random: [emoji, lead ("{model}" is replaced), follow-up].
     *
     * @var list<array{string, string, string}>
     */
    public const INTROS = [
        ['🎧', 'Listening carefully with {model}…', 'Perfect time for a cup of tea and a cookie 🍪'],
        ['👂', 'Taking notes with {model}…', 'Grab a warm drink while every word is written down ☕'],
        ['📻', 'Tuning in with {model}…', 'Cozy up with some cookies — long recordings take a while 🍪'],
        ['🎙️', 'Transcribing with {model}…', 'How about a hot chocolate while you wait? ☕'],
    ];

    protected const NAME = 'speech-to-text';

    protected const DESCRIPTION = 'Transcribe speech in an audio or video file';

    protected const OPTIONS = ['language', 'device', 'log-file'];

    protected const FLAGS = ['translate', 'timestamps'];

    protected const USAGE = <<<'TXT'
        Usage: vendor/bin/loves-ai speech-to-text <model> <audio> [options]

        Transcribe speech in an audio or video file with a transformers model pulled into .local/models in the project
        root. WAV, MP3, M4A, FLAC, OGG and the audio track of videos work, at any length.

        Arguments:
          model                 Hugging Face model id, e.g. openai/whisper-tiny (pull it first)
          audio                 Audio or video file to transcribe, e.g. interview.mp3

        Options:
          --language=LANGUAGE   Spoken language for multilingual models such as Whisper, e.g. en or french
                                (default: detected)
          --translate           Translate the speech into English (Whisper-style models)
          --timestamps          Print when each segment is spoken: phrases for Whisper-style models, words for others
          --device=DEVICE       Torch device: cpu, cuda, mps... (default: the best available)
          --log-file=PATH       Append the runner's output to this file (default: 'log_file' in config/speech-to-text.php)
          --debug               Show the runner's output while transcribing
          -h, --help            Show this help

        Environment:
          NO_COLOR              Disable colored output when set

        TXT;

    /**
     * @param SpeechToTextConfig|null   $config    defaults to the package's config/speech-to-text.php
     * @param resource|null             $stdout
     * @param resource|null             $stderr
     * @param (\Closure(int): int)|null $pickIntro see Command::__construct()
     * @param LocalStorage|null         $storage   defaults to the project's own
     */
    public function __construct(
        private ?SpeechToTextConfig $config = null,
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
                ? 'Both a model and an audio file are required.'
                : 'Too many arguments; wrap file names with spaces in quotes.');
        }

        [$model, $audio] = $positional;
        $config = $this->config ??= SpeechToTextConfig::load();
        $timestamps = isset($options['timestamps']);

        $runner = new SpeechToText($this->storage);
        $runner->ensureCanTranscribe($model, $options['language'] ?? null, isset($options['translate']));

        $this->startLog($options['log-file'] ?? $config->logFile, "speech-to-text {$model}: {$audio}");
        $this->writeIntro(self::INTROS, ['{model}' => $model]);

        $arguments = [
            $model,
            $audio,
            'language' => $options['language'] ?? null,
            'translate' => isset($options['translate']),
            'device' => $options['device'] ?? null,
            'onOutput' => $this->binaryOutputHandler(),
        ];

        try {
            $transcript = $timestamps
                ? array_map(self::formatSegment(...), $runner->transcribeWithTimestamps(...$arguments))
                : [$runner->transcribe(...$arguments)];
        } catch (RunFailedException $e) {
            return $this->binaryFailed("Error: failed to transcribe {$audio} with {$model} (exit code {$e->exitCode}).");
        }

        $exitCode = $this->succeeded("Transcript by {$model}:");
        foreach ($transcript as $line) {
            $this->writeLine($line);
        }

        return $exitCode;
    }

    protected function hintFor(PhpLovesAiException $e): ?string
    {
        return match (true) {
            $e instanceof ModelNotFoundException => "Pull it first with: vendor/bin/loves-ai pull {$e->model}",
            $e instanceof UnsupportedModelException => 'To try one: vendor/bin/loves-ai pull ' . SpeechToText::EXAMPLE_MODEL,
            default => parent::hintFor($e),
        };
    }

    /**
     * @param array{start: float, end: float|null, text: string} $segment
     */
    private static function formatSegment(array $segment): string
    {
        $end = $segment['end'] === null ? '…' : self::formatTime($segment['end']);

        return sprintf('[%s → %s] %s', self::formatTime($segment['start']), $end, $segment['text']);
    }

    /**
     * 83.5 seconds → "01:23.50"; an hour or longer → "1:01:23.50".
     */
    private static function formatTime(float $seconds): string
    {
        $centiseconds = (int) round($seconds * 100);
        $hours = intdiv($centiseconds, 360000);
        $minutes = intdiv($centiseconds % 360000, 6000);
        $time = sprintf('%02d:%02d.%02d', $minutes, intdiv($centiseconds % 6000, 100), $centiseconds % 100);

        return $hours > 0 ? "{$hours}:{$time}" : $time;
    }
}
