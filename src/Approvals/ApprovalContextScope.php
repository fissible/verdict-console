<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Constrains operator visibility to a captured, host-owned approval context.
 *
 * Matching happens in PHP after Eloquent has read the cast context: no backend JSON containment
 * operator, nor any backend's number/string coercion, can preserve ADR 0031 §3's typed-exact
 * rule across every supported database (the portability decision from Verdict #327). Context is
 * stable enough to constrain by the resulting ids because it is immutable after issue and console
 * capture is first-write-wins. Consequently, on the same identifiers and with the same rule, this
 * scope exposes a subset of what Verdict would let them decide.
 */
final readonly class ApprovalContextScope implements ApprovalScope
{
    /** @var non-empty-array<string, string|int> */
    private array $scope;

    /**
     * @param  array<string, string|int>  $scope
     */
    public function __construct(array $scope)
    {
        if ($scope === []) {
            throw new InvalidArgumentException(
                'ApprovalContextScope requires a non-empty approval-context scope; unscoped global visibility is deliberately not expressible (ADR 0031).'
            );
        }

        $this->scope = $scope;
    }

    public function apply(Builder $query): Builder
    {
        $model = $query->getModel();
        $keyName = $model->getKeyName();
        $ids = [];

        // This deliberately starts from the incoming model rather than cloning or changing the
        // operator query: candidate discovery must be unscoped, while the returned query retains
        // every constraint the host already added.
        $candidates = $model->newQueryWithoutScopes()
            ->whereNotNull('approval_context')
            ->where('approval_context', '!=', '[]')
            ->get([$keyName, 'approval_context']);

        foreach ($candidates as $candidate) {
            $context = $candidate->getAttribute('approval_context');

            if (! is_array($context)) {
                continue;
            }

            foreach ($this->scope as $key => $value) {
                if (! array_key_exists($key, $context) || $context[$key] !== $value) {
                    continue 2;
                }
            }

            $ids[] = $candidate->getKey();
        }

        return $query->whereIn($keyName, $ids);
    }
}
