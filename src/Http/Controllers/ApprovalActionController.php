<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Http\Controllers;

use Fissible\VerdictConsole\Approvals\ApprovalResolutionService;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Exceptions\ApprovalNotDrivable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Relays one scoped inbox form post to the headless approval-resolution service. */
final readonly class ApprovalActionController
{
    public function __construct(
        private PendingApprovalStore $pendingApprovals,
        private ApprovalResolutionService $resolutions,
    ) {}

    public function approve(Request $request, string $approval): RedirectResponse
    {
        return $this->resolve($request, $approval, 'approve');
    }

    public function reject(Request $request, string $approval): RedirectResponse
    {
        return $this->resolve($request, $approval, 'reject');
    }

    public function close(Request $request, string $approval): RedirectResponse
    {
        $pending = $this->pendingApprovals->findVisible($approval);

        abort_if($pending === null, 404);

        try {
            $status = $this->resolutions->close($pending, $request->user())->value;
        } catch (ApprovalNotDrivable) {
            $status = 'not_actionable';
        }

        return redirect()->back()->with('verdict-console.status', $status);
    }

    /** @param 'approve'|'reject' $verb */
    private function resolve(Request $request, string $approval, string $verb): RedirectResponse
    {
        $pending = $this->pendingApprovals->findVisible($approval);

        abort_if($pending === null, 404);

        try {
            $transition = match ($verb) {
                'approve' => $this->resolutions->approve($pending, $request->user()),
                'reject' => $this->resolutions->reject($pending, $request->user()),
            };
            $status = $transition === null ? 'not_actionable' : match ($verb) {
                'approve' => 'approved',
                'reject' => 'rejected',
            };
        } catch (ApprovalNotDrivable) {
            $status = 'not_actionable';
        }

        return redirect()->back()->with('verdict-console.status', $status);
    }
}
