<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Config\FaceConfig;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Runner\DetectedFace;
use PhpLovesAi\Runner\Face;

/**
 * CLI entry point behind `vendor/bin/loves-ai face`: finds the faces in one image via Face and prints where each
 * one is, how sure the detector is and its face id.
 */
final class FaceCommand extends Command
{
    /**
     * Cozy messages shown before looking, one picked at random: [emoji, lead ("{model}" is replaced), follow-up].
     *
     * @var list<array{string, string, string}>
     */
    public const INTROS = [
        ['🙂', 'Looking for faces with {model}…', 'Should be quick — no time for a whole cookie 🍪'],
        ['🔍', 'Scanning the picture with {model}…', 'Grab a sip of tea, this one is fast ☕'],
        ['👀', 'Searching for faces with {model}…', 'Back in a moment ✨'],
        ['🧐', 'Studying the faces with {model}…', 'A short one — the kettle will still be hot ☕'],
    ];

    protected const NAME = 'face';

    protected const DESCRIPTION = 'Find, count and fingerprint the faces in an image';

    protected const OPTIONS = ['min-score', 'max-faces', 'hash-bits', 'det-size', 'device', 'log-file'];

    protected const FLAGS = ['count', 'embeddings', 'json'];

    protected const USAGE = <<<'TXT'
        Usage: vendor/bin/loves-ai face <model> <image> [options]

        Find the faces in an image with an InsightFace pack pulled into .local/models in the project root.
        Every face gets a box, a confidence and a face id: a short fingerprint to store per face.

        Arguments:
          model                 Hugging Face model id, e.g. immich-app/buffalo_l (pull it first)
          image                 Image file to look in, e.g. photo.jpg

        Options:
          --count               Only report how many faces there are (skips recognition, much faster)
          --embeddings          Include each face's 512 numbers; only useful together with --json
          --json                Print the result as one JSON object, for piping into a backend
          --min-score=N         Detector confidence from 0 to 1 (default: 'min_score' in config/face.php)
          --max-faces=N         Keep only the N most confident faces (default: all of them)
          --hash-bits=N         Bits in a face id: 64, 128, 256 or 512 (default: 'hash_bits' in config/face.php)
          --det-size=PX         Size the image is resized to for detection (default: 'detection_size' in config/face.php);
                                ignored by a detector built for one fixed size, as the InsightFace packs are
          --device=DEVICE       Where to run: cpu, cuda, coreml... (default: the best available)
          --log-file=PATH       Append the runner's output to this file (default: 'log_file' in config/face.php)
          --debug               Show the runner's output while looking
          -h, --help            Show this help

        A face id is a fingerprint, not a primary key: two photos of one person give two different ids that
        differ in only some of their bits. Compare them with PhpLovesAi\Runner\FaceId::distance(), never with
        ===, and only against ids made with the same --hash-bits and the same model.

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

    protected function execute(array $positional, array $options): int
    {
        if (count($positional) !== 2) {
            throw new \InvalidArgumentException(count($positional) < 2
                ? 'Both a model and an image are required.'
                : 'Too many arguments; face looks at one image. To match two, use face-match.');
        }

        [$model, $image] = $positional;
        $config = $this->config ??= FaceConfig::load();
        $json = isset($options['json']);
        $countOnly = isset($options['count']);

        $runner = new Face($this->storage);
        $minScore = self::floatOption($options, 'min-score') ?? $config->minScore;
        $detectionSize = self::intOption($options, 'det-size') ?? $config->detectionSize;
        $hashBits = self::intOption($options, 'hash-bits') ?? $config->hashBits;
        $maxFaces = self::intOption($options, 'max-faces');

        $runner->ensureCanRun($model);

        $this->startLog($options['log-file'] ?? $config->logFile, "face {$model}: {$image}");

        // With --json nothing but the object may reach stdout, so the cozy lines are skipped.
        if (!$json) {
            $this->writeIntro(self::INTROS, ['{model}' => $model]);
        }

        try {
            if ($countOnly) {
                $count = $runner->countFaces($model, $image, $minScore, $detectionSize, $options['device'] ?? null, $this->binaryOutputHandler());
                $faces = [];
            } else {
                $faces = $runner->analyze(
                    $model,
                    $image,
                    minScore: $minScore,
                    maxFaces: $maxFaces,
                    hashBits: $hashBits,
                    withEmbeddings: isset($options['embeddings']),
                    detectionSize: $detectionSize,
                    device: $options['device'] ?? null,
                    onOutput: $this->binaryOutputHandler(),
                );
                $count = count($faces);
            }
        } catch (RunFailedException $e) {
            return $this->binaryFailed("Error: failed to look for faces in {$image} with {$model} (exit code {$e->exitCode}).");
        }

        if ($json) {
            fwrite($this->stdout, self::encode($model, $image, $count, $faces) . "\n");

            return self::EXIT_OK;
        }

        $name = basename($image);
        $exitCode = $this->succeeded(match ($count) {
            0 => "No faces found in {$name}",
            1 => "Found 1 face in {$name}",
            default => "Found {$count} faces in {$name}",
        });

        foreach ($faces as $index => $face) {
            $this->writeLine(sprintf(
                '   👤 %d  %.1f%% confident, %d×%d px at %d,%d',
                $index + 1,
                $face->score * 100,
                $face->width(),
                $face->height(),
                $face->box[0],
                $face->box[1],
            ));

            if ($face->id !== null) {
                $this->writeLine("      {$face->id}", self::GREY);
            }
        }

        return $exitCode;
    }

    protected function hintFor(PhpLovesAiException $e): ?string
    {
        return match (true) {
            $e instanceof ModelNotFoundException => "Pull it first with: vendor/bin/loves-ai pull {$e->model}",
            $e instanceof UnsupportedModelException => 'To try one: vendor/bin/loves-ai pull ' . Face::EXAMPLE_MODEL,
            default => parent::hintFor($e),
        };
    }

    /**
     * @param list<DetectedFace> $faces
     */
    private static function encode(string $model, string $image, int $count, array $faces): string
    {
        $encoded = [];
        foreach ($faces as $face) {
            $encoded[] = array_filter([
                'id' => $face->id,
                'score' => $face->score,
                'box' => $face->box,
                'landmarks' => $face->landmarks,
                'embedding' => $face->embedding,
            ], static fn (mixed $value): bool => $value !== null && $value !== []);
        }

        return (string) json_encode([
            'model' => $model,
            'image' => $image,
            'count' => $count,
            'faces' => $encoded,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
