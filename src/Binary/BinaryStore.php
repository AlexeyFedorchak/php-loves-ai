<?php

declare(strict_types=1);

namespace PhpLovesAi\Binary;

use Composer\InstalledVersions;

/**
 * Where `vendor/bin/setup` installs binaries, and where commands look for them:
 * <project root>/.local/share/php-loves-ai/bin/<package version>/<tool executable>.
 *
 * The location is derived from the project's own directory and the installed package version only. It does not
 * depend on the user, HOME or any environment variable, so the CLI, a web server, a queue worker or another container
 * sharing the project all find the binaries `setup` installed, and upgrading the package switches to matching ones.
 */
final class BinaryStore
{
    /** Directory binaries are installed into, relative to the project root. */
    public const HOME_DIR = '.local/share/php-loves-ai';

    public const PACKAGE = 'php-loves-ai/php-loves-ai';

    /** Version used for development installs, which download from the latest release. */
    public const LATEST = 'latest';

    private readonly string $home;

    private readonly string $version;

    /**
     * @param string|null $home    defaults to <project root>/.local/share/php-loves-ai
     * @param string|null $version defaults to the installed version of this package
     */
    public function __construct(?string $home = null, ?string $version = null)
    {
        $this->home = $home ?? self::defaultHome();
        $this->version = $version ?? self::installedVersion();
    }

    public function home(): string
    {
        return $this->home;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function versionDir(): string
    {
        return $this->home . '/bin/' . $this->version;
    }

    public function path(Tool $tool): string
    {
        return $this->versionDir() . '/' . $tool->executablePath();
    }

    public function isInstalled(Tool $tool): bool
    {
        $path = $this->path($tool);

        return is_file($path) && is_executable($path);
    }

    /**
     * <project root>/.local/share/php-loves-ai
     */
    public static function defaultHome(): string
    {
        return self::projectRoot() . '/' . self::HOME_DIR;
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

    /**
     * The release tag matching this package's installed version, or "latest" for development installs.
     */
    public static function installedVersion(): string
    {
        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (\OutOfBoundsException) {
            $version = null;
        }

        // Branches ("dev-main", "1.x-dev") and a root package without a version ("1.0.0+no-version-set") have no release.
        if ($version === null
            || str_starts_with($version, 'dev-')
            || str_ends_with($version, '-dev')
            || str_ends_with($version, '+no-version-set')
        ) {
            return self::LATEST;
        }

        return $version;
    }
}
