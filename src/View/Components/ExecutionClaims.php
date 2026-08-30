<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\View\Components;

use Fissible\VerdictConsole\ExecutionClaims\ExecutionClaimItem;
use Fissible\VerdictConsole\ExecutionClaims\ExecutionClaimService;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Renders Verdict's unresolved-claim queue for operators.
 *
 * This component owns urgency ordering because the service preserves Verdict's raw updated_at
 * order; indeterminate claims need human attention before merely claimed work. A resolve form is
 * follow-up work, so this projection only names the existing authorized command.
 */
final class ExecutionClaims extends Component
{
    public function __construct(private ExecutionClaimService $claims) {}

    public function render(): View
    {
        $claims = $this->claims->unresolved();

        usort($claims, fn (ExecutionClaimItem $left, ExecutionClaimItem $right): int => [
            $left->status !== 'indeterminate',
            $left->updatedAt,
        ] <=> [
            $right->status !== 'indeterminate',
            $right->updatedAt,
        ]);

        return view('verdict-console::components.execution-claims', ['claims' => $claims]);
    }
}
