<?php

declare(strict_types=1);

use Fissible\Verdict\Capabilities\Events\CapabilityConfigurationUnrecorded;
use Fissible\Verdict\Evidence\Events\ChainWriteFailed;
use Fissible\Verdict\Evidence\Events\ConsequentialActionUnrecorded;
use Fissible\Verdict\Evidence\Events\EvidenceWriteFailed;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Approvals\UnresumableReason;
use Fissible\VerdictConsole\Events\ApprovalIngestionIncident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_incidents_table.php.stub')->up();
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_incidents');
    Schema::dropIfExists('verdict_console_pending_approvals');
});

it('persists each Verdict anomaly as one incident', function (): void {
    event(new ConsequentialActionUnrecorded('No durable recorder.'));
    event(new EvidenceWriteFailed('orders.refund', 'after_execution', 'invocation-1', 'Write failed.'));
    event(new ChainWriteFailed('chain-1', 'correlation-1', 'decision', 'append', 3, 'Append failed.'));
    event(new CapabilityConfigurationUnrecorded('orders.refund', 'fingerprint-1', 'Database unavailable.'));

    expect(DB::table('verdict_console_incidents')->orderBy('source')->get()->map(fn (object $incident): array => [
        'source' => $incident->source,
        'cause' => $incident->cause,
        'pending_approval_id' => $incident->pending_approval_id,
    ])->all())->toBe([
        ['source' => 'capability_configuration_unrecorded', 'cause' => 'Database unavailable.', 'pending_approval_id' => null],
        ['source' => 'chain_write_failed', 'cause' => 'Append failed.', 'pending_approval_id' => null],
        ['source' => 'consequential_action_unrecorded', 'cause' => 'No durable recorder.', 'pending_approval_id' => null],
        ['source' => 'evidence_write_failed', 'cause' => 'Write failed.', 'pending_approval_id' => null],
    ]);
});

it('persists an ingestion incident once with the row\'s typed cause', function (): void {
    $approval = (new PendingApprovalStore)->ingest(
        toolCallId: 'call-1',
        resumability: Resumability::Unresumable,
        unresumableReason: UnresumableReason::ChallengeUnavailable,
    );
    $incident = new ApprovalIngestionIncident($approval, UnresumableReason::ChallengeUnavailable);

    event($incident);
    event($incident);

    $stored = DB::table('verdict_console_incidents')->sole();

    expect($stored->source)->toBe('approval_ingestion')
        ->and($stored->cause)->toBe(UnresumableReason::ChallengeUnavailable->value)
        ->and($stored->pending_approval_id)->toBe($approval->id)
        ->and(PendingApproval::query()->sole()->unresumable_reason)->toBe(UnresumableReason::ChallengeUnavailable);
});

it('refuses an ingestion event whose cause disagrees with its persisted row', function (): void {
    $approval = (new PendingApprovalStore)->ingest(
        toolCallId: 'call-1',
        resumability: Resumability::Unresumable,
        unresumableReason: UnresumableReason::ChallengeUnavailable,
    );

    expect(fn (): mixed => event(new ApprovalIngestionIncident($approval, UnresumableReason::AgentUnresolvable)))
        ->toThrow(LogicException::class, 'must carry the row\'s typed unresumable reason.')
        ->and(DB::table('verdict_console_incidents')->count())->toBe(0);
});
