<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Support;

use PhpLovesAi\Binary\Tool;
use PhpLovesAi\Filesystem\LocalStorage;
use PhpLovesAi\Filesystem\Path;

/**
 * A throwaway project directory in the system temp dir, with its own .local storage.
 */
final class FakeProject
{
    public const FAKE_PULLER = __DIR__ . '/../Fixtures/fake-puller';

    public const FAKE_TEXT_TO_IMAGE = __DIR__ . '/../Fixtures/fake-text-to-image';

    public readonly string $root;

    public readonly LocalStorage $storage;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/php-loves-ai-project-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $this->storage = new LocalStorage($this->root);
    }

    /**
     * Installs $fixture as $tool's executable, where `setup` would put it.
     */
    public function install(Tool $tool, string $fixture): self
    {
        $path = $this->storage->binaryPath($tool);
        is_dir(dirname($path)) || mkdir(dirname($path), 0777, true);
        copy($fixture, $path);
        chmod($path, 0755);

        return $this;
    }

    /**
     * Creates an (empty) pulled model directory.
     */
    public function addModel(string $model): self
    {
        mkdir($this->storage->modelPath($model), 0777, true);

        return $this;
    }

    public function remove(): void
    {
        Path::remove($this->root);
    }
}
