<?php

declare(strict_types=1);

namespace PhpLovesAi\Binary;

use Composer\InstalledVersions;
use PhpLovesAi\Exception\HomeDirectoryNotFoundException;
use PhpLovesAi\Filesystem\Path;

/**
 * Where `vendor/bin/setup` installs binaries, and where commands look for them:
 * <home>/bin/<package version>/<tool executable>.
 *
 * The location is derived from the OS, the platform and the installed package version only, so nothing needs to be
 * remembered between `setup` and later commands, and upgrading the package switches to matching binaries.
 */
final class BinaryStore
{
    /** Overrides the default home directory, e.g. for Docker images or web servers running as another user. */
    public const HOME_ENV = 'PHP_LOVES_AI_HOME';

    public const PACKAGE = 'php-loves-ai/php-loves-ai';

    /** Version used for development installs, which download from the latest release. */
    public const LATEST = 'latest';

    private readonly string $home;

    private readonly string $version;

    /**
     * @param string|null $home    defaults to PHP_LOVES_AI_HOME, else the OS's per-user application data directory
     * @param string|null $version defaults to the installed version of this package
     *
     * @throws HomeDirectoryNotFoundException
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
     * macOS: ~/Library/Application Support/php-loves-ai
     * Linux: $XDG_DATA_HOME/php-loves-ai (~/.local/share/php-loves-ai)
     * Windows: %LOCALAPPDATA%\php-loves-ai
     *
     * @throws HomeDirectoryNotFoundException
     */
    public static function defaultHome(): string
    {
        $custom = getenv(self::HOME_ENV);
        if (is_string($custom) && $custom !== '') {
            return Path::expandHome($custom);
        }

        return match (PHP_OS_FAMILY) {
            'Darwin' => Path::expandHome('~/Library/Application Support/php-loves-ai'),
            'Windows' => (getenv('LOCALAPPDATA') ?: Path::expandHome('~/AppData/Local')) . '/php-loves-ai',
            default => (getenv('XDG_DATA_HOME') ?: Path::expandHome('~/.local/share')) . '/php-loves-ai',
        };
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
