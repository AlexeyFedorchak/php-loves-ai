<?php

declare(strict_types=1);

namespace PhpLovesAi\Config;

use PhpLovesAi\Exception\InvalidConfigException;

/**
 * Settings for transforming images, read from config/image-to-image.php.
 */
final class ImageToImageConfig
{
    public const DEFAULT_FILE = __DIR__ . '/../../config/image-to-image.php';

    public function __construct(
        public readonly string $outputDir,
        public readonly ?string $logFile = null,
    ) {
    }

    /**
     * @throws InvalidConfigException
     */
    public static function load(string $file = self::DEFAULT_FILE): self
    {
        $config = ConfigFile::load($file);

        return new self($config->string('output_dir'), $config->nullableString('log_file'));
    }
}
