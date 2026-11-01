<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Config;

use PhpLovesAi\Config\FaceConfig;
use PhpLovesAi\Exception\InvalidConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FaceConfigTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testLoadsPackageConfig(): void
    {
        $config = FaceConfig::load();

        self::assertSame(256, $config->hashBits);
        self::assertSame(0.40, $config->threshold);
        self::assertSame(0.5, $config->minScore);
        self::assertSame(640, $config->detectionSize);
        self::assertNull($config->logFile);
    }

    public function testAcceptsAWholeNumberForAThreshold(): void
    {
        self::assertSame(1.0, $this->load(['threshold' => 1])->threshold);
    }

    public function testAcceptsANegativeThreshold(): void
    {
        self::assertSame(-0.2, $this->load(['threshold' => -0.2])->threshold);
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValue(string $key, mixed $value, string $expected): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'{$key}' must be {$expected}.");

        $this->load([$key => $value]);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'bit count not offered' => ['hash_bits', 100, 'one of 64, 128, 256, 512'];
        yield 'bit count as a string' => ['hash_bits', '256', 'one of 64, 128, 256, 512'];
        yield 'bit count missing' => ['hash_bits', null, 'one of 64, 128, 256, 512'];
        yield 'threshold above one' => ['threshold', 1.5, 'a number between -1 and 1'];
        yield 'threshold below minus one' => ['threshold', -2.0, 'a number between -1 and 1'];
        yield 'threshold as a string' => ['threshold', '0.4', 'a number between -1 and 1'];
        yield 'score of zero' => ['min_score', 0.0, 'a number between 0.01 and 1'];
        yield 'score above one' => ['min_score', 2.0, 'a number between 0.01 and 1'];
        yield 'detection size too small' => ['detection_size', 32, 'a whole number of at least 64'];
        yield 'detection size as a float' => ['detection_size', 640.0, 'a whole number of at least 64'];
    }

    /**
     * Writes a config file with $overrides applied to the package's own values and loads it.
     *
     * @param array<string, mixed> $overrides
     */
    private function load(array $overrides): FaceConfig
    {
        $values = array_merge([
            'hash_bits' => 256,
            'threshold' => 0.40,
            'min_score' => 0.5,
            'detection_size' => 640,
            'log_file' => null,
        ], $overrides);

        $file = sys_get_temp_dir() . '/php-loves-ai-face-' . bin2hex(random_bytes(4)) . '.php';
        $this->files[] = $file;
        file_put_contents($file, '<?php return ' . var_export($values, true) . ';');

        return FaceConfig::load($file);
    }
}
