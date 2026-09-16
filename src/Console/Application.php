<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Binary\Installer;
use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Filesystem\LocalStorage;

/**
 * CLI entry point behind `vendor/bin/loves-ai`: the package's only command, which runs the others as subcommands,
 * e.g. `vendor/bin/loves-ai text-to-image <model> "<prompt>"`.
 *
 * Without a subcommand it lists what the package can do and which runners are installed.
 */
final class Application
{
    /** How the command is called; used in every usage line and hint. */
    public const NAME = 'loves-ai';

    /** How users type it, from a project's root. */
    public const COMMAND = 'vendor/bin/' . self::NAME;

    /**
     * The subcommands, in the order they are listed.
     *
     * @var list<class-string<Command>>
     */
    private const COMMANDS = [
        SetupCommand::class,
        PullCommand::class,
        TextToImageCommand::class,
        ImageToImageCommand::class,
        TextToVideoCommand::class,
        ImageToVideoCommand::class,
        TextToTextCommand::class,
        ImageToTextCommand::class,
        SpeechToTextCommand::class,
        TextToSpeechCommand::class,
    ];

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private readonly LocalStorage $storage;

    /**
     * @param resource|null     $stdout
     * @param resource|null     $stderr
     * @param LocalStorage|null $storage defaults to the project's own; used to mark installed runners
     */
    public function __construct($stdout = null, $stderr = null, ?LocalStorage $storage = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->storage = $storage ?? new LocalStorage();
    }

    /**
     * @param list<string> $args command-line arguments, without the script name
     */
    public function run(array $args): int
    {
        $name = $args[0] ?? null;

        if ($name === null || in_array($name, ['help', '-h', '--help'], true)) {
            $this->writeOverview();

            return Command::EXIT_OK;
        }

        if (in_array($name, ['-V', '--version'], true)) {
            fwrite($this->stdout, sprintf("%s %s\n", self::NAME, Installer::installedVersion()));

            return Command::EXIT_OK;
        }

        foreach (self::COMMANDS as $command) {
            if ($command::commandName() === $name) {
                return (new $command(null, $this->stdout, $this->stderr))->run(array_slice($args, 1));
            }
        }

        fwrite($this->stderr, sprintf(
            "Error: unknown command '%s'. Available: %s.\nRun '%s --help' to see what each one does.\n",
            $name,
            implode(', ', array_map(static fn (string $command): string => $command::commandName(), self::COMMANDS)),
            self::COMMAND,
        ));

        return Command::EXIT_USAGE;
    }

    /**
     * Lists the subcommands, marking the runners whose binary is installed.
     */
    private function writeOverview(): void
    {
        $installed = [];
        foreach (Tool::cases() as $tool) {
            $installed[$tool->value] = $this->storage->isInstalled($tool);
        }

        fwrite($this->stdout, sprintf(
            "🧰 php-loves-ai %s — run small AI models locally, without installing Python\n\nUsage: %s <command> [arguments] [options]\n\n",
            Installer::installedVersion(),
            self::COMMAND,
        ));

        $width = max(array_map(static fn (string $command): int => strlen($command::commandName()), self::COMMANDS));

        foreach (self::COMMANDS as $index => $command) {
            $name = $command::commandName();
            if ($index === 2) {
                fwrite($this->stdout, "\nTasks (✅ = runner installed, run `setup <task>` for the others):\n");
            }

            fwrite($this->stdout, sprintf(
                "  %s %-{$width}s  %s\n",
                ($installed[$name] ?? null) === true ? '✅' : '  ',
                $name,
                $command::describe(),
            ));
        }

        fwrite($this->stdout, sprintf(
            "\nRun '%s <command> --help' for a command's arguments and options.\n",
            self::COMMAND,
        ));
    }
}
