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
    public function __construct(private ApprovalInbox $inbox, private ?string $conversation = null) {}

    public function render(): View
    {
        return view('verdict-console::components.approvals', [
            'conversation' => $this->conversation,
            'items' => $this->conversation === null
                ? $this->inbox->items()
                : $this->inbox->itemsForConversation($this->conversation),
            'mounted' => Route::has('verdict-console.approvals.approve'),
            'routes' => [
                'approve' => 'verdict-console.approvals.approve',
                'reject' => 'verdict-console.approvals.reject',
                'close' => 'verdict-console.approvals.close',
            ],
        ]);
    }
}
