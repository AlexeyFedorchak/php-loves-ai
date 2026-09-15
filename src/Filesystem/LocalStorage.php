<?php

declare(strict_types=1);

namespace PhpLovesAi\Filesystem;

use Composer\InstalledVersions;
use PhpLovesAi\Binary\Tool;

/**
 * The single place models and runner binaries live, inside the project that required this package:
 *
 *   <project root>/.local/models/<model id>
 *   <project root>/.local/runners/<tool executable>
 *
 * Paths depend only on the project directory, never on the user, HOME, the working directory or any environment
 * variable, so `setup`, `pull`, the CLI, a web server, queue workers and other containers sharing the project all
 * agree on them without configuration.
 */
final class LocalStorage
{
    public const DIR = '.local';

    private readonly string $dir;

    /**
     * @param string|null $projectRoot defaults to the root of the project that required this package
     */
    public function __construct(?string $projectRoot = null)
    {
        $this->dir = rtrim($projectRoot ?? self::projectRoot(), '/\\') . '/' . self::DIR;
    }

    /** <project root>/.local */
    public function dir(): string
    {
        return $this->dir;
    }

    /** <project root>/.local/models */
    public function modelsDir(): string
    {
        return $this->dir . '/models';
    }

    /** <project root>/.local/runners */
    public function runnersDir(): string
    {
        return $this->dir . '/runners';
    }

    /** Directory a model is pulled into and loaded from, e.g. <project root>/.local/models/stabilityai/sd-turbo */
    public function modelPath(string $model): string
    {
        return $this->modelsDir() . '/' . $model;
    }

    /** Executable of an installed binary, e.g. <project root>/.local/runners/puller-darwin-arm64 */
    public function binaryPath(Tool $tool): string
    {
        return $this->runnersDir() . '/' . $tool->executablePath();
    }

    public function isInstalled(Tool $tool): bool
    {
        $path = $this->binaryPath($tool);

        return is_file($path) && is_executable($path);
    }

    /**
     * Creates $dir (one of this storage's directories) and keeps .local, with its hundreds of MB of models and
     * binaries, out of the project's git repository.
     *
     * @return bool whether $dir exists afterwards
     */
    public function ensureDirectory(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }

        $gitignore = $this->dir . '/.gitignore';
        if (!is_file($gitignore)) {
            @file_put_contents($gitignore, "*\n");
        }

        return true;
    }

    /**
     * The root of the project that required this package (the directory holding its composer.json), as recorded by
     * Composer; independent of the working directory.
     */
    public static function projectRoot(): string
    {
        $path = InstalledVersions::getRootPackage()['install_path'];

        return rtrim(realpath($path) ?: $path, '/\\');
    }
}
