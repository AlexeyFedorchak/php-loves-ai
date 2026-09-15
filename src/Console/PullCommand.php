<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Config\PullConfig;
use PhpLovesAi\Exception\PullFailedException;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Process\ModelPuller;

/**
 * CLI entry point behind `vendor/bin/pull`: pulls a single model via ModelPuller.
 */
final class PullCommand extends Command
{
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

    protected const NAME = 'pull';

    protected const OPTIONS = ['revision', 'log-file'];

    protected const USAGE = <<<'TXT'
        Usage: pull <model> [options]

        Pull a model from the Hugging Face Hub into .local/models/<model> in the project root.

        Arguments:
          model              Hugging Face model id, e.g. openai-community/gpt2

        Options:
          --revision=REV     Branch, tag or commit hash (default: 'revision' in config/pull.php)
          --log-file=PATH    Append the puller's output to this file (default: 'log_file' in config/pull.php)
          --debug            Show the puller's output while pulling
          -h, --help         Show this help

        Environment:
          HUGGING_FACE_API_KEY   Hugging Face API key (required)
          NO_COLOR               Disable colored output when set

        TXT;

    /**
     * @param PullConfig|null           $config    defaults to the package's config/pull.php
     * @param resource|null             $stdout
     * @param resource|null             $stderr
     * @param (\Closure(int): int)|null $pickIntro see Command::__construct()
     * @param LocalStorage|null         $storage   defaults to the project's own
     */
    public function __construct(
        private ?PullConfig $config = null,
        $stdout = null,
        $stderr = null,
        ?\Closure $pickIntro = null,
        private readonly ?LocalStorage $storage = null,
    ) {
        parent::__construct($stdout, $stderr, $pickIntro);
    }

    protected function execute(array $positional, array $options): int
    {
        if (count($positional) !== 1) {
            throw new \InvalidArgumentException($positional === []
                ? 'Missing model argument.'
                : 'Only one model can be pulled at a time.');
        }

        $model = $positional[0];
        $config = $this->config ??= PullConfig::load();
        $revision = $options['revision'] ?? $config->revision;

        $puller = new ModelPuller($this->storage);
        $puller->ensureCanPull([$model]);

        $this->startLog($options['log-file'] ?? $config->logFile, "pull {$model} (revision {$revision})");
        $this->writeIntro(self::INTROS, ['{model}' => $model]);

        try {
            $paths = $puller->pull([$model], $revision, $this->binaryOutputHandler());
        } catch (PullFailedException $e) {
            return $this->binaryFailed("Error: failed to pull {$model} (exit code {$e->exitCode}).");
        }

        return $this->succeeded("Pulled {$model} into {$paths[$model]}");
    }
}
