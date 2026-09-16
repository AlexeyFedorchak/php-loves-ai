<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Exception\BinaryNotInstalledException;
use PhpLovesAi\Exception\LogFileNotWritableException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Filesystem\Path;

/**
 * Shared behavior of the CLI commands in bin/: option parsing, --help, --debug, log files and colored output.
 *
 * The output of the underlying binary is hidden unless --debug is given; it is appended to the log file when
 * one is configured.
 */
abstract class Command
{
    public const EXIT_OK = 0;
    public const EXIT_FAILURE = 1;
    public const EXIT_USAGE = 2;

    /** Name of the subcommand, e.g. "text-to-image", used in hints. */
    protected const NAME = '';

    /** One line for the command list of `vendor/bin/loves-ai`. */
    protected const DESCRIPTION = '';

    /** Help text shown for -h / --help. */
    protected const USAGE = '';

    /**
     * Options that take a value, without the leading "--".
     *
     * @var list<string>
     */
    protected const OPTIONS = [];

    protected const BOLD_CYAN = '1;36';
    protected const YELLOW = '33';
    protected const GREEN = '32';
    protected const RED = '31';
    protected const GREY = '90';

    /**
     * Command-specific options that take no value, in addition to --help and --debug.
     *
     * @var list<string>
     */
    protected const FLAGS = [];

    /** Options every command accepts that take no value. */
    private const COMMON_FLAGS = ['help', 'debug'];

    /** @var resource */
    protected $stdout;

    /** @var resource */
    protected $stderr;

    protected bool $debug = false;

    /** @var resource|null */
    private $log = null;

    private ?string $logPath = null;

    /** @var \Closure(int): int */
    private \Closure $pickIntro;

    /**
     * @param resource|null             $stdout
     * @param resource|null             $stderr
     * @param (\Closure(int): int)|null $pickIntro receives the number of intros, returns the index to show;
     *                                             defaults to a random pick
     */
    public function __construct($stdout = null, $stderr = null, ?\Closure $pickIntro = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->pickIntro = $pickIntro ?? static fn (int $count): int => random_int(0, $count - 1);
    }

    /** The subcommand this class runs, e.g. "text-to-image". */
    final public static function commandName(): string
    {
        return static::NAME;
    }

    /** One line describing the command, for the command list. */
    final public static function describe(): string
    {
        return static::DESCRIPTION;
    }

    /**
     * @param list<string> $args command-line arguments, without the script name
     */
    final public function run(array $args): int
    {
        $this->debug = false;
        $this->log = null;
        $this->logPath = null;

        try {
            [$positional, $options] = self::parse($args, static::OPTIONS, [...self::COMMON_FLAGS, ...static::FLAGS]);

            if (isset($options['help'])) {
                fwrite($this->stdout, static::USAGE);

                return self::EXIT_OK;
            }

            $this->debug = isset($options['debug']);

            return $this->execute($positional, $options);
        } catch (\InvalidArgumentException $e) {
            return $this->report($this->stderr, "Error: {$e->getMessage()}", self::EXIT_USAGE, "Run '" . Application::COMMAND . ' ' . static::NAME . " --help' for usage.");
        } catch (PhpLovesAiException $e) {
            return $this->report($this->stderr, "Error: {$e->getMessage()}", self::EXIT_FAILURE, $this->hintFor($e));
        } finally {
            if ($this->log !== null) {
                fclose($this->log);
            }
        }
    }

    /**
     * @param list<string>          $positional
     * @param array<string, string> $options
     *
     * @throws \InvalidArgumentException on invalid usage
     * @throws PhpLovesAiException
     */
    abstract protected function execute(array $positional, array $options): int;

    /**
     * Console-only follow-up line for an error that stopped the command, e.g. how to fix it.
     */
    protected function hintFor(PhpLovesAiException $e): ?string
    {
        return $e instanceof BinaryNotInstalledException
            ? "Run {$e->tool->setupCommand()} first to download it 🧰"
            : null;
    }

    /**
     * @param string|null $color one of the color constants
     */
    protected function writeLine(string $text, ?string $color = null): void
    {
        fwrite($this->stdout, ($color === null ? $text : $this->style($this->stdout, $text, $color)) . "\n");
    }

    /**
     * Redraws the current terminal line with $text; does nothing when stdout is not a terminal.
     */
    protected function writeProgress(string $text): void
    {
        if ($this->isTerminal($this->stdout)) {
            fwrite($this->stdout, "\r\e[2K{$text}");
        }
    }

    /**
     * Ends a line drawn with writeProgress() by rewriting it as a regular line.
     */
    protected function finishProgress(string $text): void
    {
        if ($this->isTerminal($this->stdout)) {
            fwrite($this->stdout, "\r\e[2K");
        }

        $this->writeLine($text);
    }

    /**
     * Opens the log file (when a path is given) and writes a timestamped header for this run.
     *
     * @throws LogFileNotWritableException
     */
    protected function startLog(?string $path, string $header): void
    {
        if ($path === null) {
            return;
        }

        $this->logPath = Path::expandHome($path);

        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $log = @fopen($this->logPath, 'ab');
        if ($log === false) {
            throw LogFileNotWritableException::atPath($this->logPath);
        }

        $this->log = $log;
        fwrite($log, sprintf("[%s] %s\n", date('Y-m-d H:i:s P'), $header));
    }

    /**
     * Callback for the binary's output: shown with --debug, appended to the log file when logging, else dropped.
     *
     * @return (\Closure(string): void)|null
     */
    protected function binaryOutputHandler(): ?\Closure
    {
        if ($this->log === null && !$this->debug) {
            return null;
        }

        $log = $this->log;
        $stderr = $this->debug ? $this->stderr : null;

        return static function (string $chunk) use ($log, $stderr): void {
            if ($stderr !== null) {
                fwrite($stderr, $chunk);
            }
            if ($log !== null) {
                fwrite($log, $chunk);
            }
        };
    }

    /**
     * Prints one of $intros at random, followed by the --debug hint unless debugging.
     *
     * @param list<array{string, string, string}> $intros       [emoji, lead, follow-up]
     * @param array<string, string>               $replacements placeholder => value, applied to the lead
     */
    protected function writeIntro(array $intros, array $replacements): void
    {
        [$emoji, $lead, $followUp] = $intros[($this->pickIntro)(count($intros))] ?? $intros[0];

        fwrite($this->stdout, sprintf(
            "%s %s %s\n",
            $emoji,
            $this->style($this->stdout, strtr($lead, $replacements), self::BOLD_CYAN),
            $this->style($this->stdout, $followUp, self::YELLOW),
        ));

        if (!$this->debug) {
            fwrite($this->stdout, $this->style($this->stdout, 'If you wish to see all logs, re-run the command with the "--debug" option.', self::GREY) . "\n");
        }
    }

    protected function succeeded(string $message): int
    {
        return $this->report($this->stdout, $message, self::EXIT_OK);
    }

    /**
     * Reports a failed binary run, pointing to where its output can be found.
     */
    protected function binaryFailed(string $message): int
    {
        $hint = match (true) {
            $this->logPath !== null => "See {$this->logPath} for details.",
            $this->debug => null,
            default => 'Re-run the command with the "--debug" option to see what went wrong.',
        };

        return $this->report($this->stderr, $message, self::EXIT_FAILURE, $hint);
    }

    /**
     * Writes the command's outcome to the console and, when logging, to the log file.
     *
     * @param resource $stream
     * @param string|null $hint console-only follow-up line
     */
    private function report($stream, string $message, int $exitCode, ?string $hint = null): int
    {
        $console = $exitCode === self::EXIT_OK
            ? '🎉 ' . $this->style($stream, $message, self::GREEN)
            : $this->style($stream, $message, self::RED);

        if ($hint !== null) {
            $console .= "\n" . $this->style($stream, $hint, self::GREY);
        }

        fwrite($stream, "{$console}\n");

        if ($this->log !== null) {
            fwrite($this->log, "{$message}\n");
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
        return getenv('NO_COLOR') === false && $this->isTerminal($stream) ? "\e[{$code}m{$text}\e[0m" : $text;
    }

    /**
     * Whether $stream is a terminal that understands ANSI escape sequences.
     *
     * @param resource $stream
     */
    private function isTerminal($stream): bool
    {
        if (!stream_isatty($stream)) {
            return false;
        }

        return DIRECTORY_SEPARATOR !== '\\' || (function_exists('sapi_windows_vt100_support') && sapi_windows_vt100_support($stream));
    }

    /**
     * Accepts options before or after positional arguments, as "--name=value" or "--name value".
     *
     * @param list<string> $args
     * @param list<string> $valueOptions
     * @param list<string> $flags
     *
     * @return array{list<string>, array<string, string>}
     */
    private static function parse(array $args, array $valueOptions, array $flags): array
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

            $isFlag = in_array($name, $flags, true);
            if (!str_starts_with($arg, '--') || (!$isFlag && !in_array($name, $valueOptions, true))) {
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
