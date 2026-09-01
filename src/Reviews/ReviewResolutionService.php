<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Reviews;

use Fissible\Verdict\Contracts\ReviewStatusReader;
use Fissible\Verdict\Reviews\ReviewManager;
use Fissible\Verdict\Reviews\ReviewTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;

/** Records one authorized upstream review decision; it never starts, resumes, or mints a run. */
final readonly class ReviewResolutionService
{
    private const string AUTHORIZATION_REFUSAL_MESSAGE = 'This reviewer may not resolve this review.';

    public function __construct(
        private ReviewManager $reviews,
        private ReviewStatusReader $statuses,
        private Gate $gate,
        private Config $config,
    ) {}

    public function approve(string $requestId, ?Authenticatable $reviewer): ReviewTransition
    {
        return $this->resolve($requestId, $reviewer, true);
    }

    public function reject(string $requestId, ?Authenticatable $reviewer): ReviewTransition
    {
        return $this->resolve($requestId, $reviewer, false);
    }

    private function resolve(string $requestId, ?Authenticatable $reviewer, bool $approve): ReviewTransition
    {
        if ($reviewer === null || ! $this->gate->forUser($reviewer)->allows($this->ability(), $requestId)) {
            throw new AuthorizationException(self::AUTHORIZATION_REFUSAL_MESSAGE);
        }

        $scope = $this->config->get('verdict-console.reviews.scope');
        $view = $this->statuses->statusFor($requestId);

        if (! is_array($scope) || $scope === [] || $view === null || ! $this->contains($view->approvalContext, $scope)) {
            throw new AuthorizationException(self::AUTHORIZATION_REFUSAL_MESSAGE);
        }

        $actor = $reviewer->getAuthIdentifier();

        if ($actor === null || (is_string($actor) && trim($actor) === '')) {
            throw new AuthorizationException(self::AUTHORIZATION_REFUSAL_MESSAGE);
        }

        return $approve
            ? $this->reviews->approve($requestId, (string) $actor)
            : $this->reviews->reject($requestId, (string) $actor);
    }

    /**
     * @param  ?array<string, string|int>  $context
     * @param  array<string, mixed>  $scope
     */
    private function contains(?array $context, array $scope): bool
    {
        if ($context === null || $context === []) {
            return false;
        }

        foreach ($scope as $key => $value) {
            if ((! is_string($value) && ! is_int($value))
                || ! array_key_exists($key, $context) || $context[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function ability(): string
    {
        $ability = $this->config->get('verdict-console.reviews.gate');

        return is_string($ability) && $ability !== '' ? $ability : 'review-verdict-action';
    }
}
