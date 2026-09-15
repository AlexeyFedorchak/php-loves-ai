<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Exception\UnsupportedModelException;
use PhpLovesAi\Filesystem\LocalStorage;
use Symfony\Component\Process\Process;

/**
 * Shared plumbing for runners: locating the binary and the model, running the process and reading its result.
 *
 * Runner binaries share one contract: `<binary> --model <model dir> [task options]`, progress on stderr, and the
 * result as a JSON object on the last such line of stdout; a non-zero exit code means the run failed.
 */
abstract class BinaryRunner implements Runner
{
    private readonly LocalStorage $storage;

    /**
     * @param LocalStorage|null $storage defaults to the project's own
     * @param float|null        $timeout seconds before a run is aborted; null waits indefinitely
     */
    public function __construct(?LocalStorage $storage = null, private readonly ?float $timeout = null)
    {
        $this->storage = $storage ?? new LocalStorage();
    }

    public function modelPath(string $model): string
    {
        return $this->storage->modelPath($model);
    }

    public function ensureCanRun(string $model): void
    {
        if (!$this->storage->isInstalled(static::tool())) {
            throw new BinaryNotInstalledException(static::tool());
        }

        $modelPath = $this->modelPath($model);
        if (!is_dir($modelPath)) {
            throw new ModelNotFoundException($model, $modelPath);
        }

        $this->ensureModelIsSupported($model, $modelPath);
    }

    /**
     * Rejects a pulled model this runner cannot load, with an explanation instead of the binary's stack trace.
     *
     * @throws UnsupportedModelException
     */
    protected function ensureModelIsSupported(string $model, string $modelPath): void
    {
    }

    /**
     * Runs the binary with a model and returns the result object it printed.
     *
     * @param array<string, string|int|float|bool|null> $options  task options in order, e.g. ['--prompt' => 'a cat'];
     *                                                            true passes a flag without a value, false and null
     *                                                            leave the option out
     * @param (callable(string): void)|null             $onOutput receives the runner's progress output as it arrives
     *
     * @return array<mixed>
     *
     * @throws BinaryNotInstalledException
     * @throws ModelNotFoundException
     * @throws UnsupportedModelException
     * @throws RunFailedException
     */
    protected function run(string $model, array $options, ?callable $onOutput = null): array
    {
        $this->ensureCanRun($model);

        $command = [$this->storage->binaryPath(static::tool()), '--model', $this->modelPath($model)];
        foreach ($options as $name => $value) {
            if ($value === true) {
                $command[] = $name;
            } elseif ($value !== null && $value !== false) {
                array_push($command, $name, (string) $value);
            }
        }

        $process = new Process($command, timeout: $this->timeout);
        $process->run(static function (string $type, string $buffer) use ($onOutput): void {
            if ($onOutput !== null && $type === Process::ERR) {
                $onOutput($buffer);
            }
        });

        $exitCode = $process->getExitCode() ?? -1;
        $result = self::parseResult($process->getOutput());

        if ($exitCode !== 0 || $result === null) {
            throw new RunFailedException($exitCode, $process->getErrorOutput());
        }

        return $result;
    }

    /**
     * Libraries may print to stdout too, so the result is the last line that is a JSON object.
     *
     * @return array<mixed>|null
     */
    private static function parseResult(string $output): ?array
    {
        $lines = preg_split('/\R/', $output, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (array_reverse($lines) as $line) {
            $result = json_decode($line, true);

            if (is_array($result) && !array_is_list($result)) {
                return $result;
            }
        }

        return null;
    }
}
