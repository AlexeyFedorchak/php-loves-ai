<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Config;

use PhpLovesAi\Config\TextToSpeechConfig;
use PHPUnit\Framework\TestCase;

final class TextToSpeechConfigTest extends TestCase
{
    public function testLoadsPackageConfig(): void
    {
        $config = TextToSpeechConfig::load();

        self::assertSame('~/tmp/hugging-face/audio', $config->outputDir);
        self::assertNull($config->logFile);
    }
}
