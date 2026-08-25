<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\VerdictConsole\Exceptions\ApprovalSurfaceContractViolation;

/**
 * Test helper VC-19/21/24/25/28 must use to pin ADR 0001's verb invariant.
 *
 * A component test may prove its own markup and still accidentally render an override, bulk, or
 * stale approve control. Comparing its extracted verb set here makes every surface answer to the
 * same live-challenge rule instead of re-implementing authorization policy in presentation code.
 */
final readonly class ApprovalSurfaceContract
{
    public function __construct(private ApprovalVerbs $verbs) {}

    /**
     * @param  list<ApprovalVerb>  $rendered
     */
    public function assertRendered(array $rendered, PendingApproval $approval, ?ApprovalChallenge $challenge): void
    {
        $expected = $this->verbs->resolve($approval, $challenge);
        $normalizedExpected = $this->normalized($expected);
        $normalizedRendered = $this->normalized($rendered);

        // Sorted value comparison, so a surface may order its controls however it likes and still
        // answer to the same set. It also settles duplicates without a separate check: the resolver
        // never returns one, so a repeated verb changes the length and fails this comparison. An
        // explicit array_unique() guard here would be a branch that cannot reach its own false.
        if ($normalizedRendered !== $normalizedExpected) {
            throw new ApprovalSurfaceContractViolation($expected, $rendered);
        }
    }

    /**
     * @param  list<ApprovalVerb>  $verbs
     * @return list<string>
     */
    private function normalized(array $verbs): array
    {
        $values = array_map(static fn (ApprovalVerb $verb): string => $verb->value, $verbs);
        sort($values);

        return $values;
    }
}
