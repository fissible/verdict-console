<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Approvals\UnresumableReason;
use Fissible\VerdictConsole\Events\ApprovalIngestionIncident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_incidents_table.php.stub')->up();
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_incidents');
    Schema::dropIfExists('verdict_console_pending_approvals');
});

it('does not register the default warning logger when disabled in configuration', function (): void {
    Log::spy();
    $row = (new PendingApprovalStore)->ingest(
        toolCallId: 'tool-call-1',
        resumability: Resumability::Unresumable,
        unresumableReason: UnresumableReason::ChallengeUnavailable,
    );

    event(new ApprovalIngestionIncident($row, UnresumableReason::ChallengeUnavailable));

    Log::shouldNotHaveReceived('warning');

    expect(DB::table('verdict_console_incidents')->count())->toBe(1);
});
