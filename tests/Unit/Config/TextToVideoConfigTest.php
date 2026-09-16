<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Config;

use PhpLovesAi\Config\TextToVideoConfig;
use PHPUnit\Framework\TestCase;

final class TextToVideoConfigTest extends TestCase
{
    public function testLoadsPackageConfig(): void
    {
        $config = TextToVideoConfig::load();

        self::assertSame('~/tmp/hugging-face/videos', $config->outputDir);
        self::assertNull($config->logFile);
    }
}
