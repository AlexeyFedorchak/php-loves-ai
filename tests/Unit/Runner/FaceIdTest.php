<?php

declare(strict_types=1);

namespace PhpLovesAi\Tests\Unit\Runner;

use PhpLovesAi\Exception\InvalidFaceIdException;
use PhpLovesAi\Runner\FaceId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FaceIdTest extends TestCase
{
    /** 256 bits of zeroes, as 64 hex characters. */
    private const ZEROES = 'v1:256:0000000000000000000000000000000000000000000000000000000000000000';

    public function testParsesVersionBitsAndHash(): void
    {
        $id = FaceId::parse('v1:256:3f8a21c4d9e07b15000000000000000000000000000000000000000000000000');

        self::assertSame(1, $id->version);
        self::assertSame(256, $id->bits);
        self::assertSame('3f8a21c4d9e07b15000000000000000000000000000000000000000000000000', $id->hash);
        self::assertSame('v1:256:3f8a21c4d9e07b15000000000000000000000000000000000000000000000000', (string) $id);
    }

    public function testParseIgnoresSurroundingWhitespace(): void
    {
        self::assertSame(64, FaceId::parse(" v1:64:ffffffffffffffff\n")->bits);
    }

    public function testDistanceBetweenIdenticalIdsIsZero(): void
    {
        self::assertSame(0, FaceId::distance(self::ZEROES, self::ZEROES));
    }

    public function testDistanceCountsEveryDifferingBit(): void
    {
        $all = 'v1:64:ffffffffffffffff';
        $none = 'v1:64:0000000000000000';

        self::assertSame(64, FaceId::distance($all, $none));
        self::assertSame(64, FaceId::distance($none, $all), 'the distance is symmetric');
    }

    #[DataProvider('distances')]
    public function testDistance(string $a, string $b, int $expected): void
    {
        self::assertSame($expected, FaceId::distance($a, $b));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function distances(): iterable
    {
        // 0x1 ^ 0x0 = 0001, one bit.
        yield 'one bit in the last digit' => ['v1:64:0000000000000001', 'v1:64:0000000000000000', 1];

        // 0xf ^ 0x0 = 1111, four bits.
        yield 'four bits in one digit' => ['v1:64:000000000000000f', 'v1:64:0000000000000000', 4];

        // 0x5 ^ 0xa = 1111: the two digits share no bit.
        yield 'alternating bits' => ['v1:64:5555555555555555', 'v1:64:aaaaaaaaaaaaaaaa', 64];

        yield 'one bit in the first digit' => ['v1:64:1000000000000000', 'v1:64:0000000000000000', 1];

        yield 'spread over both halves' => ['v1:64:8000000000000001', 'v1:64:0000000000000000', 2];

        yield '512-bit ids' => ['v1:512:' . str_repeat('f', 128), 'v1:512:' . str_repeat('0', 128), 512];
    }

    #[DataProvider('malformedIds')]
    public function testRejectsMalformedId(string $id): void
    {
        $this->expectException(InvalidFaceIdException::class);
        $this->expectExceptionMessage('is not a face id');

        FaceId::parse($id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedIds(): iterable
    {
        yield 'empty' => [''];
        yield 'no version' => ['256:0000000000000000'];
        yield 'no bit count' => ['v1:0000000000000000'];
        yield 'uppercase hex' => ['v1:64:FFFFFFFFFFFFFFFF'];
        yield 'not hex' => ['v1:64:zzzzzzzzzzzzzzzz'];
        yield 'truncated hash' => ['v1:64:ffffffff'];
        yield 'hash too long' => ['v1:64:' . str_repeat('f', 32)];
        yield 'bits not a multiple of eight' => ['v1:12:fff'];
        yield 'zero bits' => ['v1:0:'];
        yield 'a plain sha256' => [str_repeat('a', 64)];
    }

    public function testRefusesToCompareIdsOfDifferentWidths(): void
    {
        $this->expectException(InvalidFaceIdException::class);
        $this->expectExceptionMessage('differ in version or bit count');

        FaceId::distance('v1:64:ffffffffffffffff', 'v1:256:' . str_repeat('f', 64));
    }

    public function testRefusesToCompareIdsOfDifferentVersions(): void
    {
        $this->expectException(InvalidFaceIdException::class);
        $this->expectExceptionMessage('differ in version or bit count');

        FaceId::distance('v1:64:ffffffffffffffff', 'v2:64:ffffffffffffffff');
    }

    public function testErrorDoesNotRepeatTheWholeHash(): void
    {
        try {
            FaceId::distance('v1:64:ffffffffffffffff', 'v1:256:' . str_repeat('a', 64));
            self::fail('Expected InvalidFaceIdException.');
        } catch (InvalidFaceIdException $e) {
            self::assertStringContainsString('v1:64:…', $e->getMessage());
            self::assertStringNotContainsString('ffffffffffffffff', $e->getMessage());
        }
    }

    public function testSimilarityOfIdenticalEmbeddingsIsOne(): void
    {
        self::assertSame(1.0, FaceId::similarity([0.6, 0.8], [0.6, 0.8]));
    }

    public function testSimilarityOfOppositeEmbeddingsIsMinusOne(): void
    {
        self::assertSame(-1.0, FaceId::similarity([0.6, 0.8], [-0.6, -0.8]));
    }

    public function testSimilarityOfPerpendicularEmbeddingsIsZero(): void
    {
        self::assertSame(0.0, FaceId::similarity([1.0, 0.0], [0.0, 1.0]));
    }

    public function testSimilarityNormalizesRoundedEmbeddings(): void
    {
        // The same direction at four times the length: cosine similarity ignores magnitude.
        self::assertEqualsWithDelta(1.0, FaceId::similarity([0.6, 0.8], [2.4, 3.2]), 1e-12);
    }

    public function testSimilarityOfAZeroEmbeddingIsZero(): void
    {
        self::assertSame(0.0, FaceId::similarity([0.0, 0.0], [0.6, 0.8]));
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    #[DataProvider('mismatchedEmbeddings')]
    public function testSimilarityRejectsMismatchedEmbeddings(array $a, array $b): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same length');

        FaceId::similarity($a, $b);
    }

    /**
     * @return iterable<string, array{list<float>, list<float>}>
     */
    public static function mismatchedEmbeddings(): iterable
    {
        yield 'different lengths' => [[1.0, 0.0], [1.0]];
        yield 'both empty' => [[], []];
        yield 'one empty' => [[], [1.0]];
    }
}
