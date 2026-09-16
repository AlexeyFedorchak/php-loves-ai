<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Config;

use PhpLovesAi\Config\ImageToImageConfig;
use PHPUnit\Framework\TestCase;

final class ImageToImageConfigTest extends TestCase
{
    public function testLoadsPackageConfig(): void
    {
        $config = ImageToImageConfig::load();

        self::assertSame('~/tmp/hugging-face/images', $config->outputDir);
        self::assertNull($config->logFile);
    }
}
