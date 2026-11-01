<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

use PhpLovesAi\Exception\InvalidFaceIdException;

/**
 * One face found in an image: where it is, how sure the detector is, and what identifies it.
 */
final class DetectedFace
{
    /**
     * @param array{int, int, int, int}  $box       left, top, right and bottom edge, in pixels
     * @param float                      $score     the detector's confidence, from 0 to 1
     * @param list<array{int, int}>      $landmarks right eye, left eye, nose, right mouth corner and left mouth
     *                                              corner, in pixels
     * @param string|null                $id        the face id (see FaceId); null when only locating faces
     * @param list<float>|null           $embedding the 512 numbers the id is derived from, L2-normalized; null when
     *                                              not asked for
     */
    public function __construct(
        public readonly array $box,
        public readonly float $score,
        public readonly array $landmarks = [],
        public readonly ?string $id = null,
        public readonly ?array $embedding = null,
    ) {
    }

    public function width(): int
    {
        return $this->box[2] - $this->box[0];
    }

    public function height(): int
    {
        return $this->box[3] - $this->box[1];
    }

    /**
     * How many bits of this face's id differ from another's; see FaceId::distance().
     *
     * @throws InvalidFaceIdException when either face has no id, or the two were hashed differently
     */
    public function distanceTo(self $other): int
    {
        if ($this->id === null || $other->id === null) {
            throw new InvalidFaceIdException(
                'Both faces need an id to be compared; they are left out when only locating faces.',
            );
        }

        return FaceId::distance($this->id, $other->id);
    }
}
