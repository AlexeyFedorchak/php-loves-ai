<?php

declare(strict_types=1);

namespace PhpLovesAi\Process;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\InvalidConfigException;
use PhpLovesAi\Exception\InvalidModelIdException;
use PhpLovesAi\Exception\MissingApiKeyException;
use PhpLovesAi\Exception\ModelAccessDeniedException;
use PhpLovesAi\Exception\PullFailedException;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\HuggingFace\Credentials;
use Symfony\Component\Process\Process;

/**
 * Runs the puller installed by `vendor/bin/loves-ai setup` to download models from the Hugging Face Hub into the project's
 * models directory, <project root>/.local/models/<model id>, where the runners find them.
 *
 * Public models are pulled without an API key. Private and gated models need the key saved in the project's
 * credentials (see Credentials); an environment variable of the same name is never used.
 */
final class ModelPuller
{
    /** How the key is handed to the puller process. Must stay in sync with API_KEY_ENV in python/puller/puller.py. */
    private const API_KEY_ENV = 'HUGGING_FACE_API_KEY';

    /** Must stay in sync with MODEL_ID_PATTERN in python/puller/puller.py. */
    private const MODEL_ID_PATTERN = '~^(?:[A-Za-z0-9][\w.-]*/)?[A-Za-z0-9][\w.-]*$~';

    /** Exit code of pullers from before API keys became optional, when pulling without one. */
    private const EXIT_OUTDATED_MISSING_API_KEY = 3;

    private readonly LocalStorage $storage;

    private readonly Credentials $credentials;

    /**
     * @param LocalStorage|null $storage defaults to the project's own
     * @param float|null        $timeout seconds before the pull is aborted; null waits indefinitely
     */
    public function __construct(?LocalStorage $storage = null, private readonly ?float $timeout = null)
    {
        $this->storage = $storage ?? new LocalStorage();
        $this->credentials = new Credentials($this->storage);
    }

    /**
     * @param list<string>                  $models     Hugging Face model ids, e.g. "openai-community/gpt2"
     * @param string|null                   $revision   branch, tag or commit hash; null for the default branch
     * @param (callable(string): void)|null $onProgress receives the puller's progress output as it arrives
     *
     * @return array<string, string> model id => absolute local path
     *
     * @throws InvalidModelIdException
     * @throws BinaryNotInstalledException
     * @throws InvalidConfigException     when the credentials file is unreadable
     * @throws ModelAccessDeniedException when Hugging Face refuses a model (gated, private or missing)
     * @throws MissingApiKeyException     when an outdated puller requires a key that is not saved
     * @throws PullFailedException        when a model could not be pulled for another reason
     */
    public function pull(array $models, ?string $revision = null, ?callable $onProgress = null): array
    {
        if ($models === []) {
            return [];
        }

        $this->ensureCanPull($models);

        $modelsDir = $this->storage->modelsDir();
        $this->storage->ensureDirectory($modelsDir);

        $command = [$this->storage->binaryPath(Tool::Puller), '--dir', $modelsDir];
        if ($revision !== null) {
            array_push($command, '--revision', $revision);
        }
        // "--" stops option parsing, so model ids can never be read as flags.
        array_push($command, '--', ...$models);

        $apiKey = $this->credentials->apiKey();

        // false removes the variable, so a key exported in the shell is never used instead of the project's.
        $process = new Process($command, env: [self::API_KEY_ENV => $apiKey ?? false], timeout: $this->timeout);
        $process->run(static function (string $type, string $buffer) use ($onProgress): void {
            if ($onProgress !== null && $type === Process::ERR) {
                $onProgress($buffer);
            }
        });

        [$pulled, $denied] = self::parseOutput($process->getOutput());
        $exitCode = $process->getExitCode() ?? -1;

        if ($exitCode === self::EXIT_OUTDATED_MISSING_API_KEY && $apiKey === null) {
            throw MissingApiKeyException::forOutdatedPuller();
        }

        if ($denied !== []) {
            $model = array_key_first($denied);
            throw new ModelAccessDeniedException($model, $denied[$model], $apiKey !== null, $pulled);
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
     * @throws BinaryNotInstalledException
     */
    public function ensureCanPull(array $models): void
    {
        foreach ($models as $model) {
            if (preg_match(self::MODEL_ID_PATTERN, $model) !== 1 || str_contains($model, '..')) {
                throw InvalidModelIdException::forId($model);
            }
        }

        if (!$this->storage->isInstalled(Tool::Puller)) {
            throw new BinaryNotInstalledException(Tool::Puller);
        }
    }

    /**
     * @return array{array<string, string>, array<string, string>} pulled (model => path) and denied (model => reason)
     */
    private static function parseOutput(string $output): array
    {
        $pulled = [];
        $denied = [];

        foreach (preg_split('/\R/', $output, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry) || !is_string($entry['model'] ?? null)) {
                continue;
            }

            if (is_string($entry['path'] ?? null)) {
                $pulled[$entry['model']] = $entry['path'];
            } elseif (in_array($entry['error'] ?? null, [ModelAccessDeniedException::GATED, ModelAccessDeniedException::NOT_FOUND], true)) {
                $denied[$entry['model']] = $entry['error'];
            }
        }

        return [$pulled, $denied];
    }
}
