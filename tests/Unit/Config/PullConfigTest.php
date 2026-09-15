<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Config;

use PhpLovesAi\Config\PullConfig;
use PhpLovesAi\Exception\InvalidConfigException;
use PHPUnit\Framework\TestCase;

final class PullConfigTest extends TestCase
{
    private ?string $tempFile = null;

    protected function tearDown(): void
    {
        if ($this->tempFile !== null) {
            unlink($this->tempFile);
        }
    }

    public function testLoadsPackageConfig(): void
    {
        $config = PullConfig::load();

        self::assertSame('main', $config->revision);
        self::assertSame('~/tmp/hugging-face/models', $config->modelsDir);
        self::assertNull($config->logFile);
        self::assertNull($config->binary, 'The binary installed by setup is used by default.');
    }

    public function testRejectsMissingFile(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Config file not found: /nonexistent/pull.php');

        PullConfig::load('/nonexistent/pull.php');
    }

    public function testRejectsInvalidValue(): void
    {
        $file = $this->writeConfig("<?php return ['binary' => '/bin/puller', 'models_dir' => '', 'revision' => 'main'];");

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'models_dir' must be a non-empty string");

        PullConfig::load($file);
    }

    public function testLoadsLogFile(): void
    {
        $file = $this->writeConfig("<?php return ['binary' => '/bin/puller', 'models_dir' => '/models', 'revision' => 'main', 'log_file' => '/logs/pull.log'];");

        self::assertSame('/logs/pull.log', PullConfig::load($file)->logFile);
    }

    public function testRejectsInvalidLogFile(): void
    {
        $file = $this->writeConfig("<?php return ['binary' => '/bin/puller', 'models_dir' => '/models', 'revision' => 'main', 'log_file' => 42];");

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'log_file' must be a non-empty string");

        PullConfig::load($file);
    }

    public function testRejectsNonArrayConfig(): void
    {
        $file = $this->writeConfig('<?php return "nope";');

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('must return an array');

        PullConfig::load($file);
    }

    private function writeConfig(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pull-config-');
        self::assertIsString($file);
        file_put_contents($file, $contents);

        return $this->tempFile = $file;
    }
}
