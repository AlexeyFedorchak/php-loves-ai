<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Config;

use PhpLovesAi\Config\JevConfig;
use PHPUnit\Framework\TestCase;

final class JevConfigTest extends TestCase
{
    public function testLoadsPackageConfig(): void
    {
        self::assertNull(JevConfig::load()->logFile);
    }
}
