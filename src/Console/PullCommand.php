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
 * The puller's own output is hidden unless --debug is given; it is appended to the log file when one is configured.
 */
final class PullCommand
{
    public const EXIT_OK = 0;
    public const EXIT_FAILURE = 1;
    public const EXIT_USAGE = 2;

    /** Options that take a value. */
    private const OPTIONS = ['dir', 'revision', 'log-file'];

    /** Options that take no value. */
    private const FLAGS = ['help', 'debug'];

    /**
     * Cozy messages shown before pulling, one picked at random: [emoji, lead ("{model}" is replaced), follow-up].
     *
     * @var list<array{string, string, string}>
     */
    public const INTROS = [
        ['☕', 'Pulling {model}…', 'Big downloads take a moment — perfect time for a cup of tea and some cookies 🍪'],
        ['🍪', 'Fetching {model}…', "This can take a little while — go bake some cookies, we'll be here when you're back ☕"],
        ['🧸', 'Sit back and relax — {model} is on its way.', 'Big models take a moment, so grab some tea and cookies ☕🍪'],
        ['☕', 'Brewing {model} for you…', 'Perfect moment for a warm drink and a cookie or two 🍪'],
        ['🌙', 'Downloading {model}…', 'Cozy up with a blanket, some cookies and tea — this may take a bit ✨'],
    ];

    private const BOLD_CYAN = '1;36';
    private const YELLOW = '33';
    private const GREEN = '32';
    private const RED = '31';
    private const GREY = '90';

    private const USAGE = <<<'TXT'
        Usage: pull <model> [options]

        Pull a model from the Hugging Face Hub.

        Arguments:
          model              Hugging Face model id, e.g. openai-community/gpt2

        Options:
          --dir=DIR          Directory to save models into (default: 'models_dir' in config/pull.php)
          --revision=REV     Branch, tag or commit hash (default: 'revision' in config/pull.php)
          --log-file=PATH    Append the puller's output to this file (default: 'log_file' in config/pull.php)
          --debug            Show the puller's output while pulling
          -h, --help         Show this help

        Environment:
          HUGGING_FACE_API_KEY   Hugging Face API key (required)
          NO_COLOR               Disable colored output when set

        TXT;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /** @var \Closure(int): int */
    private \Closure $pickIntro;

    /**
     * @param PullConfig|null             $config    defaults to the package's config/pull.php
     * @param resource|null               $stdout
     * @param resource|null               $stderr
     * @param (\Closure(int): int)|null   $pickIntro receives the number of intros, returns the index to show;
     *                                               defaults to a random pick
     */
    public function __construct(
        private ?PullConfig $config = null,
        $stdout = null,
        $stderr = null,
        ?\Closure $pickIntro = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->pickIntro = $pickIntro ?? static fn (int $count): int => random_int(0, $count - 1);
    }

    /**
     * @param list<string> $args command-line arguments, without the script name
     */
    public function run(array $args): int
    {
        $log = null;
        $logPath = null;
        $debug = false;

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
            $debug = isset($options['debug']);
            $config = $this->config ??= PullConfig::load();
            $revision = $options['revision'] ?? $config->revision;
            $puller = new ModelPuller($config->binary, $options['dir'] ?? $config->modelsDir);
            $puller->ensureCanPull([$model]);

            $logPath = $options['log-file'] ?? $config->logFile;
            if ($logPath !== null) {
                $logPath = Path::expandHome($logPath);
                $log = self::openLog($logPath);
                fwrite($log, sprintf("[%s] pull %s (revision %s)\n", date('Y-m-d H:i:s P'), $model, $revision));
            }

            $this->writeIntro($model, $debug);

            $stderr = $this->stderr;
            $paths = $puller->pull(
                [$model],
                $revision,
                $log === null && !$debug ? null : static function (string $chunk) use ($log, $debug, $stderr): void {
                    if ($debug) {
                        fwrite($stderr, $chunk);
                    }
                    if ($log !== null) {
                        fwrite($log, $chunk);
                    }
                },
            );

            return $this->report($this->stdout, "Pulled {$model} into {$paths[$model]}", self::EXIT_OK, $log);
        } catch (\InvalidArgumentException $e) {
            return $this->report($this->stderr, "Error: {$e->getMessage()}", self::EXIT_USAGE, $log, "Run 'pull --help' for usage.");
        } catch (BinaryNotFoundException $e) {
            return $this->report($this->stderr, "Error: {$e->getMessage()}", self::EXIT_FAILURE, $log, "Build it with python/puller/build.sh or set 'binary' in config/pull.php.");
        } catch (PullFailedException $e) {
            $hint = match (true) {
                $logPath !== null => "See {$logPath} for details.",
                $debug => null,
                default => 'Re-run the command with the "--debug" option to see what went wrong.',
            };

            return $this->report($this->stderr, "Error: failed to pull {$model} (exit code {$e->exitCode}).", self::EXIT_FAILURE, $log, $hint);
        } catch (PhpLovesAiException $e) {
            return $this->report($this->stderr, "Error: {$e->getMessage()}", self::EXIT_FAILURE, $log);
        } finally {
            if ($log !== null) {
                fclose($log);
            }
        }
    }

    private function writeIntro(string $model, bool $debug): void
    {
        [$emoji, $lead, $followUp] = self::INTROS[($this->pickIntro)(count(self::INTROS))] ?? self::INTROS[0];

        fwrite($this->stdout, sprintf(
            "%s %s %s\n",
            $emoji,
            $this->style($this->stdout, str_replace('{model}', $model, $lead), self::BOLD_CYAN),
            $this->style($this->stdout, $followUp, self::YELLOW),
        ));

        if (!$debug) {
            fwrite($this->stdout, $this->style($this->stdout, 'If you wish to see all logs, re-run the command with the "--debug" option.', self::GREY) . "\n");
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
        $console = $exitCode === self::EXIT_OK
            ? '🎉 ' . $this->style($stream, $message, self::GREEN)
            : $this->style($stream, $message, self::RED);

        if ($hint !== null) {
            $console .= "\n" . $this->style($stream, $hint, self::GREY);
        }

        fwrite($stream, "{$console}\n");

        if ($log !== null) {
            fwrite($log, "{$message}\n");
        }

        return $exitCode;
    }

    /**
     * Wraps $text in an ANSI color when $stream is a terminal that supports it and NO_COLOR is not set.
     *
     * @param resource $stream
     */
    private function style($stream, string $text, string $code): string
    {
        if (getenv('NO_COLOR') !== false || !stream_isatty($stream)) {
            return $text;
        }

        if (DIRECTORY_SEPARATOR === '\\' && !(function_exists('sapi_windows_vt100_support') && sapi_windows_vt100_support($stream))) {
            return $text;
        }

        return "\e[{$code}m{$text}\e[0m";
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

            if ($arg === '-h') {
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

            $isFlag = in_array($name, self::FLAGS, true);
            if (!str_starts_with($arg, '--') || (!$isFlag && !in_array($name, self::OPTIONS, true))) {
                throw new \InvalidArgumentException("Unknown option: {$arg}");
            }

            if ($isFlag) {
                if ($value !== null) {
                    throw new \InvalidArgumentException("Option --{$name} does not take a value.");
                }

                $options[$name] = '';
                continue;
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
