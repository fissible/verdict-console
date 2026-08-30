<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\View\Components;

use Fissible\VerdictConsole\Approvals\ApprovalInbox;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Component;

/** Server-rendered inbox for the approvals currently visible to the host operator. */
final class Approvals extends Component
{
    public function __construct(private ApprovalInbox $inbox) {}

    public function render(): View
    {
        return view('verdict-console::components.approvals', [
            'items' => $this->inbox->items(),
            'mounted' => Route::has('verdict-console.approvals.approve'),
            'routes' => [
                'approve' => 'verdict-console.approvals.approve',
                'reject' => 'verdict-console.approvals.reject',
                'close' => 'verdict-console.approvals.close',
            ],
        ]);
    }
}
