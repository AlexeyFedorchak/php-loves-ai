<?php

declare(strict_types=1);

namespace PhpLovesAi\Config;

use PhpLovesAi\Exception\InvalidConfigException;
use PhpLovesAi\Runner\Face;

/**
 * Settings for finding and matching faces, read from config/face.php.
 *
 * Unlike the other tasks' settings these are not only defaults for the command line: hash_bits and
 * threshold decide what the ids and matches a project stores mean, so they belong in one place per
 * project rather than at each call site.
 */
final class FaceConfig
{
    public const DEFAULT_FILE = __DIR__ . '/../../config/face.php';

    public function __construct(
        public readonly int $hashBits = Face::DEFAULT_HASH_BITS,
        public readonly float $threshold = Face::DEFAULT_THRESHOLD,
        public readonly float $minScore = Face::DEFAULT_MIN_SCORE,
        public readonly int $detectionSize = Face::DEFAULT_DETECTION_SIZE,
        public readonly ?string $logFile = null,
    ) {
    }

    /**
     * @throws InvalidConfigException
     */
    public static function load(string $file = self::DEFAULT_FILE): self
    {
        $config = ConfigFile::load($file);

        return new self(
            $config->intFrom('hash_bits', Face::HASH_BITS),
            $config->float('threshold', -1, 1),
            $config->float('min_score', 0.01, 1),
            $config->int('detection_size', 64),
            $config->nullableString('log_file'),
        );
    }
}
