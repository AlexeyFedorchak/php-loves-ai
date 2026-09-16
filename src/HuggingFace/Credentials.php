<?php

declare(strict_types=1);

namespace PhpLovesAi\HuggingFace;

use PhpLovesAi\Exception\CredentialsNotWritableException;
use PhpLovesAi\Exception\InvalidApiKeyException;
use PhpLovesAi\Exception\InvalidConfigException;
use PhpLovesAi\Filesystem\LocalStorage;

/**
 * The project's Hugging Face API key, saved by `vendor/bin/loves-ai setup` or `pull --token` in
 * <project root>/.local/huggingface/credentials.json, so every process running the project uses it.
 *
 * The key is optional: public models are pulled without one; private and gated models need it. Declining at setup
 * is remembered as {"api_key": null}, so setup does not ask again.
 */
final class Credentials
{
    /** Where users create API keys. */
    public const TOKENS_URL = 'https://huggingface.co/settings/tokens';

    private readonly LocalStorage $storage;

    /**
     * @param LocalStorage|null $storage defaults to the project's own
     */
    public function __construct(?LocalStorage $storage = null)
    {
        $this->storage = $storage ?? new LocalStorage();
    }

    public function path(): string
    {
        return $this->storage->credentialsPath();
    }

    /** Whether a key was saved or explicitly declined. */
    public function isConfigured(): bool
    {
        return is_file($this->path());
    }

    /**
     * @throws InvalidConfigException when the credentials file was edited into something unreadable
     */
    public function apiKey(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $credentials = json_decode((string) file_get_contents($this->path()), true);
        $apiKey = is_array($credentials) && array_key_exists('api_key', $credentials) ? $credentials['api_key'] : false;

        if ($apiKey !== null && !(is_string($apiKey) && self::isValid($apiKey))) {
            throw new InvalidConfigException(sprintf(
                "Credentials file %s must contain {\"api_key\": \"hf_...\"} or {\"api_key\": null}; delete it and run vendor/bin/loves-ai setup to save your key again.",
                $this->path(),
            ));
        }

        return $apiKey;
    }

    /**
     * @throws InvalidApiKeyException
     * @throws CredentialsNotWritableException
     */
    public function saveApiKey(string $apiKey): void
    {
        $apiKey = trim($apiKey);
        if (!self::isValid($apiKey)) {
            throw InvalidApiKeyException::forKey($apiKey);
        }

        $this->write($apiKey);
    }

    /**
     * Remembers that the project goes without a key, so only public models can be pulled.
     *
     * @throws CredentialsNotWritableException
     */
    public function declineApiKey(): void
    {
        $this->write(null);
    }

    /**
     * Hugging Face user access tokens look like "hf_" followed by letters and digits.
     */
    public static function isValid(string $apiKey): bool
    {
        return preg_match('/^hf_[A-Za-z0-9]+$/', $apiKey) === 1;
    }

    /**
     * @throws CredentialsNotWritableException
     */
    private function write(?string $apiKey): void
    {
        $path = $this->path();

        // The file is created empty and restricted to its owner before the key is written into it.
        if (!$this->storage->ensureDirectory(dirname($path))
            || (!is_file($path) && @touch($path) === false)
            || !@chmod($path, 0600)
            || @file_put_contents($path, json_encode(['api_key' => $apiKey], JSON_PRETTY_PRINT) . "\n") === false
        ) {
            throw CredentialsNotWritableException::atPath($path);
        }
    }
}
