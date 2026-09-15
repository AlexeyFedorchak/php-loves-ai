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
        public readonly string $binary,
        public readonly string $modelsDir,
        public readonly string $revision,
        public readonly ?string $logFile = null,
    ) {
    }

    /**
     * @throws InvalidConfigException
     */
    public static function load(string $file = self::DEFAULT_FILE): self
    {
        if (!is_file($file)) {
            throw InvalidConfigException::missingFile($file);
        }

        $values = require $file;
        if (!is_array($values)) {
            throw new InvalidConfigException("Config file {$file} must return an array.");
        }

        return new self(
            self::nonEmptyString($values, 'binary', $file),
            self::nonEmptyString($values, 'models_dir', $file),
            self::nonEmptyString($values, 'revision', $file),
            ($values['log_file'] ?? null) === null ? null : self::nonEmptyString($values, 'log_file', $file),
        );
    }

    /**
     * @param array<mixed> $values
     */
    private static function nonEmptyString(array $values, string $key, string $file): string
    {
        $value = $values[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw InvalidConfigException::invalidValue($file, $key, 'a non-empty string');
        }

        return $value;
    }
}
