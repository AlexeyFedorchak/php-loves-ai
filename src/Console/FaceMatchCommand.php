<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Config\FaceConfig;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\NoFaceDetectedException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Runner\Face;

/**
 * CLI entry point behind `vendor/bin/loves-ai face-match`: tells whether two images show the same person, via
 * Face::compare().
 */
final class FaceMatchCommand extends Command
{
    /**
     * Cozy messages shown before matching, one picked at random: [emoji, lead, follow-up].
     *
     * @var list<array{string, string, string}>
     */
    public const INTROS = [
        ['🤝', 'Comparing the two faces with {model}…', 'Quick one — hold on to your cookie 🍪'],
        ['🔗', 'Matching the faces with {model}…', 'A sip of tea and it is done ☕'],
        ['👯', 'Checking whether it is the same person, with {model}…', 'Back in a moment ✨'],
    ];

    protected const NAME = 'face-match';

    protected const DESCRIPTION = 'Check whether two images show the same person';

    protected const OPTIONS = ['threshold', 'min-score', 'det-size', 'device', 'log-file'];

    protected const FLAGS = ['json'];

    protected const USAGE = <<<'TXT'
        Usage: vendor/bin/loves-ai face-match <model> <image> <image> [options]

        Compare the faces in two images with an InsightFace pack pulled into .local/models in the project root,
        and report how similar they are.

        Arguments:
          model                 Hugging Face model id, e.g. immich-app/buffalo_l (pull it first)
          image                 First image, e.g. passport.jpg
          image                 Second image, e.g. selfie.jpg

        Options:
          --threshold=N         Similarity at which the two count as the same person, from -1 to 1
                                (default: 'threshold' in config/face.php)
          --min-score=N         Detector confidence from 0 to 1 (default: 'min_score' in config/face.php)
          --det-size=PX         Size images are resized to for detection (default: 'detection_size' in config/face.php);
                                ignored by a detector built for one fixed size, as the InsightFace packs are
          --device=DEVICE       Where to run: cpu, cuda, coreml... (default: the best available)
          --json                Print the result as one JSON object, for piping into a backend
          --log-file=PATH       Append the runner's output to this file (default: 'log_file' in config/face.php)
          --debug               Show the runner's output while matching
          -h, --help            Show this help

        When an image holds several faces, only its most confident one is matched, and the result says so.

        The threshold decides what "the same person" means, and the default is a starting point, not a safe
        value: measure it on your own images before relying on it, because only you know what a wrong match
        costs. The exit code is 0 whether or not it is a match; read the output to find out which.

        Environment:
          NO_COLOR              Disable colored output when set

        TXT;

    /**
     * @param FaceConfig|null           $config    defaults to the package's config/face.php
     * @param resource|null             $stdout
     * @param resource|null             $stderr
     * @param (\Closure(int): int)|null $pickIntro see Command::__construct()
     * @param LocalStorage|null         $storage   defaults to the project's own
     */
    public function __construct(
        private ?FaceConfig $config = null,
        $stdout = null,
        $stderr = null,
        ?\Closure $pickIntro = null,
        private readonly ?LocalStorage $storage = null,
    ) {
        parent::__construct($stdout, $stderr, $pickIntro);
    }

    /** Shared with `face`, so it is not named after its binary. */
    public static function requiresTool(): Tool
    {
        return Tool::Face;
    }

    protected function execute(array $positional, array $options): int
    {
        if (count($positional) !== 3) {
            throw new \InvalidArgumentException(count($positional) < 3
                ? 'A model and two images are required.'
                : 'Too many arguments; face-match compares two images.');
        }

        [$model, $first, $second] = $positional;
        $config = $this->config ??= FaceConfig::load();
        $json = isset($options['json']);

        $runner = new Face($this->storage);
        $threshold = self::floatOption($options, 'threshold') ?? $config->threshold;

        $runner->ensureCanRun($model);

        $this->startLog($options['log-file'] ?? $config->logFile, "face-match {$model}: {$first} vs {$second}");

        // With --json nothing but the object may reach stdout, so the cozy lines are skipped.
        if (!$json) {
            $this->writeIntro(self::INTROS, ['{model}' => $model]);
        }

        try {
            $match = $runner->compare(
                $model,
                $first,
                $second,
                threshold: $threshold,
                minScore: self::floatOption($options, 'min-score') ?? $config->minScore,
                hashBits: $config->hashBits,
                detectionSize: self::intOption($options, 'det-size') ?? $config->detectionSize,
                device: $options['device'] ?? null,
                onOutput: $this->binaryOutputHandler(),
            );
        } catch (RunFailedException $e) {
            return $this->binaryFailed("Error: failed to compare the two images with {$model} (exit code {$e->exitCode}).");
        }

        if ($json) {
            fwrite($this->stdout, (string) json_encode([
                'model' => $model,
                'same_person' => $match->samePerson,
                'similarity' => $match->similarity,
                'threshold' => $match->threshold,
                'a' => ['image' => $first, 'faces' => $match->facesInA, 'id' => $match->a->id],
                'b' => ['image' => $second, 'faces' => $match->facesInB, 'id' => $match->b->id],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

            return self::EXIT_OK;
        }

        $exitCode = $this->succeeded(sprintf(
            '%s — %s similar (cut-off %s)',
            $match->samePerson ? 'Same person' : 'Different people',
            self::percent($match->similarity),
            self::percent($match->threshold),
        ));

        if ($match->wasAmbiguous()) {
            $this->writeLine(sprintf(
                '   💡 %d face(s) in %s and %d in %s; the most confident of each was matched.',
                $match->facesInA,
                basename($first),
                $match->facesInB,
                basename($second),
            ), self::GREY);
        }

        return $exitCode;
    }

    protected function hintFor(PhpLovesAiException $e): ?string
    {
        return match (true) {
            $e instanceof ModelNotFoundException => "Pull it first with: vendor/bin/loves-ai pull {$e->model}",
            $e instanceof UnsupportedModelException => 'To try one: vendor/bin/loves-ai pull ' . Face::EXAMPLE_MODEL,
            $e instanceof NoFaceDetectedException => 'Both images need a face to compare. Lower --min-score, or raise '
                . '--det-size if the face is small in the frame.',
            default => parent::hintFor($e),
        };
    }

    private static function percent(float $value): string
    {
        return sprintf('%.1f%%', $value * 100);
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
