<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Reviews;

use Fissible\Verdict\Contracts\ReviewStatusReader;
use Fissible\Verdict\Reviews\ReviewStatusView;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;

/** Read-only, never-widened queue of pending review requests. */
final readonly class ReviewQueue
{
    public function __construct(private Container $app, private Config $config) {}

    public function items(): ReviewQueueResult
    {
        if (! is_string($this->config->get('verdict.reviews.store'))) {
            return new ReviewQueueResult(ReviewQueueState::Unconfigured, []);
        }

        $scope = $this->config->get('verdict-console.reviews.scope');

        if (! is_array($scope) || $scope === []) {
            return new ReviewQueueResult(ReviewQueueState::Unscoped, []);
        }

        /** @var non-empty-array<string, string|int> $scope */
        $views = $this->app->make(ReviewStatusReader::class)->pendingWithin($scope);

        return new ReviewQueueResult(ReviewQueueState::Ready, array_map(function (ReviewStatusView $view): ReviewItem {
            $state = $view->expiresAt > now()
                ? ReviewItemState::Pending
                : ReviewItemState::LapsedUndecided;

            return new ReviewItem(
                requestId: $view->requestId,
                capability: $view->capability,
                state: $state,
                reason: $view->reason,
                summaryFingerprint: $view->summaryFingerprint,
                createdAt: $view->createdAt,
                expiresAt: $view->expiresAt,
                resolvedBy: $view->resolvedBy,
                resolvedAt: $view->resolvedAt,
            );
        }, $views));
    }
}
