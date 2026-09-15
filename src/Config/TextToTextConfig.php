<?php

declare(strict_types=1);

namespace PhpLovesAi\Config;

use PhpLovesAi\Exception\InvalidConfigException;

/**
 * Settings for generating text, read from config/text-to-text.php.
 */
final class TextToTextConfig
{
    public const DEFAULT_FILE = __DIR__ . '/../../config/text-to-text.php';

    public function __construct(
        public readonly ?string $logFile = null,
    ) {
    }

    /**
     * @throws InvalidConfigException
     */
    public static function load(string $file = self::DEFAULT_FILE): self
    {
        return new self(ConfigFile::load($file)->nullableString('log_file'));
    }
}
