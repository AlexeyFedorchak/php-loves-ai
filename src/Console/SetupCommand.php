<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Binary\Installer;
use PhpLovesAi\Binary\Platform;
use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Exception\InstallFailedException;
use PhpLovesAi\Exception\InvalidApiKeyException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\HuggingFace\Credentials;
use PhpLovesAi\Runner\TextToImage;

/**
 * CLI entry point behind `vendor/bin/loves-ai setup`: downloads the prebuilt binaries for this OS into the project's
 * .local/runners directory, where `pull` and the runners find them automatically, and saves the optional
 * Hugging Face API key into the project's credentials.
 */
final class SetupCommand extends Command
{
    protected const NAME = 'setup';

    protected const DESCRIPTION = 'Download the runner binaries for this computer';

    protected const OPTIONS = ['token'];

    protected const FLAGS = ['force'];

    protected const USAGE = <<<'TXT'
        Usage: vendor/bin/loves-ai setup [binary ...] [options]

        Download the prebuilt binaries for this computer. Run it once after `composer require`.

        Arguments:
          binary             Which binaries to install (default: puller)
                               puller         needed by `pull` (~17 MB)
                               text-to-image  needed by `text-to-image` (a few hundred MB)
                               text-to-text   needed by `text-to-text` (a few hundred MB)
                               image-to-text  needed by `image-to-text` (a few hundred MB)
                               speech-to-text needed by `speech-to-text` (a few hundred MB)
                               text-to-speech needed by `text-to-speech` (a few hundred MB)
                               image-to-image needed by `image-to-image` (a few hundred MB)
                               text-to-video  needed by `text-to-video` (a few hundred MB)
                               image-to-video needed by `image-to-video` (a few hundred MB)

        Options:
          --token=KEY        Save this Hugging Face API key instead of asking for it
          --force            Download again even when already installed
          --debug            Show where binaries are downloaded from and installed to
          -h, --help         Show this help

        Binaries are installed into .local/runners in the project root, where every process running the
        project (CLI, web server, queue worker, other containers sharing it) finds them.

        A Hugging Face API key is optional: public models are pulled without one, private and gated models
        need it. When none is saved yet, setup asks for it once (press Enter to skip) and saves the answer
        in .local/huggingface/credentials.json.

        Environment:
          PHP_LOVES_AI_DOWNLOAD_URL   Base URL to download binaries from (default: this version's GitHub release)
          NO_COLOR                    Disable colored output when set

        TXT;

    /** @var resource */
    private $stdin;

    private readonly bool $interactive;

    /**
     * @param Installer|null $installer   defaults to one installing into the project's .local/runners
     * @param resource|null  $stdout
     * @param resource|null  $stderr
     * @param resource|null  $stdin       where the API key is read from
     * @param bool|null      $interactive whether to ask for the API key; defaults to whether stdin is a terminal
     */
    public function __construct(
        private ?Installer $installer = null,
        $stdout = null,
        $stderr = null,
        $stdin = null,
        ?bool $interactive = null,
    ) {
        parent::__construct($stdout, $stderr);
        $this->stdin = $stdin ?? STDIN;
        $this->interactive = $interactive ?? stream_isatty($this->stdin);
    }

    protected function execute(array $positional, array $options): int
    {
        $tools = self::tools($positional);
        $force = isset($options['force']);

        $installer = $this->installer ??= new Installer();
        $storage = $installer->storage();

        $this->writeLine(sprintf('🧰 Setting up php-loves-ai (%s) for %s', $installer->version(), Platform::current()), self::BOLD_CYAN);
        if ($this->debug) {
            $this->writeLine("   Installing into {$storage->runnersDir()}", self::GREY);
        }

        $this->setUpApiKey(new Credentials($storage), $options['token'] ?? null);

        foreach ($tools as $tool) {
            if (!$force && $storage->isInstalled($tool)) {
                $this->writeLine("✅ The {$tool->label()} is already installed at {$storage->binaryPath($tool)}");
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

            $this->finishProgress("✅ The {$tool->label()} is installed at {$path}");
        }

        $exitCode = $this->succeeded('All set! Happy hacking 🍪');

        foreach ($tools as $tool) {
            $this->writeLine("👉 {$tool->usage()}", self::GREY);
        }
        foreach (Tool::cases() as $tool) {
            if ($tool->purpose() !== null && !in_array($tool, $tools, true) && !$storage->isInstalled($tool)) {
                $this->writeLine("💡 Want to {$tool->purpose()} too? Run: {$tool->setupCommand()} (a few hundred MB)", self::GREY);
            }
        }

        return $exitCode;
    }

    /**
     * Saves the API key given with --token, or asks for it once when none was saved or declined yet.
     */
    private function setUpApiKey(Credentials $credentials, ?string $token): void
    {
        if ($token !== null) {
            $credentials->saveApiKey($token);
            $this->writeLine("🔑 Saved your Hugging Face API key to {$credentials->path()}");

            return;
        }

        $addLater = 'Add one any time with: vendor/bin/loves-ai setup --token=<your Hugging Face API key>';

        if ($credentials->isConfigured()) {
            $this->writeLine($credentials->apiKey() !== null
                ? "🔑 Using the Hugging Face API key saved in {$credentials->path()}"
                : "🔑 No Hugging Face API key: only public models can be pulled. {$addLater}");

            return;
        }

        if (!$this->interactive) {
            $this->writeLine("💡 No Hugging Face API key saved: only public models can be pulled. {$addLater}", self::GREY);

            return;
        }

        $this->writeLine('🔑 Hugging Face API key (optional)', self::BOLD_CYAN);
        $this->writeLine(sprintf(
            "   Public models, like %s, are pulled without a key. Private and gated models need one:\n   create it at %s",
            TextToImage::EXAMPLE_MODEL,
            Credentials::TOKENS_URL,
        ));

        while (true) {
            $apiKey = $this->askHidden('   Paste your key (hidden), or press Enter to use public models only: ');

            if ($apiKey === null || $apiKey === '') {
                $credentials->declineApiKey();
                $this->writeLine("👌 Continuing without a key: only public models can be pulled. {$addLater}");

                return;
            }

            try {
                $credentials->saveApiKey($apiKey);
            } catch (InvalidApiKeyException $e) {
                $this->writeLine("   {$e->getMessage()}", self::YELLOW);
                continue;
            }

            $this->writeLine("✅ Saved your Hugging Face API key to {$credentials->path()}");

            return;
        }
    }

    /**
     * Reads one line from stdin without echoing it on a terminal; null when stdin is closed.
     */
    private function askHidden(string $prompt): ?string
    {
        fwrite($this->stdout, $prompt);

        $hide = DIRECTORY_SEPARATOR === '/' && stream_isatty($this->stdin);
        if ($hide) {
            shell_exec('stty -echo');
        }

        try {
            $line = fgets($this->stdin);
        } finally {
            if ($hide) {
                shell_exec('stty echo');
                fwrite($this->stdout, "\n");
            }
        }

        return $line === false ? null : trim($line);
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
