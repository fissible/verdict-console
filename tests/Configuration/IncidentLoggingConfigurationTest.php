<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\UnresumableReason;
use Fissible\VerdictConsole\Events\ApprovalIngestionIncident;
use Illuminate\Support\Facades\Log;

it('does not register the default warning logger when disabled in configuration', function (): void {
    Log::spy();
    $row = new PendingApproval;
    $row->id = 'pending-approval-1';
    $row->tool_call_id = 'tool-call-1';

    event(new ApprovalIngestionIncident($row, UnresumableReason::ChallengeUnavailable));

    Log::shouldNotHaveReceived('warning');
});
