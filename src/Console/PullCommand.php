<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Config\PullConfig;
use PhpLovesAi\Exception\BinaryNotFoundException;
use PhpLovesAi\Exception\LogFileNotWritableException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Exception\PullFailedException;
use PhpLovesAi\Filesystem\Path;
use PhpLovesAi\Process\ModelPuller;

/**
 * CLI entry point behind `vendor/bin/pull`: pulls a single model via ModelPuller.
 *
 * The puller's own output is never shown; it is appended to the log file when one is configured.
 */
final class PullCommand
{
    public const EXIT_OK = 0;
    public const EXIT_FAILURE = 1;
    public const EXIT_USAGE = 2;

    private const OPTIONS = ['dir', 'revision', 'log-file'];

    private const USAGE = <<<'TXT'
        Usage: pull <model> [options]

        Pull a model from the Hugging Face Hub.

        Arguments:
          model              Hugging Face model id, e.g. openai-community/gpt2

        Options:
          --dir=DIR          Directory to save models into (default: 'models_dir' in config/pull.php)
          --revision=REV     Branch, tag or commit hash (default: 'revision' in config/pull.php)
          --log-file=PATH    Append the puller's output to this file (default: 'log_file' in config/pull.php)
          -h, --help         Show this help

        Environment:
          HUGGING_FACE_API_KEY   Hugging Face API key (required)

        TXT;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param PullConfig|null $config defaults to the package's config/pull.php
     * @param resource|null   $stdout
     * @param resource|null   $stderr
     */
    public function __construct(
        private ?PullConfig $config = null,
        $stdout = null,
        $stderr = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    /**
     * @param list<string> $args command-line arguments, without the script name
     */
    public function run(array $args): int
    {
        $log = null;
        $logPath = null;

        try {
            [$positional, $options] = self::parse($args);

            if (isset($options['help'])) {
                fwrite($this->stdout, self::USAGE);

                return self::EXIT_OK;
            }

            if (count($positional) !== 1) {
                throw new \InvalidArgumentException($positional === []
                    ? 'Missing model argument.'
                    : 'Only one model can be pulled at a time.');
            }

            $model = $positional[0];
            $config = $this->config ??= PullConfig::load();
            $revision = $options['revision'] ?? $config->revision;
            $puller = new ModelPuller($config->binary, $options['dir'] ?? $config->modelsDir);

            $logPath = $options['log-file'] ?? $config->logFile;
            if ($logPath !== null) {
                $logPath = Path::expandHome($logPath);
                $log = self::openLog($logPath);
                fwrite($log, sprintf("[%s] pull %s (revision %s)\n", date('Y-m-d H:i:s P'), $model, $revision));
            }

            $paths = $puller->pull(
                [$model],
                $revision,
                $log === null ? null : static function (string $chunk) use ($log): void {
                    fwrite($log, $chunk);
                },
            );

            return $this->report($this->stdout, "Pulled {$model} into {$paths[$model]}", self::EXIT_OK, $log);
        } catch (\InvalidArgumentException $e) {
            return $this->report($this->stderr, "Error: {$e->getMessage()}", self::EXIT_USAGE, $log, "Run 'pull --help' for usage.");
        } catch (BinaryNotFoundException $e) {
            return $this->report($this->stderr, "Error: {$e->getMessage()}", self::EXIT_FAILURE, $log, "Build it with python/puller/build.sh or set 'binary' in config/pull.php.");
        } catch (PullFailedException $e) {
            $hint = $logPath !== null
                ? "See {$logPath} for details."
                : "Pass --log-file=PATH (or set 'log_file' in config/pull.php) to save the puller's output.";

            return $this->report($this->stderr, "Error: failed to pull {$model} (exit code {$e->exitCode}).", self::EXIT_FAILURE, $log, $hint);
        } catch (PhpLovesAiException $e) {
            return $this->report($this->stderr, "Error: {$e->getMessage()}", self::EXIT_FAILURE, $log);
        } finally {
            if ($log !== null) {
                fclose($log);
            }
        }
    }

    /**
     * Writes the command's outcome to the console and, when logging, to the log file.
     *
     * @param resource      $stream
     * @param resource|null $log
     * @param string|null   $hint   console-only follow-up line
     */
    private function report($stream, string $message, int $exitCode, $log, ?string $hint = null): int
    {
        fwrite($stream, $hint === null ? "{$message}\n" : "{$message}\n{$hint}\n");

        if ($log !== null) {
            fwrite($log, "{$message}\n");
        }

        return $exitCode;
    }

    /**
     * @return resource
     *
     * @throws LogFileNotWritableException
     */
    private static function openLog(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            throw LogFileNotWritableException::atPath($path);
        }

        return $handle;
    }

    /**
     * Accepts options before or after the model, as "--name=value" or "--name value".
     *
     * @param list<string> $args
     *
     * @return array{list<string>, array<string, string>}
     */
    private static function parse(array $args): array
    {
        $positional = [];
        $options = [];

        for ($i = 0, $count = count($args); $i < $count; ++$i) {
            $arg = $args[$i];

            if ($arg === '--') {
                array_push($positional, ...array_slice($args, $i + 1));
                break;
            }

            if ($arg === '-h' || $arg === '--help') {
                $options['help'] = '';
                continue;
            }

            if (!str_starts_with($arg, '-')) {
                $positional[] = $arg;
                continue;
            }

            $name = substr($arg, 2);
            $value = null;
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            if (!str_starts_with($arg, '--') || !in_array($name, self::OPTIONS, true)) {
                throw new \InvalidArgumentException("Unknown option: {$arg}");
            }

            // A following argument that looks like an option is never taken as this option's value.
            if ($value === null && isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '-')) {
                $value = $args[++$i];
            }

            if ($value === null || $value === '') {
                throw new \InvalidArgumentException("Option --{$name} requires a value.");
            }

            $options[$name] = $value;
        }

        return [$positional, $options];
    }
}
