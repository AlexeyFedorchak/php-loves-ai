<?php

declare(strict_types=1);

namespace PhpLovesAi\Config;

use PhpLovesAi\Exception\InvalidConfigException;

/**
 * Reads a PHP config file that returns an array, with typed accessors that name the offending key on error.
 */
final class ConfigFile
{
    /**
     * @param array<mixed> $values
     */
    private function __construct(
        private readonly string $file,
        private readonly array $values,
    ) {
    }

    /**
     * @throws InvalidConfigException
     */
    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw InvalidConfigException::missingFile($file);
        }

        $values = require $file;
        if (!is_array($values)) {
            throw new InvalidConfigException("Config file {$file} must return an array.");
        }

        return new self($file, $values);
    }

    /**
     * @throws InvalidConfigException
     */
    public function string(string $key): string
    {
        $value = $this->values[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw InvalidConfigException::invalidValue($this->file, $key, 'a non-empty string');
        }

        return $value;
    }

    /**
     * @throws InvalidConfigException
     */
    public function nullableString(string $key): ?string
    {
        return ($this->values[$key] ?? null) === null ? null : $this->string($key);
    }
}
