<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Config;

use PhpLovesAi\Config\ImageToVideoConfig;
use PHPUnit\Framework\TestCase;

final class ImageToVideoConfigTest extends TestCase
{
    public function testLoadsPackageConfig(): void
    {
        $config = ImageToVideoConfig::load();

        self::assertSame('~/tmp/hugging-face/videos', $config->outputDir);
        self::assertNull($config->logFile);
    }
}
