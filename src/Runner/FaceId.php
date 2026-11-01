<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

use PhpLovesAi\Exception\InvalidFaceIdException;

/**
 * A face id: the locality-sensitive hash of a face's embedding, as "v<version>:<bits>:<hex>",
 * e.g. "v1:256:3f8a21c4…".
 *
 * It is a fingerprint, not a primary key. Two photos of one person give two different ids that
 * differ in only some of their bits, so ids are compared by distance(), never with ===:
 *
 *     FaceId::distance($rowA->face_id, $rowB->face_id) < 90   // 256-bit ids: likely the same person
 *
 * The expected distance between two ids is bits × θ / π, where θ is the angle between the two
 * embeddings, so unrelated faces centre on half the bits. Measured with buffalo_l at 256 bits: two
 * photos of one person came out 16–68 bits apart, the same photo rescaled and re-encoded 63, and two
 * different people 109–122. A cut-off of 90 separates those cleanly, but measure it on your own
 * images before relying on it — only you know what a wrong match costs.
 *
 * Ids only mean anything next to ids made the same way: same version, same bit count and, because
 * the hash describes a direction in one model's embedding space, the same model. On the CPU provider
 * they are reproducible across machines: one photo hashed byte-identically on macOS arm64, Linux arm64
 * and Linux x86_64, so an id survives a move from a developer's Mac to a container on either chip.
 * They are not identical across *providers* though — CPU and CoreML came out a bit or two apart on one
 * image — which is another reason never to compare them for equality. For an exact answer, compare the
 * embeddings themselves with similarity().
 */
final class FaceId
{
    /** Ids with a different version were hashed differently and cannot be compared. */
    public const VERSION = 1;

    private function __construct(
        public readonly int $version,
        public readonly int $bits,
        public readonly string $hash,
    ) {
    }

    /**
     * @throws InvalidFaceIdException
     */
    public static function parse(string $id): self
    {
        if (preg_match('/^v(\d+):(\d+):([0-9a-f]+)$/', trim($id), $match) !== 1) {
            throw InvalidFaceIdException::malformed($id);
        }

        $bits = (int) $match[2];

        // Every bit must be in the hex, or the id was truncated somewhere (a too-short column).
        if ($bits === 0 || $bits % 8 !== 0 || strlen($match[3]) !== intdiv($bits, 4)) {
            throw InvalidFaceIdException::malformed($id);
        }

        return new self((int) $match[1], $bits, $match[3]);
    }

    /**
     * How many of the two ids' bits differ: 0 for identical faces, around half the bits for
     * unrelated ones.
     *
     * @throws InvalidFaceIdException when either id is malformed, or they were hashed differently
     */
    public static function distance(string $a, string $b): int
    {
        $left = self::parse($a);
        $right = self::parse($b);

        if ($left->version !== $right->version || $left->bits !== $right->bits) {
            throw InvalidFaceIdException::notComparable($a, $b);
        }

        // Counted one hex digit at a time: the hashes are up to 512 bits, far wider than PHP's integers.
        $distance = 0;
        for ($i = 0, $length = strlen($left->hash); $i < $length; ++$i) {
            $distance += self::BITS_SET[intval($left->hash[$i], 16) ^ intval($right->hash[$i], 16)];
        }

        return $distance;
    }

    /**
     * Cosine similarity of two embeddings, from -1 to 1; the exact measure face ids approximate.
     *
     * @param list<float> $a
     * @param list<float> $b
     *
     * @throws \InvalidArgumentException when the embeddings have different lengths
     */
    public static function similarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || $a === []) {
            throw new \InvalidArgumentException(sprintf(
                'Embeddings must be non-empty and the same length, got %d and %d.',
                count($a),
                count($b),
            ));
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value ** 2;
            $normB += $b[$i] ** 2;
        }

        // The runner normalizes embeddings, but one read back from a database may have been rounded.
        return $normA === 0.0 || $normB === 0.0 ? 0.0 : $dot / sqrt($normA * $normB);
    }

    public function __toString(): string
    {
        return "v{$this->version}:{$this->bits}:{$this->hash}";
    }

    /**
     * Bits set in each of the sixteen hex digit values, so distance() does not recount them per call.
     *
     * @var list<int>
     */
    private const BITS_SET = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];
}
