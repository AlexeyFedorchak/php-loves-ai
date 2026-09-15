<?php

declare(strict_types=1);

namespace PhpLovesAi\Config;

use PhpLovesAi\Exception\InvalidConfigException;

/**
 * Settings for transcribing speech, read from config/speech-to-text.php.
 */
final class SpeechToTextConfig
{
    public const DEFAULT_FILE = __DIR__ . '/../../config/speech-to-text.php';

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
