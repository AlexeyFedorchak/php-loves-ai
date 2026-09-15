<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

use PhpLovesAi\Binary\BinaryStore;
use PhpLovesAi\Exception\BinaryNotFoundException;
use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\HomeDirectoryNotFoundException;
use PhpLovesAi\Exception\ModelNotFoundException;
use PhpLovesAi\Exception\RunFailedException;
use PhpLovesAi\Filesystem\Path;
use Symfony\Component\Process\Process;

/**
 * Shared plumbing for runners: locating the binary and the model, running the process and reading its result.
 *
 * Runner binaries share one contract: `<binary> --model <model dir> [task options]`, progress on stderr, and the
 * result as a JSON object on the last such line of stdout; a non-zero exit code means the run failed.
 */
abstract class BinaryRunner implements Runner
{
    private readonly string $modelsDir;

    /**
     * @param string     $binaryPath path to the compiled runner binary
     * @param string     $modelsDir  directory models were pulled into; a model is loaded from <modelsDir>/<model id>.
     *                               A leading "~" is expanded to the user's home directory
     * @param float|null $timeout    seconds before a run is aborted; null waits indefinitely
     *
     * @throws HomeDirectoryNotFoundException
     */
    final public function __construct(
        private readonly string $binaryPath,
        string $modelsDir,
        private readonly ?float $timeout = null,
    ) {
        $this->modelsDir = Path::expandHome($modelsDir);
    }

    /**
     * @throws BinaryNotInstalledException
     * @throws HomeDirectoryNotFoundException
     */
    public static function installed(string $modelsDir, ?float $timeout = null, ?BinaryStore $store = null): static
    {
        $store ??= new BinaryStore();
        if (!$store->isInstalled(static::tool())) {
            throw new BinaryNotInstalledException(static::tool());
        }

        return new static($store->path(static::tool()), $modelsDir, $timeout);
    }

    public function modelPath(string $model): string
    {
        return rtrim($this->modelsDir, '/\\') . '/' . $model;
    }

    public function ensureCanRun(string $model): void
    {
        $modelPath = $this->modelPath($model);
        if (!is_dir($modelPath)) {
            throw new ModelNotFoundException($model, $modelPath);
        }

        if (!is_file($this->binaryPath) || !is_executable($this->binaryPath)) {
            throw BinaryNotFoundException::atPath($this->binaryPath);
        }
    }

    /**
     * Runs the binary with a model and returns the result object it printed.
     *
     * @param array<string, string|int|float|null> $options  task options in order, e.g. ['--prompt' => 'a cat'];
     *                                                       null values are left out
     * @param (callable(string): void)|null        $onOutput receives the runner's progress output as it arrives
     *
     * @return array<mixed>
     *
     * @throws ModelNotFoundException
     * @throws BinaryNotFoundException
     * @throws RunFailedException
     */
    protected function run(string $model, array $options, ?callable $onOutput = null): array
    {
        $this->ensureCanRun($model);

        $command = [$this->binaryPath, '--model', $this->modelPath($model)];
        foreach ($options as $name => $value) {
            if ($value !== null) {
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
