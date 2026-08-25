<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Incidents;

use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\UnresumableReason;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/** Records anomaly observations without treating them as Verdict authority or workflow state. */
final class IncidentStore
{
    /** @param array<string, scalar|null> $context */
    public function record(string $source, string $cause, array $context = []): Incident
    {
        return Incident::query()->create([
            'id' => Str::uuid()->toString(),
            'source' => $source,
            'cause' => $cause,
            'context' => $context,
            'observed_at' => now(),
        ]);
    }

    public function recordIngestion(PendingApproval $approval, UnresumableReason $reason): Incident
    {
        if ($approval->unresumable_reason !== $reason) {
            throw new \LogicException('An approval-ingestion incident must carry the row\'s typed unresumable reason.');
        }

        try {
            return Incident::query()->create([
                'id' => Str::uuid()->toString(),
                'source' => 'approval_ingestion',
                'cause' => $reason->value,
                'context' => ['tool_call_id' => $approval->tool_call_id],
                'pending_approval_id' => $approval->id,
                'observed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return Incident::query()
                ->where('source', 'approval_ingestion')
                ->where('pending_approval_id', $approval->id)
                ->sole();
        }
    }
}
