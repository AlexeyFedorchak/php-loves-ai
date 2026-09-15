<?php

declare(strict_types=1);

namespace PhpLovesAi\Config;

use PhpLovesAi\Exception\InvalidConfigException;

/**
 * Settings for pulling models, read from config/pull.php.
 */
final class PullConfig
{
    public const DEFAULT_FILE = __DIR__ . '/../../config/pull.php';

    public function __construct(
        public readonly string $revision,
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
            $config->string('revision'),
            $config->nullableString('log_file'),
        );
    }
}
