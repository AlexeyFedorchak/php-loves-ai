<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\HomeDirectoryNotFoundException;
use PhpLovesAi\Exception\ImageNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\NoFaceDetectedException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\Path;

/**
 * Finds, counts, matches and fingerprints faces in images with InsightFace packs pulled into the project,
 * through the runner installed by `vendor/bin/loves-ai setup face`.
 *
 *     $face = new Face();
 *     $face->hasFace('immich-app/buffalo_l', 'photo.jpg');              // true
 *     $face->countFaces('immich-app/buffalo_l', 'group.jpg');           // 4
 *     $face->compare('immich-app/buffalo_l', 'a.jpg', 'b.jpg')->samePerson;
 *
 *     foreach ($face->analyze('immich-app/buffalo_l', 'photo.jpg') as $found) {
 *         $found->id;         // "v1:256:3f8a…", to store per face (see FaceId)
 *         $found->embedding;  // the 512 numbers it is derived from
 *     }
 *
 * This runner needs no torch, so its binary is tens rather than hundreds of megabytes.
 */
final class Face extends BinaryRunner
{
    /** A model the runner can load; used in error messages and hints. */
    public const EXAMPLE_MODEL = 'immich-app/buffalo_l';

    /** Hugging Face models this runner can use. */
    public const COMPATIBLE_MODELS_URL = 'https://huggingface.co/models?other=facial-recognition';

    /**
     * Bits a face id may have. Fewer bits is a shorter string but a blunter one: at 64 bits the
     * distances for the same and for different people overlap, so 256 is the default.
     *
     * Must stay in sync with HASH_BIT_CHOICES in python/runners/face/face.py.
     *
     * @var list<int>
     */
    public const HASH_BITS = [64, 128, 256, 512];

    public const DEFAULT_HASH_BITS = 256;

    /**
     * Cosine similarity above which compare() calls two faces the same person. A starting point
     * only: the right value depends on the model and on what a wrong match costs, so measure it on
     * your own images. Every FaceMatch repeats the threshold it used.
     */
    public const DEFAULT_THRESHOLD = 0.40;

    /** Detector confidence below which a box is not reported as a face. */
    public const DEFAULT_MIN_SCORE = 0.5;

    /** Size images are resized to for detection; larger finds smaller faces and is slower. */
    public const DEFAULT_DETECTION_SIZE = 640;

    private const MIN_DETECTION_SIZE = 64;

    public static function tool(): Tool
    {
        return Tool::Face;
    }

    /**
     * Every face in an image, the most confident first.
     *
     * @param string                        $model          Hugging Face model id, e.g. "immich-app/buffalo_l"
     * @param string                        $imagePath      image to look in; a leading "~" is expanded
     * @param float|null                    $minScore       detector confidence from 0 to 1; null uses 0.5
     * @param int|null                      $maxFaces       keep only this many faces; null keeps all
     * @param int|null                      $hashBits       bits in each face id, one of HASH_BITS; null uses 256
     * @param bool                          $withEmbeddings whether to also report the 512 numbers per face
     * @param int|null                      $detectionSize  size images are resized to for detection; null uses 640.
     *                                                      Larger finds smaller faces and is slower
     * @param string|null                   $device         where to run: "cpu", "cuda", "coreml"; null picks the best
     *                                                      available
     * @param (callable(string): void)|null $onOutput       receives the runner's progress output as it arrives
     *
     * @return list<DetectedFace>
     *
     * @throws \InvalidArgumentException on an unknown bit count or an out-of-range score
     * @throws ImageNotFoundException
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException when the model is not an InsightFace pack
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException e.g. when the image cannot be decoded
     */
    public function analyze(
        string $model,
        string $imagePath,
        ?float $minScore = null,
        ?int $maxFaces = null,
        ?int $hashBits = null,
        bool $withEmbeddings = true,
        ?int $detectionSize = null,
        ?string $device = null,
        ?callable $onOutput = null,
    ): array {
        $result = $this->run($model, [
            '--image' => $this->readableImage($imagePath),
            '--min-score' => self::validScore($minScore),
            '--max-faces' => self::validCount($maxFaces, 'maxFaces'),
            '--hash-bits' => self::validBits($hashBits),
            '--det-size' => self::validDetectionSize($detectionSize),
            '--no-embeddings' => !$withEmbeddings,
            '--device' => $device,
        ], $onOutput);

        return self::readFaces($result);
    }

    /**
     * How many faces are in an image. Only the detector runs, so this is much faster than analyze().
     *
     * @param (callable(string): void)|null $onOutput receives the runner's progress output as it arrives
     *
     * @throws \InvalidArgumentException
     * @throws ImageNotFoundException
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException
     */
    public function countFaces(
        string $model,
        string $imagePath,
        ?float $minScore = null,
        ?int $detectionSize = null,
        ?string $device = null,
        ?callable $onOutput = null,
    ): int {
        $result = $this->run($model, [
            '--image' => $this->readableImage($imagePath),
            '--min-score' => self::validScore($minScore),
            '--det-size' => self::validDetectionSize($detectionSize),
            '--detect-only' => true,
            '--device' => $device,
        ], $onOutput);

        $count = $result['count'] ?? null;
        if (!is_int($count)) {
            throw new RunFailedException(0, 'The runner did not report how many faces it found.');
        }

        return $count;
    }

    /**
     * Whether the image holds at least one face. Only the detector runs.
     *
     * @param (callable(string): void)|null $onOutput receives the runner's progress output as it arrives
     *
     * @throws \InvalidArgumentException
     * @throws ImageNotFoundException
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException
     */
    public function hasFace(
        string $model,
        string $imagePath,
        ?float $minScore = null,
        ?int $detectionSize = null,
        ?string $device = null,
        ?callable $onOutput = null,
    ): bool {
        return $this->countFaces($model, $imagePath, $minScore, $detectionSize, $device, $onOutput) > 0;
    }

    /**
     * Matches the most confident face of one image against that of another. When an image holds
     * several faces, the match covers only the one that was picked; FaceMatch::wasAmbiguous() says so.
     *
     * @param float|null                    $threshold      similarity for "same person"; null uses DEFAULT_THRESHOLD
     * @param bool                          $withEmbeddings whether the two faces also carry their 512 numbers
     * @param (callable(string): void)|null $onOutput       receives the runner's progress output as it arrives
     *
     * @throws \InvalidArgumentException
     * @throws ImageNotFoundException
     * @throws NoFaceDetectedException when either image holds no face
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException
     * @throws HomeDirectoryNotFoundException
     * @throws RunFailedException
     */
    public function compare(
        string $model,
        string $imagePath,
        string $otherImagePath,
        ?float $threshold = null,
        ?float $minScore = null,
        ?int $hashBits = null,
        bool $withEmbeddings = false,
        ?int $detectionSize = null,
        ?string $device = null,
        ?callable $onOutput = null,
    ): FaceMatch {
        if ($threshold !== null && ($threshold < -1 || $threshold > 1)) {
            throw new \InvalidArgumentException("A threshold is a cosine similarity, from -1 to 1; got {$threshold}.");
        }

        $first = $this->readableImage($imagePath);
        $second = $this->readableImage($otherImagePath);

        $result = $this->run($model, [
            '--image' => $first,
            '--compare' => $second,
            '--threshold' => $threshold,
            '--min-score' => self::validScore($minScore),
            '--hash-bits' => self::validBits($hashBits),
            '--det-size' => self::validDetectionSize($detectionSize),
            '--no-embeddings' => !$withEmbeddings,
            '--device' => $device,
        ], $onOutput);

        $sides = [];
        foreach (['a' => $first, 'b' => $second] as $key => $path) {
            $side = $result[$key] ?? null;
            if (!is_array($side) || !is_int($side['count'] ?? null)) {
                throw new RunFailedException(0, 'The runner did not report the faces it matched.');
            }

            if (!is_array($side['face'] ?? null)) {
                throw new NoFaceDetectedException($path);
            }

            $sides[$key] = $side;
        }

        $similarity = $result['similarity'] ?? null;
        if (!is_numeric($similarity)) {
            throw new RunFailedException(0, 'The runner did not report how similar the two faces are.');
        }

        return new FaceMatch(
            (float) $similarity,
            (bool) ($result['same_person'] ?? false),
            is_numeric($result['threshold'] ?? null) ? (float) $result['threshold'] : ($threshold ?? self::DEFAULT_THRESHOLD),
            self::readFace($sides['a']['face']),
            self::readFace($sides['b']['face']),
            $sides['a']['count'],
            $sides['b']['count'],
        );
    }

    /**
     * The model must be an InsightFace pack: two ONNX graphs in the layout InsightFaceModel describes.
     */
    protected function ensureModelIsSupported(string $model, string $modelPath): void
    {
        $problem = InsightFaceModel::problem($modelPath);

        if ($problem === null) {
            return;
        }

        throw new UnsupportedModelException($model, $modelPath, sprintf(
            '%s cannot be used to recognize faces: %s Use an InsightFace pack instead, e.g. %s (browse: %s).',
            $model,
            $problem,
            self::EXAMPLE_MODEL,
            self::COMPATIBLE_MODELS_URL,
        ));
    }

    /**
     * @throws ImageNotFoundException
     * @throws HomeDirectoryNotFoundException
     */
    private function readableImage(string $path): string
    {
        $path = Path::expandHome($path);

        if (!is_file($path) || !is_readable($path)) {
            throw new ImageNotFoundException($path);
        }

        return $path;
    }

    /**
     * @param array<mixed> $result
     *
     * @return list<DetectedFace>
     *
     * @throws RunFailedException
     */
    private static function readFaces(array $result): array
    {
        $faces = $result['faces'] ?? null;
        if (!is_array($faces) || !array_is_list($faces)) {
            throw new RunFailedException(0, 'The runner did not report the faces it found.');
        }

        return array_map(static function (mixed $face): DetectedFace {
            if (!is_array($face)) {
                throw new RunFailedException(0, 'The runner reported a face in an unexpected shape.');
            }

            return self::readFace($face);
        }, $faces);
    }

    /**
     * @param array<mixed> $face
     *
     * @throws RunFailedException
     */
    private static function readFace(array $face): DetectedFace
    {
        $box = $face['box'] ?? null;
        if (!is_array($box) || count($box) !== 4) {
            throw new RunFailedException(0, 'The runner did not report where a face is.');
        }

        [$left, $top, $right, $bottom] = array_map(static fn (mixed $edge): int => (int) $edge, array_values($box));

        $landmarks = [];
        foreach (is_array($face['landmarks'] ?? null) ? $face['landmarks'] : [] as $point) {
            if (is_array($point) && count($point) === 2) {
                $landmarks[] = [(int) array_values($point)[0], (int) array_values($point)[1]];
            }
        }

        $embedding = null;
        if (is_array($face['embedding'] ?? null)) {
            $embedding = array_map(static fn (mixed $value): float => (float) $value, array_values($face['embedding']));
        }

        return new DetectedFace(
            [$left, $top, $right, $bottom],
            is_numeric($face['score'] ?? null) ? (float) $face['score'] : 0.0,
            $landmarks,
            is_string($face['id'] ?? null) ? $face['id'] : null,
            $embedding,
        );
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function validBits(?int $bits): ?int
    {
        if ($bits !== null && !in_array($bits, self::HASH_BITS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'A face id can have %s bits, not %d.',
                implode(', ', self::HASH_BITS),
                $bits,
            ));
        }

        return $bits;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function validScore(?float $score): ?float
    {
        if ($score !== null && ($score <= 0 || $score > 1)) {
            throw new \InvalidArgumentException("A minimum score is a confidence, above 0 and at most 1; got {$score}.");
        }

        return $score;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function validCount(?int $value, string $name): ?int
    {
        if ($value !== null && $value < 1) {
            throw new \InvalidArgumentException("The {$name} must be a positive integer; got {$value}.");
        }

        return $value;
    }

    /**
     * Must stay in sync with the floor in python/runners/face/face.py.
     *
     * @throws \InvalidArgumentException
     */
    private static function validDetectionSize(?int $size): ?int
    {
        if ($size !== null && $size < self::MIN_DETECTION_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'The detection size must be at least %d pixels; got %d.',
                self::MIN_DETECTION_SIZE,
                $size,
            ));
        }

        return $size;
    }
}
