<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Reviews;

use Fissible\VerdictConsole\Exceptions\ReviewSurfaceContractViolation;

/** The one assertion every review rendering surface uses for its offered controls. */
final readonly class ReviewSurfaceContract
{
    public function __construct(private ReviewVerbs $verbs) {}

    /** @param list<ReviewVerb> $rendered */
    public function assertRendered(array $rendered, ReviewItem $item): void
    {
        $expected = $this->normalized($this->verbs->resolve($item));
        $actual = $this->normalized($rendered);

        if ($actual !== $expected) {
            throw new ReviewSurfaceContractViolation($this->verbs->resolve($item), $rendered);
        }
    }

    /**
     * @param  list<ReviewVerb>  $verbs
     * @return list<string>
     */
    private function normalized(array $verbs): array
    {
        $values = array_map(static fn (ReviewVerb $verb): string => $verb->value, $verbs);
        sort($values);

        return $values;
    }
}
