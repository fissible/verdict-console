<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Approvals\ApprovalReconciliation;
use Fissible\VerdictConsole\Approvals\ApprovalReconciliationStore;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Approvals\ResumeFailurePhase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_approval_reconciliations_table.php.stub')->up();

    $this->approvals = new PendingApprovalStore;
    $this->reconciliations = new ApprovalReconciliationStore;
    $this->approval = $this->approvals->ingest(toolCallId: 'call_1', conversationId: 'conv_1');
});

afterEach(function (): void {
    Carbon::setTestNow();
    Schema::dropIfExists('verdict_console_approval_reconciliations');
    Schema::dropIfExists('verdict_console_pending_approvals');
});

it('durably distinguishes a failure before prompt from an indeterminate prompt failure', function (): void {
    $preExecution = $this->reconciliations->detect($this->approval, ResumeFailurePhase::DefinitelyPreExecution);

    $other = $this->approvals->ingest(toolCallId: 'call_2', conversationId: 'conv_2');
    $indeterminate = $this->reconciliations->detect($other, ResumeFailurePhase::Indeterminate);

    $stored = ApprovalReconciliation::query()->orderBy('created_at')->get();

    expect($preExecution->phase)->toBe(ResumeFailurePhase::DefinitelyPreExecution)
        ->and($indeterminate->phase)->toBe(ResumeFailurePhase::Indeterminate)
        ->and($stored)->toHaveCount(2)
        ->and($stored[0]->phase)->toBe(ResumeFailurePhase::DefinitelyPreExecution)
        ->and($stored[1]->phase)->toBe(ResumeFailurePhase::Indeterminate);
});

it('records detection in the database rather than on a live approval object', function (): void {
    $detected = $this->reconciliations->detect($this->approval, ResumeFailurePhase::DefinitelyPreExecution);

    $reloaded = PendingApproval::query()->sole();
    $stored = $this->reconciliations->find($reloaded);

    expect($detected->exists)->toBeTrue()
        ->and($stored)->not->toBeNull()
        ->and($detected->id)->toBe($stored->id)
        ->and($stored->pending_approval_id)->toBe($reloaded->id)
        ->and($stored->phase)->toBe(ResumeFailurePhase::DefinitelyPreExecution)
        ->and($stored->detected_at)->not->toBeNull();
});

it('detects the same failure once through its unique database key', function (): void {
    $first = $this->reconciliations->detect($this->approval, ResumeFailurePhase::Indeterminate);
    $second = $this->reconciliations->detect($this->approval, ResumeFailurePhase::Indeterminate);

    expect($second->id)->toBe($first->id)
        ->and(ApprovalReconciliation::query()->count())->toBe(1);
});

/**
 * First detection wins, phase included — the stored phase is a fact, not the latest opinion.
 *
 * A second observation carrying a different phase is discarded rather than written over the first.
 * Overwriting would show an operator the most recent report with no way to tell it from the original,
 * and the original is the one taken at the moment continuation actually failed. A row needing two
 * observations needs two records and a schema that admits them, not a mutable field on one.
 */
it('keeps the first observed phase when a later detection disagrees', function (): void {
    $first = $this->reconciliations->detect($this->approval, ResumeFailurePhase::DefinitelyPreExecution);
    $second = $this->reconciliations->detect($this->approval, ResumeFailurePhase::Indeterminate);

    expect($second->id)->toBe($first->id, 'A second detection must not create a second record.')
        ->and($second->phase)->toBe(ResumeFailurePhase::DefinitelyPreExecution)
        ->and(ApprovalReconciliation::query()->sole()->phase)
        ->toBe(ResumeFailurePhase::DefinitelyPreExecution, 'The stored observation is the first one, not the last.');
});

it('marks a detected reconciliation abandoned once', function (): void {
    Carbon::setTestNow('2026-08-25 00:00:00');
    $reconciliation = $this->reconciliations->detect($this->approval, ResumeFailurePhase::Indeterminate);

    Carbon::setTestNow('2026-08-25 00:01:00');
    $first = $this->reconciliations->markAbandoned($reconciliation);

    Carbon::setTestNow('2026-08-25 00:02:00');
    $second = $this->reconciliations->markAbandoned($reconciliation);

    expect($first->abandoned_at?->toDateTimeString())->toBe('2026-08-25 00:01:00')
        ->and($second->abandoned_at?->toDateTimeString())->toBe('2026-08-25 00:01:00')
        ->and(ApprovalReconciliation::query()->sole()->abandoned_at?->toDateTimeString())->toBe('2026-08-25 00:01:00');
});

it('does not create a reconciliation field that replays a human decision or links an execution claim', function (): void {
    $columns = Schema::getColumnListing('verdict_console_approval_reconciliations');

    expect($columns)->not->toContain('decision')
        ->and($columns)->not->toContain('approved')
        ->and($columns)->not->toContain('rejected')
        ->and($columns)->not->toContain('execution_claim_id')
        ->and($columns)->not->toContain('execution_claim_fingerprint');
});
