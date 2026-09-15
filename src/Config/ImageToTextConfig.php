<?php

declare(strict_types=1);

namespace PhpLovesAi\Config;

use PhpLovesAi\Exception\InvalidConfigException;

/**
 * Settings for describing images, read from config/image-to-text.php.
 */
final class ImageToTextConfig
{
    public const DEFAULT_FILE = __DIR__ . '/../../config/image-to-text.php';

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
