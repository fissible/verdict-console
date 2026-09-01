<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Reviews;

/** Resolves the only controls a review surface may offer from its rendered item. */
final class ReviewVerbs
{
    /** @return list<ReviewVerb> */
    public function resolve(ReviewItem $item): array
    {
        return $item->state === ReviewItemState::Pending
            ? [ReviewVerb::Approve, ReviewVerb::Reject]
            : [];
    }
}
