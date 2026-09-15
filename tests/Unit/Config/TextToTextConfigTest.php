<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Config;

use PhpLovesAi\Config\TextToTextConfig;
use PHPUnit\Framework\TestCase;

final class TextToTextConfigTest extends TestCase
{
    public function testLoadsPackageConfig(): void
    {
        self::assertNull(TextToTextConfig::load()->logFile);
    }
}
