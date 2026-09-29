<?php

declare(strict_types=1);

namespace PhpLovesAi\Runner;

/**
 * The answer a JEV decision classifier chose, with the probability it gave every option.
 */
final class Decision
{
    /**
     * @param string               $prediction    the likeliest option, as given
     * @param int                  $index         its position among the options, from 0
     * @param float                $confidence    its probability, from 0 to 1
     * @param array<string, float> $probabilities every option with its probability, in the order given; they add up to 1
     *                                            (PHP turns numeric options such as "1" into integer keys)
     */
    public function __construct(
        public readonly string $prediction,
        public readonly int $index,
        public readonly float $confidence,
        public readonly array $probabilities,
    ) {
    }
}
