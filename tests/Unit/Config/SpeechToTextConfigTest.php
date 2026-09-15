<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Config;

use PhpLovesAi\Config\SpeechToTextConfig;
use PHPUnit\Framework\TestCase;

final class SpeechToTextConfigTest extends TestCase
{
    public function testLoadsPackageConfig(): void
    {
        self::assertNull(SpeechToTextConfig::load()->logFile);
    }
}
