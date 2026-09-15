<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Config;

use PhpLovesAi\Config\TextToImageConfig;
use PHPUnit\Framework\TestCase;

final class TextToImageConfigTest extends TestCase
{
    public function testLoadsPackageConfig(): void
    {
        $config = TextToImageConfig::load();

        self::assertSame('~/tmp/hugging-face/images', $config->outputDir);
        self::assertNull($config->logFile);
    }
}
