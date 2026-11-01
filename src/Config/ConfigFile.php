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

    /**
     * @param int $min smallest value the setting accepts
     *
     * @throws InvalidConfigException
     */
    public function int(string $key, int $min = PHP_INT_MIN): int
    {
        $value = $this->values[$key] ?? null;

        if (!is_int($value) || $value < $min) {
            throw InvalidConfigException::invalidValue(
                $this->file,
                $key,
                $min === PHP_INT_MIN ? 'a whole number' : "a whole number of at least {$min}",
            );
        }

        return $value;
    }

    /**
     * Accepts a whole number too, so 1 may be written for 1.0.
     *
     * @throws InvalidConfigException
     */
    public function float(string $key, float $min = -PHP_FLOAT_MAX, float $max = PHP_FLOAT_MAX): float
    {
        $value = $this->values[$key] ?? null;

        if ((!is_float($value) && !is_int($value)) || $value < $min || $value > $max) {
            throw InvalidConfigException::invalidValue($this->file, $key, "a number between {$min} and {$max}");
        }

        return (float) $value;
    }

    /**
     * One of $allowed, which keeps a typo in a setting from reaching the runner.
     *
     * @param list<int> $allowed
     *
     * @throws InvalidConfigException
     */
    public function intFrom(string $key, array $allowed): int
    {
        $value = $this->values[$key] ?? null;

        if (!is_int($value) || !in_array($value, $allowed, true)) {
            throw InvalidConfigException::invalidValue($this->file, $key, 'one of ' . implode(', ', $allowed));
        }

        return $value;
    }
}
