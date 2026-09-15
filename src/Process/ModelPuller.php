<?php

declare(strict_types=1);

namespace PhpLovesAi\Process;

use PhpLovesAi\Exception\BinaryNotFoundException;
use PhpLovesAi\Exception\HomeDirectoryNotFoundException;
use PhpLovesAi\Exception\InvalidModelIdException;
use PhpLovesAi\Exception\MissingApiKeyException;
use PhpLovesAi\Exception\PullFailedException;
use PhpLovesAi\Filesystem\Path;
use Symfony\Component\Process\Process;

/**
 * Runs the compiled puller binary to download models from the Hugging Face Hub.
 */
final class ModelPuller
{
    public const API_KEY_ENV = 'HUGGING_FACE_API_KEY';

    /** Must stay in sync with MODEL_ID_PATTERN in python/puller/puller.py. */
    private const MODEL_ID_PATTERN = '~^(?:[A-Za-z0-9][\w.-]*/)?[A-Za-z0-9][\w.-]*$~';

    /** Must stay in sync with EXIT_MISSING_API_KEY in python/puller/puller.py. */
    private const EXIT_MISSING_API_KEY = 3;

    private readonly string $modelsDir;

    /**
     * @param string     $binaryPath path to the compiled puller binary
     * @param string     $modelsDir  directory models are saved into, as <modelsDir>/<model id>;
     *                               a leading "~" is expanded to the user's home directory
     * @param float|null $timeout    seconds before the pull is aborted; null waits indefinitely
     *
     * @throws HomeDirectoryNotFoundException
     */
    public function __construct(
        private readonly string $binaryPath,
        string $modelsDir,
        private readonly ?float $timeout = null,
    ) {
        $this->modelsDir = Path::expandHome($modelsDir);
    }

    /**
     * @param list<string>                  $models     Hugging Face model ids, e.g. "openai-community/gpt2"
     * @param string|null                   $revision   branch, tag or commit hash; null for the default branch
     * @param (callable(string): void)|null $onProgress receives the puller's progress output as it arrives
     *
     * @return array<string, string> model id => absolute local path
     *
     * @throws InvalidModelIdException
     * @throws MissingApiKeyException
     * @throws BinaryNotFoundException
     * @throws PullFailedException when at least one model could not be pulled
     */
    public function pull(array $models, ?string $revision = null, ?callable $onProgress = null): array
    {
        if ($models === []) {
            return [];
        }

        $this->ensureCanPull($models);

        $command = [$this->binaryPath, '--dir', $this->modelsDir];
        if ($revision !== null) {
            array_push($command, '--revision', $revision);
        }
        // "--" stops option parsing, so model ids can never be read as flags.
        array_push($command, '--', ...$models);

        $process = new Process($command, timeout: $this->timeout);
        $process->run(static function (string $type, string $buffer) use ($onProgress): void {
            if ($onProgress !== null && $type === Process::ERR) {
                $onProgress($buffer);
            }
        });

        $pulled = self::parsePulled($process->getOutput());
        $exitCode = $process->getExitCode() ?? -1;

        if ($exitCode === self::EXIT_MISSING_API_KEY) {
            throw MissingApiKeyException::forVariable(self::API_KEY_ENV);
        }

        if ($exitCode !== 0) {
            throw new PullFailedException($exitCode, $process->getErrorOutput(), $pulled);
        }

        return $pulled;
    }

    /**
     * Runs the checks pull() performs before starting the binary, without pulling anything.
     *
     * @param list<string> $models
     *
     * @throws InvalidModelIdException
     * @throws MissingApiKeyException
     * @throws BinaryNotFoundException
     */
    public function ensureCanPull(array $models): void
    {
        foreach ($models as $model) {
            if (preg_match(self::MODEL_ID_PATTERN, $model) !== 1 || str_contains($model, '..')) {
                throw InvalidModelIdException::forId($model);
            }
        }

        if (!self::isApiKeySet()) {
            throw MissingApiKeyException::forVariable(self::API_KEY_ENV);
        }

        if (!is_file($this->binaryPath) || !is_executable($this->binaryPath)) {
            throw BinaryNotFoundException::atPath($this->binaryPath);
        }
    }

    private static function isApiKeySet(): bool
    {
        // Symfony Process passes getenv(), $_ENV and $_SERVER string values to the child process.
        $value = getenv(self::API_KEY_ENV);
        if ($value === false) {
            $value = $_ENV[self::API_KEY_ENV] ?? $_SERVER[self::API_KEY_ENV] ?? '';
        }

        return is_string($value) && trim($value) !== '';
    }

    /**
     * @return array<string, string>
     */
    private static function parsePulled(string $output): array
    {
        $pulled = [];

        foreach (preg_split('/\R/', $output, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry) && is_string($entry['model'] ?? null) && is_string($entry['path'] ?? null)) {
                $pulled[$entry['model']] = $entry['path'];
            }
        }

        return $pulled;
    }
}
