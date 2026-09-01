<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Reviews;

use Fissible\Verdict\Contracts\ReviewStatusReader;
use Fissible\Verdict\Reviews\DatabaseReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewScopeMatch;
use Fissible\Verdict\Reviews\ReviewStatus;
use Fissible\Verdict\Reviews\ReviewStatusView;

/**
 * The database store's paired observational reader.
 *
 * The queue neither queries nor re-filters it: this reader owns the documented typed containment
 * and stable order, just as a host-provided ReviewStatusReader owns those rules for another store.
 */
final readonly class DatabaseReviewStatusReader implements ReviewStatusReader
{
    public function __construct(private DatabaseReviewRequestStore $store) {}

    public function statusFor(string $requestId): ?ReviewStatusView
    {
        return ReviewStatusView::fromNullableRequest($this->store->find($requestId));
    }

    public function pendingWithin(array $scope): array
    {
        ReviewScopeMatch::assertScope($scope);
        $requests = [];

        foreach ($this->store->connection()->table($this->store->table())->where('status', ReviewStatus::Pending->value)->get() as $row) {
            $request = $this->store->find($row->id);

            if ($request !== null && ReviewScopeMatch::matches($request->approvalContext, $scope)) {
                $requests[] = $request;
            }
        }

        usort($requests, static fn (ReviewRequest $a, ReviewRequest $b): int => [$a->createdAt->format('Y-m-d H:i:s'), $a->id] <=> [$b->createdAt->format('Y-m-d H:i:s'), $b->id]);

        return array_map(ReviewStatusView::fromRequest(...), $requests);
    }
}
