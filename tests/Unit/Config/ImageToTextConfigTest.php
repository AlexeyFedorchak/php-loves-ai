<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Config;

use PhpLovesAi\Config\ImageToTextConfig;
use PHPUnit\Framework\TestCase;

final class ImageToTextConfigTest extends TestCase
{
    public function testLoadsPackageConfig(): void
    {
        self::assertNull(ImageToTextConfig::load()->logFile);
    }
}
