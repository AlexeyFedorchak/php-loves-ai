<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

/**
 * The result of matching the most prominent face of one image against that of another.
 */
final class FaceMatch
{
    /**
     * @param float        $similarity cosine similarity of the two embeddings, from -1 to 1; above $threshold the
     *                                 two are called the same person
     * @param bool         $samePerson whether $similarity reached $threshold
     * @param float        $threshold  the cut-off used, so a stored result still says what it meant
     * @param DetectedFace $a          the face matched in the first image, the most confident one it holds
     * @param DetectedFace $b          the face matched in the second image
     * @param int          $facesInA   faces found in the first image; more than one means $a was the pick
     * @param int          $facesInB   faces found in the second image
     */
    public function __construct(
        public readonly float $similarity,
        public readonly bool $samePerson,
        public readonly float $threshold,
        public readonly DetectedFace $a,
        public readonly DetectedFace $b,
        public readonly int $facesInA,
        public readonly int $facesInB,
    ) {
    }

    /** Whether either image held more than one face, so the match only covers the most confident of them. */
    public function wasAmbiguous(): bool
    {
        return $this->facesInA > 1 || $this->facesInB > 1;
    }
}
