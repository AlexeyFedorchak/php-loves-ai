<?php

declare(strict_types=1);

namespace PhpLovesAi\Console;

use PhpLovesAi\Config\PullConfig;
use PhpLovesAi\Exception\MissingApiKeyException;
use PhpLovesAi\Exception\ModelAccessDeniedException;
use PhpLovesAi\Exception\PhpLovesAiException;
use PhpLovesAi\Exception\PullFailedException;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\HuggingFace\Credentials;
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

    protected const OPTIONS = ['revision', 'token', 'log-file'];

    protected const USAGE = <<<'TXT'
        Usage: pull <model> [options]

        Pull a model from the Hugging Face Hub into .local/models/<model> in the project root.

        Arguments:
          model              Hugging Face model id, e.g. openai-community/gpt2

        Options:
          --revision=REV     Branch, tag or commit hash (default: 'revision' in config/pull.php)
          --token=KEY        Hugging Face API key for private and gated models; saved in the project for next time
          --log-file=PATH    Append the puller's output to this file (default: 'log_file' in config/pull.php)
          --debug            Show the puller's output while pulling
          -h, --help         Show this help

        Public models need no API key. The key saved by `setup` or --token is stored in
        .local/huggingface/credentials.json in the project root.

        Environment:
          NO_COLOR           Disable colored output when set

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

        if (isset($options['token'])) {
            $credentials = new Credentials($this->storage);
            $credentials->saveApiKey($options['token']);
            $this->writeLine("🔑 Saved your Hugging Face API key to {$credentials->path()}");
        }

        $this->startLog($options['log-file'] ?? $config->logFile, "pull {$model} (revision {$revision})");
        $this->writeIntro(self::INTROS, ['{model}' => $model]);

        try {
            $paths = $puller->pull([$model], $revision, $this->binaryOutputHandler());
        } catch (PullFailedException $e) {
            return $this->binaryFailed("Error: failed to pull {$model} (exit code {$e->exitCode}).");
        }

        return $this->succeeded("Pulled {$model} into {$paths[$model]}");
    }

    protected function hintFor(PhpLovesAiException $e): ?string
    {
        $retryWithKey = 'Re-run with your key: vendor/bin/pull %s --token=<your Hugging Face API key> (create one at ' . Credentials::TOKENS_URL . ')';

        return match (true) {
            $e instanceof ModelAccessDeniedException && !$e->apiKeyUsed => sprintf($retryWithKey, $e->model),
            $e instanceof ModelAccessDeniedException && $e->reason === ModelAccessDeniedException::NOT_FOUND => "Check the model id at https://huggingface.co/{$e->model}, and that your key's account can open it.",
            $e instanceof MissingApiKeyException => sprintf($retryWithKey, '<model>') . ', or update the puller with: vendor/bin/setup --force',
            default => parent::hintFor($e),
        };
    }
}
