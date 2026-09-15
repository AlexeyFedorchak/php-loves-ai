<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Binary\BinaryStore;
use PhpLovesAi\Binary\Installer;
use PhpLovesAi\Binary\Platform;
use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\InstallFailedException;
use PhpLovesAi\Exception\PhpLovesAiException;

/**
 * CLI entry point behind `vendor/bin/setup`: downloads the prebuilt binaries for this OS into the BinaryStore,
 * where `pull` and the runners find them automatically.
 */
final class SetupCommand extends Command
{
    protected const NAME = 'setup';

    protected const FLAGS = ['force'];

    protected const USAGE = <<<'TXT'
        Usage: setup [binary ...] [options]

        Download the prebuilt binaries for this computer. Run it once after `composer require`.

        Arguments:
          binary             Which binaries to install (default: puller)
                               puller         needed by `pull` (~17 MB)
                               text-to-image  needed by `text-to-image` (a few hundred MB)

        Options:
          --force            Download again even when already installed
          --debug            Show where binaries are downloaded from and installed to
          -h, --help         Show this help

        Binaries are installed into .local/share/php-loves-ai in the project root, where every process running
        the project (CLI, web server, queue worker, other containers sharing it) finds them.

        Environment:
          PHP_LOVES_AI_DOWNLOAD_URL   Base URL to download binaries from (default: this version's GitHub release)
          NO_COLOR                    Disable colored output when set

        TXT;

    /**
     * @param Installer|null $installer defaults to one installing into the default BinaryStore
     * @param resource|null  $stdout
     * @param resource|null  $stderr
     */
    public function __construct(
        private ?Installer $installer = null,
        $stdout = null,
        $stderr = null,
    ) {
        parent::__construct($stdout, $stderr);
    }

    protected function execute(array $positional, array $options): int
    {
        $tools = self::tools($positional);
        $force = isset($options['force']);

        $installer = $this->installer ??= new Installer(new BinaryStore());
        $store = $installer->store();

        $this->writeLine(sprintf('🧰 Setting up php-loves-ai (%s) for %s', $store->version(), Platform::current()), self::BOLD_CYAN);
        if ($this->debug) {
            $this->writeLine("   Installing into {$store->versionDir()}", self::GREY);
        }

        foreach ($tools as $tool) {
            if (!$force && $store->isInstalled($tool)) {
                $this->writeLine("✅ The {$tool->label()} is already installed.");
                continue;
            }

            if ($this->debug) {
                $this->writeLine("   Downloading {$installer->assetUrl($tool)}", self::GREY);
            }

            $action = "📦 Downloading the {$tool->label()}…";
            $this->writeProgress($action);

            $path = $installer->install($tool, function (int $downloaded, ?int $total) use ($action): void {
                $this->writeProgress("{$action} " . self::progress($downloaded, $total));
            });

            $this->finishProgress("✅ The {$tool->label()} is installed.");
            if ($this->debug) {
                $this->writeLine("   {$path}", self::GREY);
            }
        }

        $exitCode = $this->succeeded('All set! Happy hacking 🍪');

        if (in_array(Tool::Puller, $tools, true)) {
            $this->writeLine('👉 Pull a model: vendor/bin/pull <model>', self::GREY);
        }
        if (in_array(Tool::TextToImage, $tools, true)) {
            $this->writeLine('👉 Generate an image: vendor/bin/text-to-image <model> "<prompt>"', self::GREY);
        } elseif (!$store->isInstalled(Tool::TextToImage)) {
            $this->writeLine('💡 Want to generate images too? Run: vendor/bin/setup text-to-image (a few hundred MB)', self::GREY);
        }

        return $exitCode;
    }

    protected function hintFor(PhpLovesAiException $e): ?string
    {
        return $e instanceof InstallFailedException
            ? 'Check your internet connection and try again. An HTTP 404 means this version has no binaries for this platform (yet).'
            : parent::hintFor($e);
    }

    /**
     * @param list<string> $names
     *
     * @return list<Tool>
     */
    private static function tools(array $names): array
    {
        if ($names === []) {
            return [Tool::Puller];
        }

        $tools = [];
        foreach ($names as $name) {
            $tool = Tool::tryFrom($name) ?? throw new \InvalidArgumentException(sprintf(
                "Unknown binary '%s'. Available: %s.",
                $name,
                implode(', ', array_map(static fn (Tool $tool): string => $tool->value, Tool::cases())),
            ));

            $tools[$tool->value] = $tool;
        }

        return array_values($tools);
    }

    private static function progress(int $downloaded, ?int $total): string
    {
        $megabytes = static fn (int $bytes): string => number_format($bytes / 1048576, 1);

        if ($total === null || $total === 0) {
            return "{$megabytes($downloaded)} MB";
        }

        return sprintf('%d%% (%s / %s MB)', intdiv($downloaded * 100, $total), $megabytes($downloaded), $megabytes($total));
    }
}
